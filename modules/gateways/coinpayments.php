<?php

require_once(__DIR__ . '/coinpayments/lib/api.php');

function coinpayments_config($params)
{

    if ($params['coinpayments_webhooks'] == "on" && !empty($params['coinpayments_client_id']) && !empty($params['coinpayments_client_secret'])) {

        $coinpayments_api = new CoinpaymentsApi($params);

        if (!$coinpayments_api->checkWebhook()) {
            $coinpayments_api->createWebhook();
        }

    }

    $configarray = array(
        "FriendlyName" => array("Type" => "System", "Value" => "CoinPayments.net"),
        "coinpayments_client_id" => array("FriendlyName" => "Client ID", "Type" => "text", "Size" => "32"),
        "coinpayments_webhooks" => array("FriendlyName" => "Enable webhooks", "Type" => "yesno", "Description" => "Check to enable CoinPayments.NET webhooks"),
        "coinpayments_client_secret" => array("FriendlyName" => "Client Secret", "Type" => "password", "Size" => "32"),
    );

    return $configarray;
}

function coinpayments_link($params)
{

    if (!isset($_SESSION['coinpayments']['invoices'][$params['invoiceid']])) {
        $coinpayments_api = new CoinpaymentsApi($params);

        $invoice_id = sprintf('%s|%s', md5($params['systemurl']), $params['invoiceid']);

        $currency_code = $params['currency'];
        $coin_currency = $coinpayments_api->getCoinCurrency($currency_code);

        $amount = intval(number_format($params['amount'], $coin_currency['decimalPlaces'], '', ''));
        $display_value = $params['amount'];

        $invoice = $coinpayments_api->createInvoice($invoice_id, $coin_currency['id'], $amount, $display_value);
        if ($params['coinpayments_webhooks'] == 'on') {
            $invoice = array_shift($invoice['invoices']);
        }
    } else {
        $invoice = $_SESSION['coinpayments']['invoices'][$params['invoiceid']];
    }

    $code = false;

    if (!empty($invoice)) {

        $_SESSION['coinpayments']['invoices'][$params['invoiceid']] = $invoice;

        $fields = array(
            'invoice-id' => $invoice['id'],
            'success-url' => $params['systemurl'],
            'cancel-url' => $params['systemurl'],
        );

        $code = '<form id="cpsform" action="' . sprintf('%s/%s/', CoinpaymentsApi::API_URL, CoinpaymentsApi::API_CHECKOUT_ACTION) . '" method="GET">';
        foreach ($fields as $n => $v) {
            $code .= '<input type="hidden" name="' . $n . '" value="' . htmlspecialchars($v) . '" />';
        }
        $code .= '<input type="image" src="https://www.coinpayments.net/images/pub/buynow-med-grey.png" alt="Pay Now with Bitcoin, Litecoin, and other cryptocurrencies...">';
        $code .= '</form>';

    } else {

        $code = '<div style="color: red;"> Error: Can\'t create CoinPayments.Net invoice!</div>';

    }

    return $code;
}
