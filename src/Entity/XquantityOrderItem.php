<?php

namespace Drupal\commerce_xquantity\Entity;

use Drupal\Core\Form\FormState;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_price\Calculator;
use Drupal\xnumber\Utility\Xnumber as Numeric;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\commerce\PurchasableEntityInterface;

/**
 * Overrides the order item entity class.
 */
class XquantityOrderItem extends OrderItem {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['quantity'] = BaseFieldDefinition::create('xdecimal')
      ->setLabel(t('Quantity'))
      ->setDescription(t('The number of purchased units.'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE)
      ->setSetting('precision', 14)
      ->setSetting('scale', 4)
      ->setSetting('min', 0)
      ->setDefaultValue(1)
      ->setDisplayOptions('form', [
        'type' => 'xnumber',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemsQuantity() {
    $settings = $this->getQuantityWidgetSettings();
    // The #step value defines the actual type of the current order item's
    // quantity field. If that is int then we consider the quantity as a sum of
    // order items. If float, then we consider the quantity as one item
    // consisting of multiple units. For example: 1 + 2 T-shirts are counted as
    // 3 separate items but 1.000 + 2.000 kg of butter is counted as 1 item
    // consisting of 3000 units. Hence, this method must be used only to count
    // items on an order. The $this->getQuantity() must be used for getting real
    // quantity disregarding of whatever the type of this number is, for example
    // to calculate the price of order items.
    $step = isset($settings['step']) && is_numeric($settings['step']) ? $settings['step'] + 0 : 1;
    $quantity = $this->getQuantity();
    return (string) is_int($step) ? $quantity : (is_float($step) && $quantity > 0 ? '1' : $quantity);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuantityWidgetSettings() {
    $settings = [];
    $settings['disable_on_cart'] = FALSE;
    // If 'Add to cart' form display mode is enabled we prefer its settings
    // because exactly those settings are exposed to and used by a customer.
    $form_display = entity_get_form_display($this->getEntityTypeId(), $this->bundle(), 'add_to_cart');
    $quantity = $form_display->getComponent('quantity');

    if (!$quantity) {
      $form_display = entity_get_form_display($this->getEntityTypeId(), $this->bundle(), 'default');
      $quantity = $form_display->getComponent('quantity');
    }

    if (isset($quantity['settings']['step'])) {
      $settings = $form_display->getRenderer('quantity')->getFormDisplayModeSettings();
    }
    else {
      // If $settings has no 'step' it means that some unknown mode is used, so
      // $form_display->getRenderer('quantity')->getSettings() is useless here.
      // We use $quantity->defaultValuesForm() to get an array with #min, #max,
      // #step, #field_prefix, #field_suffix and #default_value elements.
      $form_state = new FormState();
      $form = [];
      $form = $this->get('quantity')->defaultValuesForm($form, $form_state);
      $settings += (array) NestedArray::getValue($form, ['widget', 0, 'value']);
      // Make prefix/suffix settings accessible through #prefix/#suffix keys.
      $settings['prefix'] = isset($settings['prefix']) ? $settings['prefix'] : FALSE;
      $settings['suffix'] = isset($settings['suffix']) ? $settings['suffix'] : FALSE;
      $settings['prefix'] = $settings['prefix'] ?: (isset($settings['field_prefix']) ? $settings['field_prefix'] : '');
      $settings['suffix'] = $settings['suffix'] ?: (isset($settings['field_suffix']) ? $settings['field_suffix'] : '');
    }

    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function setQuantityPrices(FormInterface &$form_object, PluginInspectionInterface $widget, FormStateInterface $form_state) {
    $settings = $this->getQuantityWidgetSettings();
    if (empty($settings['qty_prices']) || !($count = count($settings['qty_price'])) || !($purchased_entity = $this->getPurchasedEntity())) {
      return $settings;
    }
    $lis = $notify = '';
    $price = $purchased_entity->getPrice();
    $variation_type = $purchased_entity->bundle();
    $product = $purchased_entity->getProduct();
    $product_stores = $product->getStores();
    array_walk($product_stores, function (&$store) {
      $store = $store->bundle();
    });
    $list_price = $purchased_entity->getListPrice();
    $data = [
      'variation_id' => $purchased_entity->id(),
      'variation_type' => $purchased_entity->bundle(),
      'product_id' => $product->id(),
      'product_type' => $product->bundle(),
      'list_price' => $list_price,
      'product_stores' => $product_stores,
      'current_roles' => \Drupal::currentUser()->getRoles(),
    ];
    $form_object->quantityPrices = $arguments = [];
    $form_object->quantityScale = Numeric::getDecimalDigits($settings['step']);
    $formatter = \Drupal::service('commerce_price.currency_formatter');
    // Roll back to an initial price.
    $form_object->quantityPrices[] = [
      'price' => $price,
      'qty_start' => $settings['min'] ?: $settings['step'],
      'qty_end' => '',
    ];
    foreach ($settings['qty_price'] as $index => $qty_price) {
      extract($qty_price);
      if ($qty_start && ($settings['qty_prices'] > $index) && $this->quantityPriceApplies($qty_price, $data)) {
        $new = $list ? $list_price : $price;
        if (is_numeric($adjust_value)) {
          if ($adjust_type == 'fixed_number') {
            $adjust_price = new $new($adjust_value, $new->getCurrencyCode());
          }
          else {
            $adjust_price = $new->divide('100')->multiply($adjust_value);
          }
          $new = $new->$adjust_op($adjust_price);
        }
        if ($new->isNegative()) {
          $new = $new->multiply('0');
        }
        $form_object->quantityPrices[] = [
          'price' => $new,
        ] + $qty_price;
        $new = $new->toArray();
        if ($notify) {
          $args = [];
          foreach ($qty_price as $key => $value) {
            $args["%{$key}"] = $value;
          }
          $arguments[] = [
            '%price' => $formatter->format(Calculator::round($new['number'], 2), $new['currency_code']),
          ] + $args;
          $li = new TranslatableMarkup('Buy <span style="color:yellow;font-weight: bolder;">%qty_start</span> or more and get <span style="color:yellow;font-weight: bolder;">%price</span> price for an item', end($arguments));
          $lis .= "<li>{$li}</li>";
        }
      }
    }
    $module_handler = \Drupal::moduleHandler();
    $module_handler->alter("xquantity_add_to_cart_qty_prices", $form_object, $widget, $form_state);
    $form_state->setFormObject($form_object);
    if ($lis) {
      $msg = new TranslatableMarkup("Price adjustments for the %label:<br><ul>{$lis}</ul><hr>", [
        '%label' => $this->label(),
        'qty_arguments' => $arguments,
      ]);
      $module_handler->alter("xquantity_add_to_cart_qty_prices_msg", $msg, $widget, $form_state);
      $msg && $widget->messenger()->addMessage($msg);
    }

    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuantityPrice(FormInterface $form_object, PurchasableEntityInterface $purchased_entity, $quantity = NULL) {
    $price = NULL;
    $scale = $form_object->quantityScale ?: 0;
    if ($qty_prices = $form_object->quantityPrices) {
      $product = $purchased_entity->getProduct();
      $product_stores = $product->getStores();
      array_walk($product_stores, function (&$store) {
        $store = $store->bundle();
      });
      $data = [
        'variation_id' => $purchased_entity->id(),
        'variation_type' => $purchased_entity->bundle(),
        'product_id' => $product->id(),
        'product_type' => $product->bundle(),
        'list_price' => $purchased_entity->getListPrice(),
        'product_stores' => $product_stores,
        'current_roles' => \Drupal::currentUser()->getRoles(),
      ];
      foreach ($qty_prices as $qty_price) {
        $start = bccomp($qty_price['qty_start'], $quantity, $scale);
        $end = $qty_price['qty_end'] ? bccomp($quantity, $qty_price['qty_end'], $scale) : 0;
        if (($end === 1) || ($start === 1)) {
          continue;
        }
        if ($this->quantityPriceApplies($qty_price, $data)) {
          $price = $qty_price['price'];
        }
      }
    }

    return $price;
  }

  /**
   * {@inheritdoc}
   */
  public function quantityPriceApplies(array $qty_price, array $data) {
    $list = $week_days = $time_start = $time_end = $date_start = $date_end = $variation_ids = $product_ids =
      $variation_types = $product_types = $stores = $roles = NULL;
    extract($qty_price + $data);
    $current = time();
    if (
      $list && !$list_price ||
      $week_days && !in_array(date('l'), array_map('trim', explode(PHP_EOL, $week_days))) ||
      $time_start && (strtotime($time_start) > $current) ||
      $time_end && (strtotime($time_end) < $current) ||
      $date_start && (strtotime($date_start) > $current) ||
      $date_end && (strtotime($date_end) < $current) ||
      $variation_ids && !in_array($variation_id, array_map('trim', explode(PHP_EOL, $variation_ids))) ||
      $product_ids && !in_array($product_id, array_map('trim', explode(PHP_EOL, $product_ids))) ||
      $variation_types && !in_array($variation_type, array_map('trim', explode(PHP_EOL, $variation_types))) ||
      $product_types && !in_array($product_type, array_map('trim', explode(PHP_EOL, $product_types))) ||
      $stores && !array_intersect($product_stores, array_map('trim', explode(PHP_EOL, $stores))) ||
      $roles && !array_intersect($current_roles, array_map('trim', explode(PHP_EOL, $roles)))
    ) {
      return FALSE;
    }

    return TRUE;
  }

}
