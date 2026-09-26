<?php
/**
 * Shared helpers for the PayStation WHMCS gateway module.
 *
 * Holds everything the gateway file, the redirect endpoint, the callback file
 * and the cron reconciliation hook all need: the local transaction ledger, the
 * amount/currency maths, the signed redirect token, and the single settlement
 * routine that is the only place an invoice is ever marked as paid.
 *
 * @package WHMCS\Module\Gateway\Paystation
 */

namespace WHMCS\Module\Gateway\Paystation;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Helper
{
    /** WHMCS gateway module name (must match the gateway filename). */
    const MODULE_NAME = 'paystation';

    /** Local transaction ledger table. */
    const TABLE = 'mod_paystation_transactions';

    /** Currency PayStation settles in. */
    const GATEWAY_CURRENCY = 'BDT';

    /** Lifetime of a signed "Pay Now" token, in seconds. */
    const TOKEN_TTL = 1800;

    /** Tolerance used when comparing gateway amounts against our own. */
    const AMOUNT_EPSILON = 0.01;

    /** Pending transactions younger than this are not reconciled yet. */
    const RECONCILE_MIN_AGE = 180;

    /** Pending transactions older than this are abandoned. */
    const RECONCILE_MAX_AGE = 259200;

    /**
     * Make sure the WHMCS gateway helper functions are loaded.
     *
     * The gateway and callback files include them already, but the cron hook
     * runs in a context where they may not be present.
     *
     * @return void
     */
    public static function bootstrapGatewayFunctions()
    {
        if (!defined('ROOTDIR')) {
            return;
        }
        if (!function_exists('getGatewayVariables') || !function_exists('logTransaction')) {
            require_once ROOTDIR . '/includes/gatewayfunctions.php';
        }
        if (!function_exists('addInvoicePayment')) {
            require_once ROOTDIR . '/includes/invoicefunctions.php';
        }
    }

    /**
     * Create the local transaction ledger if it does not exist yet.
     *
     * Called lazily from every entry point so that the module works whether it
     * was activated through the admin area or deployed by file copy.
     *
     * @return bool True when the table is usable.
     */
    public static function ensureSchema()
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        try {
            if (!Capsule::schema()->hasTable(self::TABLE)) {
                Capsule::schema()->create(self::TABLE, function ($table) {
                    $table->increments('id');
                    $table->string('invoice_number', 100)->unique();
                    $table->unsignedInteger('invoice_id')->index();
                    $table->unsignedInteger('user_id')->default(0);
                    $table->string('status', 20)->default('pending')->index();
                    $table->string('invoice_currency', 10)->default('');
                    $table->decimal('invoice_amount', 16, 2)->default(0);
                    $table->string('gateway_currency', 10)->default(self::GATEWAY_CURRENCY);
                    $table->decimal('gateway_amount', 16, 2)->default(0);
                    $table->decimal('surcharge', 16, 2)->default(0);
                    $table->decimal('conversion_rate', 16, 6)->default(1);
                    $table->string('trx_id', 100)->nullable()->index();
                    $table->string('payment_method', 60)->nullable();
                    $table->string('payer_mobile', 60)->nullable();
                    $table->text('payment_url')->nullable();
                    $table->text('last_response')->nullable();
                    $table->boolean('applied')->default(false);
                    $table->unsignedInteger('verify_attempts')->default(0);
                    $table->dateTime('created_at');
                    $table->dateTime('updated_at');
                });
            }
            $ready = true;
        } catch (\Exception $e) {
            $ready = false;
        }

        return $ready;
    }

    /**
     * Absolute WHMCS system URL with no trailing slash.
     *
     * @return string
     */
    public static function systemUrl()
    {
        $url = '';

        if (class_exists('\\App') && method_exists('\\App', 'getSystemURL')) {
            $url = (string) \App::getSystemURL();
        }

        if ($url === '' && isset($GLOBALS['CONFIG']['SystemURL'])) {
            $url = (string) $GLOBALS['CONFIG']['SystemURL'];
        }

        return rtrim($url, '/');
    }

    /**
     * URL PayStation returns the customer to.
     *
     * @return string
     */
    public static function callbackUrl()
    {
        return self::systemUrl() . '/modules/gateways/callback/' . self::MODULE_NAME . '.php';
    }

    /**
     * URL of the local endpoint that creates the checkout session.
     *
     * @return string
     */
    public static function redirectEndpointUrl()
    {
        return self::systemUrl() . '/modules/gateways/' . self::MODULE_NAME . '/redirect.php';
    }

    /**
     * Client area URL for an invoice.
     *
     * @param int    $invoiceId
     * @param string $flag      Optional WHMCS result flag, e.g. paymentsuccess.
     *
     * @return string
     */
    public static function invoiceUrl($invoiceId, $flag = '')
    {
        $url = self::systemUrl() . '/viewinvoice.php?id=' . (int) $invoiceId;
        if ($flag !== '') {
            $url .= '&' . $flag . '=true';
        }

        return $url;
    }

    /**
     * Build a unique PayStation invoice_number for a WHMCS invoice.
     *
     * PayStation rejects a re-used invoice_number with status_code 1008, and a
     * single WHMCS invoice can legitimately be attempted many times (failed
     * card, abandoned checkout, retry). The current Unix timestamp is therefore
     * appended to the WHMCS invoice id on every attempt, and a short counter is
     * added if two attempts land inside the same second.
     *
     * @param int    $invoiceId
     * @param string $prefix    Optional merchant prefix.
     *
     * @return string
     */
    public static function generateInvoiceNumber($invoiceId, $prefix = '')
    {
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $prefix);
        $base = $prefix . (int) $invoiceId . '-' . time();

        $candidate = $base;
        for ($suffix = 1; $suffix <= 20; $suffix++) {
            if (!self::invoiceNumberExists($candidate)) {
                return $candidate;
            }
            $candidate = $base . '-' . $suffix;
        }

        // Practically unreachable, but never hand back a known duplicate.
        return $base . '-' . substr(str_replace('.', '', uniqid('', true)), -6);
    }

    /**
     * Recover the WHMCS invoice id embedded in a PayStation invoice_number.
     *
     * Only used as a fallback: the ledger lookup is authoritative.
     *
     * @param string $invoiceNumber
     * @param string $prefix
     *
     * @return int Zero when the value cannot be parsed.
     */
    public static function extractInvoiceId($invoiceNumber, $prefix = '')
    {
        $value = (string) $invoiceNumber;
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $prefix);

        if ($prefix !== '' && strpos($value, $prefix) === 0) {
            $value = substr($value, strlen($prefix));
        }

        if (preg_match('/^(\d+)-\d+/', $value, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Sign a "Pay Now" request so redirect.php only creates checkout sessions
     * that WHMCS itself rendered a button for.
     *
     * @param int    $invoiceId
     * @param int    $userId
     * @param int    $expires   Unix timestamp.
     * @param array  $gatewayParams
     *
     * @return string
     */
    public static function paymentToken($invoiceId, $userId, $expires, array $gatewayParams)
    {
        $secret = (isset($gatewayParams['merchantId']) ? $gatewayParams['merchantId'] : '')
            . '|' . (isset($gatewayParams['password']) ? $gatewayParams['password'] : '');

        $payload = implode('|', [
            self::MODULE_NAME,
            (int) $invoiceId,
            (int) $userId,
            (int) $expires,
        ]);

        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Constant time verification of a "Pay Now" token.
     *
     * @param string $token
     * @param int    $invoiceId
     * @param int    $userId
     * @param int    $expires
     * @param array  $gatewayParams
     *
     * @return bool
     */
    public static function verifyPaymentToken($token, $invoiceId, $userId, $expires, array $gatewayParams)
    {
        if ((int) $expires < time()) {
            return false;
        }

        $expected = self::paymentToken($invoiceId, $userId, $expires, $gatewayParams);

        return hash_equals($expected, (string) $token);
    }

    /**
     * Normalise a PayStation trx_status value.
     *
     * PayStation is inconsistent about casing ("Success", "success", "Failed"),
     * so everything is lower cased and trimmed before comparison.
     *
     * @param string $status
     *
     * @return string One of success, processing, failed, refund or unknown.
     */
    public static function normaliseTrxStatus($status)
    {
        $status = strtolower(trim((string) $status));

        $known = ['success', 'processing', 'failed', 'refund'];
        if (in_array($status, $known, true)) {
            return $status;
        }

        if ($status === 'successful' || $status === 'completed' || $status === 'paid') {
            return 'success';
        }
        if ($status === 'pending' || $status === 'initiated') {
            return 'processing';
        }
        if ($status === 'refunded') {
            return 'refund';
        }
        if ($status === 'cancel' || $status === 'cancelled' || $status === 'canceled') {
            return 'failed';
        }

        return $status === '' ? 'unknown' : $status;
    }

    /**
     * Reduce a WHMCS stored phone number to the digits PayStation expects.
     *
     * WHMCS stores numbers such as "+880.1712345678"; PayStation expects a
     * local Bangladeshi format such as "01712345678".
     *
     * Lives here rather than in redirect.php so the payment button can apply
     * exactly the same test before it is rendered.
     *
     * @param string $phone
     *
     * @return string Empty when nothing usable remains.
     */
    public static function normalisePhone($phone)
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
     * Work out what to charge at PayStation and what to credit in WHMCS.
     *
     * WHMCS is always credited with the invoice-currency balance. Any merchant
     * surcharge and any BDT conversion only affect the amount sent to the
     * gateway, never the amount applied to the invoice.
     *
     * @param float  $dueAmount     Invoice balance in the invoice currency.
     * @param string $currencyCode  Invoice currency code.
     * @param array  $gatewayParams Gateway configuration.
     *
     * @return array {
     *     @var float  $invoice_amount  Amount to credit to the WHMCS invoice.
     *     @var float  $surcharge       Merchant surcharge, invoice currency.
     *     @var float  $chargeable      invoice_amount + surcharge.
     *     @var float  $rate            Conversion rate applied.
     *     @var float  $gateway_amount  Amount to send to PayStation, in BDT.
     *     @var string $error           Non-empty when the amounts are unusable.
     * }
     */
    public static function computeAmounts($dueAmount, $currencyCode, array $gatewayParams)
    {
        $result = [
            'invoice_amount' => 0.0,
            'surcharge' => 0.0,
            'chargeable' => 0.0,
            'rate' => 1.0,
            'gateway_amount' => 0.0,
            'error' => '',
        ];

        $invoiceAmount = round((float) $dueAmount, 2);
        if ($invoiceAmount <= 0) {
            $result['error'] = 'There is nothing left to pay on this invoice.';

            return $result;
        }

        $percent = isset($gatewayParams['surchargePercent']) ? (float) $gatewayParams['surchargePercent'] : 0.0;
        $fixed = isset($gatewayParams['surchargeFixed']) ? (float) $gatewayParams['surchargeFixed'] : 0.0;
        $surcharge = round(($invoiceAmount * $percent / 100) + $fixed, 2);
        if ($surcharge < 0) {
            $surcharge = 0.0;
        }

        $chargeable = round($invoiceAmount + $surcharge, 2);

        $currencyCode = strtoupper(trim((string) $currencyCode));
        $rate = 1.0;
        if ($currencyCode !== self::GATEWAY_CURRENCY) {
            $rate = isset($gatewayParams['conversionRate']) ? (float) $gatewayParams['conversionRate'] : 0.0;
            if ($rate <= 0) {
                $result['error'] = 'PayStation settles in ' . self::GATEWAY_CURRENCY . '. Set a conversion rate for '
                    . $currencyCode . ' in the gateway configuration.';

                return $result;
            }
        }

        $gatewayAmount = round($chargeable * $rate, 2);
        if ($gatewayAmount <= 0) {
            $result['error'] = 'The calculated PayStation amount is not greater than zero.';

            return $result;
        }

        $result['invoice_amount'] = $invoiceAmount;
        $result['surcharge'] = $surcharge;
        $result['chargeable'] = $chargeable;
        $result['rate'] = $rate;
        $result['gateway_amount'] = $gatewayAmount;

        return $result;
    }

    /**
     * Outstanding balance of an invoice in its own currency.
     *
     * @param int $invoiceId
     *
     * @return float
     */
    public static function invoiceBalance($invoiceId)
    {
        try {
            $total = (float) Capsule::table('tblinvoices')
                ->where('id', (int) $invoiceId)
                ->value('total');

            $received = (float) Capsule::table('tblaccounts')
                ->where('invoiceid', (int) $invoiceId)
                ->sum('amountin');

            $refunded = (float) Capsule::table('tblaccounts')
                ->where('invoiceid', (int) $invoiceId)
                ->sum('amountout');

            return round($total - ($received - $refunded), 2);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    /**
     * True when the ledger already holds this invoice number.
     *
     * @param string $invoiceNumber
     *
     * @return bool
     */
    public static function invoiceNumberExists($invoiceNumber)
    {
        if (!self::ensureSchema()) {
            return false;
        }

        try {
            return Capsule::table(self::TABLE)->where('invoice_number', (string) $invoiceNumber)->exists();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Insert a pending ledger row.
     *
     * @param array $attributes
     *
     * @return bool
     */
    public static function createTransaction(array $attributes)
    {
        if (!self::ensureSchema()) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $attributes['created_at'] = $now;
        $attributes['updated_at'] = $now;

        try {
            Capsule::table(self::TABLE)->insert($attributes);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Update a ledger row by invoice number.
     *
     * @param string $invoiceNumber
     * @param array  $attributes
     *
     * @return bool
     */
    public static function updateTransaction($invoiceNumber, array $attributes)
    {
        if (!self::ensureSchema()) {
            return false;
        }

        $attributes['updated_at'] = date('Y-m-d H:i:s');

        try {
            Capsule::table(self::TABLE)
                ->where('invoice_number', (string) $invoiceNumber)
                ->update($attributes);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Fetch a ledger row by invoice number.
     *
     * @param string $invoiceNumber
     *
     * @return array|null
     */
    public static function findTransaction($invoiceNumber)
    {
        if ((string) $invoiceNumber === '' || !self::ensureSchema()) {
            return null;
        }

        try {
            $row = Capsule::table(self::TABLE)->where('invoice_number', (string) $invoiceNumber)->first();
        } catch (\Exception $e) {
            return null;
        }

        return $row ? (array) $row : null;
    }

    /**
     * Fetch a ledger row by PayStation transaction id.
     *
     * @param string $trxId
     *
     * @return array|null
     */
    public static function findTransactionByTrxId($trxId)
    {
        if ((string) $trxId === '' || !self::ensureSchema()) {
            return null;
        }

        try {
            $row = Capsule::table(self::TABLE)->where('trx_id', (string) $trxId)->first();
        } catch (\Exception $e) {
            return null;
        }

        return $row ? (array) $row : null;
    }

    /**
     * True when WHMCS already holds a payment with this transaction id.
     *
     * This is the idempotency guard: a customer refreshing the callback URL, a
     * duplicate gateway notification and the cron reconciliation must never
     * credit the same money twice.
     *
     * @param string $trxId
     *
     * @return bool
     */
    public static function paymentAlreadyRecorded($trxId)
    {
        if ((string) $trxId === '') {
            return false;
        }

        try {
            return Capsule::table('tblaccounts')->where('transid', (string) $trxId)->exists();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Pull the transaction detail out of a v1 or v2 status response.
     *
     * The two endpoints return slightly different field sets, so this maps both
     * onto one shape.
     *
     * @param array $response Normalised Api response.
     *
     * @return array
     */
    public static function extractStatusData(array $response)
    {
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];

        $requested = null;
        foreach (['request_amount', 'payment_amount', 'trx_amount'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $requested = (float) $data[$field];
                break;
            }
        }

        return [
            'invoice_number' => isset($data['invoice_number']) ? (string) $data['invoice_number'] : '',
            'trx_status' => self::normaliseTrxStatus(isset($data['trx_status']) ? $data['trx_status'] : ''),
            'trx_id' => isset($data['trx_id']) ? trim((string) $data['trx_id']) : '',
            'amount' => $requested,
            'payment_method' => isset($data['payment_method']) ? (string) $data['payment_method'] : '',
            'payer_mobile_no' => isset($data['payer_mobile_no']) ? (string) $data['payer_mobile_no'] : '',
            'reference' => isset($data['reference']) ? (string) $data['reference'] : '',
            'order_date_time' => isset($data['order_date_time']) ? (string) $data['order_date_time'] : '',
        ];
    }

    /**
     * Verify a transaction against PayStation, preferring v1 by invoice number
     * and falling back to v2 by transaction id.
     *
     * @param Api    $api
     * @param string $invoiceNumber
     * @param string $trxId Optional transaction id reported by the browser.
     *
     * @return array Normalised Api response.
     */
    public static function verifyTransaction(Api $api, $invoiceNumber, $trxId = '')
    {
        $response = $api->transactionStatus($invoiceNumber);

        if ($response['accepted'] && !empty($response['data'])) {
            return $response;
        }

        if ((string) $trxId !== '') {
            $v2 = $api->transactionStatusV2($trxId);
            if ($v2['accepted'] && !empty($v2['data'])) {
                return $v2;
            }
        }

        return $response;
    }

    /**
     * Apply a verified PayStation transaction to its WHMCS invoice.
     *
     * This is the ONLY place the module records a payment. It is safe to call
     * repeatedly for the same transaction and never trusts anything that came
     * from the customer's browser - all inputs come from a server to server
     * status lookup plus the local ledger row written before the redirect.
     *
     * @param array $gatewayParams Gateway configuration.
     * @param array $transaction   Local ledger row.
     * @param array $statusData    Output of extractStatusData().
     *
     * @return array {
     *     @var bool   $applied Whether a payment was added on this call.
     *     @var string $status  Normalised transaction status.
     *     @var string $trxId   Transaction id used for the payment record.
     *     @var string $reason  Human readable outcome, for the gateway log.
     * }
     */
    public static function settle(array $gatewayParams, array $transaction, array $statusData)
    {
        self::bootstrapGatewayFunctions();

        $invoiceNumber = (string) $transaction['invoice_number'];
        $invoiceId = (int) $transaction['invoice_id'];
        $status = $statusData['trx_status'];

        $trxId = $statusData['trx_id'] !== ''
            ? $statusData['trx_id']
            : self::MODULE_NAME . '-' . $invoiceNumber;

        $ledger = [
            'status' => $status,
            'trx_id' => $trxId,
            'payment_method' => $statusData['payment_method'] !== '' ? $statusData['payment_method'] : null,
            'payer_mobile' => $statusData['payer_mobile_no'] !== '' ? $statusData['payer_mobile_no'] : null,
            'last_response' => json_encode($statusData),
        ];

        $result = ['applied' => false, 'status' => $status, 'trxId' => $trxId, 'reason' => ''];

        if ($status !== 'success') {
            self::updateTransaction($invoiceNumber, $ledger);
            $result['reason'] = 'PayStation reported trx_status "' . $status . '".';

            return $result;
        }

        // The status response must be about the invoice we asked about.
        if ($statusData['invoice_number'] !== '' && $statusData['invoice_number'] !== $invoiceNumber) {
            $ledger['status'] = 'mismatch';
            self::updateTransaction($invoiceNumber, $ledger);
            $result['status'] = 'mismatch';
            $result['reason'] = 'Invoice number mismatch: expected ' . $invoiceNumber
                . ', PayStation returned ' . $statusData['invoice_number'] . '.';

            return $result;
        }

        // The amount collected must cover what we asked PayStation to collect.
        $expected = round((float) $transaction['gateway_amount'], 2);
        $received = $statusData['amount'];
        if ($received === null) {
            $ledger['status'] = 'mismatch';
            self::updateTransaction($invoiceNumber, $ledger);
            $result['status'] = 'mismatch';
            $result['reason'] = 'PayStation did not return a transaction amount; payment not applied.';

            return $result;
        }
        if (round((float) $received, 2) + self::AMOUNT_EPSILON < $expected) {
            $ledger['status'] = 'mismatch';
            self::updateTransaction($invoiceNumber, $ledger);
            $result['status'] = 'mismatch';
            $result['reason'] = 'Amount mismatch: expected ' . number_format($expected, 2, '.', '')
                . ' ' . self::GATEWAY_CURRENCY . ', PayStation returned '
                . number_format((float) $received, 2, '.', '') . '. Payment not applied.';

            return $result;
        }

        // Idempotency: never credit the same transaction twice.
        if (!empty($transaction['applied'])) {
            self::updateTransaction($invoiceNumber, $ledger);
            $result['reason'] = 'Payment for this transaction was already applied.';

            return $result;
        }
        if (self::paymentAlreadyRecorded($trxId)) {
            $ledger['applied'] = 1;
            self::updateTransaction($invoiceNumber, $ledger);
            $result['reason'] = 'A WHMCS payment already exists for transaction ' . $trxId . '.';

            return $result;
        }

        if (!self::invoiceExists($invoiceId)) {
            $ledger['status'] = 'orphaned';
            self::updateTransaction($invoiceNumber, $ledger);
            $result['status'] = 'orphaned';
            $result['reason'] = 'WHMCS invoice ' . $invoiceId . ' no longer exists.';

            return $result;
        }

        if (!function_exists('addInvoicePayment')) {
            $result['reason'] = 'WHMCS invoice functions are unavailable; payment not applied.';

            return $result;
        }

        // WHMCS is credited in the invoice currency, never in the gateway
        // currency, so surcharges and FX never distort the ledger.
        $creditAmount = round((float) $transaction['invoice_amount'], 2);

        addInvoicePayment(
            $invoiceId,
            $trxId,
            $creditAmount,
            0,
            self::MODULE_NAME
        );

        $ledger['applied'] = 1;
        self::updateTransaction($invoiceNumber, $ledger);

        $result['applied'] = true;
        $result['reason'] = 'Applied ' . number_format($creditAmount, 2, '.', '') . ' '
            . $transaction['invoice_currency'] . ' to invoice ' . $invoiceId . '.';

        return $result;
    }

    /**
     * True when the invoice exists and can still receive a payment.
     *
     * @param int $invoiceId
     *
     * @return bool
     */
    public static function invoiceExists($invoiceId)
    {
        try {
            return Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->exists();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Re-check pending transactions against PayStation.
     *
     * Customers close the browser, lose connectivity or never come back from
     * the hosted checkout. Without this the money is taken but the invoice is
     * never marked paid, so the cron hook sweeps up anything left pending.
     *
     * @param array $gatewayParams Gateway configuration.
     * @param int   $limit         Maximum rows examined per run.
     *
     * @return array Counts keyed by outcome.
     */
    public static function reconcilePending(array $gatewayParams, $limit = 50)
    {
        $summary = ['checked' => 0, 'applied' => 0, 'failed' => 0, 'abandoned' => 0];

        if (!self::ensureSchema()) {
            return $summary;
        }

        $api = new Api(
            isset($gatewayParams['merchantId']) ? $gatewayParams['merchantId'] : '',
            isset($gatewayParams['password']) ? $gatewayParams['password'] : '',
            !empty($gatewayParams['testMode'])
        );

        if (!$api->isConfigured()) {
            return $summary;
        }

        try {
            $rows = Capsule::table(self::TABLE)
                ->whereIn('status', ['pending', 'processing'])
                ->where('applied', 0)
                ->where('created_at', '<=', date('Y-m-d H:i:s', time() - self::RECONCILE_MIN_AGE))
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - self::RECONCILE_MAX_AGE))
                ->orderBy('id')
                ->limit((int) $limit)
                ->get();
        } catch (\Exception $e) {
            return $summary;
        }

        foreach ($rows as $row) {
            $transaction = (array) $row;
            $invoiceNumber = (string) $transaction['invoice_number'];
            $summary['checked']++;

            $response = self::verifyTransaction($api, $invoiceNumber, (string) $transaction['trx_id']);

            self::updateTransaction($invoiceNumber, [
                'verify_attempts' => (int) $transaction['verify_attempts'] + 1,
            ]);

            if (!$response['accepted']) {
                continue;
            }

            $statusData = self::extractStatusData($response);
            $outcome = self::settle($gatewayParams, $transaction, $statusData);

            if ($outcome['applied']) {
                $summary['applied']++;
                self::log($gatewayParams, [
                    'context' => 'Cron Reconciliation',
                    'invoice_number' => $invoiceNumber,
                    'invoice_id' => $transaction['invoice_id'],
                    'result' => $outcome,
                ], 'Success');
            } elseif (in_array($outcome['status'], ['failed', 'mismatch', 'orphaned'], true)) {
                $summary['failed']++;
                self::log($gatewayParams, [
                    'context' => 'Cron Reconciliation',
                    'invoice_number' => $invoiceNumber,
                    'invoice_id' => $transaction['invoice_id'],
                    'result' => $outcome,
                ], 'Unsuccessful');
            }
        }

        // Anything still pending past the reconciliation window is abandoned:
        // the customer never completed the hosted checkout.
        try {
            $summary['abandoned'] = Capsule::table(self::TABLE)
                ->whereIn('status', ['pending', 'processing'])
                ->where('applied', 0)
                ->where('created_at', '<', date('Y-m-d H:i:s', time() - self::RECONCILE_MAX_AGE))
                ->update([
                    'status' => 'abandoned',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } catch (\Exception $e) {
            // Non fatal.
        }

        return $summary;
    }

    /**
     * Remove credentials from data before it reaches the gateway log.
     *
     * @param mixed $data
     *
     * @return mixed
     */
    public static function maskSecrets($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $secretKeys = ['password', 'merchantid', 'merchant_id', 'token', 'signature_key', 'apikey', 'api_key'];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::maskSecrets($value);
                continue;
            }
            if (in_array(strtolower((string) $key), $secretKeys, true) && (string) $value !== '') {
                $data[$key] = '***masked***';
            }
        }

        return $data;
    }

    /**
     * Write a masked entry to the WHMCS gateway log.
     *
     * @param array  $gatewayParams
     * @param mixed  $data
     * @param string $status
     *
     * @return void
     */
    public static function log(array $gatewayParams, $data, $status)
    {
        self::bootstrapGatewayFunctions();

        if (!function_exists('logTransaction')) {
            return;
        }

        $name = !empty($gatewayParams['name']) ? $gatewayParams['name'] : 'PayStation';

        try {
            logTransaction($name, self::maskSecrets($data), $status);
        } catch (\Exception $e) {
            // Logging must never break a payment flow.
        }
    }

    /**
     * Send the browser somewhere and stop.
     *
     * @param string $url
     *
     * @return void
     */
    public static function redirect($url)
    {
        if (!headers_sent()) {
            header('Location: ' . $url);
            exit;
        }

        echo '<!doctype html><meta http-equiv="refresh" content="0;url='
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . '<p>Redirecting… <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Continue</a></p>';
        exit;
    }
}
