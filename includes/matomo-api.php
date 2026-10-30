<?php

if (!defined('YOURLS_ABSPATH')) {
    die();
}

/**
 * Send an authenticated request to the Matomo API.
 *
 * @param array  $settings Matomo connection settings.
 * @param string $method   Matomo API method.
 * @param array  $params   Additional API parameters.
 *
 * @return array
 */
if (!function_exists('kh_matomo_api_request')) {
    function kh_matomo_api_request($settings, $method, $params = [])
    {

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'message' => kh_matomo_t('La extensión PHP cURL no está disponible.'),
                'data'    => null,
            ];
        }

        $base_url = rtrim(
            trim($settings['matomo_url'] ?? ''),
            '/'
        );

        $token = trim($settings['auth_token'] ?? '');

        if ($base_url === '' || $token === '') {
            return [
                'success' => false,
                'message' => kh_matomo_t('La URL y el token de Matomo son obligatorios.'),
                'data'    => null,
            ];
        }

        $endpoint = $base_url . '/index.php';

        $request_data = array_merge(
            [
                'module'     => 'API',
                'method'     => $method,
                'format'     => 'JSON',
                'token_auth' => $token,
            ],
            $params
        );

        $curl = curl_init($endpoint);

        if ($curl === false) {
            return [
                'success' => false,
                'message' => kh_matomo_t('No fue posible inicializar la conexión con Matomo.'),
                'data'    => null,
            ];
        }

        curl_setopt_array(
            $curl,
            [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($request_data),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                ],
            ]
        );

        $response = curl_exec($curl);
        $curl_error = curl_errno($curl);
        $http_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if ($response === false || $curl_error !== 0) {
            return [
                'success' => false,
                'message' => kh_matomo_t('No fue posible establecer comunicación con Matomo. Verifica la URL y la disponibilidad del servicio.'),
                'data'    => null,
            ];
        }

        if ($http_code < 200 || $http_code >= 300) {
            return [
                'success' => false,
                'message' => sprintf(
                    kh_matomo_t('Matomo respondió con HTTP %1$s. Respuesta: %2$s'),
                    $http_code,
                    substr(strip_tags($response), 0, 500)
                ),
                'data' => null,
            ];
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [
                'success' => false,
                'message' => kh_matomo_t('Matomo respondió con un formato de datos inesperado.'),
                'data'    => null,
            ];
        }

        if (
            isset($decoded['result'])
            && $decoded['result'] === 'error'
        ) {
            return [
                'success' => false,
                'message' => kh_matomo_t('Matomo rechazó la solicitud. Verifica el token y los permisos de la cuenta.'),
                'data'    => null,
            ];
        }

        return [
            'success' => true,
                'message' => kh_matomo_t('Solicitud procesada correctamente.'),
            'data'    => $decoded,
        ];
    }
}

/**
 * Send one best-effort event to Matomo's HTTP Tracking API.
 *
 * This transport has a short hard timeout because it runs before YOURLS sends
 * the redirect response. It deliberately does not retry or log request data.
 *
 * @param array $settings Matomo connection settings.
 * @param array $params   Tracking API parameters.
 *
 * @return bool
 */
if (!function_exists('kh_matomo_send_tracking_request')) {
    function kh_matomo_send_tracking_request($settings, $params, &$diagnostics = null)
    {
        $diagnostics = [
            'curl_errno' => 0,
            'http_code' => 0,
            'total_time_ms' => 0,
            'reason' => '',
        ];

        if (!function_exists('curl_init') || !is_array($params)) {
            $diagnostics['reason'] = 'curl_unavailable';
            return false;
        }

        $base_url = rtrim(
            trim($settings['matomo_url'] ?? ''),
            '/'
        );
        $token = trim($settings['auth_token'] ?? '');

        if ($base_url === '' || $token === '') {
            $diagnostics['reason'] = 'settings_missing';
            return false;
        }

        $params['token_auth'] = $token;
        $endpoint = $base_url . '/matomo.php';
        $curl = curl_init($endpoint);

        if ($curl === false) {
            $diagnostics['reason'] = 'curl_init_failed';
            return false;
        }

        curl_setopt_array(
            $curl,
            [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($params),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 500,
                CURLOPT_TIMEOUT_MS     => 1000,
                CURLOPT_NOSIGNAL       => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER     => [
                    'Accept: */*',
                ],
            ]
        );

        $response = curl_exec($curl);
        $curl_error = curl_errno($curl);
        $http_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $total_time_ms = (int) round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000);
        curl_close($curl);

        $diagnostics['curl_errno'] = $curl_error;
        $diagnostics['http_code'] = $http_code;
        $diagnostics['total_time_ms'] = $total_time_ms;

        $success = (
            $response !== false
            && $curl_error === 0
            && $http_code >= 200
            && $http_code < 300
        );

        $diagnostics['reason'] = $success
            ? 'ok'
            : ($curl_error !== 0 ? 'curl_error' : 'http_error');

        return $success;
    }
}

/**
 * Test Matomo connection and verify write access to the configured site.
 *
 * @param array $settings Matomo connection settings.
 *
 * @return array
 */
if (!function_exists('kh_matomo_test_connection')) {
    function kh_matomo_test_connection($settings)
    {

        $site_id = trim((string) ($settings['site_id'] ?? ''));

        if ($site_id === '' || !ctype_digit($site_id) || (int) $site_id < 1) {
            return [
                'success' => false,
                'message' => kh_matomo_t('El Site ID no es válido.'),
            ];
        }

        // Matomo exposes separate lists for site writers and administrators.
        // An admin token can write through the Tracking API even though its
        // site ID is absent from getSitesIdWithWriteAccess().
        $permission_methods = [
            'SitesManager.getSitesIdWithWriteAccess',
            'SitesManager.getSitesIdWithAdminAccess',
        ];

        $permission_api_error = null;
        foreach ($permission_methods as $method) {
            $response = kh_matomo_api_request($settings, $method);

            if (!$response['success']) {
                $permission_api_error = $response['message'];
                continue;
            }

            if (!is_array($response['data'])) {
                $permission_api_error = kh_matomo_t('Matomo devolvió una respuesta de permisos inesperada.');
                continue;
            }

            $accessible_sites = array_map('strval', $response['data']);
            if (in_array($site_id, $accessible_sites, true)) {
                return [
                    'success' => true,
                    'message' => kh_matomo_t('Conexión correcta. El token puede registrar eventos en el sitio configurado.'),
                ];
            }
        }

        if ($permission_api_error !== null) {
            return [
                'success' => false,
                'message' => sprintf(
                    kh_matomo_t('No se pudieron verificar los permisos del token en Matomo. %s'),
                    $permission_api_error
                ),
            ];
        }

        return [
            'success' => false,
            'message' => kh_matomo_t('El token no tiene permisos de escritura ni de administración en el sitio configurado.'),
        ];
    }
}
