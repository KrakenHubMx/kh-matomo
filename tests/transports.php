<?php
// Replace only cURL initialization: php -d disable_functions=curl_init tests/transports.php
define('YOURLS_ABSPATH', true);
$curl_calls = 0;
if (function_exists('curl_init')) {
    fwrite(STDERR, "Run with: php -d disable_functions=curl_init tests/transports.php\n");
    exit(1);
} else {
    function curl_init($url) { global $curl_calls; $curl_calls++; return false; }
}
function kh_matomo_t($text) { return $text; }
function kh_matomo_check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
require __DIR__ . '/../includes/matomo-api.php';
foreach (['http://analytics.invalid', 'HTTP://analytics.invalid', 'ftp://analytics.invalid', '//analytics.invalid'] as $url) {
    $settings = ['matomo_url' => $url, 'auth_token' => 'secret'];
    $result = kh_matomo_api_request($settings, 'SitesManager.getSitesIdWithWriteAccess');
    kh_matomo_check(!$result['success'], 'Reporting API rejects insecure transport');
    kh_matomo_check(!kh_matomo_send_tracking_request($settings, ['rec' => 1], $diagnostics), 'Tracking rejects insecure transport');
    kh_matomo_check($diagnostics['reason'] === 'https_required', 'Safe diagnostic reason');
    kh_matomo_check(!str_contains(json_encode([$result, $diagnostics]), 'secret'), 'Token not returned');
}
kh_matomo_check($curl_calls === 0, 'Insecure settings never initialize cURL');
$settings = ['matomo_url' => 'https://analytics.invalid', 'auth_token' => 'secret'];
kh_matomo_api_request($settings, 'SitesManager.getSitesIdWithWriteAccess');
kh_matomo_send_tracking_request($settings, ['rec' => 1], $diagnostics);
kh_matomo_check($curl_calls === 2, 'HTTPS reaches transport in both APIs');
echo "Transport checks passed\n";
