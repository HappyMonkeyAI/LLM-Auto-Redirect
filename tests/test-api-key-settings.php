<?php
/**
 * Regression tests for API-key preservation and non-disclosing settings fields.
 * Run with: php tests/test-api-key-settings.php
 */

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

$GLOBALS['lar_test_options'] = [];
$GLOBALS['lar_registered_settings'] = [];

function add_action(...$args)
{
}

function register_setting($group, $name, $args = [])
{
    $GLOBALS['lar_registered_settings'][$name] = $args;
}

function add_settings_section(...$args)
{
}

function add_settings_field(...$args)
{
}

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['lar_test_options'])
        ? $GLOBALS['lar_test_options'][$name]
        : $default;
}

function sanitize_text_field($value)
{
    return trim(strip_tags((string) $value));
}

function sanitize_textarea_field($value)
{
    return sanitize_text_field($value);
}

function rest_sanitize_boolean($value)
{
    return (bool) $value;
}

function wp_unslash($value)
{
    return $value;
}

function esc_attr($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function esc_html($value)
{
    return htmlspecialchars((string) $value, ENT_NOQUOTES, 'UTF-8');
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

function setup_settings_test()
{
    $GLOBALS['lar_test_options'] = [
        'lar_openai_api_key' => 'saved-openai-key-DO-NOT-RENDER',
        'lar_openrouter_api_key' => 'saved-openrouter-key-DO-NOT-RENDER',
        'lar_gemini_api_key' => 'saved-gemini-key-DO-NOT-RENDER',
    ];
    $GLOBALS['lar_registered_settings'] = [];
    $_POST = [];

    $plugin = new LLM_Auto_Redirect();
    $plugin->register_settings();
    return $plugin;
}

$key_options = [
    'lar_openai_api_key' => ['render' => 'render_openai_key_field', 'clear' => 'lar_clear_openai_api_key'],
    'lar_openrouter_api_key' => ['render' => 'render_openrouter_key_field', 'clear' => 'lar_clear_openrouter_api_key'],
    'lar_gemini_api_key' => ['render' => 'render_gemini_key_field', 'clear' => 'lar_clear_gemini_api_key'],
];

$tests = [];
$tests['saved keys never appear in rendered input values'] = function () use ($key_options) {
    $plugin = setup_settings_test();
    foreach ($key_options as $option => $field) {
        ob_start();
        $plugin->{$field['render']}();
        $html = ob_get_clean();
        assert_true(strpos($html, $GLOBALS['lar_test_options'][$option]) === false, $option . ' must not render saved secret');
        assert_true(strpos($html, 'value=""') !== false, $option . ' input should be blank');
        assert_true(strpos($html, 'lar_clear_') !== false, $option . ' should expose an explicit clear control');
    }
};

$tests['blank key submissions preserve saved values'] = function () use ($key_options) {
    setup_settings_test();
    foreach ($key_options as $option => $field) {
        $callback = $GLOBALS['lar_registered_settings'][$option]['sanitize_callback'];
        assert_same($GLOBALS['lar_test_options'][$option], call_user_func($callback, ''), $option . ' blank submission');
    }
};

$tests['explicit clear controls remove only their matching key'] = function () use ($key_options) {
    setup_settings_test();
    foreach ($key_options as $option => $field) {
        $_POST = [$field['clear'] => '1'];
        $callback = $GLOBALS['lar_registered_settings'][$option]['sanitize_callback'];
        assert_same('', call_user_func($callback, ''), $option . ' clear submission');
        $_POST = [];
    }
};

$tests['new key submissions are sanitized and stored'] = function () use ($key_options) {
    setup_settings_test();
    foreach ($key_options as $option => $field) {
        $callback = $GLOBALS['lar_registered_settings'][$option]['sanitize_callback'];
        assert_same('new-key-value', call_user_func($callback, '  new-key-value  '), $option . ' replacement value');
    }
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
