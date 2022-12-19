<?php

namespace Drupal\commerce_qb_webconnect\Plugin\migrate\destination;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides null destination plugin.
 *
 * @MigrateDestination(
 *   id = "commerce_qb_webconnect",
 *   requirements_met = true
 * )
 */
class QbWebConnectDestination extends DestinationBase implements ContainerFactoryPluginInterface {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The uuid service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

  /**
   * Construct a new entity.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration.
   * @param \Drupal\Core\State\StateInterface $state
   *   The SOAP session manager service.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, StateInterface $state, UuidInterface $uuid) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
    $this->state = $state;
    $this->uuid = $uuid;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('state'),
      $container->get('uuid')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $row->setDestinationProperty('uuid', $this->uuid->generate());
    $this->state->set('qb_webconnect.current_row', $row);
    $this->state->set('qb_webconnect.current_migration', $this->migration->getPluginId());
    return [$row->getDestinationProperty('uuid')];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'uuid' => ['type' => 'string'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function fields(MigrationInterface $migration = NULL) {
    return [];
  }

}
