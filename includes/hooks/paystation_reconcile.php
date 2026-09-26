<?php
/**
 * PayStation transaction reconciliation hook.
 *
 * Customers abandon hosted checkouts: they close the tab, lose mobile data
 * mid-OTP, or the return redirect never fires. When that happens the money can
 * be taken at PayStation while the WHMCS invoice stays unpaid.
 *
 * This hook re-checks every pending PayStation transaction against the
 * Transaction Status API on each cron run and settles anything that actually
 * succeeded. Settlement goes through the same idempotent routine the callback
 * uses, so a transaction can never be credited twice.
 *
 * Lives in /includes/hooks/ rather than inside the gateway directory because
 * that is the load path WHMCS documents for all hook files.
 *
 * @see https://developers.whmcs.com/hooks/
 *
 * @package WHMCS\Module\Gateway\Paystation
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$paystationLoader = __DIR__ . '/../../modules/gateways/paystation/lib/loader.php';

if (!file_exists($paystationLoader)) {
    // Gateway module not installed; nothing to reconcile.
    return;
}

require_once $paystationLoader;

use WHMCS\Module\Gateway\Paystation\Helper;

add_hook('AfterCronJob', 1, function ($vars) {
    Helper::bootstrapGatewayFunctions();

    if (!function_exists('getGatewayVariables')) {
        return;
    }

    $gatewayParams = getGatewayVariables(Helper::MODULE_NAME);

    if (empty($gatewayParams['type']) || empty($gatewayParams['autoReconcile'])) {
        return;
    }

    try {
        $summary = Helper::reconcilePending($gatewayParams);
    } catch (\Exception $e) {
        Helper::log($gatewayParams, [
            'context' => 'Cron Reconciliation',
            'exception' => $e->getMessage(),
        ], 'Error');

        // Cron output is easy to miss, so mirror it into the module log file
        // where every other PayStation failure is recorded.
        Helper::logToFile('PS-CRON', 'Reconciliation pass threw ' . get_class($e) . ': ' . $e->getMessage(), [
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);

        return;
    }

    // Only leave a log entry when there was something to report, so the gateway
    // log does not fill up with empty cron runs.
    if ($summary['applied'] > 0 || $summary['failed'] > 0 || $summary['abandoned'] > 0) {
        Helper::log($gatewayParams, array_merge([
            'context' => 'Cron Reconciliation Summary',
        ], $summary), $summary['applied'] > 0 ? 'Success' : 'Info');
    }
});
