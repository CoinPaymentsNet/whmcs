<?php

$whmcs = false;
require_once(__DIR__ . '/../coinpayments/lib/api.php');
require("../../../init.php");

$whmcs->load_function('gateway');
$whmcs->load_function('invoice');

$gatewaymodule = "coinpayments";
$params = getGatewayVariables($gatewaymodule);
$content = file_get_contents('php://input');

if (!$params["type"]) die("Module Not Activated");

if ($params['coinpayments_webhooks'] == 'on') {

    $coinpayments_api = new CoinpaymentsApi($params);
    $signature = $_SERVER['HTTP_X_COINPAYMENTS_SIGNATURE'];
    $request_data = json_decode($content, true);

    if ($coinpayments_api->checkDataSignature($signature, $content, $request_data['invoice']['status']) && isset($request_data['invoice']['invoiceId'])) {

        $invoice_str = $request_data['invoice']['invoiceId'];
        $invoice_str = explode('|', $invoice_str);
        $host_hash = array_shift($invoice_str);
        $invoice_id = array_shift($invoice_str);

        if ($host_hash == md5($coinpayments_api->getSystemUrl())) {
            $display_value = $request_data['invoice']['amount']['displayValue'];
            $trans_id = $request_data['invoice']['id'];
            $invoice_id = checkCbInvoiceID($invoice_id, $params["name"]);
            checkCbTransID($trans_id);

            if ($request_data['invoice']['status'] == CoinpaymentsApi::PAID_EVENT) {
                addInvoicePayment($invoice_id, $trans_id, $display_value, 0.00, $gatewaymodule);
            }
        }
    }
}
