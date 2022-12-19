<?php

namespace Drupal\commerce_qb_webconnect\SoapBundle;

use Drupal\commerce_qb_webconnect\SoapBundle\Services\SoapServiceInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class SoapServiceController.
 *
 * @package Drupal\commerce_qb_webconnect\SoapBundle
 */
class SoapServiceController extends ControllerBase {

  /**
   * The SOAP service.
   *
   * @var \Drupal\commerce_qb_webconnect\SoapBundle\Services\SoapService
   */
  protected $soapService;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The SOAP server.
   *
   * @var \SoapServer
   */
  protected $server;

  /**
   * SoapServiceController constructor.
   *
   * @param \Drupal\commerce_qb_webconnect\SoapBundle\Services\SoapServiceInterface $soapService
   *   The SOAP service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(SoapServiceInterface $soapService, LoggerChannelFactoryInterface $logger, ModuleHandlerInterface $moduleHandler) {
    $this->soapService = $soapService;
    $this->logger = $logger;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_qb_webconnect.soap_service'),
      $container->get('logger.factory'),
      $container->get('module_handler')
    );
  }

  /**
   * Construct the SOAP service and handle the request.
   *
   * @TODO: Pass in WSDL file location as a parameter.
   */
  public function handleRequest() {
    // Allow other modules to make changes to the SOAP service, such as swapping
    // out the validation or qbxml parser plugins.
    $this->moduleHandler->alter('commerce_qb_webconnect_soapservice', $this->soapService);

    // Clear the wsdl caches.
    ini_set('soap.wsdl_cache_enabled', 0);
    ini_set('soap.wsdl_cache_ttl', 0);

    // Create the Soap server.
    $this->server = new \SoapServer(__DIR__ . '/QBWebConnectorSvc.wsdl');
    $this->server->setObject($this->soapService);

    $response = new Response();
    $response->headers->set('Content-Type', 'text/xml; charset=ISO-8859-1');

    ob_start();
    $this->server->handle();
    $response->setContent(ob_get_clean());

    return $response;
  }

}
