<?php

if (!defined('YOURLS_ABSPATH')) {
    die();
}

/**
 * Check GitHub for the latest published stable release of KH Matomo.
 *
 * Results are cached in YOURLS options so the admin page does not contact
 * GitHub on every load. GitHub's latest-release endpoint excludes drafts and
 * prereleases, so only stable releases are advertised as updates.
 *
 * @return array
 */
if (!function_exists('kh_matomo_get_update_status')) {
    function kh_matomo_get_update_status()
    {
        $installed = KH_MATOMO_VERSION;
        $cache_key = 'kh_matomo_update_check';
        $cache = yourls_get_option($cache_key);
        $now = time();
        $stable_ttl = 24 * 60 * 60;
        $error_ttl = 2 * 60 * 60;

        if (
            is_array($cache)
            && ($cache['installed_version'] ?? '') === $installed
            && isset($cache['checked_at'], $cache['status'])
            && is_numeric($cache['checked_at'])
        ) {
            $ttl = $cache['status'] === 'error' ? $error_ttl : $stable_ttl;
            if ($now - (int) $cache['checked_at'] < $ttl) {
                return kh_matomo_format_update_status($installed, $cache);
            }
        }

        $result = [
            'installed_version' => $installed,
            'checked_at' => $now,
            'status' => 'error',
            'latest_version' => '',
        ];

        if (!function_exists('yourls_http_get')) {
            yourls_update_option($cache_key, $result);
            return kh_matomo_format_update_status($installed, $result);
        }

        $response = yourls_http_get(
            'https://api.github.com/repos/KrakenHubMx/kh-matomo/releases/latest',
            ['Accept' => 'application/vnd.github+json'],
            [],
            [
                'timeout' => 3,
                'follow_redirects' => false,
                'useragent' => 'KH-Matomo/' . KH_MATOMO_VERSION
                    . ' (+https://github.com/KrakenHubMx/kh-matomo)',
            ]
        );

        if (is_object($response) && isset($response->status_code)) {
            if ((int) $response->status_code === 404) {
                $result['status'] = 'no_release';
            } elseif ((int) $response->status_code === 200 && isset($response->body)) {
                $release = json_decode($response->body, true);
                $tag = is_array($release) && isset($release['tag_name'])
                    ? (string) $release['tag_name']
                    : '';
                $version = preg_replace('/^v/i', '', $tag);

                if (
                    is_array($release)
                    && empty($release['draft'])
                    && empty($release['prerelease'])
                    && is_string($version)
                    && preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version)
                ) {
                    $result['status'] = 'ok';
                    $result['latest_version'] = $version;
                    $result['latest_tag'] = $tag;
                }
            }
        }

        yourls_update_option($cache_key, $result);
        return kh_matomo_format_update_status($installed, $result);
    }
}

/** Format cached release data for the admin settings page. */
if (!function_exists('kh_matomo_format_update_status')) {
    function kh_matomo_format_update_status($installed, $result)
    {
        $latest = (string) ($result['latest_version'] ?? '');
        $status = (string) ($result['status'] ?? 'error');

        return [
            'status' => $status,
            'installed_version' => $installed,
            'latest_version' => $latest,
            'update_available' => (
                $status === 'ok'
                && $latest !== ''
                && version_compare($latest, $installed, '>')
            ),
            'release_url' => !empty($result['latest_tag'])
                ? 'https://github.com/KrakenHubMx/kh-matomo/releases/tag/'
                    . rawurlencode((string) $result['latest_tag'])
                : 'https://github.com/KrakenHubMx/kh-matomo/releases',
        ];
    }
}
