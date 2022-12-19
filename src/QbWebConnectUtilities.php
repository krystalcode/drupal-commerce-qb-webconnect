<?php

namespace Drupal\commerce_qb_webconnect;

/**
 * Class QbWebConnectUtilities.
 *
 * @package Drupal\commerce_qb_webconnect
 */
final class QbWebConnectUtilities {

  /**
   * Extract a unique record identifier from an XML response.
   *
   * Some (most?) records within QuickBooks have unique identifiers which are
   * returned with the qbXML responses. This method will try to extract all
   * identifiers it can find from a qbXML response and return them in an
   * associative array.
   *
   * For example, Customers have unique ListIDs, Invoices have unique TxnIDs,
   * etc. For an AddCustomer request, you'll get an array that looks like
   * this:
   * <code>
   * array(
   *  'ListID' => '2C0000-1039887390'
   * )
   * </code>
   *
   * Other transactions might have more than one identifier. For instance, a
   * call to AddInvoice returns both a ListID and a TxnID:
   * <code>
   * array(
   *  'ListID' => '200000-1036881887', // This is actually part of the
   * 'CustomerRef' entity in the Invoice XML response
   *  'TxnID' => '11C26-1196256987', // This is the actual transaction ID for
   * the Invoice XML response
   * )
   * </code>
   *
   * *** IMPORTANT *** If there are duplicate fields (i.e.: 3 different
   * ListIDs returned) then only the first value encountered will appear in
   * the associative array.
   *
   * The following elements/attributes are supported:
   *  - ListID
   *  - TxnID
   *  - iteratorID
   *  - OwnerID
   *  - TxnLineID
   *
   * @param string $xml
   *   The XML stream to look for an identifier in.
   * @param string $entity_type
   *   The entity type.
   *
   * @return string
   *   The identifier for the content type.
   *
   * @see \QuickBooks_WebConnector_Handlers::_extractIdentifiers()
   */
  public static function extractIdentifiers($xml, $entity_type) {
    $fetch_tagdata = [
      'ListID',
      'TxnID',
      'OwnerID',
      'TxnLineID',
      'EditSequence',
      'FullName',
      'Name',
      'RefNumber',
    ];

    $fetch_attributes = [
      'requestID',
      'iteratorID',
      'iteratorRemainingCount',
      'metaData',
      'retCount',
      'statusCode',
      'statusSeverity',
      'statusMessage',
      'newMessageSetID',
      'messageSetStatusCode',
    ];
    $list = [];
    foreach ($fetch_tagdata as $tag) {
      if (FALSE !== ($start = strpos($xml, '<' . $tag . '>')) &&
        FALSE !== ($end = strpos($xml, '</' . $tag . '>'))) {
        $list[$tag] = substr($xml, $start + 2 + strlen($tag), $end - $start - 2 - strlen($tag));
      }
    }
    foreach ($fetch_attributes as $attribute) {
      if (FALSE !== ($start = strpos($xml, ' ' . $attribute . '="')) &&
        FALSE !== ($end = strpos($xml, '"', $start + strlen($attribute) + 3))) {
        $list[$attribute] = substr($xml, $start + strlen($attribute) + 3, $end - $start - strlen($attribute) - 3);
      }
    }
    switch ($entity_type) {
      case 'profile':
      case 'commerce_product':
      case 'commerce_product_variation':
      case 'commerce_payment':
        $attribute = 'ListID';
        break;

      default:
        $attribute = 'TxnID';
    }

    return $list[$attribute] ?? NULL;
  }

  /**
   * Extract the status code from an XML response.
   *
   * Each qbXML response should return a status code and a status message
   * indicating whether or not an error occurred.
   *
   * @param string $xml
   *   The XML stream to look for a response status code in.
   *
   * @return int|bool
   *   The response status code (FALSE if OK, another positive integer if an
   *   error occured).
   */
  public static function extractStatusCode($xml) {
    $code = FALSE;
    if (FALSE !== ($start = strpos($xml, ' statusCode="')) &&
      FALSE !== ($end = strpos($xml, '"', $start + 13))) {
      $code = substr($xml, $start + 13, $end - $start - 13);
    }
    return $code;
  }

  /**
   * Extract the status message from an XML response.
   *
   * Each qbXML response should return a status code and a status message
   * indicating whether or not an error occured.
   *
   * @param string $xml
   *   The XML stream to look for a response status message in.
   *
   * @return string
   *   The response status message.
   */
  public static function extractStatusMessage($xml) {
    $message = '';
    if (FALSE !== ($start = strpos($xml, ' statusMessage="')) &&
      FALSE !== ($end = strpos($xml, '"', $start + 16))) {
      $message = substr($xml, $start + 16, $end - $start - 16);
    }
    return $message;
  }

  /**
   * Is this a Quickbooks identifier?
   *
   * @param string $identifier
   *   The identifier.
   *
   * @return bool
   *   Returns TRUE if is an identifier from Quickbooks. Otherwise, FALSE.
   */
  public static function isQuickbooksIdentifier($identifier) {
    // UUIDs from drupal look like: e37a610c-30b9-49f0-b701-d7cf4602c130.
    // Identifiers from Quickbooks look like: 110000-1232697602.
    str_replace('-', '', $identifier, $count);
    return $count < 2;
  }

}
