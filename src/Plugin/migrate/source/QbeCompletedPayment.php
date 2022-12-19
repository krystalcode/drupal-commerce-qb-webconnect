<?php

namespace Drupal\commerce_qb_webconnect\Plugin\migrate\source;

use Drupal\migrate_drupal_d8\Plugin\migrate\source\d8\ContentEntity;

/**
 * Completed payment extract.
 *
 * @MigrateSource(
 *   id = "qb_webconnect_completed_payment",
 * )
 */
class QbeCompletedPayment extends ContentEntity {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = parent::query();
    $query->condition('state', 'completed');

    return $query;
  }

}
