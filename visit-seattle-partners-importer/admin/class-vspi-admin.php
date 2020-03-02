<?php

/**
 * Class VSPI_Admin - Handles the Importer's WP admin page.
 *
 * Defines the plugin name, version, and enqueue hooks for the Importer's WP admin page.
 *
 * @package VSPI/admin
 * @version 1.0.0
 * @author Visit Seattle <webmaster@visitseattle.org>
 */

class VSPI_Admin
{
    /**
     * VSPI_Admin constructor.
     */
    public function __construct() {
        // Silence is golden
    }

    /**
     * Register stylesheets for the admin page.
     */
    public function enqueue_styles() {
        wp_enqueue_style(
            VSPI_BASENAME,
            plugins_url('includes/vspi-admin-styles.css', __FILE__),
            array(),
            VSPI_VERSION,
            'all'
        );
    }

    /**
     * Register JavaScript scripts for the admin page.
     */
    public function enqueue_scripts() {
        wp_enqueue_script(
            'moment',
            plugins_url('includes/moment.min.js', __FILE__),
            array(),
            VSPI_VERSION,
            false
        );
        wp_enqueue_script(
            'minidaemon',
            plugins_url('includes/mdn-minidaemon.js', __FILE__),
            array(),
            VSPI_VERSION,
            false
        );
        wp_enqueue_script(
            'vspi-admin',
            plugins_url('includes/vspi-admin.js', __FILE__),
            array('jquery'),
            VSPI_VERSION,
            false
        );
    }

    /**
     * Displays admin page.
     */
    public static function display_page() {
        require_once VSPI_PATH . 'admin/includes/vspi-admin-display.php';
    }

    /**
     * Creates menu item linking to plugin's admin page.
     */
    public function display_admin_menu_item() {
        add_menu_page(
            'Visit Seattle Partners Importer',
            'Partners Importer',
            'manage_options',
            'visit-seattle-partners-importer',
            array( 'VSPI_Admin', 'display_page' ),
            'dashicons-update'
        );
    }
}
