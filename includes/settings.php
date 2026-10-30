<?php

if ( !defined( 'YOURLS_ABSPATH' ) ) {
    die();
}

if ( !function_exists( 'kh_matomo_default_settings' ) ) {
    function kh_matomo_default_settings() {
        return [
            'matomo_url'       => '',
            'site_id'          => '',
            'auth_token'       => '',
            'tracking_enabled' => false,
            'anonymize_ip'     => true,
        ];
    }
}

if ( !function_exists( 'kh_matomo_get_settings' ) ) {
    function kh_matomo_get_settings() {
        $saved = yourls_get_option( 'kh_matomo_settings' );

        if ( !is_array( $saved ) ) {
            $saved = [];
        }

        $settings = array_merge(
            kh_matomo_default_settings(),
            $saved
        );

        $settings['matomo_url'] = is_string( $settings['matomo_url'] )
            ? trim( $settings['matomo_url'] )
            : '';

        $settings['site_id'] = is_scalar( $settings['site_id'] )
            ? (string) $settings['site_id']
            : '';

        $settings['auth_token'] = is_string( $settings['auth_token'] )
            ? $settings['auth_token']
            : '';

        $settings['tracking_enabled'] = (bool) $settings['tracking_enabled'];
        $settings['anonymize_ip'] = (bool) $settings['anonymize_ip'];

        return $settings;
    }
}

if ( !function_exists( 'kh_matomo_save_settings' ) ) {
    function kh_matomo_save_settings( $settings ) {
        if ( !is_array( $settings ) ) {
            return false;
        }

        $current = kh_matomo_get_settings();

        $settings = array_merge( $current, $settings );

        $settings['matomo_url'] = is_string( $settings['matomo_url'] )
            ? trim( $settings['matomo_url'] )
            : '';

        $settings['site_id'] = is_scalar( $settings['site_id'] )
            ? (string) $settings['site_id']
            : '';

        $settings['auth_token'] = is_string( $settings['auth_token'] )
            ? $settings['auth_token']
            : $current['auth_token'];

        $settings['tracking_enabled'] = (bool) $settings['tracking_enabled'];
        $settings['anonymize_ip'] = (bool) $settings['anonymize_ip'];

        yourls_update_option( 'kh_matomo_settings', $settings );

        return true;
    }
}
