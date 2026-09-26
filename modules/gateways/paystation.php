<?php
/**
 * PayStation Payment Gateway Module for WHMCS.
 *
 * Third party (hosted checkout) gateway integration for PayStation Bangladesh.
 *
 * Flow
 * ----
 *  1. This file renders a signed "Pay Now" button on the invoice.
 *  2. modules/gateways/paystation/redirect.php creates the checkout session
 *     server side (POST /initiate-payment) and redirects to payment_url.
 *  3. PayStation returns the customer to
 *     modules/gateways/callback/paystation.php.
 *  4. The callback verifies the transaction server to server before any
 *     payment is applied.
 *  5. includes/hooks/paystation_reconcile.php reconciles anything the customer
 *     abandoned mid-checkout.
 *
 * The API call deliberately does not happen here. _link() runs on every render
 * of the invoice page, and PayStation requires a unique invoice_number per
 * checkout session, so creating one here would burn a session on every page
 * view.
 *
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 * @see https://paystation.com.bd/documentation
 *
 * @package    WHMCS\Module\Gateway\Paystation
 * @author     Riyad Munauwar
 * @license    MIT
 * @version    1.0.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/paystation/lib/loader.php';

use WHMCS\Module\Gateway\Paystation\Helper;

/**
 * Module metadata.
 *
 * @return array
 */
function paystation_MetaData()
{
    return [
        'DisplayName' => 'PayStation',
        'APIVersion' => '1.1',
        // Hosted checkout: WHMCS must never collect card details locally.
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

/**
 * Gateway configuration fields.
 *
 * @return array
 */
function paystation_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'PayStation (bKash, Nagad, Rocket, Upay, Cards)',
        ],
        'merchantId' => [
            'FriendlyName' => 'Merchant ID',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => '<br>Merchant ID issued by PayStation, e.g. <code>204-16537301811</code>.',
        ],
        'password' => [
            'FriendlyName' => 'Merchant Password',
            'Type' => 'password',
            'Size' => '40',
            'Default' => '',
            'Description' => '<br>Merchant password issued by PayStation. Stored server side only.',
        ],
        'testMode' => [
            'FriendlyName' => 'Sandbox Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to use <code>sandbox.paystation.com.bd</code>. Sandbox and production credentials are not interchangeable.',
        ],
        'invoicePrefix' => [
            'FriendlyName' => 'Invoice Number Prefix',
            'Type' => 'text',
            'Size' => '12',
            'Default' => '',
            'Description' => '<br>Optional. Prepended to every PayStation invoice number. Useful when several systems share one merchant account. Letters, digits, <code>-</code> and <code>_</code> only.',
        ],
        'payWithCharge' => [
            'FriendlyName' => 'Gateway Charge Borne By',
            'Type' => 'dropdown',
            'Options' => [
                '1' => 'Customer (added at PayStation checkout)',
                '0' => 'Merchant (deducted from settlement)',
            ],
            'Default' => '0',
            'Description' => '<br>Maps to the PayStation <code>pay_with_charge</code> field.',
        ],
        'enableEmi' => [
            'FriendlyName' => 'Request EMI',
            'Type' => 'yesno',
            'Description' => 'Tick to send <code>emi=1</code> so the checkout offers EMI. Only enable this if EMI is active on your merchant account.',
        ],
        'conversionRate' => [
            'FriendlyName' => 'BDT Conversion Rate',
            'Type' => 'text',
            'Size' => '12',
            'Default' => '',
            'Description' => '<br>Only used for invoices that are <strong>not</strong> in BDT. Enter how many BDT one unit of the invoice currency is worth (e.g. <code>120</code> for 1 USD = 120 BDT). The invoice is still credited in its own currency.',
        ],
        'surchargePercent' => [
            'FriendlyName' => 'Additional Service Charge (%)',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '0',
            'Description' => '<br>Percentage added to the amount sent to PayStation. Enter <code>3</code> for 3%, or <code>0</code> for none.',
        ],
        'surchargeFixed' => [
            'FriendlyName' => 'Additional Service Charge (fixed)',
            'Type' => 'text',
            'Size' => '8',
            'Default' => '0',
            'Description' => '<br>Flat amount added to the amount sent to PayStation, in the invoice currency. Enter <code>0</code> for none.',
        ],
        'autoReconcile' => [
            'FriendlyName' => 'Cron Reconciliation',
            'Type' => 'yesno',
            'Default' => 'on',
            'Description' => 'Recommended. Re-checks abandoned checkouts against PayStation on every cron run so payments are never lost when a customer closes the browser.',
        ],
        'debugLogging' => [
            'FriendlyName' => 'Verbose Gateway Log',
            'Type' => 'yesno',
            'Description' => 'Log full PayStation requests and responses under Billing &raquo; Gateway Log. Credentials are always masked. Leave off in production once the integration is verified.',
        ],
    ];
}

/**
 * Render the payment button on the invoice.
 *
 * @param array $params WHMCS gateway parameters.
 *
 * @return string HTML
 */
function paystation_link($params)
{
    $invoiceId = (int) $params['invoiceid'];

    // Anything the redirect endpoint or the callback failed on is shown here,
    // in place of the generic WHMCS "your payment attempt was not successful"
    // banner, so the customer and the merchant both see the real cause.
    $failure = paystation_renderStoredError($params, $invoiceId);

    if (!Helper::ensureSchema()) {
        return $failure . paystation_configError(
            $params,
            $invoiceId,
            'PS-DB-SCHEMA',
            'The PayStation ledger table ' . Helper::TABLE . ' is missing and could not be created. '
                . (Helper::lastInternalError() !== ''
                    ? Helper::lastInternalError()
                    : 'Check that the WHMCS database user has CREATE privileges.'),
            'PayStation is not set up correctly on this site yet. Please contact support.'
        );
    }

    $merchantId = trim((string) $params['merchantId']);
    $password = trim((string) $params['password']);

    if ($merchantId === '' || $password === '') {
        return $failure . paystation_configError(
            $params,
            $invoiceId,
            'PS-NO-CREDENTIALS',
            'PayStation credentials are missing. Merchant ID '
                . ($merchantId === '' ? 'is empty' : 'is set') . ', Merchant Password '
                . ($password === '' ? 'is empty' : 'is set')
                . '. Set both under Setup > Payments > Payment Gateways > PayStation.',
            'PayStation is not fully configured. Please contact support.'
        );
    }

    $currency = trim(isset($params['currency']) ? (string) $params['currency'] : '');

    if ($currency === '') {
        // WHMCS passed no currency code, which happens when the invoice does
        // not name one. Resolve it exactly as redirect.php will, so the
        // summary shown here matches what the customer is actually charged.
        $currencyLookup = Helper::resolveInvoiceCurrency($invoiceId);
        $currency = $currencyLookup['code'];

        if ($currency === '') {
            return $failure . paystation_configError(
                $params,
                $invoiceId,
                'PS-CURRENCY',
                'No currency could be resolved for invoice ' . $invoiceId . '. WHMCS passed none, and '
                    . 'the invoice, the client account, the default currency and tblcurrencies itself '
                    . 'all came back empty: '
                    . json_encode($currencyLookup['tried'], JSON_UNESCAPED_SLASHES) . '.',
                'The currency on this invoice could not be resolved. Please contact support.'
            );
        }
    }

    // The client id is signed into the token and re-checked against the invoice
    // owner in redirect.php, so resolve it from whichever key WHMCS supplied.
    $userId = 0;
    foreach ([
        isset($params['clientdetails']['userid']) ? $params['clientdetails']['userid'] : null,
        isset($params['clientdetails']['id']) ? $params['clientdetails']['id'] : null,
        isset($params['userid']) ? $params['userid'] : null,
    ] as $candidate) {
        if ((int) $candidate > 0) {
            $userId = (int) $candidate;
            break;
        }
    }

    $amounts = Helper::computeAmounts($params['amount'], $currency, $params);
    if ($amounts['error'] !== '') {
        return $failure . paystation_configError(
            $params,
            $invoiceId,
            'PS-AMOUNT',
            $amounts['error'] . ' Invoice amount "' . $params['amount'] . '" in ' . $currency
                . ', conversion rate setting "' . (isset($params['conversionRate']) ? $params['conversionRate'] : '')
                . '".',
            $amounts['error']
        );
    }

    // PayStation requires cust_phone, and redirect.php aborts without one.
    // Catch it here so the customer is told what to fix instead of being sent
    // to a button that can only ever fail. The check is skipped when WHMCS did
    // not supply clientdetails, so an unexpected params shape never hides a
    // working button - redirect.php still enforces the real requirement.
    if (isset($params['clientdetails']) && is_array($params['clientdetails'])) {
        $phone = isset($params['clientdetails']['phonenumber'])
            ? $params['clientdetails']['phonenumber']
            : '';

        if (Helper::normalisePhone($phone) === '') {
            return $failure . paystation_configError(
                $params,
                $invoiceId,
                'PS-NO-PHONE',
                'Client ' . $userId . ' has no usable phone number; PayStation requires cust_phone. '
                    . 'The stored value holds ' . strlen(trim((string) $phone)) . ' characters and no digits.',
                'PayStation requires a contact phone number. Please add one to your account details, '
                    . 'then reload this page.'
            );
        }
    }

    $expires = time() + Helper::TOKEN_TTL;
    $token = Helper::paymentToken($invoiceId, $userId, $expires, $params);

    $buttonLabel = !empty($params['langpaynow']) ? $params['langpaynow'] : 'Pay Now';
    $action = Helper::redirectEndpointUrl();

    if ($action === '' || strpos($action, 'http') !== 0) {
        return $failure . paystation_configError(
            $params,
            $invoiceId,
            'PS-NO-SYSTEM-URL',
            'The WHMCS System URL is not set, so the Pay Now form and the PayStation callback URL '
                . 'cannot be built. Set it under Setup > General Settings > General > WHMCS System URL. '
                . 'Resolved value: "' . $action . '".',
            'This payment method is not configured correctly. Please contact support.'
        );
    }

    $html = $failure;

    $fields = [
        'invoiceid' => $invoiceId,
        'userid' => $userId,
        'expires' => $expires,
        'token' => $token,
    ];

    $html .= '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
            . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '" />';
    }
    $html .= '<input type="submit" class="btn btn-primary btn-lg" value="'
        . htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8') . '" />';
    $html .= '</form>';

    $html .= paystation_amountSummary($amounts, $currency);

    if (!empty($params['testMode'])) {
        $html .= paystation_notice(
            'PayStation sandbox mode is enabled. No real payment will be taken.',
            'warning'
        );
    }

    return $html;
}

/**
 * Explain any surcharge or currency conversion before the customer commits.
 *
 * @param array  $amounts  Output of Helper::computeAmounts().
 * @param string $currency Invoice currency code.
 *
 * @return string HTML
 */
function paystation_amountSummary(array $amounts, $currency)
{
    $lines = [];

    if ($amounts['surcharge'] > 0) {
        $lines[] = 'A service charge of ' . number_format($amounts['surcharge'], 2, '.', ',')
            . ' ' . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ' applies to PayStation payments.';
    }

    if ($amounts['rate'] != 1.0) {
        $lines[] = 'You will be charged ' . number_format($amounts['gateway_amount'], 2, '.', ',')
            . ' ' . Helper::GATEWAY_CURRENCY . ' at a rate of 1 '
            . htmlspecialchars($currency, ENT_QUOTES, 'UTF-8') . ' = '
            . rtrim(rtrim(number_format($amounts['rate'], 4, '.', ''), '0'), '.') . ' '
            . Helper::GATEWAY_CURRENCY . '.';
    }

    if (!$lines) {
        return '';
    }

    return '<p class="text-muted small" style="margin-top:8px">' . implode('<br />', $lines) . '</p>';
}

/**
 * Render a small bootstrap style notice.
 *
 * @param string $message
 * @param string $type    Bootstrap contextual class suffix.
 *
 * @return string HTML
 */
function paystation_notice($message, $type = 'info')
{
    return '<div class="alert alert-' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
}

/**
 * Show the failure that the redirect endpoint or the callback recorded.
 *
 * WHMCS's own paymentfailed banner says only "Unfortunately your payment
 * attempt was not successful", which is useless for diagnosing anything. Every
 * failure path in this module stores the real cause instead, and this renders
 * it in its place, together with the error code and reference needed to find
 * the matching log entry.
 *
 * @param array $params    WHMCS gateway parameters.
 * @param int   $invoiceId
 *
 * @return string HTML, empty when there is nothing to show.
 */
function paystation_renderStoredError(array $params, $invoiceId)
{
    // Only render on the page the customer was actually redirected to, so a
    // stale message never reappears on an unrelated visit.
    if (!isset($_GET[Helper::ERROR_QUERY_KEY])) {
        Helper::clearError();

        return '';
    }

    $error = Helper::pendingError($invoiceId);

    if (!$error) {
        return paystation_notice(
            'That payment attempt did not complete, but the reason is no longer available. '
                . 'Please try again.',
            'warning'
        );
    }

    return paystation_errorBox($params, $error);
}

/**
 * Record a configuration problem found while rendering the button, and render
 * it in the same shape as a redirect failure.
 *
 * @param array  $params
 * @param int    $invoiceId
 * @param string $code
 * @param string $reason          Operator facing detail, logged in full.
 * @param string $customerMessage
 *
 * @return string HTML
 */
function paystation_configError(array $params, $invoiceId, $code, $reason, $customerMessage)
{
    // The button is re-rendered on every view of the invoice, so a standing
    // misconfiguration is logged once per hour rather than once per refresh.
    $error = Helper::failOnce($params, $code, $reason, [
        'context' => 'Payment Button',
        'invoice_id' => (int) $invoiceId,
    ], $customerMessage);

    // It is being shown right here, so nothing needs to survive to the next
    // page load.
    Helper::clearError();

    return paystation_errorBox($params, $error);
}

/**
 * Render one failure: what happened, and how to find it in the logs.
 *
 * The technical reason is only added for an admin or when verbose logging is
 * on, so ordinary customers get the plain explanation while whoever is
 * debugging gets the exact cause on the page itself.
 *
 * @param array $params
 * @param array $error  Output of Helper::fail().
 *
 * @return string HTML
 */
function paystation_errorBox(array $params, array $error)
{
    $escape = function ($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };

    $html = '<div class="alert alert-danger" style="text-align:left">'
        . '<strong>' . $escape($error['message']) . '</strong>';

    if (Helper::maySeeDetail($params)) {
        if ($error['reason'] !== $error['message']) {
            $html .= '<div style="margin-top:10px;font-family:monospace;font-size:12px;white-space:pre-wrap;'
                . 'word-break:break-word">' . $escape($error['reason']) . '</div>';
        }

        $html .= '<div style="margin-top:10px;font-size:12px">Logged to <code>'
            . $escape($error['log_file'] !== '' ? $error['log_file'] : 'gateway log only (no writable log directory)')
            . '</code>, and to Billing &raquo; Gateway Log and Utilities &raquo; Logs &raquo; Activity Log.</div>';
    }

    $html .= '<div style="margin-top:10px;font-size:12px;opacity:.85">Error code <code>'
        . $escape($error['code']) . '</code> &middot; reference <code>' . $escape($error['reference'])
        . '</code>. Quote this reference when contacting support.</div>'
        . '</div>';

    return $html;
}
