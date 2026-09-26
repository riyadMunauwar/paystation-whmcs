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
    Helper::ensureSchema();

    $merchantId = trim((string) $params['merchantId']);
    $password = trim((string) $params['password']);

    if ($merchantId === '' || $password === '') {
        return paystation_notice(
            'PayStation is not fully configured. Please contact support.',
            'danger'
        );
    }

    $invoiceId = (int) $params['invoiceid'];
    $currency = isset($params['currency']) ? (string) $params['currency'] : '';

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
        Helper::log($params, [
            'context' => 'Payment Button',
            'invoice_id' => $invoiceId,
            'currency' => $currency,
            'amount' => $params['amount'],
            'error' => $amounts['error'],
        ], 'Configuration Error');

        return paystation_notice($amounts['error'], 'danger');
    }

    $expires = time() + Helper::TOKEN_TTL;
    $token = Helper::paymentToken($invoiceId, $userId, $expires, $params);

    $buttonLabel = !empty($params['langpaynow']) ? $params['langpaynow'] : 'Pay Now';
    $action = Helper::redirectEndpointUrl();

    $fields = [
        'invoiceid' => $invoiceId,
        'userid' => $userId,
        'expires' => $expires,
        'token' => $token,
    ];

    $html = '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">';
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
