<?php

namespace Drupal\commerce_qb_webconnect\SoapBundle\Services;

use Drupal\Core\State\StateInterface;

/**
 * Class SoapSessionManager.
 *
 * @package Drupal\commerce_qb_webconnect\SoapBundle\Services
 */
class SoapSessionManager {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The UUID given by a SOAP client.
   *
   * @var string
   */
  protected $uuid;

  /**
   * The UID of the connected User.
   *
   * @var int
   */
  protected $uid;

  /**
   * The state UID key.
   */
  const SESSION_USER_KEY = 'qbe.session_uid';

  /**
   * The state UUID key.
   */
  const SESSION_UUID_KEY = 'qbe.session_uuid';

  /**
   * The state stage key.
   */
  const SESSION_STAGE_KEY = 'qbe.session_stage';

  /**
   * Whether or not the current session is valid.
   *
   * @var bool
   */
  protected $isValid = FALSE;

  /**
   * A list of allowed service calls after the previous call.
   *
   * The key is the previous call made to the server, with the value being an
   * array of calls the client is being expected from the client.  If we receive
   * an unexpected call it's probably because the connection went down in the
   * middle.  In this case, we either return empty responses, or a negative
   * number during 'receiveResponseXML'.
   *
   * @var array
   */
  protected $nextStep = [
    'serverVersion' => ['clientVersion'],
    'clientVersion' => ['authenticate'],
    'authenticate' => ['sendRequestXML', 'closeConnection'],
    'sendRequestXML' => ['getLastError', 'receiveResponseXML', 'sendRequestXML'],
    'receiveResponseXML' => [
      'getLastError',
      'sendRequestXML',
      'closeConnection',
    ],
    'getLastError' => ['closeConnection', 'sendRequestXML'],
    'closeConnection' => [],
  ];

  /**
   * SoapSessionManager constructor.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(StateInterface $state) {
    $this->state = $state;
  }

  /**
   * Stores a new session into the database.
   *
   * @param string $uuid
   *   The GUID given to a client that will act as a validation token.
   * @param string $uid
   *   The User ID that corresponds to the User the client logs in with.
   */
  public function startSession($uuid, $uid) {
    $this->uuid = $uuid;
    $this->uid = $uid;

    // Naively overwrite a new session.
    $this->state->set($this::SESSION_USER_KEY, $uid);
    $this->state->set($this::SESSION_UUID_KEY, $uuid);
    $this->state->set($this::SESSION_STAGE_KEY, 'authenticate');
  }
  /**
   * Validates the current session and request.
   *
   * UUID and/or UID should be set before calling this function.  An effort will
   * be made to set one or the other if either is missing, but will return
   * invalid if neither are supplied.
   *
   * @param string $request
   *   The current call being made to the server. ex. sendRequestXML.
   *
   * @return bool
   *   TRUE if the session and request are valid, FALSE otherwise.
   */
  public function validateSession($request) {
    $user = $this->state->get($this::SESSION_USER_KEY);
    $uuid = $this->state->get($this::SESSION_UUID_KEY);
    $stage = $this->state->get($this::SESSION_STAGE_KEY);

    // If no match found, the session is invalid or the user doesn't have a
    // session.
    if (empty($user) || empty($uuid)) {
      return FALSE;
    }

    $this->uid = $user;

    // Now we must check to see if a valid request was made.  A request is valid
    // if it lies in the array of nextSteps according to this session's last
    // step.  Either way, we update the session info and send back the result.
    $this->isValid = !empty($this->nextStep[$stage]) ? in_array($request, $this->nextStep[$stage]) : FALSE;
    $this->updateStage($request);

    return $this->isValid;
  }

  /**
   * Removes the current session.
   */
  public function closeSession() {
    $this->state->delete($this::SESSION_USER_KEY);
    $this->state->delete($this::SESSION_UUID_KEY);
    $this->state->delete($this::SESSION_STAGE_KEY);
  }

  /**
   * Update the session in the database.
   *
   * If the current session and request are valid, then we just update the
   * 'stage' with the current incoming request.  Otherwise, we delete
   * the stage from the database since it is no longer valid.
   *
   * @param string $stage
   *   The stage.
   */
  protected function updateStage($stage) {
    if ($this->isValid) {
      $this->state->set($this::SESSION_STAGE_KEY, $stage);
    }
    else {
      $this->state->delete($this::SESSION_STAGE_KEY);
    }
  }

  /**
   * Set the uuid for the session.
   *
   * @param string $uuid
   *   The UUID for the session.
   *
   * @return $this
   *   The session manager.
   */
  public function setUuid($uuid) {
    $this->uuid = $uuid;
    return $this;
  }

  /**
   * Set the UID of the User connected by the client.
   *
   * @param string $uid
   *   The uid.
   *
   * @return $this
   *   The session manager.
   */
  public function setUid($uid) {
    $this->uid = $uid;
    return $this;
  }

  /**
   * Get the UID of the User associated with the current session token.
   *
   * @return int
   *   The uid of the user.
   */
  public function getUid() {
    return $this->uid;
  }

}
