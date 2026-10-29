<?php
/**
 * Regression tests for stable URL-cursor pagination in bulk processing.
 * Run with: php tests/test-bulk-pagination.php
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
    public $payload;

    public function __construct($payload)
    {
        $this->payload = $payload;
        parent::__construct('Captured WordPress AJAX response');
    }
}

class WP_Test_DB
{
    public $prefix = 'wp_';
    public $next_results = [];
    public $prepared = [];
    public $queries = [];

    public function prepare($query, ...$args)
    {
        $this->prepared[] = ['query' => $query, 'args' => $args];
        return $query;
    }

    public function get_results($query)
    {
        $this->queries[] = $query;
        return $this->next_results;
    }

    public function get_var($query)
    {
        return count($this->next_results);
    }
}

function add_action(...$args)
{
}

function check_ajax_referer(...$args)
{
    return true;
}

function current_user_can($capability)
{
    return true;
}

function get_option($name, $default = false)
{
    return $default;
}

function wp_unslash($value)
{
    return $value;
}

function sanitize_text_field($value)
{
    return trim(strip_tags((string) $value));
}

function wp_send_json_success($data, $status_code = null)
{
    throw new WP_Test_Response(['success' => true, 'data' => $data]);
}

function wp_send_json_error($data, $status_code = null)
{
    throw new WP_Test_Response(['success' => false, 'data' => $data]);
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

function call_bulk_handler($post, $rows)
{
    $GLOBALS['wpdb'] = new WP_Test_DB();
    $GLOBALS['wpdb']->next_results = $rows;
    $_POST = $post;
    $plugin = new LLM_Auto_Redirect();

    try {
        $plugin->handle_bulk_process_ajax();
    } catch (WP_Test_Response $response) {
        return [$response->payload['data'], $GLOBALS['wpdb']];
    }

    throw new RuntimeException('Bulk handler did not return a JSON response');
}

$tests = [];
$tests['cursor query advances through ignored entries without OFFSET'] = function () {
    [$data, $db] = call_bulk_handler(
        ['cursor' => '/assets/a.css', 'batch_size' => '2'],
        [(object) ['url' => '/assets/b.css'], (object) ['url' => '/images/c.png']]
    );

    assert_same('/images/c.png', $data['cursor'], 'next cursor');
    assert_same(false, $data['complete'], 'full batch should continue');
    assert_same(['/assets/a.css', 2], $db->prepared[0]['args'], 'prepared cursor and bounded limit');
    assert_true(strpos($db->prepared[0]['query'], 'l.url > %s') !== false, 'query should use keyset cursor');
    assert_true(strpos($db->prepared[0]['query'], 'ORDER BY l.url ASC') !== false, 'query should have stable ordering');
    assert_true(strpos($db->prepared[0]['query'], 'OFFSET') === false, 'query must not use OFFSET');
};

$tests['batch size is clamped and short batch completes'] = function () {
    [$data, $db] = call_bulk_handler(
        ['batch_size' => '500'],
        [(object) ['url' => '/images/only.png']]
    );

    assert_same([50], $db->prepared[0]['args'], 'batch limit should clamp at 50');
    assert_same('/images/only.png', $data['cursor'], 'cursor should advance on ignored row');
    assert_same(true, $data['complete'], 'short batch should complete');
};

$tests['empty batch completes without losing the cursor'] = function () {
    [$data, $db] = call_bulk_handler(
        ['cursor' => '/last.css', 'batch_size' => '-10'],
        []
    );

    assert_same(['/last.css', 1], $db->prepared[0]['args'], 'minimum batch size should be one');
    assert_same('/last.css', $data['cursor'], 'empty response should preserve cursor');
    assert_same(true, $data['complete'], 'empty batch should complete');
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
