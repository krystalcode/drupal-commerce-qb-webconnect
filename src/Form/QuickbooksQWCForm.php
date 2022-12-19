<?php

namespace Drupal\commerce_qb_webconnect\Form;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class QuickbooksQWCForm.
 *
 * @package Drupal\commerce_qb_webconnect\Form
 */
class QuickbooksQWCForm extends FormBase {

  /**
   * The current request.
   *
   * @var null|\Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuid;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a new QuickbooksQWCForm.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(RequestStack $requestStack, ModuleHandlerInterface $moduleHandler, EntityTypeManagerInterface $entityTypeManager, UuidInterface $uuid, ConfigFactoryInterface $configFactory, RendererInterface $renderer) {
    $this->request = $requestStack->getCurrentRequest();
    $this->moduleHandler = $moduleHandler;
    $this->entityTypeManager = $entityTypeManager;
    $this->uuid = $uuid;
    $this->configFactory = $configFactory;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('module_handler'),
      $container->get('entity_type.manager'),
      $container->get('uuid'),
      $container->get('config.factory'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quickbooks_qwc_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Determine if we're using a secure connection, and get the domain.
    $secure = $this->request->isSecure();

    // Useful path storage.
    $qbwc_path = Url::fromRoute('commerce_qb_webconnect.quickbooks_soap_controller', [], ['absolute' => TRUE])->toString();
    if ($this->moduleHandler->moduleExists('help')) {
      $help_path = Url::fromRoute('help.page', ['name' => 'commerce_qb_webconnect'], ['absolute' => TRUE])->toString();
    }

    // Get all users with the 'access quickbooks soap service' permission.
    $ids = $this->entityTypeManager->getStorage('user')->getQuery()
      ->condition('status', 1)
      ->condition('roles', 'quickbooks_user')
      ->execute();
    $users = User::loadMultiple($ids);

    $user_options = [];

    if (!empty($users)) {
      foreach ($users as $user) {
        $name = $user->getAccountName();
        $user_options[$name] = $name;
      }
    }

    // Generate a FileID (GUID for Quickbooks), and load our
    // OwnerID (GUID for server).
    $file_id = $this->uuid->generate();
    $owner_id = $this->configFactory->get('commerce_qb_webconnect.quickbooks_admin')->get('qwc_owner_id');
    $config_set = 0;

    if (empty($owner_id)) {
      // If the Owner ID is empty, then the user hasn't configured the module
      // yet. That's OK, but it means we need to generate the Owner ID here and
      // let the configuration know we've done so.
      $owner_id = $this->uuid->generate();
      $config_set = 1;
    }

    // Finished pre-setup, create form now.
    $form['container'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('QWC generation form'),
    ];

    $form['container']['app_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App Name'),
      '#description' => $this->t('The name of the application visible to the user. This name is displayed in the QB web connector. It is also the name supplied in the SDK OpenConnection call to QuickBooks or QuickBooks POS'),
      '#maxlength' => 32,
      '#size' => 64,
      '#default_value' => '',
      '#required' => TRUE,
    ];

    $description = $this->t('The URL of your web service.  For internal development and testing only, you can specify localhost or a machine name in place of the domain name.');
    $form['container']['app_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('App URL'),
      '#description' => $secure
      ? $description
      : $this->t('WARNING: Only local testing can be made over an insecure connection. :::') . ' ' . $description,
      '#maxlength' => 64,
      '#size' => 64,
      '#default_value' => $qbwc_path,
      '#required' => TRUE,
    ];

    $form['container']['app_support'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Support URL'),
      '#description' => $this->t('The support URL.  This can most likely stay unchanged, but if change is desired then the domain or machine name must match the App URL domain or machine name.'),
      '#size' => 64,
      '#default_value' => empty($help_path) ?: $help_path,
      '#required' => TRUE,
    ];

    $form['container']['user_name'] = [
      '#type' => 'select',
      '#title' => $this->t('Quickbooks User'),
      '#description' => $this->t("A user with specific permission to access the SOAP service calls on your site.  This list is populated by users with the 'access quickbooks soap service' permission."),
      '#options' => $user_options,
      '#required' => TRUE,
    ];

    $form['container']['file_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('File ID'),
      '#description' => $this->t('An ID assigned to your Quickbooks application.  This should be left alone, but if necessary can be replaced if you have a working GUID already.'),
      '#default_value' => $file_id,
      '#maxlength' => 36,
      '#size' => 64,
      '#required' => TRUE,
    ];

    $form['owner_id'] = [
      '#type' => 'hidden',
      '#value' => $owner_id,
    ];

    $form['config_set'] = [
      '#type' => 'hidden',
      '#value' => $config_set,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Download QWC file'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_values = $form_state->cleanValues()->getValues();
    $app_name = $description = $app_url = $app_support = $user_name = $file_id = $owner_id = NULL;
    extract($form_values, EXTR_IF_EXISTS);

    // Update the config if we set a new Owner ID in this form.
    $update_config = $form_values['config_set'];
    unset($form_values['config_set']);
    if ($update_config) {
      $this->configFactory
        ->getEditable('commerce_qb_webconnect.quickbooks_admin')
        ->set('qwc_owner_id', $form_values['owner_id'])
        ->save();
    }

    // Generate the XML file.
    $qwc = new \QuickBooks_WebConnector_QWC($app_name, $description, $app_url, $app_support, $user_name, $file_id, $owner_id);
    $xml = $qwc->generate();

    // Save the generated QWC file as SERVER_HOST.qwc.
    $file = file_save_data($xml, 'private://' . $this->request->getHost() . '.qwc');

    if ($file) {
      $uri = $file->getFileUri();

      // Automatically sets content headers and opens the file stream.
      // In order to get the full file name and attachment, we set the content
      // type to 'text/xml' and the disposition to 'attachment'.  We also delete
      // the file after sending it to ensure prying eyes don't find it later.
      $response = new BinaryFileResponse($uri, 200, [], TRUE, 'attachment');
      $response->deleteFileAfterSend(TRUE);

      $form_state->setResponse($response);
    }
    else {
      drupal_set_message(t('Unable to generate QWC file, check the log files for full details.'));
    }
  }

}
