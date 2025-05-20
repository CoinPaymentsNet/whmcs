<?php

if (!function_exists('curl_init')) {
    throw new Exception('CoinPayments needs the CURL PHP extension.');
}
if (!function_exists('json_decode')) {
    throw new Exception('CoinPayments needs the JSON PHP extension.');
}

class CoinpaymentsApi
{

    const API_URL = 'https://api.coinpayments.com';
    const CHECKOUT_URL = 'https://checkout.coinpayments.com';
    const API_VERSION = '2';

    const API_MERCHANT_INVOICE_ACTION = 'merchant/invoices';
    const API_CHECKOUT_ACTION = 'checkout';

    const NOTIFICATION_INVOICE_COMPLETED = 'invoiceCompleted';
    const NOTIFICATION_INVOICE_CANCELLED = 'invoiceCancelled';
    const NOTIFICATION_INVOICE_TIMED_OUT = 'invoiceTimedOut';

    const WEBHOOK_NOTIFICATION_URL = 'modules/gateways/callback/coinpayments.php';

    protected $client_id;
    protected $client_secret;
    protected $system_url;
    protected $version;

    /**
     * CoinpaymentsApi constructor.
     * @param $params
     */
    public function __construct($params)
    {
        $this->system_url = $params['systemurl'];
        $this->client_id = $params['coinpayments_client_id'];
        $this->client_secret = $params['coinpayments_client_secret'];
        $this->version = $params["whmcsVersion"];
    }

    /**
     * @param $invoice_params
     * @param array $client
     * @return bool|mixed
     * @throws Exception
     */
    public function createInvoice($invoice_params, $client)
    {
        $invoice_params['clientId'] = $this->client_id;
        $invoice_params['webhooks'] = [
            [
                'notificationsUrl' => $this->getNotificationUrl(),
                'notifications' => [
                    CoinpaymentsApi::NOTIFICATION_INVOICE_COMPLETED,
                    CoinpaymentsApi::NOTIFICATION_INVOICE_CANCELLED,
                    CoinpaymentsApi::NOTIFICATION_INVOICE_TIMED_OUT,
                ]
            ]
        ];

        $params = $this->append_billing_data($invoice_params, $client);
        $params = $this->appendInvoiceMetadata($params);

        return $this->sendRequest('POST', self::API_MERCHANT_INVOICE_ACTION, $params);
    }

    /**
     * @param array $request_data
     * @param array $billing_data
     * @return array
     */
    function append_billing_data($request_data, $billing_data)
    {
        $buyer = [];
        empty($billing_data['companyname']) ?: $buyer['companyName'] = $billing_data['companyname'];
        empty($billing_data['firstname']) ?: $buyer['name']['firstName'] = $billing_data['firstname'];
        empty($billing_data['lastname']) ?: $buyer['name']['lastName'] = $billing_data['lastname'];
        empty($billing_data['email']) ?: $buyer['emailAddress'] = $billing_data['email'];
        empty($billing_data['phonenumber']) ?: $buyer['phoneNumber'] = $billing_data['phonenumber'];


        if (preg_match('/^([A-Z]{2})$/', $billing_data['country'])
        && !empty($billing_data['address1'])
            && !empty($billing_data['city'])
        ) {
            $address = array(
                'address1' => $billing_data['address1'],
                'city' => $billing_data['city'],
                'countryCode' => $billing_data['country'],
            );

            empty($billing_data['state']) ?: $address['provinceOrState'] = $billing_data['state'];
            empty($billing_data['postcode']) ?: $address['postalCode'] = $billing_data['postcode'];
            $buyer['address'] = $address;
        }

        if (!empty($buyer)) {
            $request_data['buyer'] = $buyer;
        }

        return $request_data;
    }

    /**
     * @param string $signature
     * @param string $method
     * @param string $date
     * @param string $content
     * @return bool
     */
    public function checkDataSignature($signature, $method, $date, $content)
    {
        $expectedSignature = $this->createSignature($method, $this->getNotificationUrl(), $date, $content);

        return $signature == $expectedSignature;
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
    protected function getNotificationUrl()
    {
        return sprintf('%s/%s', $this->getSystemUrl(), self::WEBHOOK_NOTIFICATION_URL);
    }

    /**
     * @param $method
     * @param $api_action
     * @param null $params
     * @return array
     * @throws Exception
     */
    protected function sendRequest($method, $api_action, $params = null)
    {
        $response = [];

        $api_url = $this->getApiUrl($api_action);
        $date = new \Datetime();
        $timestamp = $date->format('Y-m-d\TH:i:s');
        try {

            $curl = curl_init();
            $options = array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
            );

            $content = !empty($params) ? json_encode($params) : '';
            $signature = $this->createSignature($method, $api_url, $timestamp, $content);
            $options[CURLOPT_HTTPHEADER] = array(
                'Content-Type: application/json',
                'X-CoinPayments-Client: ' . $this->client_id,
                'X-CoinPayments-Timestamp: ' . $timestamp,
                'X-CoinPayments-Signature: ' . $signature
            );

            if ($method == 'POST') {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $content;
            } elseif ($method == 'GET' && !empty($params)) {
                $api_url .= '?' . http_build_query($params);
            }

            $options[CURLOPT_URL] = $api_url;

            curl_setopt_array($curl, $options);

            $response = json_decode(curl_exec($curl), true);

            curl_close($curl);

        } catch (Exception $e) {

        }

        return is_array($response) ? $response : [];
    }

    /**
     * @param $request_data
     * @return mixed
     */
    protected function appendInvoiceMetadata($request_data)
    {
        $request_data['customData'] = array(
            "integration" => sprintf('WHMCS_%s', $this->version),
            "hostname" => $this->system_url,
        );

        return $request_data;
    }

    /**
     * @param $method
     * @param $api_url
     * @param $date
     * @param $params
     * @return string
     */
    protected function createSignature($method, $api_url, $date, $params)
    {
        $signature_data = [chr(239), chr(187), chr(191), $method, $api_url, $this->client_id, $date];
        if (!empty($params)) {
            $signature_data[] = $params;
        }

        return $this->encodeSignatureString(implode('', $signature_data), $this->client_secret);
    }

}
