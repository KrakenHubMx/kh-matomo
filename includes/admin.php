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

/** Format a stored Unix timestamp using YOURLS' timezone and locale filters. */
if (!function_exists('kh_matomo_format_timestamp')) {
    function kh_matomo_format_timestamp($timestamp)
    {
        return yourls_date_i18n(
            yourls_get_datetime_format('Y-m-d H:i:s'),
            yourls_get_timestamp((int) $timestamp)
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
                <th scope="row"><?php echo kh_matomo_escape(kh_matomo_t('Enlaces no encontrados')); ?></th>
                <td>
                    <label for="kh-matomo-track-not-found">
                        <input
                            type="checkbox"
                            id="kh-matomo-track-not-found"
                            name="track_not_found"
                            value="1"
                            aria-describedby="kh-matomo-not-found-help"
                            <?php echo $settings['track_not_found'] ? 'checked' : ''; ?>>
                        <?php echo kh_matomo_escape(kh_matomo_t('Registrar enlaces no encontrados')); ?>
                    </label>
                    <p class="description" id="kh-matomo-not-found-help">
                        <?php echo kh_matomo_escape(kh_matomo_t('Envía un evento a Matomo cuando alguien solicita un enlace corto que no existe. Requiere que el seguimiento esté habilitado y no cambia la redirección de YOURLS.')); ?>
                        <?php echo kh_matomo_escape(kh_matomo_t('Solo se registran solicitudes GET con formato de keyword válido, hasta 30 intentos de envío por minuto para toda la instalación.')); ?>
                    </p>
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
        $update = kh_matomo_get_update_status();
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
                    <?php if ($update['update_available']) : ?>
                        <br>
                        <strong><?php echo kh_matomo_escape(kh_matomo_t('Actualización disponible:')); ?></strong>
                        <a href="<?php echo kh_matomo_escape($update['release_url']); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo kh_matomo_escape($update['latest_version']); ?>
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    <?php
    }
}

/** Render sampled transport diagnostics without visitor data. */
if (!function_exists('kh_matomo_render_diagnostics_section')) {
    function kh_matomo_render_diagnostics_section()
    {
        $labels = [
            'ok' => kh_matomo_t('Respuesta exitosa'),
            'curl_error' => kh_matomo_t('Error de transporte'),
            'http_error' => kh_matomo_t('Respuesta HTTP no exitosa'),
            'curl_unavailable' => kh_matomo_t('cURL no disponible'),
            'curl_init_failed' => kh_matomo_t('No se pudo iniciar cURL'),
            'settings_missing' => kh_matomo_t('Configuración incompleta'),
            'exception' => kh_matomo_t('Error interno'),
        ];
        ?>
        <h3><?php echo kh_matomo_escape(kh_matomo_t('Diagnóstico de envíos')); ?></h3>
        <p><?php echo kh_matomo_escape(kh_matomo_t('Muestra de respuestas del seguimiento: se guarda como máximo un éxito y un fallo por minuto, salvo solicitudes concurrentes. Puede omitir resultados intermedios. La prueba de conexión no se incluye.')); ?></p>
        <p><?php echo kh_matomo_escape(kh_matomo_t('Una respuesta exitosa no confirma que el evento aparezca en los informes de Matomo. Las fechas usan la zona horaria y el formato configurados en YOURLS.')); ?></p>
        <div class="kh-matomo-diagnostics">
        <?php foreach ([
            'success' => kh_matomo_t('Último éxito registrado'),
            'failure' => kh_matomo_t('Último fallo registrado'),
        ] as $outcome => $title) :
            $entry = yourls_get_option('kh_matomo_diagnostics_' . $outcome);
            $has_entry = is_array($entry) && !empty($entry['recorded_at']);
            $reason = $has_entry ? ($labels[$entry['reason'] ?? ''] ?? kh_matomo_t('Error interno')) : '';
            if ($has_entry && ($entry['reason'] ?? '') === 'curl_error' && (int) ($entry['curl_errno'] ?? 0) === 28) {
                $reason = kh_matomo_t('Tiempo de espera agotado');
            }
            ?>
            <article class="kh-matomo-result<?php echo $has_entry ? ' kh-matomo-result--' . kh_matomo_escape($outcome) : ''; ?>">
                <h4><?php echo kh_matomo_escape($title); ?></h4>
                <?php if (!$has_entry) : ?>
                    <p class="description"><?php echo kh_matomo_escape(kh_matomo_t('Sin registros.')); ?></p>
                <?php else : ?>
                    <strong><?php echo kh_matomo_escape($reason); ?></strong>
                    <time datetime="<?php echo kh_matomo_escape(gmdate('Y-m-d\TH:i:s\Z', (int) $entry['recorded_at'])); ?>">
                        <?php echo kh_matomo_escape(kh_matomo_format_timestamp($entry['recorded_at'])); ?>
                    </time>
                    <dl>
                        <div><dt>HTTP</dt><dd><?php echo (int) ($entry['http_code'] ?? 0); ?></dd></div>
                        <div><dt>cURL</dt><dd><?php echo (int) ($entry['curl_errno'] ?? 0); ?></dd></div>
                        <div><dt><?php echo kh_matomo_escape(kh_matomo_t('Duración')); ?></dt><dd><?php echo (int) ($entry['total_time_ms'] ?? 0); ?> ms</dd></div>
                    </dl>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
        <h4><?php echo kh_matomo_escape(kh_matomo_t('Historial de muestras')); ?></h4>
        <p class="description"><?php echo kh_matomo_escape(kh_matomo_t('Hasta 10 éxitos y 10 fallos recientes, del más nuevo al más antiguo. No incluye todos los intentos.')); ?></p>
        <?php $history = kh_matomo_get_diagnostics_history(); ?>
        <?php if (!$history) : ?>
            <p><?php echo kh_matomo_escape(kh_matomo_t('Sin registros.')); ?></p>
        <?php else : ?>
        <div class="kh-matomo-history-scroll">
            <table class="kh-matomo-history">
                <caption class="description"><?php echo kh_matomo_escape(kh_matomo_t('Historial de muestras')); ?></caption>
                <thead><tr>
                    <th scope="col"><?php echo kh_matomo_escape(kh_matomo_t('Fecha')); ?></th>
                    <th scope="col"><?php echo kh_matomo_escape(kh_matomo_t('Resultado')); ?></th>
                    <th scope="col">HTTP</th><th scope="col">cURL</th>
                    <th scope="col"><?php echo kh_matomo_escape(kh_matomo_t('Duración')); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($history as $entry) :
                    $reason = $labels[$entry['reason'] ?? ''] ?? kh_matomo_t('Error interno');
                    if (($entry['reason'] ?? '') === 'curl_error' && (int) ($entry['curl_errno'] ?? 0) === 28) {
                        $reason = kh_matomo_t('Tiempo de espera agotado');
                    }
                    ?>
                    <tr>
                        <td><?php echo kh_matomo_escape(kh_matomo_format_timestamp($entry['recorded_at'])); ?></td>
                        <td><?php echo kh_matomo_escape($reason); ?></td>
                        <td><?php echo (int) ($entry['http_code'] ?? 0); ?></td>
                        <td><?php echo (int) ($entry['curl_errno'] ?? 0); ?></td>
                        <td><?php echo (int) ($entry['total_time_ms'] ?? 0); ?> ms</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <form method="post">
            <?php yourls_nonce_field('kh_matomo_clear_diagnostics_history'); ?>
            <p><button type="submit" class="button" name="kh_matomo_action" value="clear_diagnostics_history"><?php echo kh_matomo_escape(kh_matomo_t('Borrar historial de muestras')); ?></button></p>
        </form>
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
        $settings['track_not_found'] = isset($input['track_not_found']);
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

        if (($_POST['kh_matomo_action'] ?? '') === 'clear_diagnostics_history') {
            yourls_verify_nonce('kh_matomo_clear_diagnostics_history', $_POST['nonce'] ?? '');
            try {
                kh_matomo_clear_diagnostics_history();
                return ['type' => 'success', 'message' => kh_matomo_t('El historial de muestras se borró. Las tarjetas se conservan.')];
            } catch (Throwable $e) {
                return ['type' => 'error', 'message' => kh_matomo_t('No se pudo borrar el historial de muestras.')];
            }
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
        <style>
            .kh-matomo-admin { max-width: 1080px; }
            .kh-matomo-admin .kh-matomo-panel { background: #fff; border: 1px solid #d9dfe5; border-radius: 8px; padding: 24px; margin: 24px 0; box-shadow: 0 2px 5px rgba(0,0,0,.035); }
            .kh-matomo-admin .kh-matomo-panel h3 { margin: 0 0 16px; padding-bottom: 14px; border-bottom: 1px solid #e5e9ed; font-size: 18px; color: #243447; }
            .kh-matomo-admin .form-table { width: 100%; border-collapse: collapse; margin: 12px 0 0; }
            .kh-matomo-admin .form-table th { width: 200px; text-align: left; font-weight: 600; }
            .kh-matomo-admin .form-table th, .kh-matomo-admin .form-table td { padding: 14px 12px 14px 0; vertical-align: top; }
            .kh-matomo-admin .regular-text { width: 100%; max-width: 520px; box-sizing: border-box; }
            .kh-matomo-admin .description { color: #526170; font-size: 13px; line-height: 1.6; }
            .kh-matomo-admin .kh-matomo-save { margin: 0 0 32px; }
            .kh-matomo-admin #kh-matomo-test-result:empty { display: none; }
            .kh-matomo-admin #kh-matomo-test-result:not(:empty) { margin-top: 16px; padding: 12px; }
            .kh-matomo-admin .kh-matomo-diagnostics { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin: 20px 0; }
            .kh-matomo-admin .kh-matomo-result { border: 1px solid #d9dfe5; border-top: 3px solid #748291; border-radius: 6px; padding: 20px; background: #f8fafb; }
            .kh-matomo-admin .kh-matomo-result--success { border-top-color: #23754b; }
            .kh-matomo-admin .kh-matomo-result--failure { border-top-color: #b14c24; }
            .kh-matomo-admin .kh-matomo-result h4 { margin: 0 0 14px; font-size: 15px; }
            .kh-matomo-admin .kh-matomo-result time { display: block; margin: 10px 0; color: #526170; font-size: 13px; }
            .kh-matomo-admin .kh-matomo-result dl { display: flex; flex-wrap: wrap; gap: 20px; margin: 18px 0 0; }
            .kh-matomo-admin .kh-matomo-result dt { font-size: 12px; color: #526170; }
            .kh-matomo-admin .kh-matomo-result dd { margin: 5px 0 0; font-size: 16px; font-weight: 600; }
            .kh-matomo-admin .kh-matomo-history-scroll { overflow-x: auto; }
            .kh-matomo-admin .kh-matomo-history { width: 100%; border-collapse: collapse; margin: 12px 0; }
            .kh-matomo-admin .kh-matomo-history caption { text-align: left; padding-bottom: 8px; }
            .kh-matomo-admin .kh-matomo-history th, .kh-matomo-admin .kh-matomo-history td { padding: 12px; text-align: left; border-bottom: 1px solid #e5e9ed; }
            .kh-matomo-admin .kh-matomo-history thead { background: #f0f4f7; }
            @media (max-width: 680px) {
                .kh-matomo-admin .kh-matomo-panel { padding: 16px; }
                .kh-matomo-admin .kh-matomo-diagnostics { grid-template-columns: 1fr; }
                .kh-matomo-admin .form-table th, .kh-matomo-admin .form-table td { display: block; width: auto; padding: 8px 0; }
            }
        </style>
        <div class="wrap kh-matomo-admin">
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

                <section class="kh-matomo-panel">
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

                </section>

                <section class="kh-matomo-panel">
                    <?php kh_matomo_render_tracking_section($settings); ?>
                </section>

                <p class="kh-matomo-save">
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

            <section class="kh-matomo-panel">
                <?php kh_matomo_render_status_section($settings); ?>
            </section>

            <section class="kh-matomo-panel">
                <?php kh_matomo_render_diagnostics_section(); ?>
            </section>

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
