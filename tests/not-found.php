<?php
// Standalone behavior checks: php tests/not-found.php
define('YOURLS_ABSPATH', true);
define('YOURLS_DB_NAME', 'yourls_test');
define('YOURLS_DB_TABLE_OPTIONS', 'yourls_options');
$_SERVER['REQUEST_METHOD'] = 'GET';
$options = [];
$hooks = [];
$requests = [];
$transport_mode = 'ok';
$keyword_characters = 'a-zA-Z0-9_\-';
// Emulate the YOURLS database boundary; no real network or database is used.
class KH_Matomo_Test_Database {
    public $locked = false;
    public $busy = false;
    public $fail = false;
    public function fetchValue($sql, $binds) {
        global $options;
        if ($this->fail) { throw new RuntimeException('database'); }
        if (str_contains($sql, 'GET_LOCK')) {
            if ($this->busy) { return 0; }
            $this->locked = true;
            return 1;
        }
        if (str_contains($sql, 'RELEASE_LOCK')) { $this->locked = false; return 1; }
        kh_matomo_check($this->locked, 'Counter read holds lock');
        return $options[$binds['name']] ?? null;
    }
    public function fetchAffected($sql, $binds) {
        global $options;
        kh_matomo_check($this->locked, 'Counter write holds lock');
        $options[$binds['name']] = $binds['value'];
        return 1;
    }
}
$db = new KH_Matomo_Test_Database();
function yourls_get_db($context) { global $db; return $db; }
function yourls_sanitize_keyword($keyword, $restrict = false) {
    global $keyword_characters;
    return substr(preg_replace('~[^' . $keyword_characters . ']~', '', $keyword), 0, 199);
}
function yourls_get_option($key) { global $options; return $options[$key] ?? false; }
function yourls_update_option($key, $value) { global $options; $options[$key] = $value; }
function yourls_add_action($hook, $callback, ...$rest) { global $hooks; $hooks[$hook][] = $callback; }
function yourls_get_yourls_site() { return 'https://short.invalid'; }
function yourls_get_IP() { return '192.0.2.123'; }
function yourls__($text, $domain) { return $text; }
function kh_matomo_send_tracking_request($settings, $params, &$diagnostics = null) {
    global $requests, $transport_mode, $db;
    kh_matomo_check(!$db->locked, 'Lock released before transport');
    $requests[] = $params;
    if ($transport_mode === 'exception') { throw new RuntimeException('transport'); }
    $diagnostics = ['reason' => $transport_mode, 'http_code' => $transport_mode === 'ok' ? 204 : 503];
    return $transport_mode === 'ok';
}
function kh_matomo_check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
require __DIR__ . '/../plugin.php';

kh_matomo_check(kh_matomo_get_settings()['track_not_found'] === false, 'Off by default');
$options['kh_matomo_settings'] = [
    'matomo_url' => 'https://analytics.invalid', 'site_id' => '1',
    'auth_token' => 'secret', 'tracking_enabled' => true, 'anonymize_ip' => true,
];
$callback = $hooks['redirect_keyword_not_found'][0];
kh_matomo_check(!$callback(['missing']) && !$requests, 'Existing installations do not opt in');
$result = kh_matomo_validate_settings_input([
    'matomo_url' => 'https://analytics.invalid', 'site_id' => '1',
    'tracking_enabled' => '1', 'anonymize_ip' => '1', 'track_not_found' => '1',
], kh_matomo_get_settings());
kh_matomo_check(!$result['errors'] && $result['settings']['auth_token'] === 'secret', 'Checkbox save retains token');
kh_matomo_save_settings($result['settings']);
kh_matomo_check(kh_matomo_get_settings()['track_not_found'] === true, 'Opt-in persists');
$_SERVER['HTTP_REFERER'] = 'https://source.invalid/article';
$_SERVER['HTTP_USER_AGENT'] = 'Test browser';
kh_matomo_check($callback(['missing?private=value#fragment']), 'Missing event sent');
$event = $requests[0];
kh_matomo_check($event['e_c'] === 'YOURLS' && $event['e_a'] === 'keyword_not_found', 'Distinct event action');
kh_matomo_check($event['e_n'] === 'missing' && $event['url'] === 'https://short.invalid/missing', 'Query and fragment excluded');
kh_matomo_check(!isset($event['cvar']) && $event['cip'] === '192.0.2.0', 'No invented destination; IP masked');
kh_matomo_check($event['urlref'] === $_SERVER['HTTP_REFERER'] && $event['ua'] === 'Test browser', 'Visitor context retained');
$before_counter = $options['kh_matomo_not_found_limit'];
foreach ([[], [null], [42], [''], ['/'], ['?secret=value'], ["bad\nkeyword"], [str_repeat('x', 201)], ['assets/logo.png'], ['wp-admin/config.php'], ['/nested'], ['bad\\path'], ['bad%2Fpath']] as $args) {
    kh_matomo_check(!$callback($args), 'Invalid or empty keyword ignored');
}
kh_matomo_check(count($requests) === 1, 'Invalid inputs send nothing');
kh_matomo_check($options['kh_matomo_not_found_limit'] === $before_counter, 'Invalid paths do not consume capacity');
kh_matomo_save_settings(['tracking_enabled' => false]);
kh_matomo_check(!$callback(['missing']) && count($requests) === 1, 'Global switch controls missing events');
kh_matomo_check($options['kh_matomo_not_found_limit'] === $before_counter, 'Disabled tracking does not consume capacity');
kh_matomo_save_settings(['tracking_enabled' => true, 'anonymize_ip' => false]);
kh_matomo_check($callback(['another']) && $requests[1]['cip'] === '192.0.2.123', 'Full IP option respected');
$transport_mode = 'http_error';
kh_matomo_check(!$callback(['missing']), 'HTTP failures contained');
$transport_mode = 'exception';
kh_matomo_check(!$callback(['missing']), 'Transport exceptions contained');
$transport_mode = 'ok';
kh_matomo_save_settings(['track_not_found' => false]);
kh_matomo_check(kh_matomo_handle_redirect_action(['https://destination.invalid/path', 'valid']), 'Valid redirects still tracked');
$event = end($requests);
kh_matomo_check($event['e_a'] === 'redirect' && $event['e_n'] === 'valid' && isset($event['cvar']), 'Redirect payload preserved');
kh_matomo_check(json_decode($event['cvar'], true)[1][1] === 'https://destination.invalid/path', 'Full destination preserved as before');
$input = ['matomo_url' => 'https://analytics.invalid', 'site_id' => '1', 'tracking_enabled' => '1'];
$result = kh_matomo_validate_settings_input($input, kh_matomo_get_settings());
kh_matomo_save_settings($result['settings']);
kh_matomo_check(kh_matomo_get_settings()['track_not_found'] === false, 'Unchecked option saves as false');
ob_start();
kh_matomo_render_tracking_section(kh_matomo_get_settings());
$html = ob_get_clean();
kh_matomo_check(str_contains($html, 'name="track_not_found"') && str_contains($html, 'aria-describedby="kh-matomo-not-found-help"'), 'Accessible option rendered');

kh_matomo_save_settings(['track_not_found' => true]);
foreach (['HEAD', 'POST', 'OPTIONS', ''] as $method) {
    $_SERVER['REQUEST_METHOD'] = $method;
    kh_matomo_check(!$callback(['missing']), 'Only GET accepted');
}
$_SERVER['REQUEST_METHOD'] = 'GET';
kh_matomo_check($callback(['a8Kp2Z']), 'Random keyword accepted');
kh_matomo_check($callback(['custom-key_1/']), 'Custom keyword and optional trailing slash accepted');
kh_matomo_check(!$callback(['archivo.php']), 'Unsupported character rejected');
$keyword_characters .= '.';
kh_matomo_check($callback(['archivo.php']), 'Custom charset respected without extension blacklist');
kh_matomo_check($callback([str_repeat('x', 100)]), '100-byte keyword accepted');
kh_matomo_check(!$callback([str_repeat('x', 101)]), 'Overlong keyword rejected without truncation');

unset($options['kh_matomo_not_found_limit']);
for ($i = 0; $i < 30; $i++) {
    kh_matomo_check(kh_matomo_reserve_not_found_event(12000), 'First thirty reservations allowed');
}
kh_matomo_check(!kh_matomo_reserve_not_found_event(12059), 'Window capped');
kh_matomo_check(!$db->locked, 'Lock released when capped');
kh_matomo_check(kh_matomo_reserve_not_found_event(12060), 'Next minute resets capacity');
kh_matomo_check(!kh_matomo_reserve_not_found_event(12000), 'Clock rollback cannot reopen window');

$options['kh_matomo_not_found_limit'] = intdiv(time(), 60) . ':30';
kh_matomo_check(kh_matomo_not_found_quota_reached(), 'Panel can identify exhausted latest window');
$before_requests = count($requests);
$before_quota = $options['kh_matomo_not_found_limit'];
$before_diagnostics = array_intersect_key($options, array_flip(['kh_matomo_diagnostics_success', 'kh_matomo_diagnostics_failure']));
kh_matomo_check(!$callback(['missing']), 'Saturated limiter skips transport');
kh_matomo_check(!$callback(['assets/logo.png']), 'Filtered paths skip transport');
kh_matomo_check(count($requests) === $before_requests, 'No sends for skipped requests');
kh_matomo_check($options['kh_matomo_not_found_limit'] === $before_quota, 'No counter writes on excess requests');
kh_matomo_check(array_intersect_key($options, $before_diagnostics) === $before_diagnostics, 'Skips leave diagnostics unchanged');
kh_matomo_check(kh_matomo_handle_redirect_action(['https://destination.invalid', 'valid']), 'Limiter does not affect valid redirects');
$db->busy = true;
kh_matomo_check(!$callback(['missing']) && !$db->locked, 'Lock contention skips optional event');
$db->busy = false;
$db->fail = true;
kh_matomo_check(!$callback(['missing']), 'Database failure contained');
kh_matomo_check(kh_matomo_handle_redirect_action(['https://destination.invalid', 'valid']), 'Valid redirects do not need limiter storage');
$db->fail = false;
echo "Missing-keyword checks passed\n";
