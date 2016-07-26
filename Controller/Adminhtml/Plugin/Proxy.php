<?php
namespace CloudFlare\Plugin\Controller\Adminhtml\Plugin;

use \CF\API\Client;
use \CF\API\Plugin;
use \CF\API\Request;
use \CF\Integration\DefaultConfig;
use \CF\Integration\DefaultIntegration;
use \CF\Router\DefaultRestAPIRouter;
use \CloudFlare\Plugin\Backend\ClientRoutes;
use \CloudFlare\Plugin\Backend\DataStore;
use \CloudFlare\Plugin\Backend\MagentoAPI;
use \CloudFlare\Plugin\Backend\PluginRoutes;
use \CloudFlare\Plugin\Model\KeyValueFactory;

use \Magento\Backend\App\AbstractAction;
use \Magento\Backend\App\Action\Context;
use \Magento\Framework\App\DeploymentConfig\Reader;
use \Magento\Framework\Controller\Result\JsonFactory;
use \Magento\Store\Model\StoreManagerInterface;
use \Psr\Log\LoggerInterface;

class Proxy extends AbstractAction {

    protected $clientAPIClient;
    protected $config;
    protected $dataStore;
    protected $integrationContext;
    protected $logger;
    protected $jsonBody;
    protected $magentoAPI;
    protected $pluginAPIClient;
    protected $resultJsonFactory;

    const FORM_KEY = "form_key";


    /**
     * @param Client $clienAPIClient
     * @param Context $context
     * @param Backend\DataStore|DataStore $dataStore
     * @param DefaultConfig $config
     * @param DefaultIntegration $integrationContext
     * @param JsonFactory $resultJsonFactory
     * @param LoggerInterface $logger
     * @param Backend\MagentoAPI|MagentoAPI $magentoAPI
     * @param Plugin $pluginAPIClient
     */
    public function __construct(
        Client $clienAPIClient,
        Context $context,
        DataStore $dataStore,
        DefaultConfig $config,
        DefaultIntegration $integrationContext,
        JsonFactory $resultJsonFactory,
        LoggerInterface $logger,
        MagentoAPI $magentoAPI,
        Plugin $pluginAPIClient

    ) {
        $this->clientAPIClient = $clienAPIClient;
        $this->config = $config;
        $this->dataStore = $dataStore;
        $this->integrationContext = $integrationContext;
        $this->logger = $logger;
        $this->magentoAPI = $magentoAPI;
        $this->pluginAPIClient = $pluginAPIClient;
        $this->resultJsonFactory = $resultJsonFactory;

        // php://input can only be read once
        $decodedJson = json_decode(file_get_contents('php://input'), true);
        if(json_last_error() !== 0) {
            $this->logger->error("Error decoding JSON: ". json_last_error_msg());
        }
        $this->jsonBody = $decodedJson;

        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute() {
        $result = $this->resultJsonFactory->create();

        $magentoRequest = $this->getRequest();
        $method =  $magentoRequest->getMethod();
        $parameters = $magentoRequest->getParams();
        $body = $this->getJsonBody();
        $path = (strtoupper($method === "GET") ? $parameters['proxyURL'] : $body['proxyURL']);

        $request = new Request($method, $path, $parameters, $body);

        $apiClient = null;
        $routes = null;
        if($this->isClientAPI($path)) {
            $apiClient = $this->clientAPIClient;
            $routes = ClientRoutes::$routes;
        } else if($this->isPluginAPI($path)) {
            $apiClient = $this->pluginAPIClient;
            $routes = PluginRoutes::$routes;
        } else {
            $this->logger->error("Bad Request: ". $request->getUrl());
            return $result->setData($this->clientAPIClient->createAPIError("Bad Request: ". $request->getUrl()));
        }

        $router = new DefaultRestAPIRouter($this->integrationContext, $apiClient, $routes);
        $response = $router->route($request);

        return $result->setData($response);
    }

    public function getJsonBody() {
        return $this->jsonBody;
    }

    /**
     * @param $jsonBody
     */
    public function setJsonBody($jsonBody) {
        $this->jsonBody = $jsonBody;
    }

    /**
     * @param $path
     * @return bool
     */
    public function isClientAPI($path) {
        return (strpos($path, Client::ENDPOINT) !== false);
    }

    /**
     * @param $path
     * @return bool
     */
    public function isPluginAPI($path) {
        return (strpos($path, Plugin::ENDPOINT) !== false);
    }

    /*
     * Magento CSRF validation can't find the CSRF Token "form_key" if its in the JSON
     * so we copy it from the JSON body to the Magento request parameters.
    */
    public function _processUrlKeys() {
        $requestJsonBody = $this->getJsonBody();
        if($requestJsonBody !== null && array_key_exists(self::FORM_KEY, $requestJsonBody)) {
            $this->setJsonFormTokenOnMagentoRequest($requestJsonBody[self::FORM_KEY], $this->getRequest());
        }
        return parent::_processUrlKeys();
    }

    /**
     * @param $token "form_key"
     * @param $request
     */
    public function setJsonFormTokenOnMagentoRequest($token, $request) {
        $parameters = $request->getParams();
        $parameters[self::FORM_KEY] = $token;
        $request->setParams($parameters);
        return $request;
    }
}