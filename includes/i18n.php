<?php

if (!defined('YOURLS_ABSPATH')) {
    die();
}

/** Translate a plugin string using YOURLS' gettext domain. */
if (!function_exists('kh_matomo_t')) {
    function kh_matomo_t($text)
    {
        return yourls__($text, 'kh-matomo');
    }
}

/** Load the plugin catalog for the active YOURLS locale when one exists. */
if (!function_exists('kh_matomo_load_textdomain')) {
    function kh_matomo_load_textdomain()
    {
        $locale = yourls_get_locale();
        if (!is_string($locale) || $locale === '') {
            return false;
        }

        $catalog = KH_MATOMO_DIR . '/languages/kh-matomo-' . $locale . '.mo';
        if (!is_readable($catalog)) {
            return false;
        }

        return yourls_load_textdomain('kh-matomo', $catalog);
    }
}
