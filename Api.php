<?php
namespace Fenix\Core\Import\ThirdParty;

// A seprate class to handle the API handeling logic
class ExpediaApiClient {
    private $response;
    private $request;

    // This function is responsible for our HTTP requests
    public function sendRequest($headers, $url) {
        $soap_do = curl_init();
        curl_setopt($soap_do, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($soap_do, CURLOPT_URL, $url);
        curl_setopt($soap_do, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($soap_do, CURLOPT_ENCODING, '');

        $this->request = json_encode($headers);
        $this->response = curl_exec($soap_do);
        curl_close($soap_do);
    }

    public function getRequest() {
        return $this->request;
    }

    public function getResponse() {
        return $this->response;
    }
}
