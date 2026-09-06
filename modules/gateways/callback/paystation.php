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
 * Safe to hit repeatedly - refreshes, duplicate notifications and the cron
 * reconciliation all converge on the same idempotent settlement routine.
 *
 * @see https://developers.whmcs.com/payment-gateways/callbacks/
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
    http_response_code(403);
    exit('Module Not Activated');
}

Helper::ensureSchema();

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
 * Log the outcome and send the customer back to their invoice.
 *
 * @param array  $gatewayParams
 * @param array  $payload
 * @param string $status    Gateway log status column.
 * @param array  $detail    Extra context for the gateway log.
 * @param int    $invoiceId Zero when unknown.
 * @param string $flag      WHMCS result flag, e.g. paymentsuccess.
 *
 * @return void
 */
function paystation_callback_finish(array $gatewayParams, array $payload, $status, array $detail, $invoiceId, $flag)
{
    Helper::log($gatewayParams, array_merge([
        'context' => 'Callback',
        'callback_payload' => $payload,
    ], $detail), $status);

    if ((int) $invoiceId > 0) {
        Helper::redirect(Helper::invoiceUrl($invoiceId, $flag));
    }

    Helper::redirect(Helper::systemUrl() . '/clientarea.php?action=invoices');
}

$payload = paystation_callback_payload();

if (!$payload) {
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
        ['reason' => 'PayStation credentials are not configured.'],
        0,
        ''
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
            'reason' => 'No local PayStation transaction matches this callback.',
            'invoice_number' => $invoiceNumber,
            'trx_id' => $reportedTrxId,
        ],
        $guessedInvoiceId,
        $guessedInvoiceId > 0 ? 'paymentfailed' : ''
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
                ? $verification['error']
                : ('PayStation status lookup returned status_code ' . $verification['statusCode']
                    . ': ' . $verification['message']),
            'invoice_number' => $invoiceNumber,
            'invoice_id' => $invoiceId,
        ],
        $invoiceId,
        'paymentfailed'
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
            'reason' => 'WHMCS invoice ' . $invoiceId . ' no longer exists; payment needs manual review.',
            'invoice_number' => $invoiceNumber,
            'trx_status' => $statusData['trx_status'],
            'trx_id' => $statusData['trx_id'],
        ],
        0,
        ''
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

paystation_callback_finish($gatewayParams, $payload, 'Unsuccessful', $detail, $invoiceId, 'paymentfailed');
