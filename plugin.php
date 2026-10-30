<?php
/*
Plugin Name: KH Matomo Tracker
Plugin URI: https://github.com/KrakenHubMx/kh-matomo
Description: Server-side click tracking integration between YOURLS and Matomo.
Version: 0.1.0
Author: KRAKENHUB
Author URI: https://www.dusk.mx/
*/

if ( !defined( 'YOURLS_ABSPATH' ) ) {
    die();
}

if ( !defined( 'KH_MATOMO_VERSION' ) ) {
    define( 'KH_MATOMO_VERSION', '0.1.0' );
}

if ( !defined( 'KH_MATOMO_DIR' ) ) {
    define( 'KH_MATOMO_DIR', __DIR__ );
}

if ( !defined( 'KH_MATOMO_FILE' ) ) {
    define( 'KH_MATOMO_FILE', __FILE__ );
}

// Plugin modules.
require_once KH_MATOMO_DIR . '/includes/settings.php';
require_once KH_MATOMO_DIR . '/includes/matomo-api.php';
require_once KH_MATOMO_DIR . '/includes/tracker.php';
require_once KH_MATOMO_DIR . '/includes/admin.php';

yourls_add_action(
    'redirect_shorturl',
    'kh_matomo_track_redirect',
    10,
    2
);

// YOURLS 1.10.6's admin-ajax.php dispatches from $_REQUEST['action'] and exits
// silently when PHP's request_order/variables_order leaves it out. Preserve the
// normal authenticated admin-ajax flow by restoring this plugin's action from
// the raw query string or POST data before the core dispatcher runs.
if (defined('YOURLS_AJAX') && YOURLS_AJAX) {
    $kh_matomo_query = [];

    if (isset($_SERVER['QUERY_STRING'])) {
        parse_str($_SERVER['QUERY_STRING'], $kh_matomo_query);
    }

    $kh_matomo_ajax_action = $_POST['action']
        ?? $kh_matomo_query['action']
        ?? '';

    if ($kh_matomo_ajax_action === 'kh_matomo_test_connection') {
        $_REQUEST['action'] = $kh_matomo_ajax_action;
    }
}
