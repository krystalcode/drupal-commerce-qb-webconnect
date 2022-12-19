<?php

namespace Drupal\commerce_qb_webconnect\EventSubscriber;

use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Plugin\MigrationInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class MigrateSubscriber.
 *
 * @package Drupal\commerce_qb_webconnect\EventSubscriber
 */
class MigrateSubscriber implements EventSubscriberInterface {

  /**
   * Stop the migration early so we don't actually import the data here.
   *
   * Rather import the data inside the SOAP service.
   *
   * @param \Drupal\migrate\Event\MigratePostRowSaveEvent $event
   *   The post row save event.
   */
  public function onPostRowSave(MigratePostRowSaveEvent $event) {
    $migration = $event->getMigration();
    $tags = $migration->getMigrationTags();
    if (in_array('QB Webconnect', $tags)) {
      $migration->interruptMigration(MigrationInterface::RESULT_STOPPED);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [MigrateEvents::POST_ROW_SAVE => 'onPostRowSave'];
  }

}
