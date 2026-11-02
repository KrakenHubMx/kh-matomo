<?php
/**
 * GitHub update checker for KH Matomo.
 *
 * Checks the latest published stable release and caches the result in YOURLS
 * options. Call this from the admin area only, not from public redirect requests.
 *
 * @return array Update status for the admin UI.
 */

if (!defined('YOURLS_ABSPATH')) {
    die();
}

if (!function_exists('kh_matomo_get_update_status')) {
    function kh_matomo_get_update_status()
    {
        $installed = KH_MATOMO_VERSION;
        $cache_key = 'kh_matomo_update_check';
        $cache = yourls_get_option($cache_key);
        $now = time();

        $stable_ttl = 24 * 60 * 60;
        $error_ttl = 2 * 60 * 60;

        // Reuse a cached result while it is still fresh.
        if (
            is_array($cache)
            && ($cache['installed_version'] ?? '') === $installed
            && isset($cache['checked_at'], $cache['status'])
            && is_numeric($cache['checked_at'])
        ) {
            $ttl = ($cache['status'] === 'error')
                ? $error_ttl
                : $stable_ttl;

            if ($now - (int) $cache['checked_at'] < $ttl) {
                return kh_matomo_format_update_status($installed, $cache);
            }
        }

        // Start with an error state. If the request fails, retain the last
        // successfully discovered release instead of discarding it.
        $result = [
            'installed_version' => $installed,
            'checked_at' => $now,
            'status' => 'error',
            'latest_version' => '',
            'latest_tag' => '',
        ];

        if (is_array($cache)) {
            $result['latest_version'] = (string) ($cache['latest_version'] ?? '');
            $result['latest_tag'] = (string) ($cache['latest_tag'] ?? '');
        }

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
            $http_status = (int) $response->status_code;

            if ($http_status === 404) {
                // GitHub returns 404 when no published stable release exists.
                $result['status'] = 'no_release';
                $result['latest_version'] = '';
                $result['latest_tag'] = '';
            } elseif ($http_status === 200 && isset($response->body)) {
                $release = json_decode($response->body, true);

                $tag = (
                    is_array($release)
                    && isset($release['tag_name'])
                    && is_string($release['tag_name'])
                ) ? $release['tag_name'] : '';

                $version = preg_replace('/^v/i', '', $tag);

                // GitHub's latest endpoint excludes drafts and prereleases.
                // Also reject SemVer prerelease tags (e.g. 0.2.0-alpha) so
                // they cannot be advertised as stable if mis-tagged.
                $is_valid_stable_version = (
                    is_array($release)
                    && empty($release['draft'])
                    && empty($release['prerelease'])
                    && is_string($version)
                    && preg_match(
                        '/^\d+\.\d+\.\d+$/',
                        $version
                    )
                );

                if ($is_valid_stable_version) {
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

/**
 * Format cached release data for the admin settings page.
 *
 * @param string $installed Installed plugin version.
 * @param array  $result    Cached checker result.
 *
 * @return array
 */
if (!function_exists('kh_matomo_format_update_status')) {
    function kh_matomo_format_update_status($installed, $result)
    {
        $latest = (string) ($result['latest_version'] ?? '');
        $status = (string) ($result['status'] ?? 'error');
        $latest_tag = (string) ($result['latest_tag'] ?? '');

        $release_url = $latest_tag !== ''
            ? 'https://github.com/KrakenHubMx/kh-matomo/releases/tag/'
                . rawurlencode($latest_tag)
            : 'https://github.com/KrakenHubMx/kh-matomo/releases';

        return [
            'status' => $status,
            'installed_version' => $installed,
            'latest_version' => $latest,
            'update_available' => (
                $status === 'ok'
                && $latest !== ''
                && version_compare($latest, $installed, '>')
            ),
            'release_url' => $release_url,
            'checked_at' => (int) ($result['checked_at'] ?? 0),
        ];
    }
}
