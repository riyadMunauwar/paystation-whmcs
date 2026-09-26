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

    /** Session key holding the most recent customer facing failure. */
    const ERROR_SESSION_KEY = 'paystation_last_error';

    /** Query string flag that asks the invoice page to render that failure. */
    const ERROR_QUERY_KEY = 'paystationerror';

    /** How long a stored failure is still shown on the invoice, in seconds. */
    const ERROR_TTL = 1800;

    /**
     * What a customer is told when a failure carries no safe wording of its own.
     *
     * Operator facing reasons name file paths, table names, database errors,
     * PHP extensions, endpoints and credential state. None of that belongs in
     * front of a customer, so a failure that forgets to supply its own wording
     * gets this instead of leaking its reason.
     */
    const CUSTOMER_FALLBACK_MESSAGE = 'This payment could not be completed. Please try again, or contact '
        . 'support and quote the reference below.';

    /** @var string Message of the last swallowed internal exception. */
    protected static $lastInternalError = '';

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
            self::noteException('Create ' . self::TABLE, $e);
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
     * @return string One of success, processing, failed, canceled, refund or
     *                unknown.
     */
    public static function normaliseTrxStatus($status)
    {
        $status = strtolower(trim((string) $status));

        $known = ['success', 'processing', 'failed', 'canceled', 'refund'];
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
        // Backing out of the wallet page is not a failure: nothing was charged
        // and nothing went wrong. Kept distinct from "failed" so the customer
        // can simply be told the payment was cancelled, instead of being shown
        // a declined-payment error with a reference to quote to support.
        $cancelled = ['cancel', 'cancelled', 'canceled', 'aborted', 'user cancel', 'user cancelled',
            'user canceled', 'payment cancelled', 'payment canceled'];
        if (in_array($status, $cancelled, true)) {
            return 'canceled';
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
     *                                  Operator facing: it names the gateway
     *                                  setting that needs attention.
     *     @var string $customer_error  The same condition worded for the
     *                                  customer, naming no configuration.
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
            'customer_error' => '',
        ];

        $invoiceAmount = round((float) $dueAmount, 2);
        if ($invoiceAmount <= 0) {
            // Nothing internal about this one, so the customer gets it verbatim.
            $result['error'] = 'There is nothing left to pay on this invoice.';
            $result['customer_error'] = $result['error'];

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
                // The customer cannot act on a gateway setting, so they are not
                // told there is one.
                $result['customer_error'] = 'This payment method is not available for invoices in '
                    . $currencyCode . '. Please choose another payment method, or contact support.';

                return $result;
            }
        }

        $gatewayAmount = round($chargeable * $rate, 2);
        if ($gatewayAmount <= 0) {
            $result['error'] = 'The calculated PayStation amount is not greater than zero (balance '
                . $invoiceAmount . ' ' . $currencyCode . ', surcharge ' . $surcharge . ', rate ' . $rate . ').';
            $result['customer_error'] = 'The amount for this payment could not be calculated. '
                . 'Please contact support.';

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
     * Resolve the currency code an invoice is billed in.
     *
     * tblinvoices.currency cannot be trusted on its own: it is 0 on invoices
     * written by code paths that never set it, on installs where the invoices
     * predate the column, and on older schemas that have no such column at
     * all. WHMCS itself then bills the invoice in the owning client's
     * currency, so fall back to that, and to the site default after it. Only a
     * database without a single usable tblcurrencies row yields no code.
     *
     * @param int   $invoiceId
     * @param array $invoice   The tblinvoices row when the caller already read
     *                         it, to save a query.
     *
     * @return array {
     *     @var string $code   Currency code, '' when nothing resolved.
     *     @var string $source Which lookup supplied the code.
     *     @var array  $tried  What each lookup returned, for the logs.
     * }
     */
    public static function resolveInvoiceCurrency($invoiceId, array $invoice = [])
    {
        $invoiceId = (int) $invoiceId;
        $result = ['code' => '', 'source' => '', 'tried' => []];

        // 1. The currency recorded on the invoice.
        $currencyId = 0;

        if (array_key_exists('currency', $invoice)) {
            $currencyId = (int) $invoice['currency'];
        } elseif ($invoice !== []) {
            // The caller handed over the row and it carries no such column.
            $result['tried']['invoice_currency'] = 'tblinvoices has no currency column';
        } else {
            try {
                $currencyId = (int) Capsule::table('tblinvoices')
                    ->where('id', $invoiceId)
                    ->value('currency');
            } catch (\Exception $e) {
                self::noteException('resolveInvoiceCurrency:invoice', $e);
                $result['tried']['invoice_currency'] = 'lookup failed: ' . $e->getMessage();
            }
        }

        if ($currencyId > 0) {
            $code = self::currencyCode($currencyId);
            $result['tried']['invoice_currency'] = $code !== ''
                ? ('id ' . $currencyId . ' => ' . $code)
                : ('id ' . $currencyId . ' => no tblcurrencies row');

            if ($code !== '') {
                $result['code'] = $code;
                $result['source'] = 'tblinvoices.currency';

                return $result;
            }
        } elseif (!isset($result['tried']['invoice_currency'])) {
            $result['tried']['invoice_currency'] = 'not set on the invoice (0)';
        }

        // 2. The currency of the account the invoice belongs to.
        $userId = isset($invoice['userid']) ? (int) $invoice['userid'] : 0;

        if ($userId <= 0) {
            try {
                $userId = (int) Capsule::table('tblinvoices')
                    ->where('id', $invoiceId)
                    ->value('userid');
            } catch (\Exception $e) {
                self::noteException('resolveInvoiceCurrency:owner', $e);
            }
        }

        if ($userId > 0) {
            $clientCurrencyId = 0;

            try {
                $clientCurrencyId = (int) Capsule::table('tblclients')
                    ->where('id', $userId)
                    ->value('currency');
            } catch (\Exception $e) {
                self::noteException('resolveInvoiceCurrency:client', $e);
                $result['tried']['client_currency'] = 'lookup failed: ' . $e->getMessage();
            }

            if ($clientCurrencyId > 0) {
                $code = self::currencyCode($clientCurrencyId);
                $result['tried']['client_currency'] = $code !== ''
                    ? ('id ' . $clientCurrencyId . ' => ' . $code)
                    : ('id ' . $clientCurrencyId . ' => no tblcurrencies row');

                if ($code !== '') {
                    $result['code'] = $code;
                    $result['source'] = 'tblclients.currency of client ' . $userId;

                    return $result;
                }
            } elseif (!isset($result['tried']['client_currency'])) {
                $result['tried']['client_currency'] = 'not set on client ' . $userId . ' (0)';
            }
        } else {
            $result['tried']['client_currency'] = 'invoice owner unknown';
        }

        // 3. The site default currency, then whichever currency does exist.
        foreach ([true, false] as $defaultOnly) {
            $key = $defaultOnly ? 'default_currency' : 'first_currency';

            try {
                $query = Capsule::table('tblcurrencies');
                if ($defaultOnly) {
                    $query = $query->where('default', 1);
                }

                $code = strtoupper(trim((string) $query->orderBy('id')->value('code')));
            } catch (\Exception $e) {
                self::noteException('resolveInvoiceCurrency:' . $key, $e);
                $result['tried'][$key] = 'lookup failed: ' . $e->getMessage();
                continue;
            }

            $result['tried'][$key] = $code !== '' ? $code : 'none';

            if ($code !== '') {
                $result['code'] = $code;
                $result['source'] = $defaultOnly
                    ? 'the default currency'
                    : 'the lowest-id currency in tblcurrencies, no default being flagged';

                return $result;
            }
        }

        return $result;
    }

    /**
     * Currency code for one tblcurrencies id.
     *
     * @param int $currencyId
     *
     * @return string '' when the row is missing or unreadable.
     */
    protected static function currencyCode($currencyId)
    {
        if ((int) $currencyId <= 0) {
            return '';
        }

        try {
            return strtoupper(trim((string) Capsule::table('tblcurrencies')
                ->where('id', (int) $currencyId)
                ->value('code')));
        } catch (\Exception $e) {
            self::noteException('currencyCode', $e);

            return '';
        }
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
            self::noteException('invoiceBalance', $e);
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
            self::noteException('invoiceNumberExists', $e);
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
            self::noteException('createTransaction', $e);
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
            self::noteException('updateTransaction', $e);
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
            self::noteException('findTransaction', $e);
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
            self::noteException('findTransactionByTrxId', $e);
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
            self::noteException('paymentAlreadyRecorded', $e);
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
            // PayStation's own spelling, kept for the logs: the normalised
            // value above is this module's word, not the gateway's, and quoting
            // it back at a merchant hides what PayStation actually said.
            'trx_status_raw' => isset($data['trx_status']) ? trim((string) $data['trx_status']) : '',
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

            $reported = isset($statusData['trx_status_raw']) && $statusData['trx_status_raw'] !== ''
                ? $statusData['trx_status_raw']
                : $status;

            $result['reason'] = $status === 'canceled'
                ? ('The checkout was cancelled before the payment completed; PayStation reported '
                    . 'trx_status "' . $reported . '".')
                : ('PayStation reported trx_status "' . $reported . '".');

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
            self::noteException('invoiceExists', $e);
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
            self::noteException('reconcilePending query', $e);
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
            } elseif (in_array($outcome['status'], ['failed', 'canceled', 'mismatch', 'orphaned'], true)) {
                $summary['failed']++;
                self::log($gatewayParams, [
                    'context' => 'Cron Reconciliation',
                    'invoice_number' => $invoiceNumber,
                    'invoice_id' => $transaction['invoice_id'],
                    'result' => $outcome,
                ], $outcome['status'] === 'canceled' ? 'Cancelled' : 'Unsuccessful');
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
            self::noteException('reconcilePending abandon', $e);
            // Non fatal.
        }

        return $summary;
    }

    // -----------------------------------------------------------------------
    // Diagnostics
    //
    // Every failure in this module ends up in three places, because any one of
    // them can be unavailable when a merchant needs it:
    //
    //   1. A dated file under modules/gateways/paystation/logs/. Always
    //      written, so it survives the WHMCS gateway log being switched off.
    //   2. The WHMCS gateway log (Billing > Gateway Log), with full context.
    //   3. The WHMCS activity log, as a single grep-able line carrying the
    //      error code, reference and log file path.
    //
    // The same failure is also stashed in the session so the invoice page can
    // replace the generic WHMCS "your payment attempt was not successful"
    // banner with something the customer can act on.
    //
    // Every failure therefore carries two texts, and the split is deliberate:
    //
    //   reason  - operator facing, logged in full. Names log paths, table
    //             names, database errors, PHP extensions, endpoints, HTTP
    //             codes, PayStation's own message and whether credentials are
    //             set. Never rendered in a browser, for anybody: it describes
    //             the hosting environment, and the pages that show a failure
    //             are client area pages that an administrator may well be
    //             logged in to at the same time.
    //   message - customer facing. Says what happened and what to do about it,
    //             and never names a file, a table, a setting, a server or a
    //             PayStation response. Always accompanied by the error code
    //             and the reference, which are what support needs to find the
    //             matching reason in the logs.
    // -----------------------------------------------------------------------

    /**
     * Record an exception that the caller is about to swallow.
     *
     * The database and schema helpers below all degrade gracefully on failure,
     * which used to make a broken table or a missing privilege indistinguishable
     * from an ordinary "nothing found". The message is kept so the calling code
     * can report it, and written to the module log immediately so it is never
     * lost even if the caller does not.
     *
     * @param string     $where Short label of the operation that failed.
     * @param \Exception $e
     *
     * @return string The recorded message.
     */
    public static function noteException($where, $e)
    {
        $message = $where . ': ' . $e->getMessage();
        self::$lastInternalError = $message;

        self::logToFile('PS-INTERNAL', $message, [
            'exception' => get_class($e),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);

        return $message;
    }

    /**
     * Message of the most recent swallowed exception, if any.
     *
     * @return string
     */
    public static function lastInternalError()
    {
        return self::$lastInternalError;
    }

    /**
     * Writable directory for the module's own log files.
     *
     * Prefers a logs/ directory beside the module so the merchant can find it
     * next to the code, and falls back to the system temp directory when the
     * module directory is read only (a common hardening measure).
     *
     * @return string Empty when nothing is writable.
     */
    public static function logDir()
    {
        static $dir = null;

        if ($dir !== null) {
            return $dir;
        }

        $candidates = [__DIR__ . '/../logs'];
        if (function_exists('sys_get_temp_dir')) {
            $candidates[] = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'paystation-whmcs-logs';
        }

        foreach ($candidates as $candidate) {
            if (!is_dir($candidate)) {
                @mkdir($candidate, 0755, true);
            }

            if (is_dir($candidate) && is_writable($candidate)) {
                self::protectLogDir($candidate);

                $resolved = realpath($candidate);

                return $dir = rtrim($resolved === false ? $candidate : $resolved, '/\\');
            }
        }

        return $dir = '';
    }

    /**
     * Keep the log directory from being served over HTTP.
     *
     * Apache honours the .htaccess; the index.php stops a directory listing
     * anywhere. Log contents are masked regardless, because neither guard
     * helps on an nginx front end.
     *
     * @param string $dir
     *
     * @return void
     */
    protected static function protectLogDir($dir)
    {
        $guards = [
            '.htaccess' => "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n",
            'index.php' => "<?php exit;\n",
        ];

        foreach ($guards as $name => $contents) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!file_exists($path)) {
                @file_put_contents($path, $contents);
            }
        }
    }

    /**
     * Path of today's module log file.
     *
     * @return string Empty when no directory is writable.
     */
    public static function logFilePath()
    {
        $dir = self::logDir();

        if ($dir === '') {
            return '';
        }

        return $dir . DIRECTORY_SEPARATOR . 'paystation-' . gmdate('Y-m-d') . '.log';
    }

    /**
     * Append a masked, human readable entry to the module log file.
     *
     * Unlike Helper::log() this does not depend on WHMCS at all, so it still
     * works when the gateway log is disabled or when the failure happened
     * before WHMCS finished booting.
     *
     * @param string $code    Stable error code, e.g. PS-DECLINED.
     * @param string $reason  Operator facing explanation.
     * @param array  $context Extra detail. reference/invoice_id get promoted
     *                        into the header line.
     *
     * @return string Path written, or empty string on failure.
     */
    public static function logToFile($code, $reason, array $context = [])
    {
        $path = self::logFilePath();

        if ($path === '') {
            return '';
        }

        $reference = isset($context['reference']) ? (string) $context['reference'] : '';
        $invoiceId = isset($context['invoice_id']) ? (int) $context['invoice_id'] : 0;
        unset($context['reference'], $context['invoice_id']);

        $lines = [
            '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $code
                . ($reference !== '' ? '  ref=' . $reference : '')
                . ($invoiceId > 0 ? '  invoice=' . $invoiceId : ''),
            '    reason: ' . self::flattenForLog($reason),
        ];

        foreach (self::maskPii(self::maskSecrets($context)) as $key => $value) {
            $lines[] = '    ' . $key . ': ' . self::flattenForLog($value);
        }

        $lines[] = '    source: ' . (isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : 'cli')
            . '  ip=' . (isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '-');
        $lines[] = str_repeat('-', 74);

        $written = @file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND | LOCK_EX);

        return $written === false ? '' : $path;
    }

    /**
     * Render one log value on a single line.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected static function flattenForLog($value)
    {
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $encoded === false ? '[unencodable]' : $encoded;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return trim(preg_replace('/\s+/', ' ', (string) $value));
    }

    /**
     * Blur customer detail that does not need to be readable in a log file.
     *
     * Phone numbers keep their length and last four digits, because those are
     * exactly what is needed to debug a rejected cust_phone.
     *
     * @param mixed $data
     *
     * @return mixed
     */
    public static function maskPii($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $phoneKeys = ['cust_phone', 'phonenumber', 'phone', 'payer_mobile', 'payer_mobile_no'];
        $emailKeys = ['cust_email', 'email'];
        $freeTextKeys = ['cust_address', 'address1', 'address2', 'cust_name'];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::maskPii($value);
                continue;
            }

            $lower = strtolower((string) $key);
            $value = (string) $value;

            if ($value === '') {
                continue;
            }

            if (in_array($lower, $phoneKeys, true)) {
                $digits = preg_replace('/\D+/', '', $value);
                $data[$key] = '***' . substr($digits, -4) . ' (' . strlen($digits) . ' digits)';
                continue;
            }

            if (in_array($lower, $emailKeys, true)) {
                $at = strrpos($value, '@');
                $data[$key] = $at === false
                    ? substr($value, 0, 1) . '***'
                    : substr($value, 0, 1) . '***' . substr($value, $at);
                continue;
            }

            if (in_array($lower, $freeTextKeys, true)) {
                $data[$key] = substr($value, 0, 1) . '*** (' . strlen($value) . ' chars)';
            }
        }

        return $data;
    }

    /**
     * Write a single line to the WHMCS activity log.
     *
     * The activity log cannot be switched off, so this is the one sink that is
     * guaranteed to be there when a merchant goes looking.
     *
     * @param string $message
     *
     * @return void
     */
    public static function logActivityLine($message)
    {
        if (!function_exists('logActivity')) {
            return;
        }

        try {
            logActivity($message);
        } catch (\Exception $e) {
            // Logging must never break a payment flow.
        }
    }

    /**
     * Short reference that ties an on screen error to its log entries.
     *
     * @return string
     */
    public static function errorReference()
    {
        return 'PS' . strtoupper(substr(md5(uniqid('', true) . mt_rand()), 0, 8));
    }

    /**
     * Record a failure everywhere it needs to be recorded.
     *
     * @param array  $gatewayParams
     * @param string $code            Stable error code, e.g. PS-DECLINED.
     * @param string $reason          Operator facing explanation. Logged in
     *                                full; shown on screen to a logged in
     *                                admin and to nobody else.
     * @param array  $context         Extra detail for the logs. An invoice_id
     *                                key binds the error to that invoice page.
     * @param string $customerMessage What the customer is told. Must be safe to
     *                                show to anyone; when omitted the customer
     *                                gets CUSTOMER_FALLBACK_MESSAGE rather than
     *                                the reason, so an internal detail is never
     *                                published by accident.
     *
     * @return array The stored error entry.
     */
    public static function fail(array $gatewayParams, $code, $reason, array $context = [], $customerMessage = '')
    {
        return self::record($gatewayParams, $code, $reason, $context, $customerMessage, 'error');
    }

    /**
     * Record a checkout the customer deliberately abandoned.
     *
     * A cancellation is not an incident: nothing was charged, nothing is broken
     * and there is nothing for support to look into. It is still logged, so a
     * merchant can see how many checkouts are being abandoned and where, but it
     * is logged as a cancellation rather than as a failure - it stays out of the
     * activity log, and the customer is shown a plain note instead of a red
     * error box carrying a reference to quote.
     *
     * @param array  $gatewayParams
     * @param string $code
     * @param string $reason          Operator facing explanation, logged in full.
     * @param array  $context
     * @param string $customerMessage
     *
     * @return array The stored entry.
     */
    public static function cancelled(
        array $gatewayParams,
        $code,
        $reason,
        array $context = [],
        $customerMessage = ''
    ) {
        return self::record($gatewayParams, $code, $reason, $context, $customerMessage, 'notice');
    }

    /**
     * Write one outcome to every log, and stash it for the next page load.
     *
     * @param array  $gatewayParams
     * @param string $code
     * @param string $reason
     * @param array  $context
     * @param string $customerMessage
     * @param string $level           'error' for a failure, 'notice' for
     *                                something the customer chose to do.
     *
     * @return array The stored entry.
     */
    protected static function record(
        array $gatewayParams,
        $code,
        $reason,
        array $context,
        $customerMessage,
        $level
    ) {
        $isNotice = ($level === 'notice');
        $reference = self::errorReference();
        $invoiceId = isset($context['invoice_id']) ? (int) $context['invoice_id'] : 0;

        $logPath = self::logToFile($code, $reason, array_merge([
            'reference' => $reference,
            'invoice_id' => $invoiceId,
        ], $context));

        self::log($gatewayParams, array_merge([
            'error_code' => $code,
            'error_reference' => $reference,
            'reason' => $reason,
            'log_file' => $logPath !== '' ? $logPath : 'no writable log directory',
        ], $context), $isNotice ? 'Cancelled' : 'Unsuccessful');

        // The activity log is where a merchant looks for things that need
        // attention, so a routine cancellation does not belong in it.
        if (!$isNotice) {
            self::logActivityLine(
                'PayStation ' . $code . ' [' . $reference . ']'
                . ($invoiceId > 0 ? ' invoice ' . $invoiceId : '')
                . ': ' . self::flattenForLog($reason)
                . ($logPath !== '' ? ' (detail: ' . $logPath . ')' : '')
            );
        }

        $error = [
            'code' => (string) $code,
            'reference' => $reference,
            'reason' => (string) $reason,
            'message' => $customerMessage !== '' ? (string) $customerMessage : self::CUSTOMER_FALLBACK_MESSAGE,
            'level' => $isNotice ? 'notice' : 'error',
            'invoice_id' => $invoiceId,
            'log_file' => $logPath,
            'time' => time(),
        ];

        self::storeError($error);

        return $error;
    }

    /**
     * Record a failure, but no more than once per throttle window.
     *
     * The invoice page re-renders the payment button on every view, so a
     * standing misconfiguration would otherwise write a log line each time the
     * customer refreshes. The first occurrence is logged in full; repeats
     * within the window reuse that entry, keeping the same reference so the
     * message on screen always points at a log line that exists.
     *
     * @param array  $gatewayParams
     * @param string $code
     * @param string $reason
     * @param array  $context
     * @param string $customerMessage
     * @param int    $seconds Throttle window.
     *
     * @return array The stored error entry.
     */
    public static function failOnce(
        array $gatewayParams,
        $code,
        $reason,
        array $context = [],
        $customerMessage = '',
        $seconds = 3600
    ) {
        $invoiceId = isset($context['invoice_id']) ? (int) $context['invoice_id'] : 0;
        $key = $code . '|' . $invoiceId;
        $now = time();

        if (self::startSession()) {
            $seen = isset($_SESSION['paystation_reported']) && is_array($_SESSION['paystation_reported'])
                ? $_SESSION['paystation_reported']
                : [];

            foreach ($seen as $seenKey => $entry) {
                if (!is_array($entry) || $now - (int) $entry['time'] > $seconds) {
                    unset($seen[$seenKey]);
                }
            }

            if (isset($seen[$key])) {
                $_SESSION['paystation_reported'] = $seen;

                return $seen[$key];
            }

            $error = self::fail($gatewayParams, $code, $reason, $context, $customerMessage);
            $seen[$key] = $error;
            $_SESSION['paystation_reported'] = $seen;

            return $error;
        }

        return self::fail($gatewayParams, $code, $reason, $context, $customerMessage);
    }

    /**
     * Make sure a PHP session is available for the error hand off.
     *
     * @return bool
     */
    protected static function startSession()
    {
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        if (session_id() !== '') {
            return true;
        }

        if (headers_sent()) {
            return false;
        }

        return (bool) @session_start();
    }

    /**
     * Hand a failure to the next page load.
     *
     * @param array $error
     *
     * @return void
     */
    public static function storeError(array $error)
    {
        if (!self::startSession()) {
            return;
        }

        $_SESSION[self::ERROR_SESSION_KEY] = $error;
    }

    /**
     * The failure waiting to be shown for an invoice, if it is still fresh.
     *
     * @param int $invoiceId
     *
     * @return array|null
     */
    public static function pendingError($invoiceId)
    {
        if (!self::startSession() || empty($_SESSION[self::ERROR_SESSION_KEY])) {
            return null;
        }

        $error = $_SESSION[self::ERROR_SESSION_KEY];

        if (!is_array($error) || empty($error['code'])) {
            self::clearError();

            return null;
        }

        if (time() - (int) $error['time'] > self::ERROR_TTL) {
            self::clearError();

            return null;
        }

        $storedInvoiceId = isset($error['invoice_id']) ? (int) $error['invoice_id'] : 0;
        if ($storedInvoiceId > 0 && $storedInvoiceId !== (int) $invoiceId) {
            return null;
        }

        // An entry stored before this version was installed has no level.
        if (!isset($error['level']) || $error['level'] !== 'notice') {
            $error['level'] = 'error';
        }

        return $error;
    }

    /**
     * Drop any stored failure.
     *
     * @return void
     */
    public static function clearError()
    {
        if (!self::startSession()) {
            return;
        }

        unset($_SESSION[self::ERROR_SESSION_KEY]);
    }

    /**
     * True when the current viewer is a logged in WHMCS administrator.
     *
     * Used only to decide whether to add a line pointing at the Gateway Log.
     * The technical reason itself is never rendered in a browser by any page in
     * this module, whoever is looking: it names absolute log paths, database
     * errors, table names, PHP extensions, PayStation endpoints and credential
     * state, and this check cannot tell the difference between an admin on their
     * own screen and an admin session that is also logged in to the client area
     * - or one using "Login as Client" - which is how that detail ended up in
     * front of paying customers. The reason lives in the logs; the reference on
     * screen is what ties the two together.
     *
     * @param array $gatewayParams Unused; kept so existing call sites and any
     *                             local customisations keep working.
     *
     * @return bool
     */
    public static function maySeeDetail(array $gatewayParams = [])
    {
        if (!self::startSession()) {
            return false;
        }

        return !empty($_SESSION['adminid']);
    }

    /**
     * Invoice URL carrying the flag that asks for a stored error to be shown.
     *
     * @param int   $invoiceId
     * @param array $error Output of Helper::fail().
     *
     * @return string
     */
    public static function invoiceErrorUrl($invoiceId, array $error)
    {
        $reference = isset($error['reference']) ? (string) $error['reference'] : '1';

        return self::invoiceUrl($invoiceId) . '&' . self::ERROR_QUERY_KEY . '=' . urlencode($reference);
    }

    /**
     * Standalone error page, for failures with no invoice to return to.
     *
     * Shows the customer facing message, the error code and the reference, and
     * nothing else. The operator facing reason and the log path are deliberately
     * absent - they are in the three logs, which is where an administrator reads
     * them.
     *
     * @param array  $error      Output of Helper::fail() or Helper::cancelled().
     * @param bool   $isAdmin    Add a line pointing at the Gateway Log.
     * @param int    $httpStatus
     * @param string $heading    Page heading. Defaults to wording that suits a
     *                           failure at any point in the payment.
     *
     * @return void
     */
    public static function renderErrorPage(array $error, $isAdmin = false, $httpStatus = 400, $heading = '')
    {
        $isNotice = isset($error['level']) && $error['level'] === 'notice';

        if ($heading === '') {
            $heading = $isNotice ? 'Payment cancelled' : 'This payment could not be completed';
        }

        if (!headers_sent()) {
            http_response_code((int) $httpStatus);
            header('Content-Type: text/html; charset=utf-8');
        }

        $escape = function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $escape($heading) . '</title>'
            . '<style>body{font:14px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;margin:0;padding:40px 20px;'
            . 'background:#f6f7f9;color:#1f2933}main{max-width:640px;margin:0 auto;background:#fff;border:1px solid #e1e5ea;'
            . 'border-radius:8px;padding:24px}h1{font-size:19px;margin:0 0 12px}code{background:#f0f2f5;padding:1px 5px;'
            . 'border-radius:3px}.ref{color:#6b7684;font-size:12px;margin-top:16px}</style>'
            . '</head><body><main>'
            . '<h1>' . $escape($heading) . '</h1>'
            . '<p>' . $escape($error['message']) . '</p>';

        if (!$isNotice) {
            echo '<p class="ref">Error code <code>' . $escape($error['code']) . '</code> &middot; reference <code>'
                . $escape($error['reference']) . '</code>. Quote this reference to support.</p>';
        }

        if ($isAdmin) {
            echo '<p class="ref">Administrator: the full reason is in Billing &raquo; Gateway Log, '
                . 'and in the module log file on the server.</p>';
        }

        echo '</main></body></html>';

        exit;
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
