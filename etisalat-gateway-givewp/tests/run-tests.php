<?php
/**
 * Standalone test harness for the Etisalat Payment Gateway for GiveWP.
 *
 * This file lets you verify the gateway logic WITHOUT WordPress, GiveWP or a
 * live EPG account. It stubs the WordPress/GiveWP functions the plugin uses,
 * injects canned EPG responses and checks the behaviour of the API client.
 *
 * Usage (from the plugin folder, with PHP CLI):
 *
 *     php tests/run-tests.php
 *
 * Exits with code 0 when all tests pass, 1 otherwise.
 *
 * @package etisalat-gateway-givewp
 */

error_reporting(E_ALL);

define('ABSPATH', __DIR__ . '/');
define('GIVE_ETISALAT_DIR', dirname(__DIR__) . '/');
define('GIVE_ETISALAT_URL', 'https://example.org/wp-content/plugins/etisalat-gateway-givewp/');
define('GIVE_ETISALAT_VERSION', '1.0.0');

/*
 * ---------------------------------------------------------------------------
 * WordPress / GiveWP stubs
 * ---------------------------------------------------------------------------
 */

$GLOBALS['__test_options'] = [];

function give_get_option($key = '', $default = false)
{
    return isset($GLOBALS['__test_options'][$key]) && '' !== $GLOBALS['__test_options'][$key]
        ? $GLOBALS['__test_options'][$key]
        : $default;
}

function give_get_meta($id, $meta_key = '', $single = false, $default = false, $meta_type = '')
{
    $map = isset($GLOBALS['__test_meta'][$id]) ? $GLOBALS['__test_meta'][$id] : [];

    return isset($map[$meta_key]) ? $map[$meta_key] : $default;
}

function give_update_meta($id, $meta_key, $meta_value, $prev_value = '', $meta_type = '')
{
    $GLOBALS['__test_meta'][$id][$meta_key] = $meta_value;

    return true;
}

function give_clean($var)
{
    return is_array($var) ? array_map('give_clean', $var) : htmlspecialchars((string) $var, ENT_QUOTES, 'UTF-8');
}

function give_is_setting_enabled($value, $compare_with = null)
{
    if (!is_null($compare_with)) {
        return is_array($compare_with) ? in_array($value, $compare_with) : ($value === $compare_with);
    }

    return in_array($value, ['enabled', 'on', 'yes'], true);
}

function apply_filters($tag, $value)
{
    return $value;
}

function add_action(...$args)
{
}

function add_filter(...$args)
{
}

function do_action(...$args)
{
}

function wp_json_encode($data, $options = 0)
{
    return json_encode($data, $options);
}

function __($text, $domain = 'default')
{
    return $text;
}

function esc_html__($text, $domain = 'default')
{
    return $text;
}

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function untrailingslashit($string)
{
    return rtrim($string, '/');
}

/*
 * ---------------------------------------------------------------------------
 * Test doubles
 * ---------------------------------------------------------------------------
 */

require GIVE_ETISALAT_DIR . 'includes/class-etisalat-api.php';

class EtisalatApiTestDouble extends EtisalatApi
{
    public $postedBodies = [];
    public $cannedResponses = [];

    protected function post($body)
    {
        $this->postedBodies[] = $body;

        if (null === ($response = array_shift($this->cannedResponses))) {
            return '';
        }

        return $response;
    }
}

/*
 * ---------------------------------------------------------------------------
 * Tiny assertion helpers
 * ---------------------------------------------------------------------------
 */

$testsRun = 0;
$testsFailed = 0;

function check($label, $condition)
{
    global $testsRun, $testsFailed;
    $testsRun++;

    if ($condition) {
        echo "  PASS  {$label}\n";
    } else {
        $testsFailed++;
        echo "  FAIL  {$label}\n";
    }
}

/*
 * ---------------------------------------------------------------------------
 * Tests
 * ---------------------------------------------------------------------------
 */

echo "Etisalat Payment Gateway for GiveWP - API client tests\n";
echo str_repeat('=', 60) . "\n";

/*
 * 1. Endpoint URL building.
 */
echo "\n[1] Endpoint URL building\n";

$GLOBALS['__test_options'] = [];
$api = new EtisalatApiTestDouble();
check('defaults to production URL + port 2443', $api->buildEndpointUrl() === 'https://ipg.comtrust.ae:2443');

$GLOBALS['__test_options'] = [
    'give_etisalat_gateway_url' => 'https://demo-ipg.ctdev.comtrust.ae',
];
$api = new EtisalatApiTestDouble();
check('uses the configured sandbox URL', $api->buildEndpointUrl() === 'https://demo-ipg.ctdev.comtrust.ae:2443');

$GLOBALS['__test_options'] = [
    'give_etisalat_gateway_url' => 'https://ipg.example.com:9443/',
    'give_etisalat_port' => '443',
];
$api = new EtisalatApiTestDouble();
check('keeps an existing port and strips trailing slash', $api->buildEndpointUrl() === 'https://ipg.example.com:9443');

/*
 * 2. Registration payload.
 */
echo "\n[2] Registration request payload\n";

$GLOBALS['__test_options'] = [
    'give_etisalat_customer_id' => 'Demo Merchant',
    'give_etisalat_username' => 'Demo_fY9c',
    'give_etisalat_password' => 'secret-pass',
    'give_etisalat_transaction_hint' => 'CPT:Y;VCC:Y;',
    // Store/Terminal intentionally left empty.
];

$api = new EtisalatApiTestDouble();
$api->cannedResponses[] = json_encode([
    'Transaction' => [
        'PaymentPage' => 'https://demoipg.comtrust.ae/PaymentEx/Payment?lang=en',
        'ResponseCode' => '0',
        'ResponseDescription' => 'Request Processed Successfully',
        'TransactionID' => '847718745846',
    ],
]);

$transaction = $api->registerTransaction([
    'Currency' => 'AED',
    'Amount' => '50.00',
    'OrderID' => '123',
    'OrderName' => 'Donation #123',
    'OrderInfo' => 'Test Fund - Jane Doe',
    'Channel' => 'Web',
    'TransactionHint' => $api->getTransactionHint(),
    'ReturnPath' => 'https://example.org/?give-listener=give-gateway',
    'Store' => '',
    'Terminal' => '',
]);

$sent = json_decode($api->postedBodies[0], true);

check('body is wrapped in a Registration object', isset($sent['Registration']) && count($sent) === 1);
check('credentials are merged into the payload', $sent['Registration']['Customer'] === 'Demo Merchant'
    && $sent['Registration']['UserName'] === 'Demo_fY9c'
    && $sent['Registration']['Password'] === 'secret-pass');
check('empty optional fields are omitted', !isset($sent['Registration']['Store']) && !isset($sent['Registration']['Terminal']));
check('transaction fields are passed through', $sent['Registration']['Amount'] === '50.00'
    && $sent['Registration']['Currency'] === 'AED'
    && $sent['Registration']['OrderID'] === '123'
    && $sent['Registration']['Channel'] === 'Web');
check('success response is parsed', $transaction['TransactionID'] === '847718745846');

/*
 * 3. Registration failure surfaces an exception with the gateway description.
 */
echo "\n[3] Registration failure handling\n";

$api = new EtisalatApiTestDouble();
$api->cannedResponses[] = json_encode([
    'Transaction' => [
        'ResponseCode' => '5000',
        'ResponseDescription' => 'Invalid Customer',
        'ResponseClassDescription' => 'Failure',
    ],
]);

try {
    $api->registerTransaction(['Currency' => 'AED', 'Amount' => '10.00', 'OrderID' => '1', 'OrderName' => 'x', 'Channel' => 'Web', 'ReturnPath' => 'https://example.org/']);
    check('invalid registration throws', false);
} catch (EtisalatApiException $e) {
    check('invalid registration throws with description', strpos($e->getMessage(), 'Invalid Customer') !== false);
    check('exception carries the raw response', isset($e->response['ResponseCode']) && $e->response['ResponseCode'] === '5000');
}

/*
 * 4. Finalization: success, pending (210) and failure.
 */
echo "\n[4] Finalization handling\n";

$api = new EtisalatApiTestDouble();
$api->cannedResponses[] = json_encode([
    'Transaction' => [
        'ResponseCode' => '0',
        'ApprovalCode' => '421218',
        'CardNumber' => '411111******1111',
        'CardBrand' => 'Visa',
        'Amount' => ['Value' => '50.000'],
    ],
]);

$transaction = $api->finalizeTransaction('847718745846');
$sent = json_decode($api->postedBodies[0], true);

check('finalization payload shape', isset($sent['Finalization'])
    && $sent['Finalization']['TransactionID'] === '847718745846'
    && $sent['Finalization']['Customer'] === 'Demo Merchant');
check('finalization success parses approval code', $transaction['ApprovalCode'] === '421218');

$api = new EtisalatApiTestDouble();
$api->cannedResponses[] = json_encode([
    'Transaction' => [
        'ResponseCode' => '210',
        'ResponseDescription' => 'Pending',
    ],
]);

try {
    $transaction = $api->finalizeTransaction('847718745846');
    check('response 210 is returned, not thrown', (int) $transaction['ResponseCode'] === 210);
} catch (EtisalatApiException $e) {
    check('response 210 is returned, not thrown', false);
}

$api = new EtisalatApiTestDouble();
$api->cannedResponses[] = json_encode([
    'Transaction' => [
        'ResponseCode' => '702',
        'ResponseDescription' => 'Transaction failed',
    ],
]);

try {
    $api->finalizeTransaction('847718745846');
    check('failing finalization throws', false);
} catch (EtisalatApiException $e) {
    check('failing finalization throws with description', strpos($e->getMessage(), 'Transaction failed') !== false);
}

/*
 * 5. Refund payload.
 */
echo "\n[5] Refund request payload\n";

$api = new EtisalatApiTestDouble();
$api->cannedResponses[] = json_encode([
    'Transaction' => ['ResponseCode' => '0', 'ResponseDescription' => 'Request processed successfully'],
]);

$api->refundTransaction('847718745846', '50.00', 'AED');
$sent = json_decode($api->postedBodies[0], true);

check('refund payload shape', isset($sent['Refund'])
    && $sent['Refund']['TransactionID'] === '847718745846'
    && $sent['Refund']['Amount'] === '50.00'
    && $sent['Refund']['Currency'] === 'AED');

/*
 * 6. Unexpected (non-JSON) responses.
 */
echo "\n[6] Unexpected responses\n";

$api = new EtisalatApiTestDouble();
$api->cannedResponses[] = '<html>Service Unavailable</html>';
$api->cannedResponses[] = '<html>Service Unavailable</html>'; // retried once with the alternate Accept header

try {
    $api->finalizeTransaction('847718745846');
    check('non-JSON response throws', false);
} catch (EtisalatApiException $e) {
    check('non-JSON response throws (after one retry)', strpos($e->getMessage(), 'Unexpected response') !== false
        && count($api->postedBodies) === 2);
}

$api = new EtisalatApiTestDouble();
$api->cannedResponses[] = '';
$api->cannedResponses[] = json_encode(['Transaction' => ['ResponseCode' => '0']]);

try {
    $api->finalizeTransaction('847718745846');
    check('retry with alternate Accept header recovers', true);
} catch (EtisalatApiException $e) {
    check('retry with alternate Accept header recovers', false);
}

/*
 * 7. Configuration checks.
 */
echo "\n[7] Configuration\n";

$GLOBALS['__test_options'] = [];
$api = new EtisalatApiTestDouble();
check('unconfigured gateway is detected', $api->isConfigured() === false);

$GLOBALS['__test_options'] = ['give_etisalat_customer_id' => 'Demo Merchant'];
$api = new EtisalatApiTestDouble();
check('configured gateway is detected', $api->isConfigured() === true);

check('default transaction hint', $api->getTransactionHint() === 'CPT:Y;VCC:Y;');

/*
 * Results
 */
echo "\n" . str_repeat('=', 60) . "\n";
echo "Tests: {$testsRun}, Failed: {$testsFailed}\n";
exit($testsFailed > 0 ? 1 : 0);
