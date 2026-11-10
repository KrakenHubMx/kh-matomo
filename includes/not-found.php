<?php

if (!defined('YOURLS_ABSPATH')) {
    die();
}

/** Read the latest quota window for the panel; never write on excess requests. */
if (!function_exists('kh_matomo_not_found_quota_reached')) {
    function kh_matomo_not_found_quota_reached()
    {
        $value = yourls_get_option('kh_matomo_not_found_limit');
        if (!is_string($value)) {
            return false;
        }
        $parts = explode(':', $value, 2);
        return count($parts) === 2 && ctype_digit($parts[0])
            && ctype_digit($parts[1]) && (int) $parts[1] >= 30;
    }
}

/**
 * Return a GET request's single-segment keyword, or '' when filtered out.
 * Strip query/fragment data and enforce the 100-byte storage limit plus
 * YOURLS' filtered character rules. Longer candidates are rejected, not cut.
 */
if (!function_exists('kh_matomo_get_not_found_keyword')) {
    function kh_matomo_get_not_found_keyword($input)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || !is_string($input)) {
            return '';
        }

        $keyword = preg_split('/[?#]/', $input, 2)[0];
        // The loader already removes the installation path. Only tolerate its
        // optional trailing slash; do not turn nested paths into keywords.
        if (substr($keyword, -1) === '/') {
            $keyword = substr($keyword, 0, -1);
        }
        if (
            $keyword === ''
            || strlen($keyword) > 100
            || strpbrk($keyword, '/\\') !== false
            || preg_match('/[\x00-\x20\x7F]/', $keyword)
        ) {
            return '';
        }

        // Compare instead of silently converting an invalid path to a different
        // keyword. Do not guess bot activity from randomness or file extensions.
        return yourls_sanitize_keyword($keyword, true) === $keyword ? $keyword : '';
    }
}

/**
 * Reserve one of 30 attempts per fixed 60-second Unix-time window, independent
 * of the display timezone. Windows reset on minute boundaries, not per visitor.
 * A non-blocking database lock serializes reservations on the same DB server.
 * Store one bounded counter, never visitor data, and release before HTTP I/O.
 * Failure or lock contention skips optional tracking, never the redirect.
 *
 * @param int|null $now Unix timestamp; null uses the current time.
 * @return bool Whether capacity was reserved, including for failed deliveries.
 */
if (!function_exists('kh_matomo_reserve_not_found_event')) {
    function kh_matomo_reserve_not_found_event($now = null)
    {
        $db = null;
        $locked = false;
        $lock_name = 'kh_matomo_nf_' . sha1(YOURLS_DB_NAME . ':' . YOURLS_DB_TABLE_OPTIONS);
        try {
            $db = yourls_get_db('write-kh_matomo_not_found_limit');
            $locked = (int) $db->fetchValue('SELECT GET_LOCK(:name, 0)', ['name' => $lock_name]) === 1;
            if (!$locked) {
                return false;
            }

            $table = YOURLS_DB_TABLE_OPTIONS;
            $name = 'kh_matomo_not_found_limit';
            // Read from the write connection, bypassing per-request option caches.
            $value = $db->fetchValue(
                "SELECT `option_value` FROM `$table` WHERE `option_name` = :name LIMIT 1",
                ['name' => $name]
            );
            $window = intdiv($now ?? time(), 60);
            $parts = is_string($value) ? explode(':', $value, 2) : [];
            $previous_window = isset($parts[0]) && ctype_digit($parts[0]) ? (int) $parts[0] : 0;
            $count = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : 0;
            // A backwards clock must not reopen an already consumed window.
            if ($previous_window > $window || ($previous_window === $window && $count >= 30)) {
                return false;
            }
            $count = $previous_window === $window ? $count + 1 : 1;
            $next = $window . ':' . $count;
            if ($value === null || $value === false) {
                $affected = $db->fetchAffected(
                    "INSERT INTO `$table` (`option_name`, `option_value`) VALUES (:name, :value)",
                    ['name' => $name, 'value' => $next]
                );
            } else {
                $affected = $db->fetchAffected(
                    "UPDATE `$table` SET `option_value` = :value WHERE `option_name` = :name",
                    ['name' => $name, 'value' => $next]
                );
            }
            return $affected === 1;
        } catch (Throwable $e) {
            return false;
        } finally {
            if ($locked) {
                try {
                    $db->fetchValue('SELECT RELEASE_LOCK(:name)', ['name' => $lock_name]);
                } catch (Throwable $e) {
                    // Optional tracking must not interfere with the response.
                }
            }
        }
    }
}
