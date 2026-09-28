<?php
/**
 * Regression tests for the admin-side OpenAI-compatible connection check.
 * Run with: php tests/test-connection-diagnostics.php
 */

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

class WP_Error
{
    private $code;
    private $message;

    public function __construct($code = '', $message = '')
    {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_code()
    {
        return $this->code;
    }

    public function get_error_message()
    {
        return $this->message;
    }
}

class WP_Test_Response extends RuntimeException
{
    public $success;
    public $payload;
    public $status;

    public function __construct($success, $payload, $status = null)
    {
        $this->success = $success;
        $this->payload = $payload;
        $this->status = $status;
        parent::__construct('Captured WordPress AJAX response');
    }
}

$GLOBALS['lar_test_options'] = [];
$GLOBALS['lar_test_response'] = [];
$GLOBALS['lar_test_request'] = null;
$GLOBALS['lar_test_hooks'] = [];
$GLOBALS['lar_test_nonce_checks'] = 0;
$GLOBALS['lar_test_can_manage'] = true;

function add_action($name, $callback)
{
    $GLOBALS['lar_test_hooks'][$name] = $callback;
}

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['lar_test_options'])
        ? $GLOBALS['lar_test_options'][$name]
        : $default;
}

function wp_remote_post($url, $args = [])
{
    $GLOBALS['lar_test_request'] = ['url' => $url, 'args' => $args];
    return $GLOBALS['lar_test_response'];
}

function is_wp_error($value)
{
    return $value instanceof WP_Error;
}

function wp_remote_retrieve_body($response)
{
    return $response['body'] ?? '';
}

function wp_remote_retrieve_response_code($response)
{
    return $response['response']['code'] ?? 0;
}

function sanitize_text_field($value)
{
    return trim(strip_tags((string) $value));
}

function check_ajax_referer($action, $query_arg)
{
    $GLOBALS['lar_test_nonce_checks']++;
    return true;
}

function current_user_can($capability)
{
    return $GLOBALS['lar_test_can_manage'];
}

function wp_send_json_success($data, $status_code = null)
{
    throw new WP_Test_Response(true, $data, $status_code);
}

function wp_send_json_error($data, $status_code = null)
{
    throw new WP_Test_Response(false, $data, $status_code);
}

require_once dirname(__DIR__) . '/LLM_Auto_Redirect.php';

function assert_same($expected, $actual, $label)
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_true($condition, $label)
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

function setup_connection_test($body, $status = 200)
{
    $GLOBALS['lar_test_options'] = [
        'lar_openai_api_key' => 'test-token',
        'lar_openai_model' => 'test-model',
        'lar_openai_base_url' => 'https://llm.example/v1/',
        'lar_openai_json_schema' => false,
    ];
    $GLOBALS['lar_test_response'] = [
        'response' => ['code' => $status],
        'body' => $body,
    ];
    $GLOBALS['lar_test_request'] = null;
    $GLOBALS['lar_test_nonce_checks'] = 0;
    $GLOBALS['lar_test_can_manage'] = true;
    $_POST = [];
    return new LLM_Auto_Redirect();
}

function invoke_connection_test($plugin)
{
    try {
        $plugin->handle_openai_connection_test_ajax();
    } catch (WP_Test_Response $response) {
        return $response;
    }
    throw new RuntimeException('Connection check did not return a JSON response');
}

$tests = [];
$tests['authorized check uses configured endpoint and hides model output'] = function () {
    $plugin = setup_connection_test(json_encode(['choices' => [['message' => ['content' => 'private test completion']]]]));
    $response = invoke_connection_test($plugin);

    assert_same(true, $response->success, 'response success');
    assert_same('OpenAI-compatible API responded successfully.', $response->payload['message'], 'public response message');
    assert_same(1, $GLOBALS['lar_test_nonce_checks'], 'nonce validation');
    assert_same('https://llm.example/v1/chat/completions', $GLOBALS['lar_test_request']['url'], 'request route');
    $body = json_decode($GLOBALS['lar_test_request']['args']['body'], true);
    assert_same(32, $body['max_tokens'], 'diagnostic token limit');
    assert_true(strpos(json_encode($response->payload), 'private test completion') === false, 'model output must not be returned');
};

$tests['provider error response is surfaced without raw response data'] = function () {
    $plugin = setup_connection_test(json_encode(['error' => ['message' => 'Unexpected endpoint or method']]), 200);
    $response = invoke_connection_test($plugin);

    assert_same(false, $response->success, 'response should fail');
    assert_true(strpos($response->payload, 'Unexpected endpoint or method') !== false, 'provider diagnostic should be visible');
    assert_true(strpos($response->payload, 'test-token') === false, 'token must not appear in diagnostics');
};

$tests['users without manage_options cannot trigger a provider request'] = function () {
    $plugin = setup_connection_test(json_encode(['choices' => [['message' => ['content' => 'unused']]]]));
    $GLOBALS['lar_test_can_manage'] = false;
    $response = invoke_connection_test($plugin);

    assert_same(false, $response->success, 'response should fail');
    assert_same(403, $response->status, 'permission failure status');
    assert_same(null, $GLOBALS['lar_test_request'], 'provider must not be called');
};

$tests['connection test AJAX action is registered'] = function () {
    setup_connection_test('{}');
    assert_true(isset($GLOBALS['lar_test_hooks']['wp_ajax_lar_test_openai_connection']), 'authenticated AJAX hook should be registered');
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        fwrite(STDOUT, "PASS: {$name}\n");
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL: {$name} — {$error->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf("%d test(s), %d failure(s)\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);
