<?php
/**
 * Class VSPI - The core plugin class.
 *
 * This is used to define plugin activation and dependency wrangling. Also maintains the
 * unique plugin identifier and current version.
 *
 * @package VSPI/classes
 * @version 1.0.0
 * @author Visit Seattle <webmaster@visitseattle.org>
 */
class VSPI
{
    /**
     * @var object $loader - The Importer's loader object.
     */
    protected $loader;

    /**
     * VSPI constructor. @constructor
     */
    public function __construct() {
		$this->load_dependencies();
		$this->loader = new VSPI_Loader();
		$this->define_filters();
		$this->define_admin_hooks();
		$this->define_importer_hooks();
	}

	/* ==== Activation / Deactivation / Uninstallation ==== */

    /**
     * Handles plugin activation.
     *
     * `activate` initializes the Importer plugin by creating fields in the Options table, creates a custom table for
     *  caching, and schedules cron events.
     *
     * (@internal `add_option` is used in cases where pre-existing values should pre preserved. `update_option` is used
     *  in the case of backwards-incompatible changes.)
     */
    public static function activate() {
	    // Add option fields
        add_option('vspi_import_status', 'free', '', 'no');
        add_option('vspi_import_method', '', '', 'no');
        add_option('vspi_last_updated', '2001-01-01', '', 'no');
        add_option('vspi_processed_count', 0, '', 'no');
        add_option('vspi_add_count', 0, '', 'no');
        add_option('vspi_delete_count', 0, '', 'no');
        add_option('vspi_total_count', 0, '', 'no');
        add_option('vspi_last_run_data', '{}', '', 'no');

        // Create cache table
        self::create_custom_table();

        // Add cron jobs
		wp_schedule_event(time(), 'daily', 'vspi_run_cron_import');
        wp_schedule_event(time(), 'weekly', 'vspi_run_cron_invalidate_cache');
	}

    /**
     * Handles plugin deactivation.
     */
    public static function deactivate() {
		// Remove import cron
        $next_import = wp_next_scheduled('vspi_run_cron_import');
        if ($next_import) {
            wp_unschedule_event($next_import, 'vspi_run_cron_import');
        }

        // Remove invalidate cron
        $next_invalidate = wp_next_scheduled('vspi_run_cron_invalidate_cache');
        if ($next_invalidate) {
            wp_unschedule_event($next_invalidate, 'vspi_run_cron_invalidate_cache');
        }
	}

    /**
     * Handles plugin uninstallation.
     *
     * `uninstall` removes the plugin and its data, including rows in the Options table, the plugin's cache table,
     *  and cron processes.
     */
    public static function uninstall() {
		// If uninstall is not called from WordPress, exit
		if (!defined( 'WP_UNINSTALL_PLUGIN' )) {
			exit;
		}

		// Remove options
        delete_option('vspi_import_status');
        delete_option('vspi_import_method');
        delete_option('vspi_last_updated');
        delete_option('vspi_processed_count');
        delete_option('vspi_add_count');
        delete_option('vspi_delete_count');
        delete_option('vspi_total_count');
        delete_option('vspi_last_run_data');

        // Remove cache table
        self::drop_custom_table();

        // Remove import cron
        $next_import = wp_next_scheduled('vspi_run_cron_import');
        if ($next_import) {
            wp_unschedule_event($next_import, 'vspi_run_cron_import');
        }

        // Remove invalidate cron
        $next_invalidate = wp_next_scheduled('vspi_cron_invalidate_cache');
        if ($next_invalidate) {
            wp_unschedule_event($next_invalidate, 'vspi_cron_invalidate_cache');
        }
	}

	/* ==== Loader Handling ==== */

    /**
     * Loads PHP files required by the plugin.
     */
    private function load_dependencies() {
		require_once VSPI_PATH . 'classes/class-vspi-loader.php';
		require_once VSPI_PATH . 'classes/class-vspi-importer.php';
		require_once VSPI_PATH . 'admin/class-vspi-admin.php';
	}

    /**
     * Defines plugin filters.
     */
    private function define_filters() {
        $this->loader->add_filter('cron_schedules', $this, 'vspi_cron_weekly_recurrence');
    }

    /**
     * Defines hooks related to the admin page.
     */
    private function define_admin_hooks() {
		$plugin_admin = new VSPI_Admin();

		$this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
		$this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');
		$this->loader->add_action('admin_menu', $plugin_admin, 'display_admin_menu_item');
	}

    /**
     * Defines hooks related to the Importer.
     */
    private function define_importer_hooks() {
		$plugin_importer = new VSPI_Importer();

		// Cron
		$this->loader->add_action('vspi_run_cron_import', $plugin_importer, 'vspi_run_cron_import');
		$this->loader->add_action('vspi_run_cron_invalidate_cache', $plugin_importer, 'vspi_run_cron_invalidate_cache');
        // Import
		$this->loader->add_action('wp_ajax_vspi_run_import_new', $plugin_importer, 'vspi_run_import_new');
		$this->loader->add_action('wp_ajax_vspi_run_import_single', $plugin_importer, 'vspi_run_import_single');
		$this->loader->add_action('wp_ajax_vspi_run_import_all', $plugin_importer, 'vspi_run_import_all');
		$this->loader->add_action('wp_ajax_vspi_run_import_images', $plugin_importer, 'vspi_run_import_images');
        // Prune/purge
		$this->loader->add_action('wp_ajax_vspi_run_delete_all', $plugin_importer, 'vspi_run_delete_all');
        $this->loader->add_action('wp_ajax_vspi_run_delete_stale', $plugin_importer, 'vspi_run_delete_stale');
        // Caching
        $this->loader->add_action('wp_ajax_vspi_run_create_cache', $plugin_importer, 'vspi_run_create_cache');
        $this->loader->add_action('wp_ajax_vspi_run_delete_cache', $plugin_importer, 'vspi_run_delete_cache');
        // Cancel/resume
        $this->loader->add_action('wp_ajax_vspi_run_cancel', $plugin_importer, 'vspi_run_cancel');
        $this->loader->add_action('wp_ajax_vspi_run_manual_resume', $plugin_importer, 'vspi_run_manual_resume');
        // Data fetch
		$this->loader->add_action('wp_ajax_vspi_fetch_importer_status', $plugin_importer, 'vspi_fetch_importer_status');
		$this->loader->add_action('wp_ajax_vspi_fetch_total_count', $plugin_importer, 'vspi_fetch_total_count');
		$this->loader->add_action('wp_ajax_vspi_fetch_running_action', $plugin_importer,'vspi_fetch_running_action');
	}

	/* ==== Data Handling ==== */

    /**
     * Creates a new table used for storing the Importer cache.
     */
    private static function create_custom_table() {
	    global $wpdb;

	    $charset_collate = $wpdb->get_charset_collate();
	    $query = "CREATE TABLE " . VSPI_CACHE_TABLE . " (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name tinytext NOT NULL,
            xmldata longblob,
            lastupdated tinytext,
            PRIMARY KEY  (id)
        ) $charset_collate;";

	    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	    dbDelta($query);
    }

    /**
     * Removes the Importer's cache table.
     */
    private static function drop_custom_table() {
	    global $wpdb;
	    $table_name = VSPI_CACHE_TABLE;
        $query = "DROP TABLE IF EXISTS {$table_name}";
        $wpdb->query($query);
    }

    /* ==== Custom Cron Interval ==== */

    /**
     * Adds a weekly scheduling option for cron processes.
     *
     * @param array $schedules - The current scheduling options for the server's cron.
     *
     * @return array - The altered scheduling options, to be read in by the server.
     */
    public static function vspi_cron_weekly_recurrence($schedules) {
        if (!array_key_exists('weekly', $schedules)) {
            $schedules['weekly'] = array(
                'display'   => __('once weekly', 'textdomain'),
                'interval'  => 604800
            );
        }
        return $schedules;
    }

	/* ==== Run the Plugin ====*/

    /**
     * Runs the plugin initialization process.
     */
    public function run() {
		$this->loader->run();
	}
}
