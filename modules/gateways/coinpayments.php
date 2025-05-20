<?php

require_once(__DIR__ . '/coinpayments/lib/api.php');

function coinpayments_config()
{
    return array(
        "FriendlyName" => array("Type" => "System", "Value" => "CoinPayments.net"),
        "coinpayments_client_id" => array("FriendlyName" => "Client ID", "Type" => "text", "Size" => "32"),
        "coinpayments_client_secret" => array("FriendlyName" => "Client Secret", "Type" => "password", "Size" => "32"),
    );
}

function coinpayments_link($params)
{
    $coinpayments_api = new CoinpaymentsApi($params);
    $whmcsInvoiceId = $params['invoiceid'];
    if (!isset($_SESSION['coinpayments']['invoices'][$whmcsInvoiceId])) {
        $orderUrl = $params['systemurl'] . "admin/orders.php?action=view&id=" . $whmcsInvoiceId;
        $invoice_params = array(
            'invoiceId' => sprintf('%s|%s', md5($coinpayments_api->getSystemUrl()), $whmcsInvoiceId),
            'currency' => $params['currency'],
            'items' => [
                'name' => $params['description'],
                'quantity' => [
                    'value' => 1,
                    'type' => '2',
                ],
                'amount' => (string)$params['amount']
            ],
            'amount' => [
                'breakdown' => [
                    'subtotal' => (string)$params['amount'],
                ],
                'total' => (string)$params['amount'],
            ],
            'notesToRecipient' => sprintf("%s|Store name: %s|Order #%s", $orderUrl, $params['companyname'], $whmcsInvoiceId)
        );


        $apiResponse = $coinpayments_api->createInvoice($invoice_params, $params['cart']->client->getAttributes());
        if (!empty($apiResponse['invoices'])) {
            $invoice = array_shift($apiResponse['invoices']);
        }
    } else {
        $invoice = $_SESSION['coinpayments']['invoices'][$whmcsInvoiceId];
    }

    if (!empty($invoice)) {
        $_SESSION['coinpayments']['invoices'][$whmcsInvoiceId] = $invoice;

        $fields = array(
            'invoice-id' => $invoice['id'],
            'success-url' => sprintf('%s/viewinvoice.php?id=%s', $coinpayments_api->getSystemUrl(), $whmcsInvoiceId),
            'cancel-url' => sprintf('%s/viewinvoice.php?id=%s', $coinpayments_api->getSystemUrl(), $whmcsInvoiceId),
        );

        $code = '<form id="cpsform" action="' . sprintf('%s/%s/', CoinpaymentsApi::CHECKOUT_URL, CoinpaymentsApi::API_CHECKOUT_ACTION) . '" method="GET">';
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
