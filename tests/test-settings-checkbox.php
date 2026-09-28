<?php
/**
 * Regression tests for saving the structured-output checkbox in both states.
 * Run with: php tests/test-settings-checkbox.php
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

function rest_sanitize_boolean($value)
{
    if (is_string($value)) {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
    return (bool) $value;
}

function checked($checked, $current = true, $echo = true)
{
    return ((string) $checked === (string) $current) ? 'checked="checked"' : '';
}

function assert_true($condition, $label)
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

require_once dirname(__DIR__) . '/LLM_Auto_Redirect.php';

$tests = [];
$tests['render includes hidden false and checked true controls'] = function () {
    $GLOBALS['lar_test_options'] = ['lar_openai_json_schema' => true];
    $plugin = new LLM_Auto_Redirect();
    ob_start();
    $plugin->render_openai_json_schema_field();
    $html = ob_get_clean();

    $hidden = strpos($html, 'type="hidden" name="lar_openai_json_schema" value="0"');
    $checkbox = strpos($html, 'type="checkbox" name="lar_openai_json_schema" value="1" checked="checked"');
    assert_true($hidden !== false, 'unchecked state needs an explicit zero field');
    assert_true($checkbox !== false, 'saved true state should render checked');
    assert_true($hidden < $checkbox, 'hidden zero must precede checkbox one');
};

$tests['render leaves checkbox unchecked when saved false'] = function () {
    $GLOBALS['lar_test_options'] = ['lar_openai_json_schema' => false];
    $plugin = new LLM_Auto_Redirect();
    ob_start();
    $plugin->render_openai_json_schema_field();
    $html = ob_get_clean();

    assert_true(strpos($html, 'type="checkbox" name="lar_openai_json_schema" value="1" checked="checked"') === false, 'saved false state must be unchecked');
};

$tests['setting sanitizer accepts explicit false and true values'] = function () {
    $GLOBALS['lar_registered_settings'] = [];
    $plugin = new LLM_Auto_Redirect();
    $plugin->register_settings();
    $sanitize = $GLOBALS['lar_registered_settings']['lar_openai_json_schema']['sanitize_callback'];

    assert_true(call_user_func($sanitize, '0') === false, 'zero should persist as false');
    assert_true(call_user_func($sanitize, '1') === true, 'one should persist as true');
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
