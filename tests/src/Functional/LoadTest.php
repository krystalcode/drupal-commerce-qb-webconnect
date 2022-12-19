<?php

namespace Drupal\Tests\commerce_qb_webconnect\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;

/**
 * Simple test to ensure that main page loads with module enabled.
 *
 * @group commerce_installments
 */
class LoadTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'address',
    'profile',
    'entity',
    'entity_reference_revisions',
    'inline_entity_form',
    'state_machine',
    'text',
    'views',
    'commerce',
    'commerce_checkout',
    'commerce_payment',
    'commerce_price',
    'commerce_store',
    'commerce_order',
    'commerce_product',
    'commerce_qb_webconnect',
  ];

  /**
   * A user with permission to administer site configuration.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->user = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($this->user);
  }

  /**
   * Tests that the home page loads with a 200 response.
   */
  public function testLoad() {
    $this->drupalGet(Url::fromRoute('<front>'));
    $this->assertSession()->statusCodeEquals(200);
  }

}
