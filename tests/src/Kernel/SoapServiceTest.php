<?php

namespace Drupal\Tests\commerce_qb_webconnect\Kernel;

use Drupal\commerce\Context;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderType;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentMethod;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\commerce_shipping\Entity\Shipment;
use Drupal\commerce_shipping\Entity\ShippingMethod;
use Drupal\commerce_shipping\ShipmentItem;
use Drupal\Core\Database\Database;
use Drupal\physical\Weight;
use Drupal\profile\Entity\Profile;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;

/**
 * Tests exporting data to quickbooks.
 *
 * @requires module commerce_shipping
 * @requires module physical
 * @requires module commerce
 * @requires module commerce_product
 * @requires module commerce_order
 * @requires module commerce_tax
 * @requires module profile
 * @requires module state_machine
 * @requires module entity_reference_revisions
 * @requires module migrate_drupal_d8
 *
 * @group commerce_qb_webconnect
 */
class SoapServiceTest extends CommerceKernelTestBase {

  /**
   * The customer list id.
   *
   * @var string
   */
  protected $customerListId = '80000001-1431947192';

  /**
   * The order list id.
   *
   * @var string
   */
  protected $orderListId = '80000001-1431947193';

  /**
   * The payment list id.
   *
   * @var string
   */
  protected $paymentListId = '80000001-1431947194';

  /**
   * The product list id.
   *
   * @var string
   */
  protected $productListId = '80000001-1431947195';

  /**
   * The variation list id.
   *
   * @var string
   */
  protected $productVariationListId = '80000001-1431947196';


  /**
   * The second variation list id.
   *
   * @var string
   */
  protected $productVariationListId2 = '80000001-1431947197';

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'migrate_drupal_d8',
    'migrate',
    'commerce_qb_webconnect',
    'entity_reference_revisions',
    'profile',
    'state_machine',
    'commerce_product',
    'commerce_order',
    'commerce_payment',
    'commerce_payment_example',
    'commerce_tax',
    'commerce_shipping',
    'commerce_promotion',
    'physical',
    'path',
  ];


  /**
   * Testing demo user.
   *
   * @var \Drupal\user\UserInterface
   */
  public $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_payment_method');
    $this->installEntitySchema('commerce_payment_gateway');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_shipment');
    $this->installEntitySchema('commerce_shipping_method');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(self::$modules);

    // Install a "donation" order item type.
    $order_item_type_storage = $this->container->get('entity_type.manager')->getStorage('commerce_order_item_type');
    $order_item_type_storage->create([
      'label' => 'Donation',
      'id' => 'donation',
      'purchasableEntityType' => '',
      'orderType' => 'default',
    ])->save();

    // Install the variation trait.
    $trait_manager = \Drupal::service('plugin.manager.commerce_entity_trait');
    $trait = $trait_manager->createInstance('purchasable_entity_shippable');
    $trait_manager->installTrait($trait, 'commerce_product_variation', 'default');

    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
    $order_type = OrderType::load('default');
    $order_type->setThirdPartySetting('commerce_shipping', 'shipment_type', 'default');
    $order_type->save();
    // Create the order field.
    $field_definition = commerce_shipping_build_shipment_field_definition($order_type->id());
    \Drupal::service('commerce.configurable_field_manager')->createField($field_definition);

    $user = $this->createUser(['mail' => 'example@example.com', 'pass' => 'abc123'], ['access quickbooks soap service']);
    $this->user = $this->reloadEntity($user);

    // Needed to provide a db connection so we don't fallback to 'migrate'.
    // Otherwise it uses a non-existent connection named 'migrate'.
    $database = Database::getConnectionInfo('default')['default'];
    $config = [
      'target' => 'default',
      'key' => 'default',
      'database' => $database,
    ];
    \Drupal::state()->set('qbe_test_db', $config);
    \Drupal::state()->set('migrate.fallback_state_key', 'qbe_test_db');

    // Create profile data.
    $profile = Profile::create(['uid' => $this->user->id(), 'type' => 'customer']);
    $profile->address = [
      'country_code' => 'AD',
      'locality' => 'Canillo',
      'postal_code' => 'AD500',
      'address_line1' => 'C. Prat de la Creu, 62-64',
      'given_name' => 'John',
      'family_name' => 'Smith',
    ];
    $profile->save();
    $profile = $this->reloadEntity($profile);

    // Create a second customer profile.
    $profile2 = Profile::create(['uid' => $this->user->id(), 'type' => 'customer']);
    $profile2->address = [
      'country_code' => 'NI',
      'locality' => 'Leon',
      'address_line1' => 'En frente de fue Bar Corinto',
      'given_name' => 'Jane',
      'family_name' => 'Pierce',
    ];
    $profile2->save();

    // Turn off title generation to allow explicit values to be used.
    $variation_type = ProductVariationType::load('default');
    $variation_type->setGenerateTitle(FALSE);
    $variation_type->save();

    // Add some products.
    $product = Product::create([
      'type' => 'default',
      'title' => 'Default testing product',
    ]);
    $product->save();
    $variation1 = ProductVariation::create([
      'type' => 'default',
      'sku' => 'SKU1',
      'title' => 'Variation1',
      'status' => 1,
      'price' => new Price('12.00', 'USD'),
    ]);
    $variation1->save();
    $product->addVariation($variation1);
    $variation2 = ProductVariation::create([
      'type' => 'default',
      'sku' => 'SKU2',
      'title' => 'Variation2',
      'status' => 1,
      'price' => new Price('14.00', 'USD'),
    ]);
    $variation2->save();
    $product->addVariation($variation2)->save();

    // Add an order.
    /** @var \Drupal\commerce_order\OrderItemStorageInterface $order_item_storage */
    $order_item_storage = $this->container->get('entity_type.manager')->getStorage('commerce_order_item');
    $order_item1 = $order_item_storage->createFromPurchasableEntity($variation1);
    $order_item1->setQuantity(3);
    $order_item1->save();
    $order_item2 = $order_item_storage->createFromPurchasableEntity($variation2);
    $order_item2->addAdjustment(new Adjustment([
      'type' => 'promotion',
      'label' => 'Order Item Discount',
      'amount' => new Price('-1.00', 'USD'),
    ]));
    $order_item2->save();
    $order_item3 = $order_item_storage->create([
      'type' => 'donation',
      'title' => 'Donation',
      'unit_price' => new Price('10.00', 'USD'),
    ]);
    $order_item3->save();
    $order = Order::create([
      'type' => 'default',
      'state' => 'completed',
      'billing_profile' => $profile,
      'mail' => $this->user->getEmail(),
      'uid' => $this->user->id(),
      'ip_address' => '127.0.0.1',
      'order_items' => [
        $order_item1,
        $order_item2,
        $order_item3
      ],
    ]);
    $order->addAdjustment(new Adjustment([
      'type' => 'tax',
      'label' => 'State Tax %5',
      'amount' => new Price('0.50', 'USD'),
    ]));
    $order->addAdjustment(new Adjustment([
      'type' => 'shipping',
      'label' => 'Shipment #1',
      'amount' => new Price('5.00', 'USD'),
    ]));
    $order->addAdjustment(new Adjustment([
      'type' => 'promotion',
      'label' => 'Order Discount',
      'amount' => new Price('-2.00', 'USD'),
    ]));
    $order->save();

    $shipmentMethod = ShippingMethod::create([
      'stores' => $this->store->id(),
      'plugin' => [
        'target_plugin_id' => 'flat_rate',
        'target_plugin_configuration' => [],
      ],
    ]);
    $shipment = Shipment::create([
      'type' => 'default',
      'order_id' => $order->id(),
      'items' => [
        new ShipmentItem([
          'order_item_id' => $order_item1->id(),
          'title' => $order_item1->label(),
          'quantity' => $order_item1->getQuantity(),
          'weight' => new Weight('1', 'kg'),
          'declared_value' => $this->container->get('commerce_order.price_calculator')->calculate($order_item1->getPurchasedEntity(), $order_item1->getQuantity(), new Context($this->user, $this->store)),
        ]),
      ],
      'amount' => new Price('5', 'USD'),
      'state' => 'draft',
      'shipping_service' => 'Acme Corporation',
      'shipping_profile' => $profile,
      'title' => 'Shipment #1',
      'shipping_method' => $shipmentMethod
    ]);
    $shipment->save();
    $this->reloadEntity($shipment);
    $order->set('shipments', [$shipment]);
    $order->save();

    // Add a payment.
    $payment_gateway = PaymentGateway::create([
      'id' => 'example',
      'label' => 'Payment Gateway Example',
      'plugin' => 'example_onsite',
    ]);
    $payment_gateway->save();
    $payment_gateway = $this->reloadEntity($payment_gateway);
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method_active = PaymentMethod::create([
      'type' => 'credit_card',
      'card_type' => 'visa',
      'payment_gateway' => 'example',
      'method_id' => 'test_payment',
      // Thu, 16 Jan 2020.
      'expires' => '1579132800',
      'uid' => $user->id(),
    ]);
    $payment_method_active->save();
    $payment_method_active = $this->reloadEntity($payment_method_active);
    // Create an actual payment.
    $payment = Payment::create([
      'payment_gateway' => 'example',
      'payment_method' => $payment_method_active->id(),
      'type' => 'payment_default',
      'remote_id' => '123456',
      'order_id' => $order->id(),
      'amount' => [
        'number' => '28.50',
        'currency_code' => 'USD',
      ],
      'state' => 'completed',
      'test' => TRUE,
    ]);
    $payment->save();
  }

  /**
   * Tests sales receipt data extraction.
   */
  public function testSalesReceiptExport() {
    /** @var \Drupal\commerce_qb_webconnect\SoapBundle\Services\SoapService $soapService */
    $soapService = \Drupal::service('commerce_qb_webconnect.soap_service');
    $response = $this->assertProductExport();

    // Test receipt migration.
    $response = $soapService->sendRequestXML($response);
    $result = str_replace(["\n", "\t"], '', $response->sendRequestXMLResult);
    $date = \Drupal::service('date.formatter')->format(\Drupal::time()->getRequestTime(), 'custom', 'Y-m-d');
    $this->assertEquals("<?xml version=\"1.0\" encoding=\"utf-8\"?><?qbxml version=\"13.0\"?><QBXML><QBXMLMsgsRq onError=\"stopOnError\"><SalesReceiptAddRq><SalesReceiptAdd><CustomerRef><ListID>80000001-1431947192</ListID><FullName>John Smith</FullName></CustomerRef><TxnDate>$date</TxnDate><RefNumber>1</RefNumber><BillAddress><Addr1>C. Prat de la Creu, 62-64</Addr1><City>Canillo</City><PostalCode>AD500</PostalCode><Country>AD</Country></BillAddress><ShipAddress><Addr1>C. Prat de la Creu, 62-64</Addr1><City>Canillo</City><PostalCode>AD500</PostalCode><Country>AD</Country></ShipAddress><PaymentMethodRef><FullName>Example</FullName></PaymentMethodRef><ShipMethodRef><FullName>Flat rate</FullName></ShipMethodRef><SalesReceiptLineAdd><ItemRef><ListID>{$this->productVariationListId}</ListID></ItemRef><Desc>Variation1</Desc><Quantity>3</Quantity><Rate>12.00</Rate></SalesReceiptLineAdd><SalesReceiptLineAdd><ItemRef><ListID>{$this->productVariationListId2}</ListID></ItemRef><Desc>Variation2</Desc><Quantity>1</Quantity><Rate>13.00</Rate></SalesReceiptLineAdd><SalesReceiptLineAdd><Desc>Donation</Desc><Quantity>1</Quantity><Rate>10.00</Rate></SalesReceiptLineAdd><SalesReceiptLineAdd><ItemRef><FullName>State Tax %5</FullName></ItemRef><Quantity>1</Quantity><Amount>0.50</Amount></SalesReceiptLineAdd><SalesReceiptLineAdd><ItemRef><FullName>Freight</FullName></ItemRef><Desc>Shipment #1</Desc><Quantity>1</Quantity><Amount>5.00</Amount></SalesReceiptLineAdd><SalesReceiptLineAdd><ItemRef><FullName>Discount</FullName></ItemRef><Desc>Order Discount</Desc><Quantity>1</Quantity><Amount>-2.00</Amount></SalesReceiptLineAdd></SalesReceiptAdd></SalesReceiptAddRq></QBXMLMsgsRq></QBXML>", $result);
    $response->response = "<TxnID>{$this->orderListId}</TxnID>";
    $response = $soapService->receiveResponseXML($response);
    // We should be 100% done.
    $this->assertSame($response->receiveResponseXMLResult, 100);
    /** @var \Drupal\migrate\Plugin\Migration $migration */
    $migration = \Drupal::service('plugin.manager.migration')->createInstance('qb_webconnect_order');
    $db_row = $migration->getIdMap()->getRowBySource(['order_id' => 1]);
    $this->assertEquals($this->orderListId, $db_row['destid1']);
  }

  /**
   * Tests invoice data extraction.
   */
  public function testInvoiceExport() {
    /** @var \Drupal\Core\Config\Config $config */
    $config = \Drupal::service('config.factory')->getEditable('commerce_qb_webconnect.quickbooks_admin');
    $exportables = $config->get('exportables');
    $exportables['order_type'] = 'invoices';
    $config->set('exportables', $exportables);
    $config->save();

    /** @var \Drupal\commerce_qb_webconnect\SoapBundle\Services\SoapService $soapService */
    $soapService = \Drupal::service('commerce_qb_webconnect.soap_service');
    $response = $this->assertProductExport();

    // Test invoice migration.
    $response = $soapService->sendRequestXML($response);
    $result = str_replace(["\n", "\t"], '', $response->sendRequestXMLResult);
    $date = \Drupal::service('date.formatter')->format(\Drupal::time()->getRequestTime(), 'custom', 'Y-m-d');
    $this->assertEquals("<?xml version=\"1.0\" encoding=\"utf-8\"?><?qbxml version=\"13.0\"?><QBXML><QBXMLMsgsRq onError=\"stopOnError\"><InvoiceAddRq><InvoiceAdd><CustomerRef><ListID>80000001-1431947192</ListID><FullName>John Smith</FullName></CustomerRef><TxnDate>$date</TxnDate><RefNumber>1</RefNumber><BillAddress><Addr1>C. Prat de la Creu, 62-64</Addr1><City>Canillo</City><PostalCode>AD500</PostalCode><Country>AD</Country></BillAddress><ShipAddress><Addr1>C. Prat de la Creu, 62-64</Addr1><City>Canillo</City><PostalCode>AD500</PostalCode><Country>AD</Country></ShipAddress><ShipMethodRef><FullName>Flat rate</FullName></ShipMethodRef><InvoiceLineAdd><ItemRef><ListID>{$this->productVariationListId}</ListID></ItemRef><Desc>Variation1</Desc><Quantity>3</Quantity><Rate>12</Rate></InvoiceLineAdd><InvoiceLineAdd><ItemRef><ListID>{$this->productVariationListId2}</ListID></ItemRef><Desc>Variation2</Desc><Quantity>1</Quantity><Rate>13</Rate></InvoiceLineAdd><InvoiceLineAdd><Desc>Donation</Desc><Quantity>1</Quantity><Rate>10</Rate></InvoiceLineAdd><InvoiceLineAdd><ItemRef><FullName>State Tax %5</FullName></ItemRef><Quantity>1</Quantity><Amount>0.50</Amount></InvoiceLineAdd><InvoiceLineAdd><ItemRef><FullName>Freight</FullName></ItemRef><Desc>Shipment #1</Desc><Quantity>1</Quantity><Amount>5.00</Amount></InvoiceLineAdd><InvoiceLineAdd><ItemRef><FullName>Discount</FullName></ItemRef><Desc>Order Discount</Desc><Quantity>1</Quantity><Amount>-2.00</Amount></InvoiceLineAdd></InvoiceAdd></InvoiceAddRq></QBXMLMsgsRq></QBXML>", $result);
    $response->response = "<TxnID>{$this->orderListId}</TxnID>";
    $response = $soapService->receiveResponseXML($response);
    // We should be 85% done.
    $this->assertSame($response->receiveResponseXMLResult, 85);
    /** @var \Drupal\migrate\Plugin\Migration $migration */
    $migration = \Drupal::service('plugin.manager.migration')->createInstance('qb_webconnect_order');
    $db_row = $migration->getIdMap()->getRowBySource(['order_id' => 1]);
    $this->assertEquals($this->orderListId, $db_row['destid1']);

    // Test payment migration.
    $response = $soapService->sendRequestXML($response);
    $result = str_replace(["\n", "\t"], '', $response->sendRequestXMLResult);
    $date = \Drupal::service('date.formatter')->format(\Drupal::time()->getRequestTime(), 'custom', 'Y-m-d');
    $this->assertEquals("<?xml version=\"1.0\" encoding=\"utf-8\"?><?qbxml version=\"13.0\"?><QBXML><QBXMLMsgsRq onError=\"stopOnError\"><ReceivePaymentAddRq><ReceivePaymentAdd><CustomerRef><ListID>80000001-1431947192</ListID></CustomerRef><TxnDate>$date</TxnDate><RefNumber>123456</RefNumber><PaymentMethodRef><FullName>Payment Gateway Example</FullName></PaymentMethodRef><AppliedToTxnAdd><TxnID>80000001-1431947193</TxnID><PaymentAmount>28.50</PaymentAmount></AppliedToTxnAdd></ReceivePaymentAdd></ReceivePaymentAddRq></QBXMLMsgsRq></QBXML>", $result);
    $response->response = "<ListID>{$this->paymentListId}</ListID>";
    $response = $soapService->receiveResponseXML($response);
    // We should be 100% done.
    $this->assertSame($response->receiveResponseXMLResult, 100);
    /** @var \Drupal\migrate\Plugin\Migration $migration */
    $migration = \Drupal::service('plugin.manager.migration')->createInstance('qb_webconnect_payment');
    $db_row = $migration->getIdMap()->getRowBySource(['payment_id' => 1]);
    $this->assertEquals($this->paymentListId, $db_row['destid1']);
  }

  /**
   * Asserts product export data.
   *
   * @return \stdClass
   *   The response object.
   */
  public function assertProductExport() {
    /** @var \Drupal\commerce_qb_webconnect\SoapBundle\Services\SoapService $soapService */
    $soapService = \Drupal::service('commerce_qb_webconnect.soap_service');
    $request = new \stdClass();
    $request->ticket = 'UUID';
    $request->strUserName = $this->user->getAccountName();
    $request->strPassword = 'abc123';
    $response = $soapService->authenticate($request);
    $this->assertNotEmpty($response->authenticateResult[0]);
    $this->assertEmpty($response->authenticateResult[1]);

    // Test profile migration.
    $response = $soapService->sendRequestXML($response);
    $response->response = "<ListID>$this->customerListId</ListID>";
    $response = $soapService->receiveResponseXML($response);

    // Test second profile migration.
    $response = $soapService->sendRequestXML($response);
    $response->response = "<ListID>{$this->customerListId}</ListID>";
    $response = $soapService->receiveResponseXML($response);

    // Test product migration.
    $response = $soapService->sendRequestXML($response);
    $result = str_replace(["\n", "\t"], '', $response->sendRequestXMLResult);
    $this->assertEquals('<?xml version="1.0" encoding="utf-8"?><?qbxml version="13.0"?><QBXML><QBXMLMsgsRq onError="stopOnError"><ItemInventoryAddRq><ItemInventoryAdd><Name>Default testing product</Name><IncomeAccountRef><FullName>Sales</FullName></IncomeAccountRef><COGSAccountRef><FullName>Cost of Goods Sold</FullName></COGSAccountRef><AssetAccountRef><FullName>Inventory Asset</FullName></AssetAccountRef></ItemInventoryAdd></ItemInventoryAddRq></QBXMLMsgsRq></QBXML>', $result);
    $response->response = "<ListID>{$this->productListId}</ListID>";
    $response = $soapService->receiveResponseXML($response);
    /** @var \Drupal\migrate\Plugin\Migration $migration */
    $migration = \Drupal::service('plugin.manager.migration')->createInstance('qb_webconnect_product');
    $db_row = $migration->getIdMap()->getRowBySource(['product_id' => 1, 'langcode' => 'en']);
    $this->assertEquals($this->productListId, $db_row['destid1']);

    // Test product variation migration.
    $response = $soapService->sendRequestXML($response);
    $result = str_replace(["\n", "\t"], '', $response->sendRequestXMLResult);
    $this->assertEquals("<?xml version=\"1.0\" encoding=\"utf-8\"?><?qbxml version=\"13.0\"?><QBXML><QBXMLMsgsRq onError=\"stopOnError\"><ItemInventoryAddRq><ItemInventoryAdd><Name>SKU1</Name><ParentRef><ListID>{$this->productListId}</ListID></ParentRef><SalesPrice>12.00</SalesPrice><IncomeAccountRef><FullName>Sales</FullName></IncomeAccountRef><COGSAccountRef><FullName>Cost of Goods Sold</FullName></COGSAccountRef><AssetAccountRef><FullName>Inventory Asset</FullName></AssetAccountRef></ItemInventoryAdd></ItemInventoryAddRq></QBXMLMsgsRq></QBXML>", $result);
    $response->response = "<ListID>{$this->productVariationListId}</ListID>";
    $response = $soapService->receiveResponseXML($response);
    /** @var \Drupal\migrate\Plugin\Migration $migration */
    $migration = \Drupal::service('plugin.manager.migration')->createInstance('qb_webconnect_product_variation');
    $db_row = $migration->getIdMap()->getRowBySource(['variation_id' => 1, 'langcode' => 'en']);
    $this->assertEquals($this->productVariationListId, $db_row['destid1']);
    // 2nd product variation.
    $response = $soapService->sendRequestXML($response);
    $result = str_replace(["\n", "\t"], '', $response->sendRequestXMLResult);
    $this->assertEquals("<?xml version=\"1.0\" encoding=\"utf-8\"?><?qbxml version=\"13.0\"?><QBXML><QBXMLMsgsRq onError=\"stopOnError\"><ItemInventoryAddRq><ItemInventoryAdd><Name>SKU2</Name><ParentRef><ListID>{$this->productListId}</ListID></ParentRef><SalesPrice>14.00</SalesPrice><IncomeAccountRef><FullName>Sales</FullName></IncomeAccountRef><COGSAccountRef><FullName>Cost of Goods Sold</FullName></COGSAccountRef><AssetAccountRef><FullName>Inventory Asset</FullName></AssetAccountRef></ItemInventoryAdd></ItemInventoryAddRq></QBXMLMsgsRq></QBXML>", $result);
    $response->response = "<ListID>{$this->productVariationListId2}</ListID>";
    $response = $soapService->receiveResponseXML($response);
    /** @var \Drupal\migrate\Plugin\Migration $migration */
    $migration = \Drupal::service('plugin.manager.migration')->createInstance('qb_webconnect_product_variation');
    $db_row = $migration->getIdMap()->getRowBySource(['variation_id' => 2, 'langcode' => 'en']);
    $this->assertEquals($this->productVariationListId2, $db_row['destid1']);

    return $response;
  }

}
