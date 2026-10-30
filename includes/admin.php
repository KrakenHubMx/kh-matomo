<?php

if (!defined('YOURLS_ABSPATH')) {
    die();
}

/**
 * Escape output for safe HTML rendering.
 */
if (!function_exists('kh_matomo_escape')) {
    function kh_matomo_escape($value)
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

/**
 * Register the plugin administration page.
 */
if (!function_exists('kh_matomo_register_admin_page')) {
    function kh_matomo_register_admin_page()
    {
        yourls_register_plugin_page(
            'kh-matomo',
            kh_matomo_t('Matomo Tracker'),
            'kh_matomo_render_admin_page'
        );

        yourls_add_action(
            'yourls_ajax_kh_matomo_test_connection',
            'kh_matomo_ajax_test_connection'
        );

    }
}

/**
 * Validate a Matomo URL.
 */
if (!function_exists('kh_matomo_is_valid_url')) {
    function kh_matomo_is_valid_url($url)
    {
        if (
            !is_string($url)
            || !filter_var($url, FILTER_VALIDATE_URL)
        ) {
            return false;
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            return false;
        }

        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        if (preg_match('~/(?:matomo|index)\.php$~i', $path)) {
            return false;
        }

        return (
            isset($parts['scheme'], $parts['host'])
            && strtolower($parts['scheme']) === 'https'
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
        );
    }
}

/**
 * Validate connection fields.
 */
if (!function_exists('kh_matomo_validate_connection_input')) {
    function kh_matomo_validate_connection_input($input, $current)
    {
        $url = isset($input['matomo_url'])
            && is_string($input['matomo_url'])
            ? trim($input['matomo_url'])
            : '';

        $site_id = isset($input['site_id'])
            && is_string($input['site_id'])
            ? trim($input['site_id'])
            : '';

        $token = isset($input['auth_token'])
            && is_string($input['auth_token'])
            ? trim($input['auth_token'])
            : '';

        if (!kh_matomo_is_valid_url($url)) {
            return [
                'success' => false,
                'message' => kh_matomo_t('Introduce la URL base HTTPS de Matomo, sin /matomo.php ni /index.php.'),
            ];
        }

        if (
            $site_id === ''
            || !ctype_digit($site_id)
            || (int) $site_id < 1
        ) {
            return [
                'success' => false,
                'message' => kh_matomo_t('El Site ID debe ser un número entero positivo.'),
            ];
        }

        // An empty field means that the existing token will be reused.
        if ($token === '') {
            $token = $current['auth_token'];
        }

        if ($token === '') {
            return [
                'success' => false,
                'message' => kh_matomo_t('Introduce el token de autenticación de Matomo.'),
            ];
        }

        return [
            'success' => true,
            'settings' => [
                'matomo_url' => $url,
                'site_id' => $site_id,
                'auth_token' => $token,
            ],
        ];
    }
}

/**
 * AJAX: test Matomo connection.
 */

if (!function_exists('kh_matomo_ajax_test_connection')) {
    function kh_matomo_ajax_test_connection()
    {
        header('Content-Type: application/json; charset=utf-8');
        $step = 'callback';

        try {
            $step = 'auth';
            if (yourls_is_valid_user() !== true) {
                throw new RuntimeException(kh_matomo_t('No tienes autorización.'));
            }

            $step = 'nonce';
            $nonce = isset($_POST['nonce']) && is_string($_POST['nonce'])
                ? $_POST['nonce']
                : '';
            $expected_nonce = yourls_create_nonce('kh_matomo_test_connection');

            if (!hash_equals($expected_nonce, $nonce)) {
                throw new RuntimeException(kh_matomo_t('La sesión expiró. Recarga la página e inténtalo de nuevo.'));
            }

            $step = 'validation';
            $input = [
                'matomo_url' => $_POST['matomo_url'] ?? '',
                'site_id'    => $_POST['site_id'] ?? '',
                'auth_token' => $_POST['auth_token'] ?? '',
            ];

            $current = kh_matomo_get_settings();

            $validation = kh_matomo_validate_connection_input(
                $input,
                $current
            );

            if (empty($validation['success'])) {
                $result = [
                    'success' => false,
                    'message' => $validation['message'] ?? kh_matomo_t('Error de validación.'),
                ];
            } else {
                $step = 'matomo';
                $result = kh_matomo_test_connection(
                    $validation['settings']
                );
            }
            $step = 'complete';
        } catch (RuntimeException $e) {
            $result = [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            $step = 'exception';
            $result = [
                'success' => false,
                'message' => kh_matomo_t('No se pudo completar la prueba de conexión.'),
            ];
        }

        echo json_encode([
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? kh_matomo_t('Resultado inesperado.')),
            'step' => $step,
        ]);

        return;
    }
}


/**
 * Render Matomo connection section.
 */
if (!function_exists('kh_matomo_render_connection_section')) {
    function kh_matomo_render_connection_section($settings)
    {
?>
        <h3><?php echo kh_matomo_escape(kh_matomo_t('Conexión con Matomo')); ?></h3>

        <p>
            <?php echo kh_matomo_escape(kh_matomo_t('Configura la instancia de Matomo que recibirá los eventos de seguimiento de YOURLS.')); ?>
        </p>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="kh-matomo-url"><?php echo kh_matomo_escape(kh_matomo_t('URL base de Matomo')); ?></label>
                </th>
                <td>
                    <input
                        type="url"
                        id="kh-matomo-url"
                        name="matomo_url"
                        class="regular-text"
                        value="<?php echo kh_matomo_escape($settings['matomo_url']); ?>"
                        placeholder="https://analytics.example.com"
                        aria-describedby="kh-matomo-url-help"
                        required>
                    <p class="description" id="kh-matomo-url-help">
                        <?php echo kh_matomo_escape(kh_matomo_t('Escribe el dominio o la carpeta donde está instalado Matomo, sin añadir /matomo.php ni /index.php.')); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="kh-matomo-site-id"><?php echo kh_matomo_escape(kh_matomo_t('Site ID')); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        id="kh-matomo-site-id"
                        name="site_id"
                        class="regular-text"
                        value="<?php echo kh_matomo_escape($settings['site_id']); ?>"
                        inputmode="numeric"
                        required>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="kh-matomo-token"><?php echo kh_matomo_escape(kh_matomo_t('Token de autenticación')); ?></label>
                </th>
                <td>
                    <?php if ($settings['auth_token'] !== '') : ?>
                        <p>
                            <code>••••••••••••••••</code>
                            <span class="description">
                                <?php echo kh_matomo_escape(kh_matomo_t('Token configurado. Introduce uno nuevo únicamente si deseas reemplazarlo. El usuario del token necesita permiso de escritura en el sitio de Matomo.')); ?>
                            </span>
                        </p>
                    <?php else : ?>
                        <p class="description">
                            <?php echo kh_matomo_escape(kh_matomo_t('No hay ningún token configurado. El usuario del token necesita permiso de escritura en el sitio de Matomo.')); ?>
                        </p>
                    <?php endif; ?>

                    <input
                        type="password"
                        id="kh-matomo-token"
                        name="auth_token"
                        class="regular-text"
                        value=""
                        autocomplete="new-password">
                </td>
            </tr>
        </table>
    <?php
    }
}

/**
 * Render tracking section.
 */
if (!function_exists('kh_matomo_render_tracking_section')) {
    function kh_matomo_render_tracking_section($settings)
    {
    ?>
        <h3><?php echo kh_matomo_escape(kh_matomo_t('Seguimiento')); ?></h3>

        <table class="form-table">
            <tr>
                <th scope="row"><?php echo kh_matomo_escape(kh_matomo_t('Habilitar seguimiento')); ?></th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="tracking_enabled"
                            value="1"
                            <?php echo $settings['tracking_enabled'] ? 'checked' : ''; ?>>
                        <?php echo kh_matomo_escape(kh_matomo_t('Enviar eventos de redirección a Matomo.')); ?>
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo kh_matomo_escape(kh_matomo_t('Anonimizar IP')); ?></th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="anonymize_ip"
                            value="1"
                            <?php echo $settings['anonymize_ip'] ? 'checked' : ''; ?>>
                        <?php echo kh_matomo_escape(kh_matomo_t('Anonimizar la IP antes de enviarla a Matomo.')); ?>
                    </label>
                    <p class="description">
                        <?php echo kh_matomo_escape(kh_matomo_t('IPv4 se reduce a /24 e IPv6 a /64. Si desactivas esta opción, se enviará la IP completa.')); ?>
                    </p>
                </td>
            </tr>
        </table>
    <?php
    }
}

/**
 * Render status section.
 */
if (!function_exists('kh_matomo_render_status_section')) {
    function kh_matomo_render_status_section($settings)
    {
        $configured = (
            $settings['matomo_url'] !== ''
            && $settings['site_id'] !== ''
            && $settings['auth_token'] !== ''
        );
    ?>

        <h3><?php echo kh_matomo_escape(kh_matomo_t('Estado')); ?></h3>

        <table class="form-table">
            <tr>
                <th scope="row"><?php echo kh_matomo_escape(kh_matomo_t('Configuración')); ?></th>
                <td>
                    <?php if ($configured) : ?>
                        <span><?php echo kh_matomo_escape(kh_matomo_t('Credenciales configuradas.')); ?></span>
                    <?php else : ?>
                        <span><?php echo kh_matomo_escape(kh_matomo_t('Configuración pendiente.')); ?></span>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo kh_matomo_escape(kh_matomo_t('Seguimiento')); ?></th>
                <td>
                    <?php if ($settings['tracking_enabled']) : ?>
                        <span><?php echo kh_matomo_escape(kh_matomo_t('Habilitado')); ?></span>
                    <?php else : ?>
                        <span><?php echo kh_matomo_escape(kh_matomo_t('Deshabilitado')); ?></span>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo kh_matomo_escape(kh_matomo_t('Versión del plugin')); ?></th>
                <td>
                    <?php echo kh_matomo_escape(KH_MATOMO_VERSION); ?>
                </td>
            </tr>
        </table>
    <?php
    }
}

/**
 * Validate complete settings input.
 */
if (!function_exists('kh_matomo_validate_settings_input')) {
    function kh_matomo_validate_settings_input($input, $current)
    {
        $errors = [];

        $connection = kh_matomo_validate_connection_input(
            $input,
            $current
        );

        if (!$connection['success']) {
            $errors[] = $connection['message'];

            return [
                'settings' => $current,
                'errors' => $errors,
            ];
        }

        $settings = array_merge(
            $current,
            $connection['settings']
        );

        $settings['tracking_enabled'] = isset($input['tracking_enabled']);
        $settings['anonymize_ip'] = isset($input['anonymize_ip']);

        return [
            'settings' => $settings,
            'errors' => $errors,
        ];
    }
}

/**
 * Process settings form submission.
 */
if (!function_exists('kh_matomo_handle_admin_actions')) {
    function kh_matomo_handle_admin_actions()
    {

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return null;
        }

        if (
            !isset($_POST['kh_matomo_action'])
            || $_POST['kh_matomo_action'] !== 'save_settings'
        ) {
            return null;
        }

        yourls_verify_nonce(
            'kh_matomo_save_settings',
            $_POST['nonce'] ?? ''
        );

        $current = kh_matomo_get_settings();

        $result = kh_matomo_validate_settings_input(
            $_POST,
            $current
        );

        if (!empty($result['errors'])) {
            return [
                'type' => 'error',
                'message' => implode(' ', $result['errors']),
            ];
        }

        if (!kh_matomo_save_settings($result['settings'])) {
            return [
                'type' => 'error',
                'message' => kh_matomo_t('No fue posible guardar la configuración.'),
            ];
        }

        return [
            'type' => 'success',
            'message' => kh_matomo_t('La configuración de Matomo se guardó correctamente.'),
        ];
    }
}

/**
 * Render plugin administration page.
 */
if (!function_exists('kh_matomo_render_admin_page')) {
    function kh_matomo_render_admin_page()
    {
        $notice = kh_matomo_handle_admin_actions();
        $settings = kh_matomo_get_settings();

        $test_url = yourls_admin_url(
            'admin-ajax.php?action=kh_matomo_test_connection'
        );
        $test_nonce = yourls_create_nonce(
            'kh_matomo_test_connection'
        );
    ?>
        <div class="wrap">
            <h2><?php echo kh_matomo_escape(kh_matomo_t('KH Matomo')); ?></h2>

            <?php if (is_array($notice)) : ?>
                <div class="notice notice-<?php echo kh_matomo_escape($notice['type']); ?>">
                    <p><?php echo kh_matomo_escape($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <p>
                <?php echo kh_matomo_escape(kh_matomo_t('Integración de seguimiento de clics del lado del servidor entre YOURLS y Matomo.')); ?>
            </p>

            <form
                method="post"
                id="kh-matomo-form"
                data-test-url="<?php echo kh_matomo_escape($test_url); ?>"
                data-test-nonce="<?php echo kh_matomo_escape($test_nonce); ?>">

                <?php yourls_nonce_field('kh_matomo_save_settings'); ?>

                <?php kh_matomo_render_connection_section($settings); ?>

                <p>
                    <button
                        type="button"
                        class="button"
                        id="kh-matomo-test-button">
                        <?php echo kh_matomo_escape(kh_matomo_t('Probar conexión')); ?>
                    </button>
                    <span class="description">
                        <?php echo kh_matomo_escape(kh_matomo_t('La prueba es opcional para guardar. Deja el campo del token vacío para conservar el token actual.')); ?>
                    </span>
                </p>

                <div
                    id="kh-matomo-test-result"
                    role="status"
                    aria-live="polite"></div>

                <hr>

                <?php kh_matomo_render_tracking_section($settings); ?>

                <hr>

                <?php kh_matomo_render_status_section($settings); ?>

                <p>
                    <button
                        type="submit"
                        class="button button-primary"
                        name="kh_matomo_action"
                        value="save_settings"
                        id="kh-matomo-save-button">
                        <?php echo kh_matomo_escape(kh_matomo_t('Guardar configuración')); ?>
                    </button>
                </p>

            </form>

        </div>

        <script>
            (function() {
                'use strict';

                const form = document.getElementById('kh-matomo-form');

                if (!form) {
                    return;
                }

                const urlInput = document.getElementById('kh-matomo-url');
                const siteInput = document.getElementById('kh-matomo-site-id');
                const tokenInput = document.getElementById('kh-matomo-token');

                const testButton = document.getElementById('kh-matomo-test-button');
                const resultBox = document.getElementById('kh-matomo-test-result');
                const strings = <?php echo json_encode([
                    'testing' => kh_matomo_t('Probando conexión...'),
                    'communicating' => kh_matomo_t('Comunicando con Matomo...'),
                    'emptyResponse' => kh_matomo_t('YOURLS devolvió una respuesta vacía (HTTP %s). La acción AJAX no produjo JSON. URL: %s'),
                    'nonJson' => kh_matomo_t('YOURLS devolvió una respuesta no JSON (HTTP %s): %s'),
                    'rejected' => kh_matomo_t('La solicitud fue rechazada por YOURLS (HTTP %s).'),
                    'connectionFailed' => kh_matomo_t('No fue posible validar la conexión.'),
                    'test' => kh_matomo_t('Probar conexión'),
                    'stage' => kh_matomo_t(' [etapa: %s]'),
                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

                function format(template, values) {
                    return template.replace(/%s/g, function() {
                        return values.shift();
                    });
                }

                function invalidateTest() {
                    resultBox.textContent = '';
                    resultBox.className = '';
                }

                [urlInput, siteInput, tokenInput].forEach(function(input) {
                    input.addEventListener('input', invalidateTest);
                });

                testButton.addEventListener('click', async function() {

                    invalidateTest();

                    testButton.disabled = true;
                    testButton.textContent = strings.testing;

                    resultBox.textContent = strings.communicating;

                    const data = new URLSearchParams();
                    data.append('action', 'kh_matomo_test_connection');
                    data.append('nonce', form.dataset.testNonce);
                    data.append('matomo_url', urlInput.value);
                    data.append('site_id', siteInput.value);
                    data.append('auth_token', tokenInput.value);

                    try {
                        const response = await fetch(
                            form.dataset.testUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                body: data
                            }
                        );

                        const responseBody = await response.text();

                        if (!responseBody.trim()) {
                            throw new Error(
                                format(strings.emptyResponse, [response.status, form.dataset.testUrl])
                            );
                        }

                        let result;

                        try {
                            result = JSON.parse(responseBody);
                        } catch (parseError) {
                            throw new Error(
                                format(strings.nonJson, [response.status, responseBody.slice(0, 160)])
                            );
                        }

                        if (!response.ok) {
                            throw new Error(
                                result.message || format(strings.rejected, [response.status])
                            );
                        }

                        if (!result.success) {
                            throw new Error(
                                (result.message || strings.connectionFailed) +
                                (result.step ? format(strings.stage, [result.step]) : '')
                            );
                        }

                        resultBox.textContent = result.message;
                        resultBox.className = 'notice notice-success';

                    } catch (error) {
                        invalidateTest();

                        resultBox.textContent = error.message;
                        resultBox.className = 'notice notice-error';

                    } finally {
                        testButton.disabled = false;
                        testButton.textContent = strings.test;
                    }
                });

            })();
        </script>

<?php
    }
}

yourls_add_action('plugins_loaded', 'kh_matomo_register_admin_page');
