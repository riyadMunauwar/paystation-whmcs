<?php
/**
 * PayStation checkout session initiator.
 *
 * Receives the signed "Pay Now" submission rendered by paystation_link(),
 * creates a PayStation hosted checkout session server side and redirects the
 * customer to the returned payment_url.
 *
 * This lives outside paystation_link() on purpose: PayStation requires a unique
 * invoice_number per session, so a session must be created per click, not per
 * render of the invoice page.
 *
 * Merchant credentials never leave this process - the browser only ever sees
 * the resulting payment_url.
 *
 * @package WHMCS\Module\Gateway\Paystation
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/lib/loader.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Gateway\Paystation\Api;
use WHMCS\Module\Gateway\Paystation\Helper;

$gatewayModuleName = Helper::MODULE_NAME;
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (empty($gatewayParams['type'])) {
    http_response_code(403);
    exit('PayStation gateway module is not activated.');
}

Helper::ensureSchema();

/**
 * Abort the checkout, log why, and return the customer to their invoice.
 *
 * @param array  $gatewayParams
 * @param int    $invoiceId
 * @param string $reason  Operator facing reason for the gateway log.
 * @param array  $context Extra data for the gateway log.
 *
 * @return void
 */
function paystation_redirect_abort(array $gatewayParams, $invoiceId, $reason, array $context = [])
{
    Helper::log($gatewayParams, array_merge([
        'context' => 'Initiate Payment',
        'invoice_id' => $invoiceId,
        'reason' => $reason,
    ], $context), 'Unsuccessful');

    if ($invoiceId > 0) {
        Helper::redirect(Helper::invoiceUrl($invoiceId, 'paymentfailed'));
    }

    http_response_code(400);
    exit('Unable to start the PayStation payment. Please try again from your invoice.');
}

/**
 * Reduce a WHMCS stored phone number to the digits PayStation expects.
 *
 * WHMCS stores numbers such as "+880.1712345678"; PayStation expects a local
 * Bangladeshi format such as "01712345678".
 *
 * @param string $phone
 *
 * @return string Empty when nothing usable remains.
 */
function paystation_normalise_phone($phone)
{
    $digits = preg_replace('/\D+/', '', (string) $phone);

    if ($digits === '') {
        return '';
    }

    // Strip the Bangladesh country code when a local number follows it.
    if (strpos($digits, '880') === 0 && strlen($digits) > 11) {
        $digits = substr($digits, 3);
    }
    if (strlen($digits) === 10 && strpos($digits, '1') === 0) {
        $digits = '0' . $digits;
    }

    return $digits;
}

/**
 * Short, human readable description of what is being paid for.
 *
 * @param int $invoiceId
 *
 * @return string
 */
function paystation_checkout_items($invoiceId)
{
    $items = [];

    try {
        $rows = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', (int) $invoiceId)
            ->limit(5)
            ->pluck('description');

        foreach ($rows as $description) {
            $description = trim(preg_replace('/\s+/', ' ', (string) $description));
            if ($description !== '') {
                $items[] = $description;
            }
        }
    } catch (\Exception $e) {
        // Fall through to the generic label.
    }

    $label = $items ? implode(', ', $items) : ('Invoice #' . (int) $invoiceId);

    if (function_exists('mb_substr')) {
        return mb_substr($label, 0, 240);
    }

    return substr($label, 0, 240);
}

// ---------------------------------------------------------------------------
// 1. Validate the signed request.
// ---------------------------------------------------------------------------

$invoiceId = isset($_POST['invoiceid']) ? (int) $_POST['invoiceid'] : 0;
$userId = isset($_POST['userid']) ? (int) $_POST['userid'] : 0;
$expires = isset($_POST['expires']) ? (int) $_POST['expires'] : 0;
$token = isset($_POST['token']) ? (string) $_POST['token'] : '';

if ($invoiceId <= 0) {
    http_response_code(400);
    exit('Missing invoice reference.');
}

if (!Helper::verifyPaymentToken($token, $invoiceId, $userId, $expires, $gatewayParams)) {
    // Either a forged request or a payment page that sat open too long.
    paystation_redirect_abort(
        $gatewayParams,
        $invoiceId,
        'Invalid or expired payment token. Reload the invoice and try again.'
    );
}

// ---------------------------------------------------------------------------
// 2. Validate the invoice itself.
// ---------------------------------------------------------------------------

try {
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
} catch (\Exception $e) {
    $invoice = null;
}

if (!$invoice) {
    paystation_redirect_abort($gatewayParams, $invoiceId, 'Invoice not found.');
}

$invoice = (array) $invoice;

if ((int) $invoice['userid'] !== $userId) {
    paystation_redirect_abort($gatewayParams, $invoiceId, 'Invoice does not belong to the signed client id.');
}

if (in_array($invoice['status'], ['Cancelled', 'Draft', 'Refunded'], true)) {
    paystation_redirect_abort(
        $gatewayParams,
        $invoiceId,
        'Invoice status "' . $invoice['status'] . '" cannot be paid.'
    );
}

if ($invoice['status'] === 'Paid') {
    Helper::redirect(Helper::invoiceUrl($invoiceId));
}

$currency = '';
try {
    $currency = (string) Capsule::table('tblcurrencies')
        ->where('id', (int) $invoice['currency'])
        ->value('code');
} catch (\Exception $e) {
    $currency = '';
}

$balance = Helper::invoiceBalance($invoiceId);
$amounts = Helper::computeAmounts($balance, $currency, $gatewayParams);

if ($amounts['error'] !== '') {
    paystation_redirect_abort($gatewayParams, $invoiceId, $amounts['error'], [
        'currency' => $currency,
        'balance' => $balance,
    ]);
}

// ---------------------------------------------------------------------------
// 3. Gather the customer detail PayStation requires.
// ---------------------------------------------------------------------------

try {
    $client = Capsule::table('tblclients')->where('id', $userId)->first();
} catch (\Exception $e) {
    $client = null;
}

if (!$client) {
    paystation_redirect_abort($gatewayParams, $invoiceId, 'Client record not found for invoice.');
}

$client = (array) $client;

$customerName = trim($client['firstname'] . ' ' . $client['lastname']);
if ($customerName === '') {
    $customerName = trim((string) $client['companyname']);
}
if ($customerName === '') {
    $customerName = 'Customer ' . $userId;
}

$customerEmail = trim((string) $client['email']);
$customerPhone = paystation_normalise_phone($client['phonenumber']);

if ($customerPhone === '') {
    paystation_redirect_abort(
        $gatewayParams,
        $invoiceId,
        'Client has no usable phone number; PayStation requires cust_phone. Ask the client to add one to their profile.'
    );
}

$addressParts = array_filter([
    trim((string) $client['address1']),
    trim((string) $client['address2']),
    trim((string) $client['city']),
    trim((string) $client['state']),
    trim((string) $client['postcode']),
    trim((string) $client['country']),
]);
$customerAddress = implode(', ', $addressParts);

// ---------------------------------------------------------------------------
// 4. Record the attempt locally, then create the checkout session.
// ---------------------------------------------------------------------------

$api = new Api($gatewayParams['merchantId'], $gatewayParams['password'], !empty($gatewayParams['testMode']));

if (!$api->isConfigured()) {
    // Fail before burning a PayStation invoice number on a request that cannot
    // possibly authenticate. The callback makes the same check.
    paystation_redirect_abort(
        $gatewayParams,
        $invoiceId,
        'PayStation credentials are not configured. Set the Merchant ID and Merchant Password in the gateway configuration.'
    );
}

$invoicePrefix = isset($gatewayParams['invoicePrefix']) ? $gatewayParams['invoicePrefix'] : '';
$debug = !empty($gatewayParams['debugLogging']);

$response = null;
$invoiceNumber = '';

// PayStation answers 1008 for a duplicate invoice_number. That should not
// happen (the timestamp guarantees a fresh value), but a clock adjustment or a
// value recycled outside WHMCS would trigger it, so retry with a new number.
for ($attempt = 1; $attempt <= 3; $attempt++) {
    $invoiceNumber = Helper::generateInvoiceNumber($invoiceId, $invoicePrefix);

    $created = Helper::createTransaction([
        'invoice_number' => $invoiceNumber,
        'invoice_id' => $invoiceId,
        'user_id' => $userId,
        'status' => 'pending',
        'invoice_currency' => $currency,
        'invoice_amount' => $amounts['invoice_amount'],
        'gateway_currency' => Helper::GATEWAY_CURRENCY,
        'gateway_amount' => $amounts['gateway_amount'],
        'surcharge' => $amounts['surcharge'],
        'conversion_rate' => $amounts['rate'],
    ]);

    if (!$created) {
        paystation_redirect_abort(
            $gatewayParams,
            $invoiceId,
            'Could not write the PayStation transaction record. Check that ' . Helper::TABLE . ' exists and is writable.'
        );
    }

    $fields = [
        'invoice_number' => $invoiceNumber,
        'currency' => Helper::GATEWAY_CURRENCY,
        'payment_amount' => number_format($amounts['gateway_amount'], 2, '.', ''),
        'pay_with_charge' => isset($gatewayParams['payWithCharge']) ? (string) (int) $gatewayParams['payWithCharge'] : '0',
        // The unique invoice number is echoed back in the status API, so it
        // doubles as the reconciliation key if the callback loses it.
        'reference' => $invoiceNumber,
        'cust_name' => $customerName,
        'cust_phone' => $customerPhone,
        'cust_email' => $customerEmail,
        'cust_address' => $customerAddress,
        'callback_url' => Helper::callbackUrl(),
        'checkout_items' => paystation_checkout_items($invoiceId),
        'opt_a' => (string) $invoiceId,
        'opt_b' => $currency,
        'opt_c' => number_format($amounts['invoice_amount'], 2, '.', ''),
    ];

    if (!empty($gatewayParams['enableEmi'])) {
        $fields['emi'] = '1';
    }

    $response = $api->initiatePayment($fields);

    if ($debug) {
        Helper::log($gatewayParams, [
            'context' => 'Initiate Payment Request',
            'attempt' => $attempt,
            'endpoint' => $api->baseUrl() . '/initiate-payment',
            'request' => $fields,
            'response' => $response['json'] ?: $response['raw'],
            'http_code' => $response['httpCode'],
            'curl_error' => $response['error'],
        ], $response['accepted'] ? 'Success' : 'Unsuccessful');
    }

    if ($response['statusCode'] !== Api::CODE_DUPLICATE_INVOICE) {
        break;
    }

    Helper::updateTransaction($invoiceNumber, [
        'status' => 'failed',
        'last_response' => $response['raw'],
    ]);
}

// ---------------------------------------------------------------------------
// 5. Redirect, or fail cleanly.
// ---------------------------------------------------------------------------

$paymentUrl = isset($response['json']['payment_url']) ? trim((string) $response['json']['payment_url']) : '';

if (!$response['accepted'] || $paymentUrl === '') {
    Helper::updateTransaction($invoiceNumber, [
        'status' => 'failed',
        'last_response' => $response['raw'] !== '' ? $response['raw'] : $response['error'],
    ]);

    $reason = $response['error'] !== ''
        ? $response['error']
        : ('PayStation declined the request (status_code ' . $response['statusCode'] . '): ' . $response['message']);

    paystation_redirect_abort($gatewayParams, $invoiceId, $reason, [
        'invoice_number' => $invoiceNumber,
        'http_code' => $response['httpCode'],
        'response' => $response['json'] ?: $response['raw'],
    ]);
}

// Only accept a checkout URL that actually belongs to PayStation.
$host = parse_url($paymentUrl, PHP_URL_HOST);
$scheme = strtolower((string) parse_url($paymentUrl, PHP_URL_SCHEME));
$hostIsPaystation = is_string($host) && preg_match('/(^|\.)paystation\.com\.bd$/i', $host);

if ($scheme !== 'https' || !$hostIsPaystation) {
    Helper::updateTransaction($invoiceNumber, [
        'status' => 'failed',
        'last_response' => $response['raw'],
    ]);

    paystation_redirect_abort($gatewayParams, $invoiceId, 'Refusing to redirect to an unexpected payment URL.', [
        'invoice_number' => $invoiceNumber,
        'payment_url' => $paymentUrl,
    ]);
}

Helper::updateTransaction($invoiceNumber, [
    'payment_url' => $paymentUrl,
    'last_response' => $response['raw'],
]);

Helper::log($gatewayParams, [
    'context' => 'Initiate Payment',
    'invoice_id' => $invoiceId,
    'invoice_number' => $invoiceNumber,
    'reference' => $invoiceNumber,
    'invoice_amount' => $amounts['invoice_amount'] . ' ' . $currency,
    'gateway_amount' => $amounts['gateway_amount'] . ' ' . Helper::GATEWAY_CURRENCY,
    'surcharge' => $amounts['surcharge'],
    'conversion_rate' => $amounts['rate'],
    'sandbox' => !empty($gatewayParams['testMode']),
    'message' => $response['message'],
], 'Payment Link Created');

Helper::redirect($paymentUrl);
