<?php
/**
 * PayStation callback handler.
 *
 * PayStation redirects the customer's browser here after the hosted checkout.
 * The published PayStation documentation does not define a signed callback
 * payload, so nothing that arrives here is trusted: the callback is only used
 * to work out WHICH transaction to look at, and the outcome is then fetched
 * server to server from the Transaction Status API before any payment is
 * applied.
 *
 * The same endpoint doubles as the PayStation IPN receiver, so this URL can be
 * given to PayStation as the merchant IPN URL. An IPN arrives as a POST with a
 * JSON body rather than as a browser redirect, and is answered with an HTTP
 * 2xx acknowledgement instead of a redirect - PayStation retries the
 * notification until it receives one.
 *
 * Safe to hit repeatedly - refreshes, duplicate notifications, IPN retries and
 * the cron reconciliation all converge on the same idempotent settlement
 * routine.
 *
 * @see https://developers.whmcs.com/payment-gateways/callbacks/
 * @see https://paystation.com.bd/documentation (Merchant IPN)
 *
 * @package WHMCS\Module\Gateway\Paystation
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../paystation/lib/loader.php';

use WHMCS\Module\Gateway\Paystation\Api;
use WHMCS\Module\Gateway\Paystation\Helper;

// Detect module name from filename, per the WHMCS sample callback.
$gatewayModuleName = basename(__FILE__, '.php');
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (empty($gatewayParams['type'])) {
    Helper::logToFile(
        'PS-CB-NOT-ACTIVATED',
        'A PayStation callback arrived but the gateway module is not activated in WHMCS, so the '
            . 'payment cannot be verified or applied.',
        ['query' => $_GET, 'post' => $_POST]
    );

    http_response_code(403);
    exit('Module Not Activated');
}

if (!Helper::ensureSchema()) {
    Helper::logToFile(
        'PS-CB-DB-SCHEMA',
        'A PayStation callback arrived but the ledger table ' . Helper::TABLE . ' is missing and '
            . 'could not be created. ' . Helper::lastInternalError(),
        ['table' => Helper::TABLE]
    );
}

/**
 * Every value PayStation sent us, whatever transport it chose.
 *
 * @return array
 */
function paystation_callback_payload()
{
    $payload = array_merge($_GET, $_POST);

    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = array_merge($payload, $decoded);
            if (isset($decoded['data']) && is_array($decoded['data'])) {
                $payload = array_merge($payload, $decoded['data']);
            }
        }
    }

    return $payload;
}

/**
 * First non-empty scalar value among a set of candidate keys.
 *
 * PayStation has not published a fixed callback schema, so several spellings
 * are accepted rather than guessing one.
 *
 * @param array $payload
 * @param array $keys
 *
 * @return string
 */
function paystation_callback_value(array $payload, array $keys)
{
    foreach ($keys as $key) {
        if (isset($payload[$key]) && is_scalar($payload[$key]) && trim((string) $payload[$key]) !== '') {
            return trim((string) $payload[$key]);
        }
    }

    return '';
}

/**
 * True when this request is PayStation's server to server IPN rather than the
 * customer's browser coming back from the hosted checkout.
 *
 * The browser return is a GET carrying query parameters; the IPN is a POST
 * with a JSON body. The two need different answers: a browser wants a
 * redirect, while the IPN wants an HTTP 2xx acknowledgement and will keep
 * retrying until it gets one.
 *
 * @return bool
 */
function paystation_callback_is_ipn()
{
    static $isIpn = null;

    if ($isIpn !== null) {
        return $isIpn;
    }

    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
    if ($method !== 'POST') {
        return $isIpn = false;
    }

    $contentType = '';
    foreach (['CONTENT_TYPE', 'HTTP_CONTENT_TYPE'] as $key) {
        if (!empty($_SERVER[$key])) {
            $contentType = strtolower((string) $_SERVER[$key]);
            break;
        }
    }

    return $isIpn = (strpos($contentType, 'application/json') !== false);
}

/**
 * Acknowledge the IPN and stop.
 *
 * PayStation retries the notification on any non 2xx response, so a definite
 * outcome - including a payment that failed at the wallet - is acknowledged
 * with a 200. Only a condition that a later retry could genuinely resolve is
 * answered with an error status.
 *
 * @param int    $httpStatus
 * @param string $status     Gateway log status, echoed back for the merchant.
 *
 * @return void
 */
function paystation_callback_acknowledge($httpStatus, $status)
{
    $httpStatus = (int) $httpStatus;

    if (!headers_sent()) {
        http_response_code($httpStatus);
        header('Content-Type: application/json');
    }

    echo json_encode([
        'status' => ($httpStatus >= 200 && $httpStatus < 300) ? 'success' : 'error',
        'result' => (string) $status,
    ]);

    exit;
}

/**
 * Log the outcome, then either acknowledge the IPN or send the customer back
 * to their invoice.
 *
 * A failure is additionally recorded through Helper::fail(), which writes it to
 * the module log file, the gateway log and the activity log, and hands it to
 * the invoice page so the customer is told what actually went wrong instead of
 * the generic WHMCS "your payment attempt was not successful" banner.
 *
 * @param array  $gatewayParams
 * @param array  $payload
 * @param string $status     Gateway log status column.
 * @param array  $detail     Extra context for the gateway log.
 * @param int    $invoiceId  Zero when unknown.
 * @param string $flag       WHMCS result flag, e.g. paymentsuccess.
 * @param int    $ipnStatus  HTTP status used when answering an IPN.
 * @param string $errorCode  Set on a failure, e.g. PS-CB-DECLINED. Switches
 *                           the redirect to the module's own error display.
 * @param string $customerMessage Wording shown to the customer on a failure.
 *
 * @return void
 */
function paystation_callback_finish(
    array $gatewayParams,
    array $payload,
    $status,
    array $detail,
    $invoiceId,
    $flag,
    $ipnStatus = 200,
    $errorCode = '',
    $customerMessage = ''
) {
    $context = paystation_callback_is_ipn() ? 'IPN' : 'Callback';

    if ($errorCode !== '') {
        $error = Helper::fail(
            $gatewayParams,
            $errorCode,
            isset($detail['reason']) ? (string) $detail['reason'] : $status,
            array_merge([
                'context' => $context,
                'invoice_id' => (int) $invoiceId,
                'callback_payload' => $payload,
            ], $detail),
            $customerMessage
        );

        if (paystation_callback_is_ipn()) {
            paystation_callback_acknowledge($ipnStatus, $status);
        }

        if ((int) $invoiceId > 0) {
            Helper::redirect(Helper::invoiceErrorUrl($invoiceId, $error));
        }

        Helper::renderErrorPage($error, Helper::maySeeDetail($gatewayParams), 200);
    }

    Helper::log($gatewayParams, array_merge([
        'context' => $context,
        'callback_payload' => $payload,
    ], $detail), $status);

    if (paystation_callback_is_ipn()) {
        paystation_callback_acknowledge($ipnStatus, $status);
    }

    // Nothing went wrong, so clear anything a previous attempt left waiting.
    Helper::clearError();

    if ((int) $invoiceId > 0) {
        Helper::redirect(Helper::invoiceUrl($invoiceId, $flag));
    }

    Helper::redirect(Helper::systemUrl() . '/clientarea.php?action=invoices');
}

$payload = paystation_callback_payload();

if (!$payload) {
    Helper::logToFile(
        'PS-CB-EMPTY',
        'A PayStation callback arrived with no data at all, so there is nothing to identify a '
            . 'transaction by.',
        ['method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '']
    );

    http_response_code(400);
    exit('No callback data received.');
}

// ---------------------------------------------------------------------------
// 1. Identify the transaction this callback is about.
// ---------------------------------------------------------------------------

$invoiceNumber = paystation_callback_value($payload, [
    'invoice_number',
    'invoiceNumber',
    'invoice_no',
    'invoiceno',
    'invoice',
    'inv',
    'order_id',
    // The module also sends the invoice number as the reference, so this is a
    // reliable fallback if PayStation only echoes the reference back.
    'reference',
    'ref',
]);

$reportedTrxId = paystation_callback_value($payload, [
    'trx_id',
    'trxId',
    'trxid',
    'transaction_id',
    'transactionId',
    'txn_id',
    'tran_id',
]);

$api = new Api($gatewayParams['merchantId'], $gatewayParams['password'], !empty($gatewayParams['testMode']));

if (!$api->isConfigured()) {
    paystation_callback_finish(
        $gatewayParams,
        $payload,
        'Configuration Error',
        [
            'reason' => 'PayStation credentials are not configured, so the callback cannot verify '
                . 'this payment server to server. Set the Merchant ID and Merchant Password under '
                . 'Setup > Payments > Payment Gateways > PayStation.',
        ],
        0,
        '',
        // Fixing the configuration makes a retried IPN succeed, so do not
        // acknowledge this one as handled.
        503,
        'PS-CB-NO-CREDENTIALS',
        'Your payment could not be confirmed because PayStation is not fully configured here. '
            . 'Please contact support before paying again.'
    );
}

$transaction = Helper::findTransaction($invoiceNumber);

// The browser may only carry the transaction id. Resolve it through the ledger
// first, then through the v2 status API.
if (!$transaction && $reportedTrxId !== '') {
    $transaction = Helper::findTransactionByTrxId($reportedTrxId);

    if (!$transaction) {
        $lookup = $api->transactionStatusV2($reportedTrxId);
        if ($lookup['accepted']) {
            $resolved = Helper::extractStatusData($lookup);
            if ($resolved['invoice_number'] !== '') {
                $invoiceNumber = $resolved['invoice_number'];
                $transaction = Helper::findTransaction($invoiceNumber);
            }
        }
    }
}

if (!$transaction) {
    // Last resort: the WHMCS invoice id is the leading segment of our invoice
    // number, so the customer can at least be returned to the right page.
    $guessedInvoiceId = Helper::extractInvoiceId(
        $invoiceNumber,
        isset($gatewayParams['invoicePrefix']) ? $gatewayParams['invoicePrefix'] : ''
    );

    paystation_callback_finish(
        $gatewayParams,
        $payload,
        'Unsuccessful',
        [
            'reason' => 'No row in ' . Helper::TABLE . ' matches this callback. Looked up invoice_number "'
                . $invoiceNumber . '" and trx_id "' . $reportedTrxId . '". Either the checkout was '
                . 'started somewhere other than this WHMCS install, or the ledger row was removed.',
            'invoice_number' => $invoiceNumber,
            'trx_id' => $reportedTrxId,
            'callback_keys' => array_keys($payload),
        ],
        $guessedInvoiceId,
        '',
        200,
        'PS-CB-NO-MATCH',
        'We could not match this payment to an order on your account. '
            . 'If money left your account, please contact support with the reference below.'
    );
}

$invoiceNumber = (string) $transaction['invoice_number'];
$invoiceId = (int) $transaction['invoice_id'];

// ---------------------------------------------------------------------------
// 2. Verify the outcome server to server. The browser is never believed.
// ---------------------------------------------------------------------------

$verification = Helper::verifyTransaction($api, $invoiceNumber, $reportedTrxId);

Helper::updateTransaction($invoiceNumber, [
    'verify_attempts' => (int) $transaction['verify_attempts'] + 1,
]);

if (!empty($gatewayParams['debugLogging'])) {
    Helper::log($gatewayParams, [
        'context' => 'Verification Request',
        'invoice_number' => $invoiceNumber,
        'reported_trx_id' => $reportedTrxId,
        'http_code' => $verification['httpCode'],
        'curl_error' => $verification['error'],
        'response' => $verification['json'] ?: $verification['raw'],
    ], $verification['accepted'] ? 'Success' : 'Unsuccessful');
}

if (!$verification['accepted']) {
    // Could not confirm the payment. Leave the row pending so the cron
    // reconciliation retries it rather than losing a real payment here.
    paystation_callback_finish(
        $gatewayParams,
        $payload,
        'Verification Failed',
        [
            'reason' => $verification['error'] !== ''
                ? ('Could not reach the PayStation transaction-status API: ' . $verification['error']
                    . ' (HTTP ' . $verification['httpCode'] . '). The payment itself may well have '
                    . 'succeeded; cron reconciliation will retry.')
                : ('The PayStation transaction-status API answered status_code "'
                    . $verification['statusCode'] . '" status "' . $verification['status'] . '": '
                    . ($verification['message'] !== '' ? $verification['message'] : '(no message)')
                    . '. The payment itself may well have succeeded; cron reconciliation will retry.'),
            'invoice_number' => $invoiceNumber,
            'http_code' => $verification['httpCode'],
            'curl_error' => $verification['error'],
            'paystation_status_code' => $verification['statusCode'],
            'paystation_message' => $verification['message'],
            'response' => $verification['json'] ?: $verification['raw'],
        ],
        $invoiceId,
        '',
        // The lookup, not the payment, is what failed. Settlement is
        // idempotent, so let PayStation retry alongside the cron pass.
        503,
        'PS-CB-UNVERIFIED',
        'We could not confirm this payment with PayStation yet. If it was taken, it will be applied '
            . 'automatically within a few minutes - please do not pay twice.'
    );
}

$statusData = Helper::extractStatusData($verification);

// ---------------------------------------------------------------------------
// 3. Apply the payment.
// ---------------------------------------------------------------------------

// checkCbInvoiceID() performs a die() on an unknown invoice, which would leave
// the customer staring at a bare error string. Catch that case first so the
// transaction is logged for review and the customer lands somewhere useful.
if (!Helper::invoiceExists($invoiceId)) {
    Helper::updateTransaction($invoiceNumber, ['status' => 'orphaned']);

    paystation_callback_finish(
        $gatewayParams,
        $payload,
        'Unsuccessful',
        [
            'reason' => 'WHMCS invoice ' . $invoiceId . ' no longer exists, so a settled PayStation '
                . 'payment has nowhere to go. The ledger row was marked orphaned and needs manual review.',
            'invoice_number' => $invoiceNumber,
            'trx_status' => $statusData['trx_status'],
            'trx_id' => $statusData['trx_id'],
        ],
        0,
        '',
        200,
        'PS-CB-ORPHANED',
        'This payment no longer has a matching invoice. Please contact support with the reference below.'
    );
}

/**
 * Validate the invoice id. Counts an invoice in any status as valid and
 * performs a die() on an invalid id.
 */
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

$candidateTrxId = $statusData['trx_id'] !== ''
    ? $statusData['trx_id']
    : Helper::MODULE_NAME . '-' . $invoiceNumber;

/**
 * Guard against duplicate transaction ids. Pre-checked so that a customer
 * refreshing the callback is redirected to their paid invoice instead of
 * hitting the die() inside checkCbTransID().
 */
if ($statusData['trx_status'] === 'success'
    && empty($transaction['applied'])
    && !Helper::paymentAlreadyRecorded($candidateTrxId)
) {
    checkCbTransID($candidateTrxId);
}

$outcome = Helper::settle($gatewayParams, $transaction, $statusData);

$detail = [
    'invoice_id' => $invoiceId,
    'invoice_number' => $invoiceNumber,
    'trx_id' => $outcome['trxId'],
    'trx_status' => $outcome['status'],
    'payment_method' => $statusData['payment_method'],
    'payer_mobile_no' => $statusData['payer_mobile_no'],
    'gateway_amount_expected' => $transaction['gateway_amount'] . ' ' . Helper::GATEWAY_CURRENCY,
    'gateway_amount_reported' => $statusData['amount'],
    'invoice_amount_credited' => $outcome['applied']
        ? ($transaction['invoice_amount'] . ' ' . $transaction['invoice_currency'])
        : null,
    'order_date_time' => $statusData['order_date_time'],
    'result' => $outcome['reason'],
];

if ($outcome['status'] === 'success') {
    paystation_callback_finish($gatewayParams, $payload, 'Success', $detail, $invoiceId, 'paymentsuccess');
}

if ($outcome['status'] === 'processing') {
    // Still in flight at PayStation. Cron reconciliation will settle it.
    paystation_callback_finish($gatewayParams, $payload, 'Pending', $detail, $invoiceId, '');
}

// Nothing was applied. Either PayStation said no, or the verified transaction
// did not match what this invoice asked for - which is a much more serious
// condition, because money may well have left the customer's account.
$detail['reason'] = 'PayStation reported trx_status "' . $outcome['status'] . '" for invoice_number '
    . $invoiceNumber . ($outcome['reason'] !== '' ? ' (' . $outcome['reason'] . ')' : '')
    . '. No payment was applied to invoice ' . $invoiceId . '.';

$mismatched = in_array($outcome['status'], ['mismatch', 'orphaned'], true);

paystation_callback_finish(
    $gatewayParams,
    $payload,
    $mismatched ? 'Verification Failed' : 'Unsuccessful',
    $detail,
    $invoiceId,
    '',
    200,
    $mismatched ? 'PS-CB-MISMATCH' : 'PS-CB-DECLINED',
    $mismatched
        ? ('This payment could not be matched to your invoice'
            . ($outcome['reason'] !== '' ? ': ' . $outcome['reason'] : '.')
            . ' Please do not pay again - contact support with the reference below.')
        : ('PayStation did not complete this payment (' . $outcome['status'] . ')'
            . ($outcome['reason'] !== '' ? ': ' . $outcome['reason'] : '.')
            . ' Nothing has been charged to your invoice. Please try again or use another method.')
);
