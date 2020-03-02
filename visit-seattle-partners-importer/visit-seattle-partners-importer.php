<?php
/*
 * Visit Seattle Partners Importer bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the admin area.
 * This file also includes all of the dependencies used by the plugin, registers the
 * activation, deactivation, and uninstallation functions, and defines a function that
 * starts the plugin.
 *
 * Boilerplate code comes from [Wordpress-Plugin-Boilerplate](https://github.com/DevinVinson/WordPress-Plugin-Boilerplate)
 */

/*
Plugin Name: Visit Seattle Partners Importer
Plugin URI: https://github.com/VisitSeattle/visitseattle-partners-plugin
Description: Fetches partner data sourced from SimpleView.
Version: 1.1.0
Author: Visit Seattle
*/

// If this file is called directly, abort
if ( !function_exists( 'add_filter' ) ) {
    header('Status: 403 Forbidden' );
    header( 'HTTP/1.1 403 Forbidden' );
    exit();
}

/* ==== Define Globals ==== */

// Intentionally not checking for a previous definition
// since this should not be overridden
define('VSPI_VERSION', '1.1.0');

if (!defined('VSPI_PATH')) {
    define('VSPI_PATH', plugin_dir_path(__FILE__));
}

if (!defined('VSPI_BASENAME')) {
    define('VSPI_BASENAME', 'Visit Seattle Partners Importer');
}

if (!defined('VSPI_CACHE_TABLE')) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'vspi_listing_cache';
    define('VSPI_CACHE_TABLE', $table_name);
}

/*==== Activation, Deactivation, and Uninstallation === */

require_once(VSPI_PATH . 'classes/class-vspi.php');

/**
 * Handler for plugin activation.
 */
function vspi_activate_plugin() {
	VSPI::activate();
}

/**
 * Handler for plugin deactivation.
 */
function vspi_deactivate_plugin() {
	VSPI::deactivate();
}

/**
 * Handler for plugin uninstallation.
 */
function vspi_uninstall_plugin() {
	VSPI::uninstall();
}

register_activation_hook(__FILE__, 'vspi_activate_plugin');
register_deactivation_hook(__FILE__, 'vspi_deactivate_plugin');
register_uninstall_hook(__FILE__, 'vspi_uninstall_plugin');

/**
 * Begins plugin execution.
 */
function vspi_run_plugin() {
	$plugin = new VSPI();
	$plugin->run();
}
vspi_run_plugin();
