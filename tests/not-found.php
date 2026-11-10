<?php
// Standalone behavior checks: php tests/not-found.php
define('YOURLS_ABSPATH', true);
$options = [];
$hooks = [];
$requests = [];
$transport_mode = 'ok';
function yourls_get_option($key) { global $options; return $options[$key] ?? false; }
function yourls_update_option($key, $value) { global $options; $options[$key] = $value; }
function yourls_add_action($hook, $callback, ...$rest) { global $hooks; $hooks[$hook][] = $callback; }
function yourls_get_yourls_site() { return 'https://short.invalid'; }
function yourls_get_IP() { return '192.0.2.123'; }
function yourls__($text, $domain) { return $text; }
function kh_matomo_send_tracking_request($settings, $params, &$diagnostics = null) {
    global $requests, $transport_mode;
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
foreach ([[], [null], [42], [''], ['/'], ['?secret=value'], ["bad\nkeyword"], [str_repeat('x', 201)]] as $args) {
    kh_matomo_check(!$callback($args), 'Invalid or empty keyword ignored');
}
kh_matomo_check(count($requests) === 1, 'Invalid inputs send nothing');
kh_matomo_save_settings(['tracking_enabled' => false]);
kh_matomo_check(!$callback(['missing']) && count($requests) === 1, 'Global switch controls missing events');
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
$input = ['matomo_url' => 'https://analytics.invalid', 'site_id' => '1', 'tracking_enabled' => '1'];
$result = kh_matomo_validate_settings_input($input, kh_matomo_get_settings());
kh_matomo_save_settings($result['settings']);
kh_matomo_check(kh_matomo_get_settings()['track_not_found'] === false, 'Unchecked option saves as false');
ob_start();
kh_matomo_render_tracking_section(kh_matomo_get_settings());
$html = ob_get_clean();
kh_matomo_check(str_contains($html, 'name="track_not_found"') && str_contains($html, 'aria-describedby="kh-matomo-not-found-help"'), 'Accessible option rendered');
echo "Missing-keyword checks passed\n";
