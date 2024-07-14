<?php

namespace Fenix\Core\Import\ThirdParty;

use Fenix\Library\Objects\Product;
use Fenix\Library\Objects\Supplier;


// Abstract class defining common functionality for importing data from Expedia APIs.
// Manages configuration settings, handles API requests, processes API responses, and logs import details.
abstract class Expedia
{
    protected const URL = 'https://apim.expedia.com/';
    protected const ENABLE_V1 = true;

    // For Legacy Imports
    protected string $apiKeyV1;
    protected string $secretV1;

    // All new Imports
    protected string $apiKeyV2;
    protected string $secretV2;

    protected string $country = 'AU';

    protected string $tripID;

    protected string $tpid = '';
    protected string $branchEmail = '';
    protected string $defaultEmail = '';
    protected array $emailList = [];

    protected $productCode = '';
    protected $productID = '';
    protected $supplierCode = '';
    protected $supplierID;
    protected $passengerID;

    protected ExpediaRepository $repository;
    protected ExpediaApiClient $apiClient;

    protected $logImportID;
    protected bool $hasError = false;
    protected array $errors = [];
    protected string $request;
    protected string $response;
    protected bool $debug = false;

    protected array $sectors = [];
    protected array $itineraries = [];


    protected string $url = '';
    protected string $acceptString = '';


    protected $data;

    public function __construct($config, ExpediaRepository $repository)
    {

        $this->productID = $config['productID'] ?? null;
        $this->supplierID = $config['supplierID'] ?? null;
        $this->passengerID = $config['passengerID'] ?? null;

        if (isset($config['tripID'])) {
            $this->tripID = $config['tripID'];
            // Now this is being injected as dependancy
            $this->repository = $repository;
            // this service will handle the http requests
            $this->apiClient = new ExpediaApiClient();
            if($this->retrieveSettings())
                $this->processSettings();
        } else {
            $this->hasError = true;
            $this->errors[] = 'Failed to pass through the tripID';
        }
    }

    // Handles an API request using API class with provided email, URL, itinerary ID, API version key, and version number.
    protected function handleAPIRequest($email, $url, $itineraryID, $apiVersionKey, $version)
    {
        $headers = [
            'Key: ' . $apiVersionKey,
            $this->acceptString,
            $this->generateAuth(),
            'User-Id: ' . $email,
            'Partner-Transaction-Id: ' . $this->tpid,
        ];
        $this->apiClient->sendRequest($headers, $url);

        $this->request = $this->apiClient->getRequest();
        $this->response = $this->apiClient->getResponse();

        $this->data = json_decode($this->response, true);

        $this->logImport($itineraryID, $version);
    }

    // Retrieves itinerary details using the provided itinerary ID and email, handling API requests and error checking.
    public function retrieveItinerary($itineraryID, $email, bool $debugMode = false): void
    {

        $this->debug = $debugMode;
        $url = $this->url . $itineraryID;


        if (!$this->hasError) {
            $this->handleAPIRequest($email, $url, $itineraryID, $this->apiKeyV2, 'v2');

            if (isset($this->data['Errors'])) {
                if (self::ENABLE_V1) {
                    $this->handleAPIRequest($email, $url, $itineraryID, $this->apiKeyV1, 'v1');
                }

                if (isset($this->data['Errors'])) {
                    $this->hasError = true;
                    $this->errors[] = 'Failed to fetch information';
                    foreach ($this->data['Errors'] as $error) {
                        $this->errors[] = $error['Description'];
                        $this->enterError($error['Description']);
                    }
                } else {
                    $this->generateSectorInformation();
                }
            } else {
                $this->generateSectorInformation();
            }
        }
    }

    // Same function will handle the logic for V1 and V2
    protected function processAPISettings(): void
    {
        $rowCount = $this->repository->fetchApiSettingsCount('V2', $this->country);
        $this->processAPIData($rowCount);
        if (self::ENABLE_V1) {
            $rowCount = $this->repository->fetchApiSettingsCount('V1', $this->country);
            $this->processAPIData($rowCount);
        }
    }

    // Processes configuration information such as merchant ID, email settings, 
    // supplier details, and product codes, handling missing configurations as needed.
    protected function processConfigs(): void
    {
        if (!empty($info['merchantID']))
            $this->tpid = $info['merchantID'];
        else {
            $this->handleMissingConfig('Partner ID');
        }

        if (!empty($info['extra1'])) {
            $this->branchEmail = $info['extra1'];
            $this->defaultEmail = $info['extra1'];
            $this->emailList[] = $info['extra1'];
        } else {
            $this->handleMissingConfig('Expedia Email');
        }

        if (empty($this->supplierID)) {
            if (!empty($info['extra2'])) {
                $this->supplierCode = $info['extra2'];
                $this->supplierID = Supplier::_getSupplierID_FromSupplierCode($this->supplierCode);
                if (empty($this->supplierID)) {
                    $this->handleMissingConfig('Supplier Code Invalid');
                }
            } else {
                $this->handleMissingConfig('Supplier Code');
            }
        }

        if (empty($this->productID)) {
            if (!empty($info['extra3'])) {
                $this->productCode = $info['extra3'];
                $this->productID = Product::_getProductID_FromProductCode($this->productCode);
                if (empty($this->productID)) {
                    $this->handleMissingConfig('Product Code Invalid');
                }
            } else {
                $this->handleMissingConfig('Product Code');
            }
        }
    }

    protected function processSettings(): void
    {
        $info = $this->repository->getMaseterDBSingleRow();
        if (!empty($info['extra4'])) {
            $this->country = strtoupper($info['extra4']);
        }

        // process the api settings for v1 and v2
        $this->processAPISettings();

        // missing configs and error logging
        $this->processConfigs();

        // Get gateway settings branch and office wide
        $rowCount = $this->repository->getConsultantExpediaGatewaySettingsCount();
        if ($rowCount > 0) {
            foreach ($this->repository->getMaseterDBResultSet() as $consultantRow) {
                if (isset($consultantRow['extra2']) && strtoupper($consultantRow['extra2']) == 'DEFAULT')
                    $this->defaultEmail = $consultantRow['extra1'];

                $this->emailList[] = $consultantRow['extra1'];
            }
        }

        $this->repository->getBranchWideExpediaGatewaySettings();
        $this->setEmails();

        $this->repository->getOfficeWideExpediaGatewaySettings();
        $this->setEmails();

        $this->emailList = array_unique($this->emailList);
        break;
    }

    protected function retrieveSettings(): bool
    {

        $rowCount = $this->repository->getExpediaGatewaySettingsCount();
        if ($rowCount === 1) {
            return true;
        } elseif ($rowCount > 1) {
            $this->hasError = true;
            $this->errors[] = 'Unable to determine the branch expedia settings (Too many expedia branch configurations)';
        } else {
            $this->hasError = true;
            $this->errors[] = 'No Settings exist for Expedia (No settings for expedia have been entered for this branch)';
        }

        return false;
    }

    protected function logImport($itineraryID, $keyVersion): void
    {

        $apiSettings = [
            'productID' => $this->productID,
            'supplierID' => $this->supplierID,
            'emails' => $this->emailList,
        ];

        $insert = new \stdClass();
        $insert->itineraryNumber = $itineraryID;
        $insert->officeID = $this->repository->getSession()->getOfficeID();
        $insert->consultantID = $this->repository->getSession()->getConsultantID();
        $insert->tripID = $this->tripID;
        $insert->dateCreated = date('Y-m-d H:i:s');
        $insert->request = $this->request;
        $insert->response = $this->response;
        $insert->apiSettings = json_encode($apiSettings);
        $insert->keyVersion = $keyVersion;
        $this->repository->getMaseterDB()->insertObject($insert, 'logExpediaImport');
        $this->logImportID =  $this->repository->getMaseterDB()->lastInsertId();
    }

    protected function generateAuth(bool $version2 = true): string
    {

        if (self::ENABLE_V1) {
            if ($version2)
                $authBasicString = $this->apiKeyV2 . ':' . $this->secretV2;
            else
                $authBasicString = $this->apiKeyV1 . ':' . $this->secretV1;
        } else
            $authBasicString = $this->apiKeyV2 . ':' . $this->secretV2;

        return 'Authorization: Basic ' . base64_encode($authBasicString);
    }

    protected function enterError($message): void
    {

        $insert = new \stdClass();
        $insert->officeID = $this->repository->getSession()->getOfficeID();
        $insert->consultantID = $this->repository->getSession()->getConsultantID();
        $insert->tripID = $this->tripID;
        $insert->logExpediaImportID = $this->logImportID;
        $insert->message = $message;
        $insert->dateCreated = date('Y-m-d H:i:s');
        $this->repository->getMaseterDB()->insertObject($insert, 'logExpediaError');
    }

    /**
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return array
     */
    public function getSectors(): array
    {
        return $this->sectors;
    }

    /**
     * @return array
     */
    public function getItineraries(): array
    {
        return $this->itineraries;
    }

    /**
     * @return mixed
     */
    public function getPassengerID()
    {
        return $this->passengerID;
    }

    /**
     * @return string
     */
    public function getBranchEmail(): string
    {
        return $this->branchEmail;
    }

    /**
     * @return string
     */
    public function getDefaultEmail(): string
    {
        return $this->defaultEmail;
    }

    /**
     * @return array
     */
    public function getEmailList(): array
    {
        return $this->emailList;
    }

    /**
     * @return string
     */
    public function getResponse(): string
    {
        return $this->response;
    }

    /**
     * @return string
     */
    public function getRequest(): string
    {
        return $this->request;
    }

    /**
     * @return string
     */
    public function getProductCode(): string
    {
        return $this->productCode;
    }

    /**
     * @return mixed|string
     */
    public function getProductID()
    {
        return $this->productID;
    }

    /**
     * @return string
     */
    public function getSupplierCode(): string
    {
        return $this->supplierCode;
    }

    /**
     * @return mixed
     */
    public function getSupplierID()
    {
        return $this->supplierID;
    }

    /**
     * @return string
     */
    public function getTpid(): string
    {
        return $this->tpid;
    }

    /**
     * @return mixed|string
     */
    public function getTripID()
    {
        return $this->tripID;
    }

    /**
     * @return bool
     */
    public function hasError(): bool
    {
        return $this->hasError;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }


    // Helper Methods 
    private function processAPIData($rowCount)
    {
        if ($rowCount == 1) {
            $apiInfo = $this->repository->getMaseterDBSingleRow();
            $this->apiKeyV2 = $apiInfo['key'];
            $this->secretV2 = $apiInfo['extra1'];
        } else {
            $this->handleMissingConfig('Invalid country code ' . $this->country);
        }
    }

    public function handleMissingConfig($configName)
    {
        $this->hasError = true;
        $this->errors[] = "Missing Config - $configName";
    }

    private function setEmails()
    {
        foreach ($this->repository->getMaseterDBResultSet() as $consultantRow) {
            $this->emailList[] = $consultantRow['extra1'];
        }
    }
}
