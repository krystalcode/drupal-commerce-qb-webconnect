<?php

namespace Drupal\commerce_qb_webconnect\SoapBundle\Services;

use Drupal\commerce_qb_webconnect\QbWebConnectUtilities;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessage;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigratePluginManagerInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManager;
use Drupal\user\Entity\User;
use Drupal\user\UserAuthInterface;

/**
 * Handle SOAP requests and return a response.
 *
 * Class SoapService.
 *
 * @package Drupal\commerce_qb_webconnect\SoapBundle\Services
 */
class SoapService implements SoapServiceInterface {


  /**
   * The row currently being migrated.
   *
   * @var \Drupal\migrate\Row
   */
  protected $row;

  /**
   * The migration plugin manager.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManager
   */
  protected $migrationPluginManager;

  /**
   * The id map plugin manager.
   *
   * @var \Drupal\migrate\Plugin\MigratePluginManagerInterface
   */
  protected $idMapPluginManager;

  /**
   * The user auth service.
   *
   * @var \Drupal\user\UserAuthInterface
   */
  private $userAuthInterface;

  /**
   * The session manager.
   *
   * Responsible for managing, validating and invalidating SOAP sessions.
   *
   * @var \Drupal\commerce_qb_webconnect\SoapBundle\Services\SoapSessionManager
   */
  protected $sessionManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module's configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The current server version.
   *
   * @var string
   */
  protected $serverVersion = '1.0';

  /**
   * The version returned by the client.
   *
   * @var string
   */
  protected $clientVersion;

  /**
   * Constructs a new SoapService.
   *
   * @param \Drupal\migrate\Plugin\MigrationPluginManager $migrationPluginManager
   *   The migration plugin manager.
   * @param \Drupal\migrate\Plugin\MigratePluginManagerInterface $idMapPluginManager
   *   The id mapping plugin migrate manager.
   * @param \Drupal\user\UserAuthInterface $userAuthInterface
   *   The user auth service.
   * @param \Drupal\commerce_qb_webconnect\SoapBundle\Services\SoapSessionManager $sessionManager
   *   The session manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    MigrationPluginManager $migrationPluginManager,
    MigratePluginManagerInterface $idMapPluginManager,
    UserAuthInterface $userAuthInterface,
    SoapSessionManager $sessionManager,
    EntityTypeManagerInterface $entityTypeManager,
    ConfigFactoryInterface $configFactory,
    StateInterface $state
  ) {
    $this->migrationPluginManager = $migrationPluginManager;
    $this->idMapPluginManager = $idMapPluginManager;
    $this->userAuthInterface = $userAuthInterface;
    $this->sessionManager = $sessionManager;
    $this->entityTypeManager = $entityTypeManager;
    $this->config = $configFactory->get('commerce_qb_webconnect.quickbooks_admin');
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public function __call($method, array $data) {
    $public_services = ['clientVersion', 'serverVersion', 'authenticate'];

    $request = $this->prepareResponse($method, $data);

    $uc = ucfirst($method);
    $callable = "call$uc";

    $response = NULL;

    // If the method being requested requires a validated user, do that now.
    if (!in_array($method, $public_services)) {
      // The request must have a ticket to proceed.
      if (empty($request->ticket)) {
        return $request;
      }

      $valid = $this->sessionManager
        ->setUuid($request->ticket)
        ->validateSession($method);

      // If the client has a valid ticket and request, log in now.
      if ($valid) {
        /** @var \Drupal\user\UserInterface $user */
        $user = User::load($this->sessionManager->getUid());
        user_login_finalize($user);

        if (!$user->hasPermission('access quickbooks soap service')) {
          \Drupal::logger('commerce_qb_webconnect')->warning('User logged in successfully but didn\'t have Quickbooks SOAP Service access permissions.');
          return $request;
        }
      }
      else {
        \Drupal::logger('commerce_qb_webconnect')->error('The user had an invalid session token or made an invalid request.  Aborting communication...');
        return $request;
      }
    }

    // If a valid method method is being called, parse the incoming request
    // and call the method with the parsed data passed in.
    if (is_callable([$this, $callable])) {
      // Prepare the response to the client.
      $response = $this->$callable($request);
    }

    return $response;
  }


  /****************************************************
   * Private helper functions                         *
   ****************************************************/

  /**
   * Builds the stdClass object required by a service response handler.
   *
   * @param string $method_name
   *   The Quickbooks method being called.
   * @param string $data
   *   The raw incoming soap request.
   *
   * @return \stdClass
   *   An object with the following properties:
   *   stdClass {
   *     methodNameResult => '',
   *     requestParam1 => 'foo',
   *     ...
   *     requestParamN => 'bar',
   *   }
   */
  private function prepareResponse($method_name, $data) {
    $response = isset($data[0]) ? $data[0] : new \stdClass();
    $response->$method_name = '';

    return $response;
  }

  /**
   * Calculate the completion progress of the current SOAP session.
   *
   * @return int
   *   The  percentage completed.
   */
  private function getCompletionProgress() {
    $done = 0;
    $todo = 0;
    foreach ($this->migrationPluginManager->createInstancesByTag('QB Webconnect') as $id => $migration) {
      $map = $migration->getIdMap();
      $done += $map->processedCount();
      $todo += $migration->getSourcePlugin()->count() - $map->processedCount();
    }

    return $done + $todo ? (int) (100 * ($done / ($done + $todo))) : 1;
  }


  /****************************************************
   * The WSDL defined SOAP service calls              *
   ****************************************************/

  /**
   * {@inheritdoc}
   */
  public function callServerVersion(\stdClass $request) {
    $request->serverVersionResult = $this->serverVersion;
    return $request;
  }

  /**
   * {@inheritdoc}
   */
  public function callClientVersion(\stdClass $request) {
    $this->clientVersion = $request->strVersion;

    $request->clientVersionResult = '';
    return $request;
  }

  /**
   * {@inheritdoc}
   *
   * @TODO: Reset failed exports id requested.
   */
  public function callAuthenticate(\stdClass $request) {
    $strUserName = $request->strUserName;
    $strPassword = $request->strPassword;

    // Initial "fail" response.
    $result = ['', 'nvu'];

    // If the service isn't set for whatever reason we can't continue.
    if (!isset($this->userAuthInterface)) {
      \Drupal::logger('commerce_qb_webconnect')->error("User Auth service couldn't be initialized.");
    }
    else {
      $uid = $this->userAuthInterface->authenticate($strUserName, $strPassword);

      if (!$uid) {
        \Drupal::logger('commerce_qb_webconnect')->error("Invalid login credentials, aborting quickbooks SOAP service.");
      }
      else {
        $uuid = \Drupal::service('uuid')->generate();
        $this->sessionManager->startSession($uuid, $uid);

        $result = [$uuid, ''];
      }
    }

    $request->authenticateResult = $result;
    return $request;
  }

  /**
   * {@inheritdoc}
   */
  public function callSendRequestXML(\stdClass $request) {
    $migrations = $this->migrationPluginManager->createInstancesByTag('QB Webconnect');
    /** @var \Drupal\migrate\Plugin\MigrationInterface $migration */
    foreach ($this->migrationPluginManager->buildDependencyMigration($migrations, []) as $migration) {
      // Proceed to next migration if there are no remaining items to import.
      $remaining = $migration->getSourcePlugin()->count() - $migration->getIdMapPlugin()->processedCount();
      if (!$remaining && !$migration->getIdMap()->updateCount()) {
        continue;
      }
      // Our MigrateSubscriber stops this migration after a single row.
      (new MigrateExecutable($migration, new MigrateMessage()))->import();
      // Let's end the import for now and we'll continue next time.
      break;
    }
    $this->row = $this->state->get('qb_webconnect.current_row');
    $callback = $this->prepareCallback($this->row->getSourceProperty('static/send_callback'));
    if (is_callable($callback)) {
      $qbxml = call_user_func($callback);
      $request->sendRequestXMLResult = $this->addXMLEnvelope($qbxml);
      return $request;
    }

    \Drupal::logger('commerce_qb_webconnect')->error("Unable to prepare data for export.  No method found for [$callback]");
    $this->state->get('qb_webconnect.current_row');
    return $request;
  }

  /**
   * Converts callback notations to a valid callable.
   *
   *
   * @param string|array $callback
   *   The callback.
   *
   * @return array
   *   A valid callable.
   */
  protected function prepareCallback($callback) {
    if (is_string($callback)) {
      $callback = [get_called_class(), $callback];
    }
    return $callback;
  }

  /**
   * Add an XML envelope.
   *
   * @param string $qbxml
   *   The qbxml.
   *
   * @return string
   *   The xml wrapped in an envelope.
   */
  protected function addXMLEnvelope($qbxml) {
    return '<?xml version="1.0" encoding="utf-8"?><?qbxml version="13.0"?><QBXML><QBXMLMsgsRq onError="stopOnError">' . $qbxml . '</QBXMLMsgsRq></QBXML>';
  }

  /**
   * {@inheritdoc}
   */
  public function callReceiveResponseXML(\stdClass $request) {
    $this->row = $this->state->get('qb_webconnect.current_row');
    $retry = FALSE;

    // Parse any errors if we have them to decide our next action.
    if (!empty($request->response)) {
      if ($code = QbWebConnectUtilities::extractStatusCode($request->response)) {
        $error = [
          'statusCode' => $code,
          'statusMessage' => QbWebConnectUtilities::extractStatusMessage($request->response),
        ];
        // 3180 is a temporary error with no clear reason. Just retry it.
        if ($error['statusCode'] == "3180") {
          $retry = TRUE;
        }
        else {
          /** @var \Drupal\migrate\Plugin\Migration $migration */
          $migration = $this->migrationPluginManager->createInstance($this->state->get('qb_webconnect.current_migration'));
          $migration->getIdMap()->saveMessage($this->row->getSourceIdValues(), print_r($error, TRUE), MigrationInterface::MESSAGE_ERROR);
        }
      }
    }
    $callback = $this->prepareCallback($this->row->getSourceProperty('static/receive_callback'));
    if (!$retry && is_callable($callback)) {
      call_user_func($callback, $request);
    }

    $request->receiveResponseXMLResult = $this->getCompletionProgress();

    return $request;
  }

  /**
   * Update identifiers.
   *
   * @param \stdClass $request
   *   The request.
   */
  protected function updateIdentifier(\stdClass $request) {
    $identifier = $this->row->getDestinationProperty('uuid');

    if ($extracted = QbWebConnectUtilities::extractIdentifiers($request->response, $this->row->getSource()['entity_type'])) {
      $identifier = $extracted;
    }
    $import_status = MigrateIdMapInterface::STATUS_IMPORTED;
    // Mark a row as needing update so it can be re-imported if an error occurs.
    if ($code = QbWebConnectUtilities::extractStatusCode($request->response)) {
      $import_status = MigrateIdMapInterface::STATUS_NEEDS_UPDATE;
      switch ($code) {
        case 3100:
          // This status code means, "Already exists".
          $import_status = MigrateIdMapInterface::STATUS_IMPORTED;
          break;

        // The given object ID in the field "list" is invalid.
        case 3000:
          // Occurs if a reference does not match an existing QB entry.
        case 3140:
          // The specified reference was not found.
        case 3120:
          // The name of the list element is already in use.
        case 3100:
          $import_status = MigrateIdMapInterface::STATUS_FAILED;
          break;

      }
    }
    /** @var \Drupal\migrate\Plugin\Migration $migration */
    $migration = $this->migrationPluginManager->createInstance($this->state->get('qb_webconnect.current_migration'));
    $migration->getIdMap()->saveIdMapping($this->row, ['uuid' => $identifier], $import_status);
  }

  /**
   * {@inheritdoc}
   */
  public function callGetLastError(\stdClass $request) {
    $progress = $this->getCompletionProgress();

    if ($progress == 100) {
      $request->getLastErrorResult = 'No new exports remaining.';
    }
    else {
      $request->getLastErrorResult = "$progress% remaining.";
    }

    return $request;
  }

  /**
   * {@inheritdoc}
   */
  public function callCloseConnection(\stdClass $request) {
    $this->sessionManager->closeSession();
    $request->closeConnectionResult = 'OK';

    return $request;
  }

  /**
   * Parse profile entities into a template-ready object.
   */
  protected function prepareCustomerExport() {
    if ($this->row->getSourceProperty('bundle') == 'customer') {
      $uuid = $this->row->getDestinationProperty('uuid');
      $profile_id = $this->row->getSourceProperty('profile_id');
      $addresses = $this->row->getSourceProperty('address');
      $address = reset($addresses);
      $customer = new \QuickBooks_QBXML_Object_Customer();
      if (QbWebConnectUtilities::isQuickbooksIdentifier($uuid)) {
        $customer->setListID($uuid);
        return $customer->asQBXML(QUICKBOOKS_QUERY_CUSTOMER);
      }

      $address1 = $address['address_line1'];
      $address2 = $address['address_line2'];
      $address3 = '';
      $address4 = '';
      $address5 = $address['dependent_locality'];
      $city = $address['locality'];
      $state = $address['administrative_area'];
      $province = '';
      $postal_code = $address['postal_code'];
      $country = $address['country_code'];
      $customer->setBillAddress($address1, $address2, $address3, $address4, $address5, $city, $state, $province, $postal_code, $country);
      $customer->setFirstName($address['given_name']);
      $customer->setLastName($address['family_name']);
      $customer->setName("{$address['given_name']} {$address['family_name']} ($profile_id)");
      if ($company = $customer->getCompanyName()) {
        $customer->setName($company ($profile_id));
        $customer->setCompanyName($company);
      }
      $user = $this->entityTypeManager->getStorage('user')->load($this->row->getSourceProperty('uid'));
      $customer->setEmail($user->mail->value);
      return $customer->asQBXML(QUICKBOOKS_ADD_CUSTOMER);
    }

  }

  /**
   * Parse Order entities into a template-ready object.
   *
   * @return string
   *   An xml export of order data.
   */
  protected function prepareOrderExport() {
    $isInvoice = $this->config->get('exportables')['order_type'] == 'invoices';
    /** @var \QuickBooks_QBXML_Object_Invoice|\QuickBooks_QBXML_Object_SalesReceipt $invoice */
    $invoice = $isInvoice ? new \QuickBooks_QBXML_Object_Invoice() : new \QuickBooks_QBXML_Object_SalesReceipt();
    $orderId = $this->row->getSourceProperty('order_id');
    /** @var \Drupal\commerce_order\Entity\Order $order */
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($orderId);
    if ($customerListID = $this->row->getDestinationProperty('billing_profile_list_id')) {
      $invoice->setCustomerListID($customerListID);
    }
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface[] $payments */
    $payments = $this->entityTypeManager->getStorage('commerce_payment')->loadMultipleByOrder($order);
    $orderPrefix = $this->config->get('id_prefixes')['po_number_prefix'];
    $invoice->setRefNumber($orderPrefix . $orderId);
    $invoice->setTransactionDate($order->getCompletedTime());
    /** @var \Drupal\profile\Entity\Profile $billingProfile */
    $billingProfile = $order->getBillingProfile();
    if ($billingProfile) {
      /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
      $address = $billingProfile->address->get(0);
      $invoice->setCustomerFullName("{$address->getGivenName()} {$address->getFamilyName()}");
      $address1 = $address->getAddressLine1();
      $address2 = $address->getAddressLine2();
      $address3 = '';
      $address4 = '';
      $address5 = $address->getDependentLocality();
      $city = $address->getLocality();
      $state = $address->getAdministrativeArea();
      $province = '';
      $postal_code = $address->getPostalCode();
      $country = $address->getCountryCode();
      $invoice->setBillAddress($address1, $address2, $address3, $address4, $address5, $city, $state, $province, $postal_code, $country);
    }

    if ($order->hasField('shipments')) {
      /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
      foreach ($order->shipments->referencedEntities() as $shipment) {
        /** @var \Drupal\profile\Entity\Profile $shipping_profile */
        if ($shippingProfile = $shipment->getShippingProfile()) {
          break;
        }
      }
    }
    if (!empty($shippingProfile)) {
      /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
      $address = $shippingProfile->address->get(0);
      $address1 = $address->getAddressLine1();
      $address2 = $address->getAddressLine2();
      $address3 = '';
      $address4 = '';
      $address5 = $address->getDependentLocality();
      $city = $address->getLocality();
      $state = $address->getAdministrativeArea();
      $province = '';
      $postal_code = $address->getPostalCode();
      $country = $address->getCountryCode();
      $invoice->setShipAddress($address1, $address2, $address3, $address4, $address5, $city, $state, $province, $postal_code, $country);
    }
    foreach ($payments as $payment) {
      if ($gateway = $payment->getPaymentGateway()) {
        $paymentMethod = $gateway->getPlugin()->getDisplayLabel();
      }
    }
    if (!empty($paymentMethod)) {
      $invoice->setPaymentMethodName($paymentMethod);
    }
    /** @var \Drupal\commerce_order\Entity\OrderItem $item */
    foreach ($order->getItems() as $item) {
      /** @var \QuickBooks_QBXML_Object_Invoice_InvoiceLine|\QuickBooks_QBXML_Object_SalesReceipt_SalesReceiptLine $line */
      $line = $isInvoice ? new \QuickBooks_QBXML_Object_Invoice_InvoiceLine() : new \QuickBooks_QBXML_Object_SalesReceipt_SalesReceiptLine();
      /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $purchasedItem */
      if ($purchasedItem = $item->getPurchasedEntity()) {
        $line->setDescription($purchasedItem->label());
        $langcode = \Drupal::service('language_manager')->getDefaultLanguage()->getId();
        /** @var \Drupal\migrate\Plugin\Migration $variationMigration */
        $variationMigration = $this->migrationPluginManager->createInstance('qb_webconnect_product_variation');
        if ($variationMigration && ($db_row = $variationMigration->getIdMap()->getRowBySource(['variation_id' => $purchasedItem->id(), 'langcode' => $langcode]))) {
          $line->setItemListID($db_row['destid1']);
        }
      }
      else {
        $line->setDescription($item->getTitle());
      }
      $line->setQuantity($item->getQuantity());
      $line->setRate($item->getAdjustedUnitPrice()->getNumber());
      if ($isInvoice) {
        $invoice->addInvoiceLine($line);
      }
      else {
        $invoice->addSalesReceiptLine($line);
      }
    }

    /** @var \Drupal\commerce_order\Adjustment $adjustment */
    foreach ($order->getAdjustments() as $adjustment) {
      switch ($adjustment->getType()) {
        case 'tax':
          $line = $isInvoice ? new \QuickBooks_QBXML_Object_Invoice_InvoiceLine() : new \QuickBooks_QBXML_Object_SalesReceipt_SalesReceiptLine();
          $line->setItemName($adjustment->getLabel());
          $line->setQuantity(1);
          $line->setAmount($adjustment->getAmount()->getNumber());
          if ($isInvoice) {
            $invoice->addInvoiceLine($line);
          }
          else {
            $invoice->addSalesReceiptLine($line);
          }
          break;

        case 'shipping':
          $line = $isInvoice ? new \QuickBooks_QBXML_Object_Invoice_InvoiceLine() : new \QuickBooks_QBXML_Object_SalesReceipt_SalesReceiptLine();
          if (!empty($shipment)) {
            $invoice->setShipMethodName($shipment->getShippingMethod()->getPlugin()->getLabel());
            $line->setItemName($this->config->get('adjustments')['shipping_service']);
            $line->setDescription($adjustment->getLabel());
            $line->setQuantity(1);
            $line->setAmount($adjustment->getAmount()->getNumber());
            if ($isInvoice) {
              $invoice->addInvoiceLine($line);
            }
            else {
              $invoice->addSalesReceiptLine($line);
            }
          }

          break;

        case 'promotion':
          $line = $isInvoice ? new \QuickBooks_QBXML_Object_Invoice_InvoiceLine() : new \QuickBooks_QBXML_Object_SalesReceipt_SalesReceiptLine();
          $discountName = $this->config->get('adjustments')['discount_service'];
          $line->setItemName($discountName);
          // TODO: https://www.drupal.org/project/commerce_qb_webconnect/issues/2953692
          $line->setDescription($adjustment->getLabel());
          $line->setQuantity(1);
          $line->setAmount($adjustment->getAmount()->getNumber());
          if ($isInvoice) {
            $invoice->addInvoiceLine($line);
          }
          else {
            $invoice->addSalesReceiptLine($line);
          }
          break;
      }
    }

    return $isInvoice ? $invoice->asQBXML(\QUICKBOOKS_ADD_INVOICE, \QuickBooks_XML::XML_DROP, '') : $invoice->asQBXML(\QUICKBOOKS_ADD_SALESRECEIPT, \QuickBooks_XML::XML_DROP, '');
  }

  /**
   * Parse payment entities into a template-ready object.
   *
   * @return string
   *   An xml export of payment data.
   */
  protected function preparePaymentExport() {
    $receivePayment = new \QuickBooks_QBXML_Object_ReceivePayment();

    $paymentId = $this->row->getSourceProperty('payment_id');
    /** @var \Drupal\commerce_payment\Entity\Payment $payment */
    $payment = $this->entityTypeManager->getStorage('commerce_payment')->load($paymentId);
    $orderId = $payment->getOrderId();
    /** @var \Drupal\commerce_order\Entity\Order $order */
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($orderId);
    /** @var \Drupal\migrate\Plugin\Migration $customerMigration */
    $customerMigration = $this->migrationPluginManager->createInstance('qb_webconnect_customer');
    /** @var \Drupal\profile\Entity\Profile $billingProfile */
    $billingProfile = $order->getBillingProfile();
    if ($db_row = $customerMigration->getIdMap()->getRowBySource(['profile_id' => $billingProfile->id()])) {
      $receivePayment->setCustomerListID($db_row['destid1']);
    }
    $paymentPrefix = $this->config->get('id_prefixes')['payment_prefix'];
    if ($paymentId = $payment->getRemoteId()) {
      $receivePayment->setRefNumber($paymentPrefix . $paymentId);
    }
    else {
      $receivePayment->setRefNumber($paymentPrefix . $payment->id());
    }
    $receivePayment->setPaymentMethodFullName($payment->getPaymentGateway()->label());
    $receivePayment->setTransactionDate($payment->getCompletedTime());
    $transactionAdd = new \QuickBooks_QBXML_Object_ReceivePayment_AppliedToTxn();
    if ($orderListId = $this->row->getDestinationProperty('order_list_id')) {
      $transactionAdd->setTxnID($orderListId);
      $transactionAdd->setPaymentAmount($payment->getAmount()->getNumber());
      $receivePayment->addAppliedToTxn($transactionAdd);
    }
    else {
      $receivePayment->setIsAutoApply(TRUE);
    }

    return $receivePayment->asQBXML(\QUICKBOOKS_ADD_RECEIVEPAYMENT, \QuickBooks_XML::XML_DROP, '');
  }

  /**
   * Parse product variation entities into a template-ready object.
   *
   * @return string
   *   An xml export of product variation data.
   */
  protected function prepareProductVariationExport() {
    $inventoryItem = new \QuickBooks_QBXML_Object_InventoryItem();
    $variationId = $this->row->getSourceProperty('variation_id');
    /** @var \Drupal\commerce_product\Entity\ProductVariation $variation */
    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($variationId);
    $inventoryItem->setName($variation->getSku());
    $inventoryItem->setSalesPrice($variation->getPrice()->getNumber());
    $inventoryItem->setIncomeAccountFullName($this->config->get('accounts')['main_income_account']);
    $inventoryItem->setCOGSAccountFullName($this->config->get('accounts')['cogs_account']);
    $inventoryItem->setAssetAccountName($this->config->get('accounts')['assets_account']);
    if ($productListId = $this->row->getDestinationProperty('product_list_id')) {
      $inventoryItem->set('ParentRef ListID', $productListId);
    }
    return $inventoryItem->asQBXML(\QUICKBOOKS_ADD_INVENTORYITEM, \QuickBooks_XML::XML_DROP, '');
  }

  /**
   * Parse product entities into a template-ready object.
   *
   * @return string
   *   An xml export of product data.
   */
  protected function prepareProductExport() {
    $inventoryItem = new \QuickBooks_QBXML_Object_InventoryItem();
    $productId = $this->row->getSourceProperty('product_id');
    /** @var \Drupal\commerce_product\Entity\Product $product */
    $product = $this->entityTypeManager->getStorage('commerce_product')->load($productId);
    $inventoryItem->setName($product->label());
    $inventoryItem->setIncomeAccountFullName($this->config->get('accounts')['main_income_account']);
    $inventoryItem->setCOGSAccountFullName($this->config->get('accounts')['cogs_account']);
    $inventoryItem->setAssetAccountName($this->config->get('accounts')['assets_account']);
    return $inventoryItem->asQBXML(\QUICKBOOKS_ADD_INVENTORYITEM, \QuickBooks_XML::XML_DROP, '');
  }

}
