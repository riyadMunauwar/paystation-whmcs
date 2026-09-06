<?php
/**
 * PayStation REST API client.
 *
 * Thin, dependency free wrapper around the three PayStation endpoints used by
 * this gateway module:
 *
 *   POST /initiate-payment        (form encoded, credentials in the body)
 *   POST /transaction-status      (form encoded, merchantId in the header)
 *   POST /v2/transaction-status   (JSON encoded, merchantId in the header)
 *
 * Every method returns a normalised array so callers never have to care about
 * transport details:
 *
 *   [
 *     'ok'          => bool,   // the HTTP call completed and returned JSON
 *     'httpCode'    => int,
 *     'error'       => string, // transport level error, empty when ok
 *     'raw'         => string, // raw response body
 *     'json'        => array,  // decoded body ([] when not decodable)
 *     'statusCode'  => string, // PayStation status_code, e.g. "200", "1008"
 *     'status'      => string, // PayStation status, e.g. "success"
 *     'message'     => string,
 *     'data'        => array,  // PayStation data payload where present
 *     'accepted'    => bool,   // ok && status_code 200 && status success
 *   ]
 *
 * @see https://paystation.com.bd/documentation
 * @package WHMCS\Module\Gateway\Paystation
 */

namespace WHMCS\Module\Gateway\Paystation;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class Api
{
    /** Sandbox base URL. */
    const SANDBOX_BASE_URL = 'https://sandbox.paystation.com.bd';

    /** Production base URL. */
    const PRODUCTION_BASE_URL = 'https://api.paystation.com.bd';

    /** PayStation status_code returned when a request was processed. */
    const CODE_OK = '200';

    /** PayStation status_code returned for a re-used invoice_number. */
    const CODE_DUPLICATE_INVOICE = '1008';

    /** PayStation status_code returned for bad credentials / unknown trx. */
    const CODE_INVALID_TOKEN = '2001';

    /** @var string */
    protected $merchantId;

    /** @var string */
    protected $password;

    /** @var bool */
    protected $testMode;

    /** @var int Seconds to wait for the TCP/TLS handshake. */
    protected $connectTimeout = 15;

    /** @var int Seconds to wait for the whole request. */
    protected $timeout = 45;

    /** @var int Extra attempts made when the transport itself fails. */
    protected $retries = 2;

    /**
     * @param string $merchantId Merchant ID issued by PayStation.
     * @param string $password   Merchant password issued by PayStation.
     * @param bool   $testMode   True to talk to the sandbox environment.
     */
    public function __construct($merchantId, $password, $testMode = false)
    {
        $this->merchantId = trim((string) $merchantId);
        $this->password = trim((string) $password);
        $this->testMode = (bool) $testMode;
    }

    /**
     * Base URL for the currently selected environment.
     *
     * @return string
     */
    public function baseUrl()
    {
        return $this->testMode ? self::SANDBOX_BASE_URL : self::PRODUCTION_BASE_URL;
    }

    /**
     * True when the client has both credentials configured.
     *
     * @return bool
     */
    public function isConfigured()
    {
        return $this->merchantId !== '' && $this->password !== '';
    }

    /**
     * Create a hosted checkout session.
     *
     * Credentials are injected here rather than by the caller so that they are
     * never handled - or accidentally logged - outside of this class.
     *
     * @param array $fields Initiate payment fields (without credentials).
     *
     * @return array Normalised response.
     */
    public function initiatePayment(array $fields)
    {
        // PayStation rejects null values. Drop anything empty, but keep "0"
        // so that pay_with_charge=0 (merchant bears the charge) survives.
        $fields = array_filter($fields, function ($value) {
            return $value !== null && $value !== '';
        });

        $payload = array_merge($fields, [
            'merchantId' => $this->merchantId,
            'password' => $this->password,
        ]);

        return $this->request('/initiate-payment', $payload, [
            'Accept: application/json',
        ], false);
    }

    /**
     * Look a transaction up by the merchant generated invoice number (v1).
     *
     * @param string $invoiceNumber
     *
     * @return array Normalised response.
     */
    public function transactionStatus($invoiceNumber)
    {
        return $this->request('/transaction-status', [
            'invoice_number' => (string) $invoiceNumber,
        ], [
            'Accept: application/json',
            'merchantId: ' . $this->merchantId,
        ], false);
    }

    /**
     * Look a transaction up by the PayStation transaction id (v2).
     *
     * @param string $trxId
     *
     * @return array Normalised response.
     */
    public function transactionStatusV2($trxId)
    {
        return $this->request('/v2/transaction-status', [
            'trxId' => (string) $trxId,
        ], [
            'Accept: application/json',
            'merchantId: ' . $this->merchantId,
        ], true);
    }

    /**
     * Perform a POST request and normalise the response.
     *
     * @param string $path    Path appended to the environment base URL.
     * @param array  $body    Request body.
     * @param array  $headers Raw header lines.
     * @param bool   $asJson  Encode the body as JSON instead of form fields.
     *
     * @return array Normalised response.
     */
    protected function request($path, array $body, array $headers, $asJson)
    {
        if (!function_exists('curl_init')) {
            return $this->normalise('', 0, 'The PHP cURL extension is required by the PayStation gateway module.');
        }

        if ($asJson) {
            $encodedBody = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        } else {
            $encodedBody = http_build_query($body);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        $url = $this->baseUrl() . $path;
        $lastError = '';
        $response = false;
        $httpCode = 0;

        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $encodedBody,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                // Certificate verification stays on: these calls carry merchant
                // credentials and decide whether money was actually received.
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_USERAGENT => 'WHMCS-PayStation-Gateway/1.0',
            ]);

            $response = curl_exec($ch);
            $lastError = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response !== false && $lastError === '') {
                break;
            }

            // Only transport failures are retried. A JSON error response is a
            // real answer from PayStation and must not be replayed, because
            // /initiate-payment is not idempotent.
            if ($attempt < $this->retries) {
                usleep(400000 * ($attempt + 1));
            }
        }

        return $this->normalise($response === false ? '' : $response, $httpCode, $lastError);
    }

    /**
     * Turn a raw HTTP response into the normalised array documented above.
     *
     * @param string $raw
     * @param int    $httpCode
     * @param string $error
     *
     * @return array
     */
    protected function normalise($raw, $httpCode, $error)
    {
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            $json = [];
        }

        $statusCode = isset($json['status_code']) ? (string) $json['status_code'] : '';
        $status = isset($json['status']) ? strtolower(trim((string) $json['status'])) : '';
        $message = isset($json['message']) ? (string) $json['message'] : '';
        $data = (isset($json['data']) && is_array($json['data'])) ? $json['data'] : [];

        if ($error === '' && $json === []) {
            $error = 'PayStation returned a non-JSON response (HTTP ' . $httpCode . ').';
        }

        $ok = ($error === '' && $json !== []);

        if ($ok && $statusCode === '' && $status === '') {
            // Valid JSON, but not a PayStation envelope.
            $ok = false;
            $error = 'Unrecognised response from PayStation.';
        }

        return [
            'ok' => $ok,
            'httpCode' => $httpCode,
            'error' => $error,
            'raw' => (string) $raw,
            'json' => $json,
            'statusCode' => $statusCode,
            'status' => $status,
            'message' => $message,
            'data' => $data,
            'accepted' => ($ok && $statusCode === self::CODE_OK && $status === 'success'),
        ];
    }
}
