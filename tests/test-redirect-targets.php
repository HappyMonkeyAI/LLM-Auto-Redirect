<?php
/**
 * Regression tests for restricting model suggestions to listed site pages.
 * Run with: php tests/test-redirect-targets.php
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
$GLOBALS['lar_test_content'] = '';

function add_action(...$args)
{
}

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['lar_test_options'])
        ? $GLOBALS['lar_test_options'][$name]
        : $default;
}

function get_site_url()
{
    return 'https://example.test';
}

function get_pages($args = [])
{
    return [(object) ['ID' => 17]];
}

function get_permalink($page_id)
{
    return 'https://example.test/about/';
}

function wp_remote_post($url, $args = [])
{
    return [
        'response' => ['code' => 200],
        'body' => json_encode(['choices' => [['message' => ['content' => $GLOBALS['lar_test_content']]]]]),
    ];
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

function suggest_for_test($content)
{
    $GLOBALS['lar_test_options'] = [
        'lar_llm_provider' => 'openai',
        'lar_openai_api_key' => 'test-token',
        'lar_openai_model' => 'test-model',
        'lar_openai_base_url' => 'https://llm.example/v1',
        'lar_openai_json_schema' => false,
    ];
    $GLOBALS['lar_test_content'] = $content;

    $plugin = new LLM_Auto_Redirect();
    $method = new ReflectionMethod(LLM_Auto_Redirect::class, 'get_llm_suggestion');
    $method->setAccessible(true);
    return $method->invoke($plugin, '/missing-page/');
}

$tests = [];
$tests['listed site destination is accepted'] = function () {
    assert_same('https://example.test/about/', suggest_for_test('https://example.test/about/'), 'listed page suggestion');
};
$tests['external destination is rejected'] = function () {
    $result = suggest_for_test('https://attacker.test/phishing/');
    assert_true(is_wp_error($result), 'external suggestions must be rejected');
    assert_same('invalid_target', $result->get_error_code(), 'error code');
};
$tests['same-host but unlisted destination is rejected'] = function () {
    $result = suggest_for_test('https://example.test/unknown/');
    assert_true(is_wp_error($result), 'unlisted same-host suggestions must be rejected');
    assert_same('invalid_target', $result->get_error_code(), 'error code');
};
$tests['malformed suggestion is rejected'] = function () {
    $result = suggest_for_test('not a URL');
    assert_true(is_wp_error($result), 'malformed suggestions must be rejected');
    assert_same('invalid_url', $result->get_error_code(), 'error code');
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
