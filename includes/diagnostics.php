<?php

if (!defined('YOURLS_ABSPATH')) {
    die();
}

/**
 * Sample shared tracking outcomes at most once per 60 seconds per outcome
 * for sequential requests. Concurrent requests may still write together;
 * this sampling does not limit event delivery and uses no atomic lock.
 */
if (!function_exists('kh_matomo_record_diagnostics')) {
    function kh_matomo_record_diagnostics($diagnostics)
    {
        try {
            $reason = $diagnostics['reason'] ?? '';
            $allowed = ['ok', 'curl_error', 'http_error', 'curl_unavailable',
                'curl_init_failed', 'settings_missing', 'https_required', 'exception'];
            if (!in_array($reason, $allowed, true)) {
                return;
            }

            // Separate options prevent a success from overwriting a failure.
            $key = $reason === 'ok'
                ? 'kh_matomo_diagnostics_success'
                : 'kh_matomo_diagnostics_failure';
            $previous = yourls_get_option($key);
            $now = time();
            if (is_array($previous)) {
                $age = $now - (int) ($previous['recorded_at'] ?? 0);
                if ($age >= 0 && $age < 60) {
                    return;
                }
            }

            $entry = [
                'recorded_at' => $now,
                'reason' => $reason,
                'http_code' => max(0, (int) ($diagnostics['http_code'] ?? 0)),
                'curl_errno' => max(0, (int) ($diagnostics['curl_errno'] ?? 0)),
                'total_time_ms' => max(0, (int) ($diagnostics['total_time_ms'] ?? 0)),
            ];
            $history = is_array($previous) && isset($previous['history']) && is_array($previous['history'])
                ? $previous['history'] : [];
            // Keep ten samples per outcome in the same write as the card.
            array_unshift($history, $entry);
            $entry['history'] = array_slice($history, 0, 10);
            yourls_update_option($key, $entry);
        } catch (Throwable $e) {
            // Diagnostics must never change the tracking result or redirect.
        }
    }
}

/** Read the bounded history, newest samples first. */
if (!function_exists('kh_matomo_get_diagnostics_history')) {
    function kh_matomo_get_diagnostics_history()
    {
        $history = [];
        foreach (['success', 'failure'] as $outcome) {
            $saved = yourls_get_option('kh_matomo_diagnostics_' . $outcome);
            if (is_array($saved) && isset($saved['history']) && is_array($saved['history'])) {
                foreach (array_slice($saved['history'], 0, 10) as $entry) {
                    if (is_array($entry) && !empty($entry['recorded_at'])) {
                        $history[] = $entry;
                    }
                }
            }
        }
        usort($history, function ($a, $b) {
            return (int) $b['recorded_at'] <=> (int) $a['recorded_at'];
        });
        return $history;
    }
}

/** Clear history only; retain card samples and their write throttles. */
if (!function_exists('kh_matomo_clear_diagnostics_history')) {
    function kh_matomo_clear_diagnostics_history()
    {
        foreach (['success', 'failure'] as $outcome) {
            $key = 'kh_matomo_diagnostics_' . $outcome;
            $saved = yourls_get_option($key);
            if (is_array($saved)) {
                $saved['history'] = [];
                yourls_update_option($key, $saved);
                $verified = yourls_get_option($key);
                if (!is_array($verified) || ($verified['history'] ?? null) !== []) {
                    throw new RuntimeException('History persistence failed');
                }
            }
        }
    }
}
