<?php

namespace Drupal\commerce_qb_webconnect\SoapBundle\Services;

/**
 * A Quickbooks SOAP Service must implement these functions.
 *
 * Interface SoapServiceInterface.
 *
 * @package Drupal\commerce_qb_webconnect\SoapBundle\Services
 */
interface SoapServiceInterface {

  /**
   * Process the incoming request and call the appropriate service method.
   *
   * This magic function is responsible for processing the incoming SOAP request
   * and doing user session validation if required forthe incoming service call.
   *
   * A response to Quickbooks is expected to be formatted as a stdClass object
   * with a property named [methodName]Result that contains an array/string
   * formatted to the QBWC specs.
   *
   * @param string $method
   *   The wsdl call being invoked.
   * @param array $data
   *   The SOAP request object.
   *
   * @return \stdClass
   *   The response object expected by Quickbooks.
   */
  public function __call($method, array $data);

  /**
   * Send the server version to the client.
   *
   * @param \stdClass $request
   *   The http request.
   *
   * @return \stdClass
   *   The response object expected by Quickbooks.
   */
  public function callServerVersion(\stdClass $request);

  /**
   * Check client version.
   *
   * @param \stdClass $request
   *   The http request.
   *
   * @return \stdClass
   *   The response object expected by Quickbooks.
   */
  public function callClientVersion(\stdClass $request);

  /**
   * Authenticate and initiate session with client.
   *
   * @param \stdClass $request
   *   The http request.
   *
   * @return \stdClass
   *   The response object expected by Quickbooks.
   */
  public function callAuthenticate(\stdClass $request);

  /**
   * Send data back to client.
   *
   * Requires session validation.
   *
   * @param \stdClass $request
   *   The http request.
   *
   * @return \stdClass
   *   The response object expected by Quickbooks.
   */
  public function callSendRequestXML(\stdClass $request);

  /**
   * Get response from last quickbooks operation.
   *
   * Requires session validation.
   *
   * @param \stdClass $request
   *   The http request.
   *
   * @return \stdClass
   *   The response object expected by Quickbooks.
   */
  public function callReceiveResponseXML(\stdClass $request);

  /**
   * Quickbooks error handler.
   *
   * Requires session validation.
   *
   * @param \stdClass $request
   *   The http request.
   *
   * @return \stdClass
   *   The response object expected by Quickbooks.
   */
  public function callGetLastError(\stdClass $request);

  /**
   * Close the connection.
   *
   * Requires session validation.
   *
   * @param \stdClass $request
   *   The http request.
   *
   * @return \stdClass
   *   The response object expected by Quickbooks.
   */
  public function callCloseConnection(\stdClass $request);

}
