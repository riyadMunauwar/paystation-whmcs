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
    $error = Helper::fail(
        [],
        'PS-NOT-ACTIVATED',
        'The PayStation gateway module is not activated in Setup > Payments > Payment Gateways.',
        ['context' => 'Initiate Payment'],
        'This payment method is not available right now. Please contact support.'
    );

    Helper::renderErrorPage($error, Helper::maySeeDetail([]), 403, 'The PayStation payment could not be started');
}

if (!Helper::ensureSchema()) {
    // Without the ledger table nothing downstream can be recorded, and an
    // unrecorded checkout is a payment that can never be matched back to an
    // invoice. Stop here rather than sending the customer to PayStation.
    $error = Helper::fail(
        $gatewayParams,
        'PS-DB-SCHEMA',
        'The PayStation ledger table ' . Helper::TABLE . ' is missing and could not be created. '
            . (Helper::lastInternalError() !== ''
                ? Helper::lastInternalError()
                : 'Check that the WHMCS database user has CREATE privileges.'),
        [
            'context' => 'Initiate Payment',
            'invoice_id' => isset($_POST['invoiceid']) ? (int) $_POST['invoiceid'] : 0,
            'table' => Helper::TABLE,
        ],
        'PayStation is not set up correctly on this site yet. Please contact support.'
    );

    $postedInvoiceId = isset($_POST['invoiceid']) ? (int) $_POST['invoiceid'] : 0;
    if ($postedInvoiceId > 0) {
        Helper::redirect(Helper::invoiceErrorUrl($postedInvoiceId, $error));
    }

    Helper::renderErrorPage($error, Helper::maySeeDetail($gatewayParams), 500, 'The PayStation payment could not be started');
}

/**
 * Abort the checkout, log why, and return the customer to their invoice.
 *
 * The reason is recorded in the module log file, the WHMCS gateway log and the
 * WHMCS activity log. The customer message is handed to the invoice page so it
 * can replace the generic WHMCS "your payment attempt was not successful"
 * banner with something specific enough to act on.
 *
 * @param array  $gatewayParams
 * @param int    $invoiceId
 * @param string $code            Stable error code, e.g. PS-DECLINED.
 * @param string $reason          Operator facing reason. Logged; only ever
 *                                displayed to a logged in admin.
 * @param array  $context         Extra data for the logs.
 * @param string $customerMessage Wording shown to the customer. Must name no
 *                                path, table, setting or gateway response;
 *                                omitting it yields the generic fallback, not
 *                                the reason.
 *
 * @return void
 */
function paystation_redirect_abort(
    array $gatewayParams,
    $invoiceId,
    $code,
    $reason,
    array $context = [],
    $customerMessage = ''
) {
    $error = Helper::fail($gatewayParams, $code, $reason, array_merge([
        'context' => 'Initiate Payment',
        'invoice_id' => (int) $invoiceId,
    ], $context), $customerMessage);

    if ((int) $invoiceId > 0) {
        Helper::redirect(Helper::invoiceErrorUrl($invoiceId, $error));
    }

    Helper::renderErrorPage($error, Helper::maySeeDetail($gatewayParams), 400, 'The PayStation payment could not be started');
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
    $error = Helper::fail(
        $gatewayParams,
        'PS-NO-INVOICE',
        'The Pay Now submission carried no invoice id. This endpoint only accepts the signed form '
            . 'rendered on the invoice page.',
        ['context' => 'Initiate Payment', 'posted_keys' => array_keys($_POST)],
        'This payment link is incomplete. Please start again from your invoice.'
    );

    Helper::renderErrorPage($error, Helper::maySeeDetail($gatewayParams), 400, 'The PayStation payment could not be started');
}

if (!Helper::verifyPaymentToken($token, $invoiceId, $userId, $expires, $gatewayParams)) {
    // Either a forged request or a payment page that sat open too long.
    $expired = $expires > 0 && $expires < time();

    paystation_redirect_abort(
        $gatewayParams,
        $invoiceId,
        $expired ? 'PS-TOKEN-EXPIRED' : 'PS-TOKEN-INVALID',
        $expired
            ? ('The signed Pay Now token expired at ' . gmdate('Y-m-d H:i:s', $expires)
                . ' UTC (' . Helper::TOKEN_TTL . "s lifetime); the invoice page had been open too long.")
            : ('The signed Pay Now token did not verify. This happens when the Merchant ID or Merchant '
                . 'Password was changed after the invoice page was rendered, when the gateway is '
                . 'configured differently from the one that rendered the button, or when the request '
                . 'did not come from the invoice page at all.'),
        [
            'expires' => $expires,
            'expires_utc' => $expires > 0 ? gmdate('Y-m-d H:i:s', $expires) : '',
            'now_utc' => gmdate('Y-m-d H:i:s'),
            'user_id' => $userId,
            'token_present' => $token !== '',
        ],
        $expired
            ? 'This payment page expired before you pressed Pay Now. Please reload the invoice and try again.'
            : 'This payment request could not be verified. Please reload the invoice and try again.'
    );
}

// ---------------------------------------------------------------------------
// 2. Validate the invoice itself.
// ---------------------------------------------------------------------------

$invoiceLookupError = '';

try {
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
} catch (\Exception $e) {
    $invoice = null;
    $invoiceLookupError = $e->getMessage();
}

if (!$invoice) {
    paystation_redirect_abort(
        $gatewayParams,
        $invoiceId,
        $invoiceLookupError !== '' ? 'PS-DB-READ' : 'PS-INVOICE-MISSING',
        $invoiceLookupError !== ''
            ? ('Could not read invoice ' . $invoiceId . ' from tblinvoices: ' . $invoiceLookupError)
            : ('Invoice ' . $invoiceId . ' does not exist in tblinvoices.'),
        ['db_error' => $invoiceLookupError],
        'This invoice could not be loaded. Please contact support.'
    );
}

$invoice = (array) $invoice;

if ((int) $invoice['userid'] !== $userId) {
    paystation_redirect_abort(
        $gatewayParams,
        $invoiceId,
        'PS-INVOICE-OWNER',
        'Invoice ' . $invoiceId . ' belongs to client ' . (int) $invoice['userid']
            . ' but the signed request claimed client ' . $userId . '.',
        ['invoice_owner' => (int) $invoice['userid'], 'signed_user_id' => $userId],
        'This invoice is not available on your account. Please contact support.'
    );
}

if (in_array($invoice['status'], ['Cancelled', 'Draft', 'Refunded'], true)) {
    paystation_redirect_abort(
        $gatewayParams,
        $invoiceId,
        'PS-INVOICE-STATUS',
        'Invoice ' . $invoiceId . ' has status "' . $invoice['status'] . '", which cannot be paid.',
        ['invoice_status' => $invoice['status']],
        'This invoice is marked "' . $invoice['status'] . '" and cannot be paid. Please contact support.'
    );
}

if ($invoice['status'] === 'Paid') {
    Helper::redirect(Helper::invoiceUrl($invoiceId));
}

// The invoice does not always name its own currency - tblinvoices.currency is
// 0 on invoices WHMCS created without setting it - so fall back the way WHMCS
// does: the client's currency, then the site default.
$currencyLookup = Helper::resolveInvoiceCurrency($invoiceId, $invoice);
$currency = $currencyLookup['code'];

if ($currency === '') {
    paystation_redirect_abort(
        $gatewayParams,
        $invoiceId,
        'PS-CURRENCY',
        'Could not resolve a currency for invoice ' . $invoiceId . '. The invoice, the client account, '
            . 'the default currency and tblcurrencies itself were all checked and none produced a code. '
            . 'Add a currency under Configuration > System Settings > Currencies and set it on the client.',
        $currencyLookup['tried'],
        'The currency on this invoice could not be resolved. Please contact support.'
    );
}

if ($currencyLookup['source'] !== 'tblinvoices.currency') {
    // Not a failure, but worth a line: the code chosen here decides whether a
    // conversion rate is needed and what the invoice is credited with.
    Helper::logToFile(
        'PS-CURRENCY-FALLBACK',
        'Invoice ' . $invoiceId . ' does not name a currency of its own; using ' . $currency
            . ' from ' . $currencyLookup['source'] . '.',
        array_merge(['invoice_id' => $invoiceId], $currencyLookup['tried'])
    );
}

$balance = Helper::invoiceBalance($invoiceId);
$amounts = Helper::computeAmounts($balance, $currency, $gatewayParams);

if ($amounts['error'] !== '') {
    paystation_redirect_abort(
        $gatewayParams,
        $invoiceId,
        'PS-AMOUNT',
        $amounts['error'] . ' (balance ' . $balance . ' ' . $currency
            . ', surcharge ' . $amounts['surcharge'] . ', rate ' . $amounts['rate'] . ')',
        [
            'currency' => $currency,
            'balance' => $balance,
            'invoice_total' => isset($invoice['total']) ? $invoice['total'] : null,
            'conversion_rate_setting' => isset($gatewayParams['conversionRate']) ? $gatewayParams['conversionRate'] : '',
            'surcharge_percent_setting' => isset($gatewayParams['surchargePercent']) ? $gatewayParams['surchargePercent'] : '',
            'surcharge_fixed_setting' => isset($gatewayParams['surchargeFixed']) ? $gatewayParams['surchargeFixed'] : '',
        ],
        $amounts['customer_error']
    );
}

// ---------------------------------------------------------------------------
// 3. Gather the customer detail PayStation requires.
// ---------------------------------------------------------------------------

$clientLookupError = '';

try {
    $client = Capsule::table('tblclients')->where('id', $userId)->first();
} catch (\Exception $e) {
    $client = null;
    $clientLookupError = $e->getMessage();
}

if (!$client) {
    paystation_redirect_abort(
        $gatewayParams,
        $invoiceId,
        $clientLookupError !== '' ? 'PS-DB-READ' : 'PS-CLIENT-MISSING',
        $clientLookupError !== ''
            ? ('Could not read client ' . $userId . ' from tblclients: ' . $clientLookupError)
            : ('Client ' . $userId . ' does not exist in tblclients, so PayStation cannot be given a customer.'),
        ['user_id' => $userId, 'db_error' => $clientLookupError],
        'Your account details could not be loaded. Please contact support.'
    );
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
$customerPhone = Helper::normalisePhone($client['phonenumber']);

if ($customerPhone === '') {
    paystation_redirect_abort(
        $gatewayParams,
        $invoiceId,
        'PS-NO-PHONE',
        'Client ' . $userId . ' has no usable phone number; PayStation requires cust_phone. '
            . 'tblclients.phonenumber holds ' . strlen(trim((string) $client['phonenumber']))
            . ' characters and no digits at all.',
        ['user_id' => $userId, 'phonenumber' => (string) $client['phonenumber']],
        'PayStation requires a contact phone number. Please add one to your account details, then try again.'
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
        'PS-NO-CREDENTIALS',
        'PayStation credentials are not configured. Set the Merchant ID and Merchant Password under '
            . 'Setup > Payments > Payment Gateways > PayStation. Merchant ID '
            . (trim((string) $gatewayParams['merchantId']) === '' ? 'is empty' : 'is set') . ', Merchant Password '
            . (trim((string) $gatewayParams['password']) === '' ? 'is empty' : 'is set') . '.',
        ['sandbox' => !empty($gatewayParams['testMode'])],
        'This payment method is not fully configured yet. Please contact support.'
    );
}

if (!function_exists('curl_init')) {
    paystation_redirect_abort(
        $gatewayParams,
        $invoiceId,
        'PS-NO-CURL',
        'The PHP cURL extension is not loaded, so this server cannot reach PayStation at all.',
        ['php_version' => PHP_VERSION],
        'This payment method is temporarily unavailable. Please contact support.'
    );
}

$invoicePrefix = isset($gatewayParams['invoicePrefix']) ? $gatewayParams['invoicePrefix'] : '';
$debug = !empty($gatewayParams['debugLogging']);
$endpoint = $api->baseUrl() . '/initiate-payment';

$response = null;
$invoiceNumber = '';
$fields = [];
$attempt = 0;

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
            'PS-DB-WRITE',
            'Could not insert the PayStation ledger row into ' . Helper::TABLE . '. '
                . (Helper::lastInternalError() !== ''
                    ? Helper::lastInternalError()
                    : 'Check that the table exists and the WHMCS database user can write to it.'),
            [
                'table' => Helper::TABLE,
                'invoice_number' => $invoiceNumber,
                'db_error' => Helper::lastInternalError(),
            ],
            'The payment could not be started because it could not be recorded. Please contact support.'
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
            'endpoint' => $endpoint,
            'request' => $fields,
            'response' => $response['json'] ?: $response['raw'],
            'http_code' => $response['httpCode'],
            'curl_error' => $response['error'],
        ], $response['accepted'] ? 'Success' : 'Unsuccessful');

        // Mirrored into the module log file so a verbose trace survives the
        // WHMCS gateway log being switched off or pruned.
        Helper::logToFile('PS-TRACE', 'initiate-payment attempt ' . $attempt, [
            'invoice_id' => $invoiceId,
            'endpoint' => $endpoint,
            'request' => $fields,
            'http_code' => $response['httpCode'],
            'curl_error' => $response['error'],
            'response' => $response['json'] ?: $response['raw'],
        ]);
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

    // Three different things can land here, and telling them apart is the
    // whole point: the request never reached PayStation, PayStation answered
    // with a rejection, or PayStation accepted it but sent no checkout URL.
    if ($response['error'] !== '') {
        $code = 'PS-TRANSPORT';
        $reason = 'Could not complete the call to ' . $endpoint . ': ' . $response['error']
            . ' (HTTP ' . $response['httpCode'] . '). This is a connectivity problem on the web server '
            . '- outbound HTTPS, DNS or the CA bundle - not a PayStation decision.';
        $customerMessage = 'We could not reach PayStation to start your payment. Please try again in a moment.';
    } elseif (!$response['accepted']) {
        $code = 'PS-DECLINED';
        $reason = 'PayStation rejected the checkout request at ' . $endpoint . ' with status_code "'
            . $response['statusCode'] . '" and status "' . $response['status'] . '": '
            . ($response['message'] !== '' ? $response['message'] : '(no message returned)')
            . ' [HTTP ' . $response['httpCode'] . ']'
            . ($response['statusCode'] === Api::CODE_INVALID_TOKEN
                ? ' - status_code 2001 means the Merchant ID or Merchant Password is wrong for this '
                    . 'environment. Sandbox and production credentials are not interchangeable.'
                : '')
            . ($response['statusCode'] === Api::CODE_DUPLICATE_INVOICE
                ? ' - status_code 1008 means the invoice_number was already used; all '
                    . $attempt . ' attempts collided.'
                : '');
        // PayStation's own message is kept for the logs only. It reports on the
        // merchant account - "Invalid Credential", an inactive account, a
        // disabled channel - which is nothing a customer can act on and
        // nothing this site should publish.
        $customerMessage = 'PayStation could not start this payment. Please try again, or use another '
            . 'payment method if it keeps happening.';
    } else {
        $code = 'PS-NO-URL';
        $reason = 'PayStation accepted the request but returned no payment_url, so there is nowhere '
            . 'to send the customer. Response keys: ' . implode(', ', array_keys($response['json'])) . '.';
        $customerMessage = 'PayStation did not return a checkout page for this payment. Please contact support.';
    }

    paystation_redirect_abort($gatewayParams, $invoiceId, $code, $reason, [
        'invoice_number' => $invoiceNumber,
        'endpoint' => $endpoint,
        'sandbox' => !empty($gatewayParams['testMode']),
        'attempts' => $attempt,
        'http_code' => $response['httpCode'],
        'curl_error' => $response['error'],
        'paystation_status_code' => $response['statusCode'],
        'paystation_status' => $response['status'],
        'paystation_message' => $response['message'],
        'request' => isset($fields) ? $fields : [],
        'response' => $response['json'] ?: $response['raw'],
    ], $customerMessage);
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

    paystation_redirect_abort(
        $gatewayParams,
        $invoiceId,
        'PS-BAD-URL',
        'Refusing to redirect to "' . $paymentUrl . '": the checkout URL must be https and on a '
            . 'paystation.com.bd host, but the scheme was "' . $scheme . '" and the host was "'
            . (is_string($host) ? $host : '(none)') . '".',
        [
            'invoice_number' => $invoiceNumber,
            'payment_url' => $paymentUrl,
            'scheme' => $scheme,
            'host' => is_string($host) ? $host : '',
        ],
        'PayStation returned an unexpected checkout address, so the payment was stopped for your safety. '
            . 'Please contact support.'
    );
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

// The checkout started, so nothing from an earlier failed attempt should still
// be waiting to be shown when the customer comes back.
Helper::clearError();

Helper::redirect($paymentUrl);
