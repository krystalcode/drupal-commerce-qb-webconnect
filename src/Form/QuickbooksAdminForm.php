<?php

namespace Drupal\commerce_qb_webconnect\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class QuickbooksAdminForm.
 *
 * @package Drupal\commerce_qb_webconnect\Form
 */
class QuickbooksAdminForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'commerce_qb_webconnect.quickbooks_admin',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'qb_webconnect_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('commerce_qb_webconnect.quickbooks_admin');

    // Check if we have a GUID before creating one.
    if (empty($config->get('qwc_owner_id'))) {
      $uuid = \Drupal::service('uuid');
      $qwc_owner_id = $uuid->generate();
    }
    else {
      $qwc_owner_id = $config->get('qwc_owner_id');
    }
    $form['#tree'] = TRUE;
    $form['exportables'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Export data'),
    ];
    $form['exportables']['products'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Export products'),
      '#description' => $this->t('Products do not have a price. Only product variations. All product variations will be exported and related to their parent product in Quickbooks.'),
      '#default_value' => $config->get('exportables')['products'],
    ];
    $form['exportables']['order_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Order export type'),
      '#description' => $this->t('Select order export type. Invoices are for sales that have a payment at a later date. Sales receipts are for payments effective immediately.'),
      '#options' => [
        'invoices' => $this->t('Invoices'),
        'sales_receipts' => $this->t('Sales receipts'),
      ],
      '#default_value' => $config->get('exportables')['order_type'],
      '#size' => 2,
    ];

    $form['accounts'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Product Export Settings'),
      '#description' => $this->t('The default account names for Product sales; these are mandatory and must match the full display name in Quickbooks, but can be changed at any time.'),
    ];
    $form['accounts']['main_income_account'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Quickbooks main income account'),
      '#description' => $this->t('When exporting products through Quickbooks WebConnect, the resulting Quickbooks products will be linked to this account.'),
      '#maxlength' => 64,
      '#size' => 64,
      '#default_value' => $config->get('accounts')['main_income_account'],
      '#required' => TRUE,
    ];
    $form['accounts']['cogs_account'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Quickbooks COGS account'),
      '#description' => $this->t('Provide the name of the Cost of Goods Sold (COGS) account for exported products.'),
      '#maxlength' => 64,
      '#size' => 64,
      '#default_value' => $config->get('accounts')['cogs_account'],
      '#required' => TRUE,
    ];
    $form['accounts']['assets_account'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Quickbooks assets account'),
      '#maxlength' => 64,
      '#size' => 64,
      '#default_value' => $config->get('accounts')['assets_account'],
      '#required' => TRUE,
    ];

    $form['adjustments'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Adjustment settings'),
    ];
    $form['adjustments']['discount_service'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Discount service'),
      '#description' => $this->t('Provide the name of default discount-item so that QuickBooks can keep track of order promotions.'),
      '#default_value' => $config->get('adjustments')['discount_service'],
    ];
    $form['adjustments']['shipping_service'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shipping item'),
      '#description' => $this->t('Provide the name of default shipping service-item so that Quickbooks can keep track of shipping charges.'),
      '#maxlength' => 64,
      '#size' => 64,
      '#default_value' => $config->get('adjustments')['shipping_service'],
    ];

    $form['id_prefixes'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Custom ID prefixes'),
      '#description' => $this->t('If your Quickbooks setup requires custom IDs, you can set a prefix here to prepend to generated reference numbers.'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];
    $form['id_prefixes']['po_number_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Quickbooks invoice/sales receipt number prefix'),
      '#description' => $this->t('Specify the prefix to the order id to be used when generating the invoice or sales receipt number.'),
      '#maxlength' => 32,
      '#size' => 32,
      '#default_value' => $config->get('id_prefixes')['po_number_prefix'],
    ];
    $form['id_prefixes']['payment_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Quickbooks payment reference number prefix'),
      '#description' => $this->t('Specify the prefix to the payment number to be used when generating the payment reference number.'),
      '#maxlength' => 32,
      '#size' => 32,
      '#default_value' => $config->get('id_prefixes')['payment_prefix'],
    ];

    $form['qwc_owner_id'] = [
      '#type' => 'hidden',
      '#value' => $qwc_owner_id,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $config = $this->config('commerce_qb_webconnect.quickbooks_admin');
    $config->delete();
    $values = $form_state->cleanValues()->getValues();
    unset($values['actions']);
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }

}
