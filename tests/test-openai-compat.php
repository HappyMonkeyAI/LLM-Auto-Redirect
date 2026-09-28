<?php
/**
 * Lightweight regression tests for the OpenAI-compatible provider.
 * Run with: php tests/test-openai-compat.php
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

$GLOBALS['lar_test_options'] = [];
$GLOBALS['lar_test_response'] = [];
$GLOBALS['lar_test_request'] = null;

function add_action(...$args)
{
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
    $value = strip_tags((string) $value);
    return trim(preg_replace('/[\r\n\t ]+/', ' ', $value));
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

function call_openai_for_test()
{
    $plugin = new LLM_Auto_Redirect();
    $method = new ReflectionMethod(LLM_Auto_Redirect::class, 'call_openai');
    $method->setAccessible(true);
    return $method->invoke($plugin, 'system prompt', 'user prompt');
}

function set_response($status, $body)
{
    $GLOBALS['lar_test_response'] = [
        'response' => ['code' => $status],
        'body' => $body,
    ];
}

$tests = [];

$tests['success keeps the OpenAI-compatible route and schema payload'] = function () {
    $GLOBALS['lar_test_options'] = [
        'lar_openai_api_key' => 'test-token-do-not-leak',
        'lar_openai_model' => 'google/gemma-4-e2b',
        'lar_openai_base_url' => 'https://llm.example/v1/',
        'lar_openai_json_schema' => true,
    ];
    set_response(200, json_encode(['choices' => [['message' => ['content' => '{"suggestion":"https://example.com/events/"}']]] ]));

    $result = call_openai_for_test();

    assert_same('{"suggestion":"https://example.com/events/"}', $result, 'completion content');
    assert_same('https://llm.example/v1/chat/completions', $GLOBALS['lar_test_request']['url'], 'request URL');
    assert_same('Bearer test-token-do-not-leak', $GLOBALS['lar_test_request']['args']['headers']['Authorization'], 'authorization header');
    $body = json_decode($GLOBALS['lar_test_request']['args']['body'], true);
    assert_same('google/gemma-4-e2b', $body['model'], 'model');
    assert_same('json_schema', $body['response_format']['type'], 'response format');
};

$tests['HTTP 200 LM Studio error payload becomes actionable WP_Error'] = function () {
    $GLOBALS['lar_test_options'] = [
        'lar_openai_api_key' => 'test-token-do-not-leak',
        'lar_openai_model' => 'google/gemma-4-e2b',
        'lar_openai_base_url' => 'https://llm.example/v1',
        'lar_openai_json_schema' => false,
    ];
    set_response(200, json_encode(['error' => ['message' => 'Unexpected endpoint or method. (POST /v1/chat/completions). Returning 200 anyway']]));

    $result = call_openai_for_test();

    assert_true(is_wp_error($result), 'expected WP_Error for error JSON with HTTP 200');
    assert_same('openai_api_error', $result->get_error_code(), 'error code');
    assert_true(strpos($result->get_error_message(), 'Unexpected endpoint or method') !== false, 'error message should include provider diagnostic');
};

$tests['non-2xx errors include status and redact the configured token'] = function () {
    $GLOBALS['lar_test_options'] = [
        'lar_openai_api_key' => 'test-token-do-not-leak',
        'lar_openai_model' => 'google/gemma-4-e2b',
        'lar_openai_base_url' => 'https://llm.example/v1',
        'lar_openai_json_schema' => false,
    ];
    set_response(401, json_encode(['error' => ['message' => 'Invalid token test-token-do-not-leak']]));

    $result = call_openai_for_test();

    assert_true(is_wp_error($result), 'expected WP_Error for HTTP 401');
    assert_same('openai_http', $result->get_error_code(), 'error code');
    assert_true(strpos($result->get_error_message(), 'HTTP 401') !== false, 'error message should include status');
    assert_true(strpos($result->get_error_message(), 'test-token-do-not-leak') === false, 'error message must not expose the token');
};

$tests['non-JSON HTTP errors still expose status without raw body'] = function () {
    $GLOBALS['lar_test_options'] = [
        'lar_openai_api_key' => '',
        'lar_openai_model' => 'google/gemma-4-e2b',
        'lar_openai_base_url' => 'https://llm.example/v1',
        'lar_openai_json_schema' => false,
    ];
    set_response(502, '<html>upstream failure</html>');

    $result = call_openai_for_test();

    assert_true(is_wp_error($result), 'expected WP_Error for HTTP 502');
    assert_same('openai_http', $result->get_error_code(), 'error code');
    assert_true(strpos($result->get_error_message(), 'HTTP 502') !== false, 'error message should include status');
    assert_true(strpos($result->get_error_message(), '<html>') === false, 'error message must not expose raw HTML body');
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
