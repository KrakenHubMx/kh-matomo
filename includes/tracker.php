<?php

if (!defined('YOURLS_ABSPATH')) {
    die();
}

/**
 * Return an IP address anonymized to /24 for IPv4 or /64 for IPv6.
 *
 * @param string $ip Client IP address.
 *
 * @return string
 */
if (!function_exists('kh_matomo_anonymize_ip')) {
    function kh_matomo_anonymize_ip($ip)
    {
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        $packed_ip = @inet_pton($ip);
        if ($packed_ip === false) {
            return '';
        }

        if (strlen($packed_ip) === 4) {
            $packed_ip = substr($packed_ip, 0, 3) . "\0";
        } else {
            $packed_ip = substr($packed_ip, 0, 8) . str_repeat("\0", 8);
        }

        $anonymized_ip = @inet_ntop($packed_ip);
        return $anonymized_ip === false ? '' : $anonymized_ip;
    }
}

/**
 * Track a valid YOURLS short URL redirect in Matomo.
 *
 * @param string $destination Original destination URL.
 * @param string $keyword     YOURLS short URL keyword.
 *
 * @return bool Whether Matomo returned a successful HTTP status.
 */
if (!function_exists('kh_matomo_track_redirect')) {
    function kh_matomo_track_redirect($destination, $keyword, &$diagnostics = null)
    {
        $diagnostics = [
            'reason' => 'not_attempted',
            'curl_errno' => 0,
            'http_code' => 0,
            'total_time_ms' => 0,
        ];

        try {
            $settings = kh_matomo_get_settings();

            if (
                empty($settings['tracking_enabled'])
                || empty($settings['matomo_url'])
                || empty($settings['auth_token'])
                || !is_string($destination)
                || !is_string($keyword)
                || trim($keyword) === ''
                || !ctype_digit((string) $settings['site_id'])
            ) {
                $diagnostics['reason'] = 'tracking_disabled_or_settings_invalid';
                return false;
            }

            $short_url = rtrim(yourls_get_yourls_site(), '/')
                . '/' . ltrim($keyword, '/');

            $params = [
                'idsite'      => (int) $settings['site_id'],
                'rec'         => 1,
                'apiv'        => 1,
                'rand'        => random_int(0, PHP_INT_MAX),
                'url'         => $short_url,
                'e_c'         => 'YOURLS',
                'e_a'         => 'redirect',
                'e_n'         => $keyword,
                'ca'          => 1,
                'send_image'  => 0,
                'cvar'        => json_encode(
                    [1 => ['YOURLS destination', $destination]],
                    JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                ),
            ];

            $referrer = $_SERVER['HTTP_REFERER'] ?? '';
            if (
                is_string($referrer)
                && strlen($referrer) <= 2048
                && filter_var($referrer, FILTER_VALIDATE_URL)
            ) {
                $referrer_scheme = strtolower((string) parse_url($referrer, PHP_URL_SCHEME));
                if (in_array($referrer_scheme, ['http', 'https'], true)) {
                    $params['urlref'] = $referrer;
                }
            }

            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if (is_string($user_agent) && $user_agent !== '') {
                $user_agent = preg_replace('/[\x00-\x1F\x7F]/', '', $user_agent);
                if (is_string($user_agent) && $user_agent !== '') {
                    $params['ua'] = substr($user_agent, 0, 512);
                }
            }

            if (function_exists('yourls_get_IP')) {
                $client_ip = yourls_get_IP();
                if (!empty($settings['anonymize_ip'])) {
                    $client_ip = kh_matomo_anonymize_ip($client_ip);
                }

                if (
                    is_string($client_ip)
                    && filter_var($client_ip, FILTER_VALIDATE_IP) !== false
                ) {
                    $params['cip'] = $client_ip;
                }
            }

            return kh_matomo_send_tracking_request($settings, $params, $diagnostics);
        } catch (Throwable $e) {
            // Tracking must never prevent YOURLS from sending the redirect.
            $diagnostics['reason'] = 'exception';
            return false;
        }
    }
}

/**
 * Adapt YOURLS 1.10.6 action arguments to the redirect tracker.
 *
 * YOURLS packs the arguments passed to yourls_do_action() into one array when
 * dispatching actions. The redirect_shorturl callback therefore receives
 * [$destination, $keyword] as its first argument, not two separate arguments.
 *
 * @param mixed $args Arguments packed by YOURLS.
 *
 * @return bool Whether Matomo returned a successful HTTP status.
 */
if (!function_exists('kh_matomo_handle_redirect_action')) {
    function kh_matomo_handle_redirect_action($args)
    {
        if (!is_array($args) || !isset($args[0], $args[1])) {
            return false;
        }

        return kh_matomo_track_redirect($args[0], $args[1]);
    }
}
