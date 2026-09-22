<?php
/**
 * Etisalat Payment Gateway (EPG) REST API client.
 *
 * Implements the e-commerce REST interface described in the "Etisalat Payment
 * Gateway Integration Guide - Ecommerce Payment REST" (EPG-REST-Integration):
 *
 *   Registration  - register a transaction, returns TransactionID + PaymentPage URL
 *   Finalization  - complete the transaction after the payer returns from the payment page
 *   Refund        - refund a settled transaction (requires bank approval)
 *
 * All calls are sent as JSON to the EPG endpoint (default port 2443) over TLS 1.2.
 *
 * @package etisalat-gateway-givewp
 */

defined('ABSPATH') || exit;

/**
 * Thrown when an EPG API call cannot be completed or the gateway rejects it.
 */
class EtisalatApiException extends Exception
{
    /**
     * @var array|null Raw Transaction payload returned by the gateway, when available.
     */
    public $response;

    /**
     * @param string $message
     * @param array|null $response
     */
    public function __construct($message, $response = null)
    {
        parent::__construct($message);

        $this->response = $response;
    }
}

/**
 * EPG REST API client.
 *
 * Configuration comes from the GiveWP gateway settings (Donations → Settings →
 * Payment Gateways → Etisalat) but every value can be overridden through the
 * 'give_etisalat_api_config' filter or the constructor.
 */
class EtisalatApi
{
    /** EPG production endpoint (per the integration guide, Appendix B). */
    const PRODUCTION_URL = 'https://ipg.comtrust.ae';

    /** EPG sandbox endpoint (per the integration guide, Appendix B). */
    const SANDBOX_URL = 'https://demo-ipg.ctdev.comtrust.ae';

    /** EPG REST API port (per the integration guide, Appendix B). */
    const DEFAULT_PORT = 2443;

    /** Documented Accept header for the REST interface. */
    const ACCEPT_JSON = 'application/json';

    /** Alternative Accept header used by several bank-provided integrations. */
    const ACCEPT_XML_STANDARD = 'text/xml-standard-api';

    /** Request timeout in seconds. */
    const TIMEOUT = 60;

    /**
     * @var string
     */
    private $gatewayUrl;

    /**
     * @var int
     */
    private $port;

    /**
     * @var string
     */
    private $customer;

    /**
     * @var string
     */
    private $userName;

    /**
     * @var string
     */
    private $password;

    /**
     * @var string
     */
    private $acceptHeader;

    /**
     * @var bool
     */
    private $verifySsl;

    /**
     * @var bool
     */
    private $debug;

    /**
     * @var string Path to the CA bundle used for peer verification.
     */
    private $caBundle;

    /**
     * @var array Redacted log of the most recent request/response pair.
     */
    public $lastLog = [];

    /**
     * @param array $config {
     *     Optional overrides. Falls back to GiveWP settings, then to defaults.
     *
     *     @type string $gateway_url
     *     @type int    $port
     *     @type string $customer
     *     @type string $username
     *     @type string $password
     *     @type string $accept_header
     *     @type bool   $verify_ssl
     *     @type bool   $debug
     * }
     */
    public function __construct(array $config = [])
    {
        $settings = self::getSettings();

        $config = array_merge($settings, $config);
        /**
         * Filter the configuration used for EPG API calls.
         *
         * @param array $config
         */
        $config = apply_filters('give_etisalat_api_config', $config);

        $this->gatewayUrl  = untrailingslashit(trim(self::value($config, 'gateway_url', self::PRODUCTION_URL)));
        $this->port        = (int) self::value($config, 'port', self::DEFAULT_PORT);
        $this->customer    = (string) self::value($config, 'customer', '');
        $this->userName    = (string) self::value($config, 'username', '');
        $this->password    = (string) self::value($config, 'password', '');
        $this->acceptHeader = (string) self::value($config, 'accept_header', self::ACCEPT_JSON);
        $this->verifySsl   = (bool) self::value($config, 'verify_ssl', true);
        $this->debug       = (bool) self::value($config, 'debug', false);
        $this->caBundle    = GIVE_ETISALAT_DIR . 'certificates/ca.crt';
    }

    /**
     * Build a client from the saved GiveWP settings.
     *
     * @return self
     */
    public static function fromSettings()
    {
        return new self();
    }

    /**
     * Read the plugin settings with sensible defaults.
     *
     * @return array
     */
    public static function getSettings()
    {
        return [
            'gateway_url'   => function_exists('give_get_option')
                ? give_get_option('give_etisalat_gateway_url', self::PRODUCTION_URL)
                : self::PRODUCTION_URL,
            'port'          => function_exists('give_get_option')
                ? give_get_option('give_etisalat_port', self::DEFAULT_PORT)
                : self::DEFAULT_PORT,
            'customer'      => function_exists('give_get_option') ? give_get_option('give_etisalat_customer_id', '') : '',
            'username'      => function_exists('give_get_option') ? give_get_option('give_etisalat_username', '') : '',
            'password'      => function_exists('give_get_option') ? give_get_option('give_etisalat_password', '') : '',
            'accept_header' => function_exists('give_get_option')
                ? give_get_option('give_etisalat_accept_header', self::ACCEPT_JSON)
                : self::ACCEPT_JSON,
            'verify_ssl'    => function_exists('give_get_option') && function_exists('give_is_setting_enabled')
                ? give_is_setting_enabled(give_get_option('give_etisalat_sslverify', 'enabled'))
                : true,
            'debug'         => function_exists('give_get_option') && function_exists('give_is_setting_enabled')
                ? give_is_setting_enabled(give_get_option('give_etisalat_debug', 'off'))
                : false,
        ];
    }

    /**
     * TransactionHint setting (payment instruments / capture behaviour).
     *
     * @return string
     */
    public function getTransactionHint()
    {
        $hint = function_exists('give_get_option')
            ? give_get_option('give_etisalat_transaction_hint', 'CPT:Y;VCC:Y;')
            : 'CPT:Y;VCC:Y;';

        return (string) apply_filters('give_etisalat_transaction_hint', $hint);
    }

    /**
     * Whether the gateway has the credentials it needs.
     *
     * @return bool
     */
    public function isConfigured()
    {
        return '' !== $this->customer && '' !== $this->gatewayUrl;
    }

    /**
     * Registration: register a transaction before redirecting the payer.
     *
     * @param array $params Request body (Currency, Amount, OrderID, OrderName,
     *                       OrderInfo, Channel, TransactionHint, ReturnPath,
     *                       Store, Terminal).
     *
     * @return array The Transaction object from the EPG response.
     * @throws EtisalatApiException
     */
    public function registerTransaction(array $params)
    {
        return $this->request('Registration', array_merge([
            'Customer' => $this->customer,
            'UserName' => $this->userName,
            'Password' => $this->password,
        ], $params));
    }

    /**
     * Finalization: complete a transaction after the payer returns from the
     * EPG payment page.
     *
     * Response code 0 means the payment succeeded. Code 210 means the payment
     * is an offline (Central Bank) payment that is pending settlement, so it
     * is returned instead of thrown.
     *
     * @param string $transactionId TransactionID returned by Registration.
     *
     * @return array The Transaction object from the EPG response.
     * @throws EtisalatApiException
     */
    public function finalizeTransaction($transactionId)
    {
        /*
         * A declined/cancelled Finalization response is still a valid API
         * response which the gateway handler must inspect and map to FAILED.
         * An empty allow-list means "return every numeric EPG response code";
         * transport, JSON and response-shape errors still throw.
         */
        return $this->request('Finalization', [
            'TransactionID' => $transactionId,
            'Customer'      => $this->customer,
            'UserName'      => $this->userName,
            'Password'      => $this->password,
        ], []);
    }

    /**
     * Refund: refund a settled transaction. Requires bank approval to be
     * enabled on your EPG account.
     *
     * @param string $transactionId
     * @param string $amount  Decimal amount, e.g. "50.00".
     * @param string $currency Currency code, e.g. "AED".
     *
     * @return array The Transaction object from the EPG response.
     * @throws EtisalatApiException
     */
    public function refundTransaction($transactionId, $amount, $currency)
    {
        return $this->request('Refund', [
            'TransactionID' => $transactionId,
            'Amount'        => $amount,
            'Currency'      => $currency,
            'Customer'      => $this->customer,
            'UserName'      => $this->userName,
            'Password'      => $this->password,
        ]);
    }

    /**
     * Perform an EPG API request.
     *
     * @param string $operation Registration|Finalization|Refund|...
     * @param array  $data      Operation payload.
     * @param int[]  $allowedResponseCodes Response codes that should be returned
     *                                      instead of throwing (default: success only).
     *
     * @return array The Transaction object from the response.
     * @throws EtisalatApiException
     */
    private function request($operation, array $data, array $allowedResponseCodes = [0])
    {
        $data = array_filter($data, static function ($value) {
            return null !== $value && '' !== $value;
        });

        $body = wp_json_encode([$operation => $data]);

        if (false === $body) {
            throw new EtisalatApiException(__('Unable to encode the request for the Etisalat gateway.', 'etisalat-gateway-givewp'));
        }

        $responseBody = $this->post($body);

        $decoded = json_decode($responseBody, true);

        /*
         * Some EPG instances only respond with JSON when the legacy
         * "text/xml-standard-api" Accept header is sent. Retry once with the
         * alternative header before giving up.
         */
        if (null === $decoded && $this->acceptHeader !== self::ACCEPT_XML_STANDARD) {
            $acceptHeader = $this->acceptHeader;
            $this->acceptHeader = self::ACCEPT_XML_STANDARD;

            try {
                $responseBody = $this->post($body);
            } finally {
                $this->acceptHeader = $acceptHeader;
            }

            $decoded = json_decode($responseBody, true);
        }

        if (!is_array($decoded)) {
            throw new EtisalatApiException(
                sprintf(
                    /* translators: %s: raw gateway response (truncated) */
                    __('Unexpected response from the Etisalat gateway: %s', 'etisalat-gateway-givewp'),
                    substr((string) $responseBody, 0, 500)
                )
            );
        }

        $transaction = isset($decoded['Transaction']) && is_array($decoded['Transaction'])
            ? $decoded['Transaction']
            : $decoded;

        if (!isset($transaction['ResponseCode']) || !is_numeric($transaction['ResponseCode'])) {
            throw new EtisalatApiException(
                __('The Etisalat gateway response did not contain a valid ResponseCode.', 'etisalat-gateway-givewp'),
                $transaction
            );
        }

        $responseCode = (int) $transaction['ResponseCode'];

        if ($allowedResponseCodes && !in_array($responseCode, $allowedResponseCodes, true)) {
            throw new EtisalatApiException(
                sprintf(
                    /* translators: 1: response code, 2: response description, 3: response class description */
                    __('Etisalat gateway error %1$s: %2$s %3$s', 'etisalat-gateway-givewp'),
                    isset($transaction['ResponseCode']) ? $transaction['ResponseCode'] : '-',
                    isset($transaction['ResponseDescription']) ? $transaction['ResponseDescription'] : '',
                    isset($transaction['ResponseClassDescription']) ? '(' . $transaction['ResponseClassDescription'] . ')' : ''
                ),
                $transaction
            );
        }

        return $transaction;
    }

    /**
     * Build the full endpoint URL (including the port) API calls are sent to.
     *
     * @return string
     */
    public function buildEndpointUrl()
    {
        $url = $this->gatewayUrl;
        $port = $this->port;

        if ($port && strpos($url, ':' . $port) === false && !preg_match('#:\d+#', $url)) {
            $url .= ':' . $port;
        }

        return $url;
    }

    /**
     * POST the JSON body to the EPG endpoint.
     *
     * Uses cURL (to guarantee TLS 1.2, a custom port and the bundled CA
     * bundle, as required by the EPG integration guide) and falls back to
     * the WordPress HTTP API when cURL is unavailable.
     *
     * Subclasses may override this method to stub the transport in tests.
     *
     * @param string $body JSON request body.
     *
     * @return string Raw response body.
     * @throws EtisalatApiException
     */
    protected function post($body)
    {
        $url = $this->buildEndpointUrl();

        $started = microtime(true);

        if (function_exists('curl_init')) {
            $response = $this->postWithCurl($url, $body, $error);
        } else {
            $response = $this->postWithWpHttp($url, $body, $error);
        }

        $this->lastLog = [
            'url'        => $url,
            'accept'     => $this->acceptHeader,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'error'      => $error,
            'response'   => is_string($response) ? substr($response, 0, 2000) : $response,
        ];

        if (null !== $error) {
            throw new EtisalatApiException(
                sprintf(
                    /* translators: %s: connection error */
                    __('Could not reach the Etisalat payment gateway: %s', 'etisalat-gateway-givewp'),
                    $error
                )
            );
        }

        return (string) $response;
    }

    /**
     * cURL transport.
     *
     * @param string $url
     * @param string $body
     * @param string|null &$error
     *
     * @return string|null
     */
    private function postWithCurl($url, $body, &$error)
    {
        $error = null;

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: ' . $this->acceptHeader,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FORBID_REUSE   => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 30,
            // EPG requires TLS 1.2.
            CURLOPT_SSLVERSION     => defined('CURL_SSLVERSION_TLSv1_2') ? CURL_SSLVERSION_TLSv1_2 : 6,
        ]);

        if ($this->verifySsl) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            if (is_readable($this->caBundle)) {
                curl_setopt($ch, CURLOPT_CAINFO, $this->caBundle);
            }
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        /**
         * Filter the cURL options used for EPG API calls.
         *
         * @param array $curlOptions
         * @param string $url
         */
        $curlOptions = apply_filters('give_etisalat_curl_options', [], $url);

        if ($curlOptions) {
            curl_setopt_array($ch, $curlOptions);
        }

        $response = curl_exec($ch);

        if (false === $response) {
            $error = curl_error($ch) ?: __('unknown cURL error', 'etisalat-gateway-givewp');
            $response = null;
        } elseif ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 400) {
            // EPG reports problems inside the JSON body even with HTTP error codes,
            // so pass the body along for parsing instead of failing here.
        }

        curl_close($ch);

        return $response;
    }

    /**
     * WordPress HTTP API transport (fallback).
     *
     * @param string $url
     * @param string $body
     * @param string|null &$error
     *
     * @return string|null
     */
    private function postWithWpHttp($url, $body, &$error)
    {
        $error = null;

        $args = [
            'method'      => 'POST',
            'body'        => $body,
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            'headers'     => [
                'Content-Type' => 'application/json',
                'Accept'       => $this->acceptHeader,
            ],
            'sslverify'   => $this->verifySsl,
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            $error = $response->get_error_message();

            return null;
        }

        return (string) wp_remote_retrieve_body($response);
    }

    /**
     * Fetch a value from a config array with a default.
     *
     * @param array $config
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     */
    private static function value(array $config, $key, $default = null)
    {
        return isset($config[$key]) && null !== $config[$key] && '' !== $config[$key] ? $config[$key] : $default;
    }
}
