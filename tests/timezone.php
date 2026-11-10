<?php
// Standalone display checks: php tests/timezone.php
define('YOURLS_ABSPATH', true);
$offset = 0;
$format = 'Y-m-d H:i:s';
$timestamp = 1794225600; // 2026-11-09 12:00:00 UTC
$options = [
    'kh_matomo_diagnostics_success' => [
        'recorded_at' => $timestamp, 'reason' => 'ok', 'http_code' => 204,
        'history' => [['recorded_at' => $timestamp, 'reason' => 'ok', 'http_code' => 204]],
    ],
];
function yourls_add_action(...$args) {}
function yourls_get_option($key) { global $options; return $options[$key] ?? false; }
function yourls_nonce_field($action) {}
function kh_matomo_t($text) { return $text; }
// Emulate the native helper contract used by Timezones' filters.
function yourls_get_timestamp($timestamp) { global $offset; return $timestamp + $offset * 3600; }
function yourls_get_datetime_format($default) { global $format; return $format; }
function yourls_date_i18n($format, $timestamp) { return gmdate($format, $timestamp); }
function kh_matomo_check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
require __DIR__ . '/../includes/diagnostics.php';
require __DIR__ . '/../includes/admin.php';

kh_matomo_check(kh_matomo_format_timestamp($timestamp) === '2026-11-09 12:00:00', 'Default UTC display');
$before = $options;
$offset = -6;
$format = 'd/m/Y h:i a';
kh_matomo_check(kh_matomo_format_timestamp($timestamp) === '09/11/2026 06:00 am', 'Offset and custom format honored');
ob_start();
kh_matomo_render_diagnostics_section();
$html = ob_get_clean();
kh_matomo_check(substr_count($html, '09/11/2026 06:00 am') === 2, 'Cards and history use same display');
kh_matomo_check(str_contains($html, 'datetime="2026-11-09T12:00:00Z"'), 'Machine-readable time remains unshifted UTC');
kh_matomo_check(!str_contains($html, 'Fecha (UTC)'), 'No incorrect UTC heading');
kh_matomo_check($options === $before, 'Display does not rewrite stored history');
$offset = 5.5;
$format = 'Y-m-d H:i';
kh_matomo_check(kh_matomo_format_timestamp($timestamp) === '2026-11-09 17:30', 'Fractional timezone offsets supported');
$offset = 14;
kh_matomo_check(kh_matomo_format_timestamp($timestamp) === '2026-11-10 02:00', 'Timezone can change displayed calendar date');
echo "Timezone checks passed\n";
