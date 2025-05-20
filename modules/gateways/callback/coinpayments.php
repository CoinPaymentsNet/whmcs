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

$coinpayments_api = new CoinpaymentsApi($params);
$signature = $_SERVER['HTTP_X_COINPAYMENTS_SIGNATURE'];
$date = $_SERVER['HTTP_X_COINPAYMENTS_TIMESTAMP'];
$request_data = json_decode($content, true);

if (!$coinpayments_api->checkDataSignature($signature, "POST", $date, $content)) {
    logTransaction($params["name"], $content, "Could not validate signature");
    return;
}

$invoice = !empty($request_data['invoice']) ? $request_data['invoice'] : null;
if (is_null($invoice)) {
    logTransaction($params["name"], $content, "Could not obtain invoice data");
    return;
}

$invoiceIdRaw = !empty($invoice['invoiceId']) ? $invoice['invoiceId'] : null;
$webhookType = !empty($request_data['type']) ? $request_data['type'] : null;
if (empty($invoiceIdRaw) || empty($webhookType)) {
    logTransaction($params["name"], $content, "Missing required invoice data");
    return;
}

$invoice_str = explode('|', $invoiceIdRaw);
$host_hash = array_shift($invoice_str);
$invoice_id = array_shift($invoice_str);
if ($host_hash != md5($coinpayments_api->getSystemUrl())) {
    logTransaction($params["name"], $content, "Could not validate host hash");
    return;
}

$display_value = $request_data['invoice']['amount']['total'];
$trans_id = $request_data['invoice']['id'];
$invoice_id = checkCbInvoiceID($invoice_id, $params["name"]);
checkCbTransID($trans_id);
if ($webhookType == CoinpaymentsApi::NOTIFICATION_INVOICE_COMPLETED) {
    addInvoicePayment($invoice_id, $trans_id, $display_value, 0.00, $gatewaymodule);
}
