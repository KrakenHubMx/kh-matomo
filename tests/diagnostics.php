<?php
// Standalone regression checks: php tests/diagnostics.php
define('YOURLS_ABSPATH', true);
$options = [];
$writes = 0;
$fail_storage = false;
function yourls_get_option($key) { global $options, $fail_storage; if ($fail_storage) { throw new RuntimeException('storage'); } return $options[$key] ?? false; }
function yourls_update_option($key, $value) { global $options, $writes; $options[$key] = $value; $writes++; }
function kh_matomo_get_settings() { global $enabled; return ['tracking_enabled' => $enabled, 'matomo_url' => 'https://example.invalid', 'auth_token' => 'secret', 'site_id' => '1', 'anonymize_ip' => true]; }
function yourls_get_yourls_site() { return 'https://short.invalid'; }
function kh_matomo_send_tracking_request($settings, $params, &$diagnostics) { global $transport_result; $diagnostics = $transport_result; return $diagnostics['reason'] === 'ok'; }
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
require __DIR__ . '/../includes/diagnostics.php';
require __DIR__ . '/../includes/tracker.php';
$enabled = true;
$transport_result = ['reason' => 'ok', 'http_code' => 204, 'curl_errno' => 0, 'total_time_ms' => 25, 'token_auth' => 'secret'];
check(kh_matomo_track_redirect('https://destination.invalid/private', 'keyword'), 'Successful tracking');
check($writes === 1, 'First success persisted');
kh_matomo_track_redirect('https://destination.invalid', 'keyword');
check($writes === 1, 'Success writes throttled');
$transport_result = ['reason' => 'curl_error', 'http_code' => 0, 'curl_errno' => 28, 'total_time_ms' => 1000];
check(!kh_matomo_track_redirect('https://destination.invalid', 'keyword'), 'Timeout preserves failure');
check($writes === 2 && isset($options['kh_matomo_diagnostics_success']), 'Failure independent of success');
kh_matomo_track_redirect('https://destination.invalid', 'keyword');
check($writes === 2, 'Failure writes throttled');
$options['kh_matomo_diagnostics_failure']['recorded_at'] -= 61;
kh_matomo_track_redirect('https://destination.invalid', 'keyword');
check($writes === 3, 'Expired sample refreshed');
check(!str_contains(json_encode($options), 'secret') && !str_contains(json_encode($options), 'destination'), 'No visitor data or token persisted');
$enabled = false;
kh_matomo_track_redirect('https://destination.invalid', 'keyword');
check($writes === 3, 'Disabled tracking not recorded');
$enabled = true;
$fail_storage = true;
$transport_result = ['reason' => 'ok'];
check(kh_matomo_track_redirect('https://destination.invalid', 'keyword'), 'Storage failure leaves tracking result unchanged');
$fail_storage = false;
for ($i = 0; $i < 15; $i++) {
    foreach (['success' => 'ok', 'failure' => 'http_error'] as $outcome => $reason) {
        $options['kh_matomo_diagnostics_' . $outcome]['recorded_at'] = time() - 61;
        kh_matomo_record_diagnostics(['reason' => $reason, 'http_code' => $reason === 'ok' ? 204 : 503]);
    }
}
$history = kh_matomo_get_diagnostics_history();
check(count($history) === 20, 'History bounded to twenty samples');
check(count($options['kh_matomo_diagnostics_success']['history']) === 10, 'Success history bounded');
check(count($options['kh_matomo_diagnostics_failure']['history']) === 10, 'Failure history bounded');
$options['kh_matomo_diagnostics_failure']['history'][0]['recorded_at'] = time() + 10;
check(kh_matomo_get_diagnostics_history()[0]['reason'] === 'http_error', 'Newest sample first');
$before = $options['kh_matomo_diagnostics_success'];
kh_matomo_clear_diagnostics_history();
check(kh_matomo_get_diagnostics_history() === [], 'History cleared');
check($options['kh_matomo_diagnostics_success']['recorded_at'] === $before['recorded_at'], 'Card timestamp retained');
check($options['kh_matomo_diagnostics_success']['reason'] === $before['reason'], 'Card result retained');
$before_writes = $writes;
kh_matomo_record_diagnostics(['reason' => 'ok']);
check($writes === $before_writes, 'Clearing preserves throttle');
// Upgrade from the earlier card-only format requires no migration.
unset($options['kh_matomo_diagnostics_success']['history']);
check(kh_matomo_get_diagnostics_history() === [], 'Legacy cards supported');
$options['kh_matomo_diagnostics_success']['recorded_at'] = time() - 61;
kh_matomo_record_diagnostics(['reason' => 'ok']);
check(count(kh_matomo_get_diagnostics_history()) === 1, 'History starts on next sampled write');
echo "Diagnostics checks passed\n";
