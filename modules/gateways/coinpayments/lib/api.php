<?php

if (!function_exists('curl_init')) {
    throw new Exception('CoinPayments needs the CURL PHP extension.');
}
if (!function_exists('json_decode')) {
    throw new Exception('CoinPayments needs the JSON PHP extension.');
}

class CoinpaymentsApi
{

    const API_URL = 'https://api.coinpayments.net';
    const CHECKOUT_URL = 'https://checkout.coinpayments.net';
    const API_VERSION = '1';

    const API_SIMPLE_INVOICE_ACTION = 'invoices';
    const API_WEBHOOK_ACTION = 'merchant/clients/%s/webhooks';
    const API_MERCHANT_INVOICE_ACTION = 'merchant/invoices';
    const API_CURRENCIES_ACTION = 'currencies';
    const API_CHECKOUT_ACTION = 'checkout';
    const FIAT_TYPE = 'fiat';

    const PAID_EVENT = 'Paid';
    const CANCELLED_EVENT = 'Cancelled';

    const WEBHOOK_NOTIFICATION_URL = 'modules/gateways/callback/coinpayments.php';

    protected $client_id;
    protected $client_secret;
    protected $system_url;
    protected $webhooks;
    protected $version;
    protected $companyname;

    /**
     * CoinpaymentsApi constructor.
     * @param $params
     */
    public function __construct($params)
    {
        $this->system_url = $params['systemurl'];
        $this->client_id = $params['coinpayments_client_id'];
        $this->client_secret = $params['coinpayments_client_secret'];
        $this->webhooks = $params['coinpayments_webhooks'];
        $this->version = $params["whmcsVersion"];
        $this->companyname = $params['companyname'];
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function checkWebhook($event)
    {
        $exists = false;
        $webhooks_list = $this->getWebhooksList();
        if (!empty($webhooks_list)) {
            $webhooks_urls_list = array();
            if (!empty($webhooks_list['items'])) {
                $webhooks_urls_list = array_map(function ($webHook) {
                    return $webHook['notificationsUrl'];
                }, $webhooks_list['items']);
            }
            if (in_array($this->getNotificationUrl($event), $webhooks_urls_list)) {
                $exists = true;
            }
        }

        return $exists;
    }

    /**
     * @return bool|mixed
     * @throws Exception
     */
    public function getWebhooksList()
    {

        $action = sprintf(self::API_WEBHOOK_ACTION, $this->client_id);

        return $this->sendRequest('GET', $action, $this->client_id, null, $this->client_secret);
    }

    /**
     * @return bool|mixed
     * @throws Exception
     */
    public function createWebHook($event)
    {

        $action = sprintf(self::API_WEBHOOK_ACTION, $this->client_id);

        $params = array(
            "notificationsUrl" => $this->getNotificationUrl($event),
            "notifications" => [
                sprintf("invoice%s", $event)
            ],
        );

        return $this->sendRequest('POST', $action, $this->client_id, $params, $this->client_secret);
    }

    /**
     * @param $invoice_params
     * @return bool|mixed
     * @throws Exception
     */
    public function createInvoice($invoice_params)
    {

        if ($this->webhooks == 'on') {
            $action = self::API_MERCHANT_INVOICE_ACTION;
            $secret = $this->client_secret;
        } else {
            $action = self::API_SIMPLE_INVOICE_ACTION;
            $secret = false;
        }

        $params = array(
            'clientId' => $this->client_id,
            'invoiceId' => $invoice_params['invoice_id'],
            'amount' => [
                'currencyId' => $invoice_params['currency_id'],
                "displayValue" => $invoice_params['display_value'],
                'value' => $invoice_params['amount']
            ],
            'notesToRecipient' => $invoice_params['notes_link']
        );

        $params = $this->append_billing_data($params, $invoice_params['billing_data']);
        $params = $this->appendInvoiceMetadata($params);
        return $this->sendRequest('POST', $action, $this->client_id, $params, $secret);
    }

    /**
     * @param $billing_data
     * @return mixed
     */
    function append_billing_data($request_data, $billing_data)
    {
        $request_data['buyer'] =  array(
            "companyName" => $billing_data['companyname'],
            "name" => array(
                "firstName" => $billing_data['firstname'],
                "lastName" => $billing_data['lastname']
            ),
            "emailAddress" => $billing_data['email'],
            "phoneNumber" => $billing_data['phonenumber'],
        );
        if (preg_match('/^([A-Z]{2})$/', $billing_data['country'])
        && !empty($billing_data['address1'])
            && !empty($billing_data['city'])
        ) {
            $request_data['buyer']['address'] = array(
                'address1' => $billing_data['address1'],
                'provinceOrState' => $billing_data['state'],
                'city' => $billing_data['city'],
                'countryCode' => $billing_data['country'],
                'postalCode' => $billing_data['postcode'],
            );
        }
        return $request_data;
    }

    /**
     * @param $name
     * @return mixed
     * @throws Exception
     */
    public function getCoinCurrency($name)
    {

        $params = array(
            'types' => self::FIAT_TYPE,
            'q' => $name,
        );
        $items = array();

        $listData = $this->getCoinCurrencies($params);
        if (!empty($listData['items'])) {
            $items = $listData['items'];
        }

        return array_shift($items);
    }

    /**
     * @param array $params
     * @return bool|mixed
     * @throws Exception
     */
    public function getCoinCurrencies($params = array())
    {
        return $this->sendRequest('GET', self::API_CURRENCIES_ACTION, false, $params);
    }

    /**
     * @param $signature
     * @param $content
     * @return bool
     */
    public function checkDataSignature($signature, $content, $event)
    {

        $request_url = $this->getNotificationUrl($event);
        $signature_string = sprintf('%s%s', $request_url, $content);
        $encoded_pure = $this->encodeSignatureString($signature_string, $this->client_secret);
        return $signature == $encoded_pure;
    }

    /**
     * @param $signature_string
     * @param $client_secret
     * @return string
     */
    public function encodeSignatureString($signature_string, $client_secret)
    {
        return base64_encode(hash_hmac('sha256', $signature_string, $client_secret, true));
    }

    /**
     * @param $action
     * @return string
     */
    public function getApiUrl($action)
    {
        return sprintf('%s/api/v%s/%s', self::API_URL, self::API_VERSION, $action);
    }

    /**
     * @return string
     */
    public function getSystemUrl()
    {
        if (!empty($this->system_url)) {
            $request_url_data = parse_url($this->system_url);
            $system_url = sprintf('%s://%s', $request_url_data['scheme'], $request_url_data['host']);
            if (!empty($request_url_data['port']) && $request_url_data['port'] != '80') {
                $system_url = sprintf('%s:%s', $system_url, $request_url_data['port']);
            }
        } else {
            $system_url = sprintf('%s://%s', $_SERVER['REQUEST_SCHEME'], $_SERVER['HTTP_HOST']);

            if (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] != '80') {
                $system_url = sprintf('%s:%s', $system_url, $_SERVER['SERVER_PORT']);
            }
        }
        return $system_url;
    }

    /**
     * @return string
     */
    protected function getNotificationUrl($event)
    {
        return sprintf('%s/%s?clientId=%s&event=%s', $this->getSystemUrl(), self::WEBHOOK_NOTIFICATION_URL, $this->client_id, $event);
    }

    /**
     * @param $method
     * @param $api_action
     * @param $client_id
     * @param null $params
     * @param null $client_secret
     * @return bool|mixed
     * @throws Exception
     */
    protected function sendRequest($method, $api_action, $client_id, $params = null, $client_secret = null)
    {

        $response = false;

        $api_url = $this->getApiUrl($api_action);
        $date = new \Datetime();
        try {

            $curl = curl_init();

            $options = array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
            );

            $headers = array(
                'Content-Type: application/json',
            );

            if ($client_secret) {
                $signature = $this->createSignature($method, $api_url, $client_id, $date, $client_secret, $params);
                $headers[] = 'X-CoinPayments-Client: ' . $client_id;
                $headers[] = 'X-CoinPayments-Timestamp: ' . $date->format('c');
                $headers[] = 'X-CoinPayments-Signature: ' . $signature;

            }

            $options[CURLOPT_HTTPHEADER] = $headers;

            if ($method == 'POST') {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = json_encode($params);
            } elseif ($method == 'GET' && !empty($params)) {
                $api_url .= '?' . http_build_query($params);
            }

            $options[CURLOPT_URL] = $api_url;

            curl_setopt_array($curl, $options);

            $response = json_decode(curl_exec($curl), true);

            curl_close($curl);

        } catch (Exception $e) {

        }
        return $response;
    }

    /**
     * @param $request_data
     * @return mixed
     */
    protected function appendInvoiceMetadata($request_data)
    {
        $request_data['metadata'] = array(
            "integration" => sprintf('WHMCS_%s', $this->version),
            "hostname" => $this->system_url,
        );

        return $request_data;
    }

    /**
     * @param $method
     * @param $api_url
     * @param $client_id
     * @param $date
     * @param $client_secret
     * @param $params
     * @return string
     */
    protected function createSignature($method, $api_url, $client_id, $date, $client_secret, $params)
    {

        if (!empty($params)) {
            $params = json_encode($params);
        }

        $signature_data = array(
            chr(239),
            chr(187),
            chr(191),
            $method,
            $api_url,
            $client_id,
            $date->format('c'),
            $params
        );

        $signature_string = implode('', $signature_data);

        return $this->encodeSignatureString($signature_string, $client_secret);
    }

}
