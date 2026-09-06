<?php
/**
 * PayStation gateway module bootstrap.
 *
 * Loads the module classes with plain require_once rather than relying on the
 * WHMCS autoloader, so the module works identically in the admin area, the
 * client area, the standalone redirect/callback endpoints and cron.
 *
 * @package WHMCS\Module\Gateway\Paystation
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/Helper.php';
