<?php

/**
 * Class VSPI_Importer - Imports partner data from WordPress.
 *
 * Imports partner data from SimpleView via their API. Creates WordPress posts, image attachments, and taxonomy based
 * on imported data. Communicates with client to allow maintenance actions -- importing, deletion, and cache control.
 *
 * @package VSPI/classes
 * @version 1.1.0
 * @author  Visit Seattle <webmaster@visitseattle.org>
 */
class VSPI_Importer
{
    /* ================================
     * ======= Class properties =======
     * ================================
     */

    /* ==== Constants ==== */

    /** @var string - URL for SimpleView API endpoint. @readonly */
    const API_URL = 'https://seattle.simpleviewcrm.com/webapi/listings/xml/listings.cfm';

    /** @var string - The registered user for the SimpleView API. @readonly */
    const API_USER = 'test_user';

    /** @var string - The password for the registered API user. @readonly */
    const API_PASS = 'test_password';

    /** @var string - The base path for partner images. @readonly */
    const BASE_IMG_PATH = 'https://res.cloudinary.com/simpleview/image/upload/';

    /** @var array - The WP custom taxonomies associated with partner type posts. @readonly */
    const CUSTOM_TAXONOMIES = array('partners_types', 'partners_categories', 'partners_regions');

    /** @var array - Valid extensions for images. @readonly */
    const IMAGE_EXTENSIONS = array('jpg', 'jpeg', 'gif', 'png');

    /** @var string - The prefix for the name of cache database rows. @readonly */
    const CACHE_NAME_PREFIX = 'vspi_partners-xml_';

    /**
     * @var number - The # of listings to request per chunk. @readonly
     * @internal Going over ~200 will exhaust available memory.
     *           Going over ~25 causes an undiagnosed timeout.
     */
    const CHUNK_SIZE = 25;

    /** @var number - The default timespan to request new/changed listings. @readonly */
    const DEFAULT_IMPORT_INTERVAL = 48;

    /** @var array - Default error messages. @since 1.1.0 @readonly */
    const ERROR_MSGS = array(
        'preflight' => 'Process failed preflight checks',
        'no_id' => 'No or invalid listing ID given',
        'no_date' => 'No or invalid date given',
        'empty' => 'No or empty result returned'

    );

    /* ==== Variables ==== */

    /** @var array - A list of already imported partners. */
    private static $existing_partners = array();

    /** @var array - A list of already imported & attached images. */
    private static $processed_images = array();

    /* ================================
     * ========= Constructor  =========
     * ================================
     */

    /**
     * VSPI_Importer constructor. @constructor
     */
    public function __construct() {
		// Nothing to see here
	}

	/* ==================================
	 * ========= AJAX Endpoints =========
	 * ==================================
	 */

	/* ==== Importer actions ==== */

    /**
     * AJAX endpoint to fetch new or changed partners.
     * @public
     */
    public static function vspi_run_import_new()
    {
        $action = !empty($_POST['import_action']) ? $_POST['import_action'] : 'import_new';
        $start_date = self::calculate_start_date();
        $init = (array_key_exists('init', $_POST) && isset($_POST['init'])) ? $_POST['init'] : null;
        $args = array('action' => $action, 'date' => $start_date);

        if (!self::preflight($args, $init)) {
            exit(json_encode(array('status' => 'error', 'message' => self::ERROR_MSGS['preflight'])));
        }

        self::import_changed_by_chunk($start_date, $args);
        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * AJAX endpoint to fetch a single partner.
     * @public
     */
    public static function vspi_run_import_single()
    {
        $action = !empty($_POST['import_action']) ? $_POST['import_action'] : 'import_single';
        $listing_id = $_POST['listing'] ?: null;
        $init = (array_key_exists('init', $_POST) && isset($_POST['init'])) ? $_POST['init'] : null;
        $args = array('action' => $action, 'listing_id' => $listing_id);

        if (empty($listing_id) || !intval($listing_id)) {
            exit(json_encode(array('status' => 'error', 'message' => self::ERROR_MSGS['no_id'])));
        }
        if (!self::preflight($args, $init)) {
            exit(json_encode(array('status' => 'error', 'message' => self::ERROR_MSGS['preflight'])));
        }

        self::import_single_partner($listing_id);
        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * AJAX endpoint to fetch all partners.
     * @public
     */
    public static function vspi_run_import_all()
    {
        $action = !empty($_POST['import_action']) ? $_POST['import_action'] : 'import_all';
        $init = (array_key_exists('init', $_POST) && isset($_POST['init'])) ? $_POST['init'] : null;
        $args = array('action' => $action);

        if (!self::preflight($args, $init)) {
            exit(json_encode(array('status' => 'error', 'message' => self::ERROR_MSGS['preflight'])));
        }

        self::import_all_by_chunk($args);
        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * AJAX endpoint to fetch all images.
     * @since 1.1.0
     * @public
     */
    public static function vspi_run_import_images()
    {
        $action = !empty($_POST['import_action']) ? $_POST['import_action'] : 'import_images';
        $init = (array_key_exists('init', $_POST) && isset($_POST['init'])) ? $_POST['init'] : null;
        $args = array('action' => $action);

        if (!self::preflight($args, $init)) {
            exit(json_encode(array('status' => 'error', 'message' => self::ERROR_MSGS['preflight'])));
        }

        self::import_images_by_chunk($args);
        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * AJAX endpoint to remove all partners.
     * @public
     */
    public static function vspi_run_delete_all()
    {
        $action = !empty($_POST['import_action']) ? $_POST['import_action'] : 'delete_all';
        $init = (array_key_exists('init', $_POST) && isset($_POST['init'])) ? $_POST['init'] : null;
        $args = array('action' => $action);

        if (!self::preflight($args, $init)) {
            exit(json_encode(array('status' => 'error', 'message' => self::ERROR_MSGS['preflight'])));
        }

        self::delete_all_by_chunk($args);
        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * AJAX endpoint to remove invalid partners.
     * @public
     */
    public static function vspi_run_delete_stale()
    {
        $action = !empty($_POST['import_action']) ? $_POST['import_action'] : 'delete_stale';
        $interval = ($action === 'import_all') ? '-1 month' : '-1 week'; // Interval differs depending on action
        $start_date = self::calculate_start_date($interval);
        $init = (array_key_exists('init', $_POST) && isset($_POST['init'])) ? $_POST['init'] : null;
        $args = array('action' => $action, 'date' => $start_date);

        if (!self::preflight($args, $init)) {
            exit(json_encode(array('status' => 'error', 'message' => self::ERROR_MSGS['preflight'])));
        }

        self::delete_stale_partners($start_date, $args);
        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * AJAX endpoint to manually create a fresh store of data.
     * @public
     */
    public static function vspi_run_create_cache()
    {
        $action = !empty($_POST['import_action']) ? $_POST['import_action'] : 'create_cache';
        $current_page = isset($_POST['page']) ? intval($_POST['page']) : 0;
        $args = array('action' => $action);

        if (!self::preflight($args, $current_page === 0)) {
            exit(json_encode(array('status' => 'error', 'message' => self::ERROR_MSGS['preflight'])));
        }

        self::create_listing_cache($args);
        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * AJAX endpoint to manually remove all cached data.
     * @public
     */
    public static function vspi_run_delete_cache()
    {
        $action = !empty($_POST['import_action']) ? $_POST['import_action'] : 'delete_cache';
        $args = array('action' => $action);

        if (!self::preflight($args)) {
            exit(json_encode(array('status' => 'error', 'message' => self::ERROR_MSGS['preflight'])));
        }

        self::clear_listing_cache();
        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * AJAX endpoint to cancel current process.
     * @public
     */
    public static function vspi_run_cancel()
    {
        $run_data = self::get_last_run_data();
        $args = array(
            'action'     => $run_data->action,
            'date'       => $run_data->fetch_date,
            'listing_id' => $run_data->listing_id
        );
        self::set_last_run_data($args);
        self::set_import_status('free:canceled');
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * AJAX endpoint to resume most recently stopped process.
     * @public
     */
    public static function vspi_run_manual_resume()
    {
        $prev_run_data = self::get_last_run_data();
        self::resume_process($prev_run_data->action, intval($prev_run_data->page), $prev_run_data->fetch_date,
            $prev_run_data->listing_id);
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * Handles the running of resumed processes.
     * @public
     *
     * @param string      $action_name - The name of the action/process being resumed.
     * @param number|null $start_page  - (optional) The page offset to start at.
     * @param string|null $start_date  - (optional) The start date for fetching listings from the API.
     * @param number|null $listing_id  - (optional) The ID of the listing to import.
     */
    public static function resume_process($action_name, $start_page = null, $start_date = null, $listing_id = null)
    {
        switch ($action_name) {
            case 'import_new':
                // Start date & start page required
                if (!$start_date || is_null($start_page)) {
                    exit(json_encode(array(
                        'status'  => 'error',
                        'message' => 'While resuming import_new: ' . self::ERROR_MSGS['no_date']
                    )));
                }
                $args = array('action' => $action_name, 'date' => $start_date);
                if (!self::preflight($args)) {
                    exit(json_encode(array(
                        'status'  => 'error',
                        'message' => ' While resuming import_new: ' . self::ERROR_MSGS['preflight']
                    )));
                }
                self::import_changed_by_chunk($start_date, $args);
                self::postflight($args);
                break;
            case 'import_single':
                // Listing id required
                if (!$listing_id || !intval($listing_id)) {
                    exit(json_encode(array(
                        'status'  => 'error',
                        'message' => 'While resuming import_single: ' . self::ERROR_MSGS['no_id']
                    )));
                }
                $args = array('action' => $action_name, 'listing_id' => $listing_id);
                if (!self::preflight($args, true)) {
                    exit(json_encode(array(
                        'status'  => 'error',
                        'message' => 'While resuming import_single: ' . self::ERROR_MSGS['preflight']
                    )));
                }
                self::import_single_partner($listing_id);
                self::postflight($args);
                break;
            case 'import_all':
                $args = array('action' => $action_name);
                if (!self::preflight($args)) {
                    exit(json_encode(array(
                        'status'  => 'error',
                        'message' => 'While resuming import_all: ' . self::ERROR_MSGS['preflight']
                    )));
                }
                self::import_all_by_chunk($args);
                self::postflight($args);
                break;

            case 'import_images':
                $args = array('action' => $action_name);
                if (!self::preflight($args, true)) {
                    exit(json_encode(array(
                        'status'  => 'error',
                        'message' => 'While resuming import_images: ' . self::ERROR_MSGS['preflight']
                    )));
                }
                self::import_images_by_chunk($args);
                self::postflight($args);
                break;
            case 'delete_all':
                $args = array('action' => $action_name);
                if (!self::preflight($args)) {
                    exit(json_encode(array(
                        'status'  => 'error',
                        'message' => 'While resuming delete_all: ' . self::ERROR_MSGS['preflight']
                    )));
                }
                self::delete_all_by_chunk($args);
                self::postflight($args);
                break;
            case 'delete_stale':
                // Start date required
                if (!$start_date) {
                    exit(json_encode(array(
                        'status'  => 'error',
                        'message' => 'While resuming delete_stale: ' . self::ERROR_MSGS['no_date']
                    )));
                }
                $args = array('action' => $action_name, 'date' => $start_date);
                if (!self::preflight($args)) {
                    exit(json_encode(array(
                        'status'  => 'error',
                        'message' => 'While resuming delete_stale: ' . self::ERROR_MSGS['preflight']
                    )));
                }
                self::delete_stale_partners($start_date, $args);
                self::postflight($args);
                break;
            case 'reset_all':
                $args = array('action' => $action_name);
                if (!self::preflight($args)) {
                    exit(json_encode(array(
                        'status'  => 'error',
                        'message' => 'While resuming reset_all: ' . self::ERROR_MSGS['preflight']
                    )));
                }
                $method = explode('/', self::get_import_method());
                if ($method[0] === 'delete') {
                    self::delete_all_by_chunk($args);
                } else if ($method[0] === 'import') {
                    self::import_all_by_chunk($args);
                } else {
                    exit(json_encode(array(
                        'status'  => 'error',
                        'message' => "While resuming reset_all: Unhandled method '$method'"
                    )));
                }
                self::postflight($args);
                break;
            case 'create_cache':
                if (is_null($start_page)) {
                    exit(json_encode(array(
                        'status'  => 'error',
                        'message' => 'While resuming cache_create: ' . self::ERROR_MSGS['no_date']
                    )));
                }
                $args = array('action' => $action_name);
                if (!self::preflight($args)) {
                    exit(json_encode(array(
                        'status'  => 'error',
                        'message' => 'While resuming create_cache: ' . self::ERROR_MSGS['preflight']
                    )));
                }
                self::create_listing_cache($args);
                self::postflight($args);
                break;
            default:
                exit(json_encode(array(
                    'status'  => 'error',
                    'message' => "Unknown action '$action_name' set to resume."
                )));
        }
    }

    /* ==== Cron actions ==== */

    /**
     * Endpoint to clear cache data via server cron.
     * @public
     */
    public static function vspi_run_cron_invalidate_cache()
    {
        $args = array('action' => 'delete_cache');

        if (!self::preflight($args)) {
            exit(json_encode(array('status' => 'error', 'message' => self::ERROR_MSGS['preflight'])));
        }

        self::clear_listing_cache();
        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /**
     * Endpoint to fetch new or changed partners via server cron.
     * @public
     */
    public static function vspi_run_cron_import()
    {
        $import_start = date('Y-m-d H:i:s', strtotime('-2 days'));
        $args = array('action' => 'cron_import');

        /* == Delete stale == */

        if (!self::preflight($args, 'hard')) {
            exit(json_encode(array('status' => 'error', 'message' => self::ERROR_MSGS['preflight'])));
        }

        self::delete_stale_partners($import_start, $args);

        self::postflight($args);

        /* == Reset counters == */

        self::set_processed_count(0);
        self::set_total_count(0);

        /* == Import new/changed == */

        if (!self::preflight($args, 'soft')) {
            exit(json_encode(array('status' => 'error', 'message' => self::ERROR_MSGS['preflight'])));
        }

        $page_num = 0;
        do {
            self::import_changed_by_chunk($import_start, $args);
            self::set_current_page(++$page_num);
        } while (self::get_processed_count() < self::get_total_count());

        /* == Postflight == */

        self::postflight($args);
        exit(json_encode(array('status' => 'success')));
    }

    /* ==== Data Fetch ==== */

    /**
     * AJAX endpoint to get the importer's current status.
     * @public
     */
    public static function vspi_fetch_importer_status()
    {
        $import_data = array(
            'status'    => self::get_import_status(),
            'method'    => self::get_import_method(),
            'timestamp' => self::get_import_time(),
            'processed' => self::get_processed_count(),
            'added'     => self::get_add_count(),
            'deleted'   => self::get_delete_count(),
            'page'      => self::get_current_page(),
            'total'     => self::get_total_count()
        );
        $import_json = json_encode($import_data) ?: "{}";
        echo $import_json;
        exit;
    }

    /**
     * AJAX endpoint to get the number of listings on the site.
     * @public
     */
    public static function vspi_fetch_total_count()
    {
        echo wp_count_posts('partners')->publish;
        exit;
    }

    /**
     * AJAX endpoint to get name of the current action.
     * @public
     */
    public static function vspi_fetch_running_action()
    {
        $prev_run_data = self::get_last_run_data();
        if ($prev_run_data) {
            echo $prev_run_data->action;
        } else {
            echo 'NONE';
        }
        exit;
    }

    /* ================================
     * ==== SimpleView API Methods ====
     * ================================
     */

    /**
     * Fetches a list of invalid (removed) partners from the API.
     *
     * @param string $start_date - Earliest date to look for invalid partners.
     * @return array|false - API data for invalid partners. False on error.
     */
    private static function fetch_and_cache_invalid($start_date = '')
    {
        $cache_title = self::CACHE_NAME_PREFIX . 'stale_' . $start_date;
        $args = array('lastSync' => $start_date);

        // Return cached data, if it exists
        $cached_data = self::retrieve_cached_data($cache_title);
        if (!empty($cached_data)) return $cached_data;

        // If not, grab fresh data
        $results = self::api_request('getInvalidListings', $args);
        if (empty($results)) return false;

        // Check for invalid results
        $results_arr = self::xml2array($results);
        if ($results_arr['REQUESTSTATUS']['HASERRORS']) {
            error_log('[VSEI Plugin] Error in fetch_and_cache_invalid:');
            foreach ($results_arr['REQUESTSTATUS']['ERRORS']['ITEM'] as $error) {
                error_log('[VSPI Plugin] ' . $error['MESSAGE'] . ': ' . $error['DETAIL']);
            }

            return false;
        }
        if (!$results_arr['REQUESTSTATUS']['RESULTS']) {
            error_log('[VSPI Plugin] In fetch_and_cache_invalid, fetch returned no listings.');

            return false;
        }

        self::save_cached_data($cache_title, $results);

        return $results_arr;
    }

    /**
     * Fetches a list of new/changed partners from the API.
     *
     * @param string $start_date - Earliest date to look for changed/new partners.
     * @return array|false - API data for changed/added partners. False on error.
     */
    private static function fetch_and_cache_changed($start_date)
    {
        $cache_title = self::CACHE_NAME_PREFIX . 'changed_' . $start_date;
        $args = array('lastSync' => $start_date);

        // Return cached data, if it exists
        $cached_data = self::retrieve_cached_data($cache_title);
        if (!empty($cached_data)) return $cached_data;

        // If not, grab fresh data
        $results = self::api_request("getChangedListings", $args);
        if (empty($results)) {
            return false;
        }

        // Check for errors
        $results_arr = self::xml2array($results);
        if ($results_arr['REQUESTSTATUS']['HASERRORS']) {
            error_log('[VSPI Plugin] Error in fetch_and_cache_invalid:');
            foreach ($results_arr['REQUESTSTATUS']['ERRORS']['ITEM'] as $error) {
                error_log('[VSPI Plugin] ' . $error['MESSAGE'] . ': ' . $error['DETAIL']);
            }

            return false;
        };
        if (!$results_arr['REQUESTSTATUS']['RESULTS']) {
            error_log('[VSPI Plugin] In fetch_and_cache_changed, fetch returned no listings.');

            return false;
        }

        self::save_cached_data($cache_title, $results);

        return $results_arr;
    }

    /**
     * Fetches API data for a single partner.
     *
     * @param string $partner_id - The API ID of the partner to retrieve.
     * @return array|false - The partner's API data. False on error.
     */
    private static function fetch_single_partner($partner_id)
    {
        $args = array('LISTINGID' => $partner_id);
        $results = self::api_request('getListing', $args);

        return empty($results) ? false : self::xml2array($results);
    }

    /**
     * Fetches and caches a chunk of partner API data.
     *
     * @param int $page_number - The page offset.
     * @return array|false - The partner data returned from the API or cache, or false on error.
     */
    private static function fetch_and_cache_chunk($page_number)
    {
        $base_name = self::CACHE_NAME_PREFIX . date('Y-m-d') . '_';
        $cache_title = $base_name . sprintf('%03d', $page_number);

        // Return cached data, if it exists
        $cached_data = self::retrieve_cached_data($cache_title);
        if (!empty($cached_data)) return $cached_data;

        // If not, grab fresh data
        $filters = array(
            array(
                'FIELDCATEGORY' => 'Listing',
                'FIELDNAME'     => 'Listingid',
                'FILTERTYPE'    => 'GREATER THAN',
                'FILTERVALUE'   => 0
            )
        );
        $args = array(
            'pagenum'          => $page_number,
            'pagesize'         => self::CHUNK_SIZE,
            'filtergroup'      => array(
                'ANDOR'   => 'AND',
                'FILTERS' => $filters
            ),
            'displayamenities' => 1
        );

        $results = self::api_request('getListings', $args);
        if (empty($results)) return false;

        // Check for error conditions
        $results_arr = self::xml2array($results);
        if ($results_arr['REQUESTSTATUS']['HASERRORS']) {
            error_log("[VSPI Plugin] In fetch_and_cache_page, error at page #$page_number");
            foreach ($results['REQUESTSTATUS']['ERRORS'] as $error) {
                error_log("[VSPI Plugin] $error");
            }

            return false;
        }
        if (!$results_arr['REQUESTSTATUS']['RESULTS']) {
            error_log("[VSPI Plugin] In fetch_and_cache_page, page #$page_number returned no listings.");

            return false;
        }

        self::save_cached_data($cache_title, $results);

        return $results_arr;
    }

    /**
     * Makes a request to SimpleView's API.
     *
     * @param string $method - The API method to access.
     * @param array  $args   - Method arguments.
     * @return array|false - The request body. False on error.
     */
    private static function api_request($method, $args)
    {
        $params = array(
            'username' => self::API_USER,
            'password' => self::API_PASS,
            'action'   => $method
        );
        $params = array_merge($params, $args);

        $response = wp_remote_post(self::API_URL, array('timeout' => 300, 'body' => $params));
        if (!is_wp_error($response) && isset($response['response'])
            && array_key_exists('code', $response['response'])
            && $response['response']['code'] === 200
            && isset($response['body'])) {
            return $response['body'];
        }

        return false;
    }

    /* =================================
     * ====== Importer Operations ======
     * =================================
     */

    /* ==== Pre & Post Flight ==== */

    /**
     * Determines whether the process can proceed and does initial setup.
     *
     * @internal `init` values are as follows:
     *           * 'hard' - Starting a new run. Hard init.
     *           * 'soft' - Doing next step in run. Partial init.
     *           * null - Resuming/continuing. Use previous data.
     * @param array $args - Data associated with the process.
     * @param bool  $init - How should the parameters be initialized?
     * @return bool
     */
    private static function preflight($args, $init = null)
    {
        // Check for partner type & import state
        if (!self::partners_exist() || !self::check_import_free()) return false;

        // Set import time
        date_default_timezone_set('America/Los_Angeles');
        self::set_import_time(date('Y-m-d H:i:s'));

        if ($init === 'hard') {
            self::set_processed_count(0);
            self::set_current_page(0);
            self::set_add_count(0);
            self::set_delete_count(0);
            self::set_total_count(0);
        } else if ($init === 'soft') {
            $previous_data = self::get_last_run_data();
            self::set_processed_count(0);
            self::set_current_page(0);
            self::set_delete_count($previous_data->deleted);
            self::set_add_count($previous_data->added);
            self::set_total_count(0);
        } else {
            $previous_data = self::get_last_run_data();
            self::set_processed_count($previous_data->processed);
            $current_page = self::calculate_current_page($previous_data);
            self::set_current_page($current_page);
            self::set_delete_count($previous_data->deleted);
            self::set_add_count($previous_data->added);
        }

        // Save process data
        self::set_last_run_data($args);

        return true;
    }

    /**
     * Handles teardown for the process.
     *
     * @param array $args - Data associated with the process.
     */
    private static function postflight($args)
    {
        // Save process data
        self::set_last_run_data($args);

        // Allow time for db writes
        sleep(2);

        self::set_import_time(date('Y-m-d H:i:s'));
        self::set_import_status('free');

        return;
    }

    /**
     * Handles the canceling of a process.
     *
     * `check_and_handle_cancel` checks for a 'free' importer state, which indicates that the importer should stop
     *  processing and start listening for new commands. Before stopping, the importer records data from the stopped
     *  process, in case that process needs to be resumed.
     *
     * @param array $args - Data about the process, to be saved to the database.
     */
    private static function check_and_handle_cancel($args)
    {
        if (self::check_import_free()) {
            self::set_last_run_data($args);
            exit(json_encode(array("status" => "canceled")));
        }
    }

    /**
     * Determines whether the importer is set to 'free'.
     *
     * @return bool
     */
    private static function check_import_free()
    {
        $import_status = explode(':', self::get_import_status());
        if ($import_status[0] !== 'free') {
            return false;
        }

        return true;
    }

    /* ==== Importing ==== */

    /**
     * Imports new/changed partner listings and saves them as WordPress posts.
     *
     * @param string $date     - Start date for data fetch.
     * @param array  $run_args - Data associated with the running action.
     */
    private static function import_changed_by_chunk($date, $run_args)
    {
        self::set_import_status('running');
        self::set_import_method('import/fetch');

        $processed_count = self::get_processed_count();
        $added_count = self::get_add_count();
        $page_num = self::get_current_page();

        // * * Fetch listings * *

        $results = self::fetch_and_cache_changed($date);
        if (!$results) {
            exit(json_encode(array(
                'status'  => 'error',
                'message' => "In import_changed_by_chunk at page #$page_num, empty results returned."
            )));
        }
        self::set_total_count($results['REQUESTSTATUS']['RESULTS']);

        self::check_and_handle_cancel($run_args);

        // * * Update listings * *

        self::set_import_method('import/update');

        $changed_partners = array_slice($results['CHANGEDLISTINGS']['LISTING'], $processed_count, self::CHUNK_SIZE);
        foreach ($changed_partners as $partner) {
            self::check_and_handle_cancel($run_args);
            if (!$partner['LISTINGID']) {
                continue;
            }
            self::set_processed_count(++$processed_count);
            $success = self::save_partner_listing($partner['LISTINGID']);
            if ($success) {
                self::set_add_count(++$added_count);
            }
            unset($partner);
        }
    }

    /**
     * Imports a single partner into WordPress.
     *
     * @param string $listing_id - The API ID for the partner.
     */
    private static function import_single_partner($listing_id)
    {
        self::set_import_status('running');
        self::set_import_method('import/update');

        self::set_total_count(1);
        self::set_processed_count(1);

        $success = self::save_partner_listing($listing_id);
        if ($success) {
            self::set_add_count(1);
        }
    }

    /**
     * Imports a page worth of partners into WordPress.
     *
     * @param array $run_args - Data associated with the running action.
     */
    private static function import_all_by_chunk($run_args)
    {
        self::set_import_status('running');
        self::set_import_method('import/fetch');

        $processed_count = self::get_processed_count();
        $added_count = self::get_add_count();
        $page_num = self::get_current_page();

        if ($page_num === 0) {
            $chunk_start = $processed_count;
        } else {
            $chunk_start = $processed_count - ($page_num * self::CHUNK_SIZE);
            // Make sure previous listing was processed
            $chunk_start = $chunk_start > 1 ? $chunk_start - 1 : $chunk_start;
        }

        // * * Fetch listing * *

        $results = self::fetch_and_cache_chunk($page_num + 1);
        if (!$results) {
            exit(json_encode(array(
                'status'  => 'error',
                'message' => "In import_all at page #$$page_num: " . self::ERROR_MSGS['empty']
            )));
        }

        self::set_total_count($results['REQUESTSTATUS']['RESULTS']);

        self::check_and_handle_cancel($run_args);

        // * * Save listing * *

        self::set_import_method('import/update');

        $partners = array_slice($results['LISTINGS']['LISTING'], $chunk_start);
        foreach ($partners as $partner) {
            self::check_and_handle_cancel($run_args);
            if (!$partner['LISTINGID']) {
                error_log("[VSPI Plugin] Partner with name " . $partner['COMPANY'] . " has no listing ID.");
                continue;
            }
            self::set_processed_count(++$processed_count);
            $success = self::save_partner_listing($partner);
            if ($success) {
                self::set_add_count(++$added_count);
            }
            unset($partner);
        }
    }

    /**
     * Updates the images for a page worth of partners.
     * @since 1.1.0
     *
     * @param array $run_args - Data associated with the running action.
     */
    private static function import_images_by_chunk($run_args)
    {
        self::set_import_status('running');
        self::set_import_method('import/fetch');

        $processed_count = self::get_processed_count();
        $added_count = self::get_add_count();
        $page_num = self::get_current_page();

        if ($page_num === 0) {
            $chunk_start = $processed_count;
        } else {
            $chunk_start = $processed_count - ($page_num * self::CHUNK_SIZE);
            // Make sure previous listing was processed
            $chunk_start = $chunk_start > 1 ? $chunk_start - 1 : $chunk_start;
        }

        // * * Fetch data * *

        $results = self::fetch_and_cache_chunk($page_num + 1);
        if (!$results) {
            exit(json_encode(array(
                'status' => 'error',
                'message' => "In import_images at page #$page_num: " . self::ERROR_MSGS['empty']
            )));
        }

        self::set_total_count($results['REQUESTSTATUS']['RESULTS']);

        self::check_and_handle_cancel($run_args);

        // * * Save images * *

        self::set_import_method('import/update_images');

        $partners = array_slice($results['LISTINGS']['LISTING'], $chunk_start);
        foreach ($partners as $partner) {
            self::check_and_handle_cancel($run_args);
            if (!$partner['LISTINGID']) {
                error_log("[VSPI Plugin] Partner with name " . $partner['COMPANY'] . " has no listing ID.");
                continue;
            }

            self::set_processed_count(++$processed_count);

            // Skip listing if it has no PHOTOFILE
            if (!isset($partner['PHOTOFILE'])) continue;

            // Fetch full data -- with images
            if (!isset($partner['IMAGES'])) {
                $full_data = self::fetch_single_partner($partner['LISTINGID']);
                if (empty($full_data)) {
                    error_log("[VSPI Plugin] No data returned for partner with ID #" . $partner['LISTINGID']);
                    continue;
                }
                $partner = $full_data['LISTING'];
            }

            // Get WP post ID
            if (empty(self::$existing_partners)) {
                self::create_partner_id_mapping();
            }
            $post_id = self::$existing_partners[$partner['LISTINGID']];

            $success = self::process_post_images($post_id, $partner);
            if ($success) {
                self:: set_add_count(++$added_count);
            }
            unset($partner);
        }
    }

    /* ==== Deletion ==== */

    /**
     * Determines which listings are stale and removes them from WordPress.
     *
     * @param string $date     - The start date for determining which listings are stale.
     * @param array  $run_args - Data associated with the running action.
     */
    private static function delete_stale_partners($date, $run_args)
    {
        self::set_import_status('running');
        self::set_import_method('delete/fetch');

        $processed_count = self::get_processed_count();
        $deleted_count = self::get_delete_count();

        // ** Fetch invalid list * *

        $results = self::fetch_and_cache_invalid($date);
        if (!$results) {
            exit(json_encode(array(
                'status'  => 'error',
                'message' => "While deleting stale partners: " . self::ERROR_MSGS['empty']
            )));
        }
        self::set_total_count($results['REQUESTSTATUS']['RESULTS']);

        self::check_and_handle_cancel($run_args);

        // * * Remove listings * *

        self::set_import_method('delete/prune');

        $default_args = array(
            'post_type'      => 'partners',
            'posts_per_page' => -1,
            'meta_key'       => 'listing_id'
        );
        $invalid_partners = $results['INVALIDLISTINGS']['LISTING'];

        foreach ($invalid_partners as $partner) {
            self::check_and_handle_cancel($run_args);
            self::set_processed_count(++$processed_count);
            // Grab WP post associated with listing
            if (empty($partner['LISTINGID'])) {
                error_log("[VSPI Plugin] When deleting stale partners, invalid or no listing ID given for event with name '"
                          . $partner['COMPANYNAME'] . "'.");
                continue;
            }
            $fetch_args = array_merge($default_args, array('meta_value' => $partner['LISTINGID']));
            $posts_to_delete = get_posts($fetch_args);
            // Delete the WP posts -- might be multiple b/c of unintentional dups
            foreach ($posts_to_delete as $post) {
                if (!empty($post->ID)) {
                    $success = wp_delete_post($post->ID, true);
                    if ($success) self::set_delete_count(++$deleted_count);
                }
            }
            unset($partner);
        }

        self::remove_partner_metadata();
        self::remove_orphaned_data();
    }

    /**
     * Removes a page worth of partner posts from WordPress.
     *
     * @param array $run_args - Variables associated with the running action.
     */
    private static function delete_all_by_chunk($run_args)
    {
        self::set_import_status('running');
        self::set_import_method('delete/fetch');

        $processed_count = intval(self::get_processed_count());
        $delete_count = intval(self::get_delete_count());

        $partners = new WP_Query(array(
            'post_type'      => 'partners',
            'posts_per_page' => self::CHUNK_SIZE,
            'orderby'        => 'name'
        ));

        // * * Delete listings * *

        self::set_import_method('delete/purge');

        if ($partners->have_posts()) {
            self::set_total_count($partners->found_posts + $processed_count);
            self::check_and_handle_cancel($run_args);

            while ($partners->have_posts()) {
                self::check_and_handle_cancel($run_args);
                self::set_processed_count(++$processed_count);
                $partners->the_post();

                // Delete images
                $partner_images = get_attached_media('image', get_the_ID());
                foreach ($partner_images as $image) {
                    wp_delete_attachment($image->ID, true);
                }
                unset($partner_images);

                // Delete post
                $success = wp_delete_post(get_the_ID(), true);
                if ($success) {
                    self::set_delete_count(++$delete_count);
                }
            }
        }
        wp_reset_query();

        self::remove_partner_metadata();
        self::remove_orphaned_data();
    }

    /* ==== Cache management ==== */

    /**
     * Fetches and caches data for all SimpleView partners.
     *
     * @param array $run_args - Data associated with the running action.
     */
    private static function create_listing_cache($run_args)
    {
        self::set_import_status('running');
        self::set_import_method('cache/create');

        $page_num = self::get_current_page();
        $total_num_pages = $page_num + 1;

        while ($page_num < $total_num_pages) {
            self::check_and_handle_cancel($run_args);

            $results = self::fetch_and_cache_chunk($page_num + 1);
            if (!$results) {
                error_log("[VSPI Plugin] In create_listing_cache, empty results returned at #$page_num");
                break;
            }

            $page_num++;
            self::set_processed_count($page_num);
            self::set_add_count($page_num);
            self::set_current_page($page_num);

            $total_num_pages = ceil($results['REQUESTSTATUS']['RESULTS'] / self::CHUNK_SIZE);
            self::set_total_count($total_num_pages);

            unset($results); // Trigger garbage collection
        }
    }

    /**
     * Empties the listings cache table.
     */
    private static function clear_listing_cache()
    {
        global $wpdb;

        self::set_import_status('running');
        self::set_import_method('cache/delete');

        $cache_count = $wpdb->get_var("SELECT COUNT(*) FROM " . VSPI_CACHE_TABLE);
        self::set_processed_count($cache_count);
        self::set_total_count($cache_count);

        $table_name = VSPI_CACHE_TABLE;
        $wpdb->query("TRUNCATE $table_name");

        self::set_delete_count($cache_count);
    }

    /* ==================================
     * ====== WordPress Operations ======
     * ==================================
     */

    /* ==== Additions ==== */

    /**
     * Imports a single partner from the API into WordPress.
     *
     * `save_partner_listing` imports and saves all data associated with the partner into various WordPress taxonomies.
     * This includes creating a partner type post, adding metadata, importing images as needed, and registering custom
     * taxonomy as needed.
     *
     * @param string|array $partner - The partner API ID or API data.
     * @return bool - Was the save successful?
     */
    private static function save_partner_listing($partner)
    {
        // Some API requests return incomplete information
        // If necessary, fetch full details
        if (is_numeric($partner) || (isset($partner['PHOTOFILE']) && !isset($partner['IMAGES']))) {
            $partner_id = is_numeric($partner) ? $partner : $partner['LISTINGID'];
            $single_partner = self::fetch_single_partner($partner_id);
            if (empty($single_partner) || !isset($single_partner['LISTING'])) {
                error_log("[VSPI Plugin] No or incomplete data returned for partner with ID #" . $partner_id);

                return false;
            }
            $partner = $single_partner['LISTING'];
        }

        // Check for valid data
        if (empty($partner)) {
            error_log("[VSPI Plugin] Import failed because invalid partner data provided.");

            return false;
        }
        if (empty($partner['COMPANY'])) {
            if (empty($partner['SORTCOMPANY'])) {
                error_log("[VSPI Plugin] Partner with id = " . $partner['LISTINGID']
                          . " has no COMPANY or SORTCOMPANY.");

                return false;
            }
            $partner['COMPANY'] = $partner['SORTCOMPANY'];
        }

        // Insert or update post
        $post_id = self::insert_or_update_post($partner);
        if (!$post_id) {
            error_log("[VSPI Plugin] Failed to insert or update partner with id = " . $partner['LISTINGID']);

            return false;
        }

        // Update metadata
        self::set_post_metadata($post_id, $partner);
        self::set_custom_taxonomies($post_id, $partner);
        self::set_social_meta($post_id, $partner);
        self::set_amenity_meta($post_id, $partner);
        self::set_post_tags($post_id, $partner);

        // Update images
        self::process_post_images($post_id, $partner);

        return true;
    }

    /**
     * Saves API data about the partner into the appropriate WordPress post. Updates the post if it exists, creates a
     * new post if it doesn't.
     *
     * @param array $partner_data - The partner's API data.
     * @return int|false - The ID of the WordPress post. False on error.
     */
    private static function insert_or_update_post($partner_data)
    {
        // Set the URL
        $post_url = sanitize_title($partner_data['COMPANY']);
        if (!empty($partner_data['TYPENAME'])) {
            $post_url .= self::get_partner_type_suffix($partner_data['TYPENAME']);
        }

        // Fill in empty fields
        if (empty($partner_data['TYPENAME']) && !empty($partner_data['LISTINGTYPENAME'])) {
            $partner_data['TYPENAME'] = $partner_data['LISTINGTYPENAME'];
        }
        if (empty($partner_data['TYPEID']) && !empty($partner_data['LISTINGTYPEID'])) {
            $partner_data['TYPEID'] = $partner_data['LISTINGTYPEID'];
        }

        // Look for ID of existing post
        if (empty(self::$existing_partners)) {
            self::create_partner_id_mapping();
        }
        $post_id = null;
        if (array_key_exists(intval($partner_data['LISTINGID']), self::$existing_partners)) {
            $post_id = self::$existing_partners[$partner_data['LISTINGID']];
        }

        // Set the post data
        $post_data = array(
            'post_title'   => $partner_data['COMPANY'],
            'post_name'    => $post_url,
            'post_content' => $partner_data['DESCRIPTION'] ?: '',
            'post_status'  => 'publish',
            'post_author'  => 1,
            'post_type'    => 'partners'
        );

        if (!$post_id) {
            $post_id = wp_insert_post($post_data);
        } else {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
        }

        // Report errors
        if (is_wp_error($post_id)) {
            error_log("[VSPI Plugin] Failed to insert/update " . $partner_data['COMPANY'] . ".");
            $messages = $post_id->get_error_messages();
            foreach ($messages as $message) {
                error_log("[VSPI Plugin] $message");
            }
        }

        return !empty($post_id) && !(is_wp_error($post_id)) ? $post_id : false;
    }

    /**
     * Sets metadata for a given listing.
     *
     * `set_post_metadata` saves as metadata any API data not easily convertible to a post field
     * that doesn't require special handling. This includes organizational tokens like categories and keywords,
     * contact information, and location data like latitude/longitude.
     *
     * @param int   $post_id      - The WP post's ID.
     * @param array $partner_data - API data for the listing.
     */
    private static function set_post_metadata($post_id, $partner_data)
    {
        $partner_metadata = array(
            'listing_id'               => 'LISTINGID',
            'type_id'                  => 'TYPEID',
            'type'                     => 'TYPENAME',
            'cat_id'                   => 'CATID',
            'category'                 => 'CATNAME',
            'sub_cat_id'               => 'SUBCATID',
            'sub_cat'                  => 'SUBCATNAME',
            'region_id'                => 'REGIONID',
            'region'                   => 'REGION',
            'acct_id'                  => 'ACCTID',
            'acct_status'              => 'ACCTSTATUS',
            'sort_company'             => 'SORTCOMPANY',
            'primary_contact_title'    => 'PRIMARYCONTACTTITLE',
            'primary_contact_fullname' => 'PRIMARYCONTACTFULLNAME',
            'logo_file'                => 'LOGOFILE',
            'photo_file'               => 'PHOTOFILE',
            'phone'                    => 'PHONE',
            'alt_phone'                => 'ALTPHONE',
            'toll_free'                => 'TOLLFREE',
            'fax'                      => 'FAX',
            'email'                    => 'EMAIL',
            'web_url'                  => 'WEBURL',
            'addr1'                    => 'ADDR1',
            'addr2'                    => 'ADDR2',
            'addr3'                    => 'ADDR3',
            'city'                     => 'CITY',
            'state'                    => 'STATE',
            'zip'                      => 'ZIP',
            'latitude'                 => 'LATITUDE',
            'longitude'                => 'LONGITUDE',
            'search_keywords'          => 'LISTINGKEYWORDS'
        );

        foreach ($partner_metadata as $acf_key => $sv_key) {
            if (empty($partner_data[$sv_key])) {
                $value = '';
            } else if (is_array($partner_data[$sv_key])) {
                $value = implode(' ', $partner_data[$sv_key]);
            } else {
                $value = $partner_data[$sv_key];
            }
            update_post_meta($post_id, $acf_key, $value);
        }
    }

    /**
     * Creates WordPress terms and associates them with a given listing.
     *
     * `set_custom_taxonomies` creates the actual taxonomy posts/terms required for sorting partner listings within WordPress.
     *  These taxonomies are type (MP, TPG, etc.), category, subcategory, and region.
     *
     * @param int   $post_id      - The WP post's ID.
     * @param array $partner_data - API data for the listing.
     */
    private static function set_custom_taxonomies($post_id, $partner_data)
    {
        // Type
        $type_name = self::get_normalized_partner_type($partner_data['TYPENAME']);
        $type_term = term_exists($type_name, self::CUSTOM_TAXONOMIES[0])
            ?: wp_insert_term($type_name, self::CUSTOM_TAXONOMIES[0], ['slug' => sanitize_title_with_dashes($type_name)]);
        if (!empty($type_term) && !is_wp_error($type_term)) {
            wp_set_post_terms($post_id, intval($type_term['term_taxonomy_id']), self::CUSTOM_TAXONOMIES[0], false);
        }

        // Category
        $category_term = term_exists(sanitize_title_with_dashes($partner_data['CATNAME']), self::CUSTOM_TAXONOMIES[1])
            ?: wp_insert_term($partner_data['CATNAME'], self::CUSTOM_TAXONOMIES[1],
                ['slug' => sanitize_title_with_dashes($partner_data['CATNAME'])]);
        if (!empty($category_term) && !is_wp_error($category_term)) {
            wp_set_post_terms($post_id, intval($category_term['term_taxonomy_id']), self::CUSTOM_TAXONOMIES[1], false);
        }

        // Subcategory
        if (isset($partner_data['SUBCATNAME'])) {
            $subcat_term = term_exists(sanitize_title_with_dashes($partner_data['SUBCATNAME']),
                self::CUSTOM_TAXONOMIES[1], $category_term['term_taxonomy_id'])
                ?: wp_insert_term($partner_data['SUBCATNAME'], self::CUSTOM_TAXONOMIES[1], [
                    'parent' => intval($category_term['term_taxonomy_id']),
                    'slug'   => sanitize_title_with_dashes($partner_data['SUBCATNAME'])
                ]);
            if (!empty($subcat_term) && !is_wp_error($subcat_term)) {
                wp_set_post_terms($post_id, intval($subcat_term['term_taxonomy_id']), self::CUSTOM_TAXONOMIES[1], true);
            }
        }

        // Region
        $region_term = term_exists($partner_data['REGION'], self::CUSTOM_TAXONOMIES[2])
            ?: wp_insert_term($partner_data['REGION'], self::CUSTOM_TAXONOMIES[2], [
                'slug' => sanitize_title_with_dashes($partner_data['REGION'])
            ]);
        if (!empty($region_term) && !is_wp_error($region_term)) {
            wp_set_post_terms($post_id, intval($region_term['term_taxonomy_id']), self::CUSTOM_TAXONOMIES[2], false);
        }
    }

    /**
     * Sets social media metadata for a given listing.
     *
     * @param int   $post_id      - The WP post's ID.
     * @param array $partner_data - API data for the listing.
     */
    private static function set_social_meta($post_id, $partner_data)
    {
        if (empty($partner_data['SOCIALMEDIA']['ITEM'])) return;

        // Normalize to array
        if (!isset($partner_data['SOCIALMEDIA']['ITEM'][0])) {
            $partner_data['SOCIALMEDIA']['ITEM'] = array($partner_data['SOCIALMEDIA']['ITEM']);
        }

        $count = 0;
        foreach ($partner_data['SOCIALMEDIA']['ITEM'] as $social_data) {
            if (empty($social_data['VALUE'])
                || ($social_data['FIELDNAME'] !== 'URL'
                    && $social_data['SERVICE'] !== 'OpenTable')) continue;
            // Social media name
            update_post_meta($post_id, 'social_media_' . $count . '_social_media_name', $social_data['SERVICE']);
            update_post_meta($post_id, '_social_media_' . $count . '_social_media_name', 'field_partners_000000000033');
            // Social media value
            update_post_meta($post_id, 'social_media_' . $count . '_social_media_value', $social_data['VALUE']);
            update_post_meta($post_id, '_social_media' . $count . '_social_media_value', 'field_partners_000000000034');
            $count++;
        }

        // * * Special case -- YouTube is in IMAGES * *

        // Normalize to array
        if (isset($partner_data['IMAGES']['ITEM'])) {
            if (!isset($partner_data['IMAGES']['ITEM'][0])) {
                $partner_data['IMAGES']['ITEM'] = array($partner_data['IMAGES']['ITEM']);
            }

            foreach ($partner_data['IMAGES']['ITEM'] as $media) {
                if (intval($media['TYPEID']) === 10) {
                    // Name
                    update_post_meta($post_id, 'social_media_' . $count . '_social_media_name', $media['TYPE']);
                    update_post_meta($post_id, '_social_media_' . $count . '_social_media_name',
                        'field_partners_000000000033');
                    // Value
                    update_post_meta($post_id, 'social_media_' . $count . '_social_media_value', $media['MEDIAFILE']);
                    update_post_meta($post_id, '_social_media' . $count . '_social_media_value',
                        'field_partners_000000000034');
                    $count++;
                }
            }
        }

        // This records how many fields are in `social_media`
        if ($count) {
            update_post_meta($post_id, 'social_media', $count);
            update_post_meta($post_id, '_social_media', 'field_partners_000000000032');
        }
    }

    /**
     * Sets amenity metadata for a given listing.
     *
     * @param int   $post_id      - The WP post's ID.
     * @param array $partner_data - API data for the listing.
     */
    private static function set_amenity_meta($post_id, $partner_data)
    {
        if (empty($partner_data['AMENITIES']['ITEM'])) return;

        // Normalize to array
        if (!isset($partner_data['AMENITIES']['ITEM'][0])) {
            $partner_data['AMENITIES']['ITEM'] = array($partner_data['AMENITIES']['ITEM']);
        }

        $count = 0;
        foreach ($partner_data['AMENITIES']['ITEM'] as $amenity_data) {
            if (empty($amenity_data['VALUE'])) continue;
            // Amenity name
            update_post_meta($post_id, 'amenities_' . $count . '_amenity_name', $amenity_data['NAME']);
            update_post_meta($post_id, '_amenities_' . $count . '_amenity_name', 'field_54f8f6e8c29ad');
            // Amenity value
            update_post_meta($post_id, 'amenities_' . $count . '_amenity_value', $amenity_data['VALUE']);
            update_post_meta($post_id, '_amenities_' . $count . '_amenity_value', 'field_54f8f6fec29ae');
            $count++;
        }

        if ($count) {
            update_post_meta($post_id, 'amenities', $count);
            update_post_meta($post_id, '_amenities', 'field_54f8f6d2c29ac');
        }
    }

    /**
     * Sets the post tags for a given listing.
     *
     * @param int   $post_id      - The WP post's ID.
     * @param array $partner_data - API data for the listing.
     */
    private static function set_post_tags($post_id, $partner_data)
    {
        if (empty($partner_data['TAGS']['ITEM'])) {
            return;
        }

        $tags = array();

        // Normalize to array
        if (!isset($partner_data['TAGS']['ITEM'][0])) {
            $partner_data['TAGS']['ITEM'] = array($partner_data['TAGS']['ITEM']);
        }

        foreach ($partner_data['TAGS']['ITEM'] as $tag_data) {
            $tags[] = $tag_data['SOURCENAME'];
        }

        update_post_meta($post_id, 'tags', implode(',', $tags));
        update_post_meta($post_id, '_tags', 'field_000000004242');
    }

    /* ==== Deletions ==== */

    /**
     * Removes metadata for partners without any listings.
     */
    private static function remove_partner_metadata()
    {
        self::set_import_method('delete/meta');
        $tax_query_args = array(
            'taxonomy'   => self::CUSTOM_TAXONOMIES,
            'hide_empty' => false,
            'orderby'    => 'term_id',
            'count'      => true
        );
        $tax_terms = get_terms($tax_query_args);
        foreach ($tax_terms as $term) {
            if ($term->count === 0) {
                wp_delete_term($term->term_id, $term->taxonomy);
            }
        }
    }

    /**
     * Removes miscellaneous metadata associated with non-existent listings.
     */
    private static function remove_orphaned_data()
    {
        global $wpdb;
        self::set_import_method('delete/cleanup');

        $wpdb->query("DELETE pm FROM $wpdb->postmeta AS pm
             LEFT JOIN $wpdb->posts AS wp on wp.ID = pm.post_id
             WHERE wp.ID IS NULL AND wp.post_type = 'partners'");
    }

    /* ==== Images ==== */

    /**
     * Processes images for listings, adding, deleting, and updating as necessary.
     *
     * `process_post_images` uses the list of images to import and the list of pre-existing images and
     * determines which need to be added, updated, or deleted. Efforts are taken to ensure that the same image
     * is not imported multiple times by different listings, since any listings sharing a partner will pull from the same
     * pool of images.
     *
     * @internal SimpleView attaches a GUID to the file name of any newly uploades file, and changes this GUID if the
     *           file is modified. This means that we can assume if the old and new files share a name, they are
     *           the same file.
     *
     * @param string $post_id      - The listing's ID.
     * @param array  $partner_data - The SimpleView data for this listing..
     * @return bool - Whether the process succeeded.
     */
    private static function process_post_images($post_id, $partner_data)
    {
        if (empty($partner_data['IMAGES']) || empty($partner_data['IMAGES']['ITEM'])) return false;

        // Normalize data to array
        if (!isset($partner_data['IMAGES']['ITEM'][0])) {
            $partner_data['IMAGES']['ITEM'] = array($partner_data['IMAGES']['ITEM']);
        }

        // * * Grab existing post images * *

        $original_images = [];
        $post_images = self::query_post_images($post_id);
        foreach ($post_images as $post_image) {
            $key_og_image = self::get_wordpress_image_key($post_image->ID);
            $original_images[$key_og_image] = $post_image;
        }

        // * * Process images * *

        $images_to_delete = $original_images;

        foreach ($partner_data['IMAGES']['ITEM'] as $new_image) {
            // Skip if data is invalid
            if (empty($new_image['MEDIAFILE']) || is_array($new_image['MEDIAFILE'])
                || (!empty($new_image['TYPEID']) && $new_image['TYPEID'] !== '2')) {
                continue;
            }

            // Skip if extension is invalid
            $extension_arr = explode('.', $new_image['MEDIAFILE']);
            $extension = strtolower(end($extension_arr));
            if (!in_array($extension, self::IMAGE_EXTENSIONS)) continue;

            $new_image_path = self::create_sv_image_url($new_image);
            $new_img_key = self::get_simpleview_image_key($new_image['MEDIAFILE']);

            if (array_key_exists($new_img_key, $original_images)) {
                // * Found in listing's images *
                $attachment_id = $original_images[$new_img_key]->ID;

                self::$processed_images[$new_image['MEDIAID']] = $attachment_id;

                $og_image_path = wp_get_attachment_url($attachment_id);
                $is_same_image = empty($og_image_path) ? false : self::is_same_image($og_image_path, $new_image_path);

                if (!$is_same_image) self::update_image($attachment_id, $new_image);

                unset($images_to_delete[$new_img_key]);
            } else {
                // * Not in listing's images *
                $attachment_id = null;

                // Search within already processed images
                if (array_key_exists($new_image['MEDIAID'], self::$processed_images)) {
                    $attachment_id = self::$processed_images[$new_image['MEDIAID']];
                }

                // Search within database
                if (empty($attachment_id)) {
                    $filename_arr = explode('/', $new_image['MEDIAFILE']);
                    $attachment_id = self::query_image_id(end($filename_arr));

                    if (!empty($attachment_id)) {
                        $attachment_path = wp_get_attachment_url($attachment_id);
                        $same = self::is_same_image($attachment_path, $new_image_path);

                        if (!$same) {
                            self::update_image($attachment_id, $new_image);
                        }
                    }
                }

                // Image doesn't exist, so insert it
                if (empty($attachment_id)) $attachment_id = self::insert_image($post_id, $new_image);

                if (!empty($attachment_id)) self::$processed_images[$new_image['MEDIAID']] = $attachment_id;
            }
            unset($attachment_id);
        }

        /** @note Connecting images MUST happen before removing for tracking to work correctly **/
        self::connect_post_images($post_id, $partner_data);
        self::remove_post_images($images_to_delete);

        return true;
    }

    /**
     * Sorts images associated with a particular listing and attaches them to that listing.
     *
     * @param int   $post_id      - The WP ID of the listing associated with the images.
     * @param array $partner_data - API data for the listing.
     */
    private static function connect_post_images($post_id, $partner_data)
    {
        // * * Sort the images * *
        $sorted_images = array();
        $order = 99;

        foreach ($partner_data['IMAGES']['ITEM'] as $image) {
            // Skip if data is invalid
            if (empty($image['MEDIAFILE']) || is_array($image['MEDIAFILE'])
                || (!empty($image['TYPEID']) && $image['TYPEID'] !== '2')) {
                continue;
            }

            if (!array_key_exists('SORTORDER', $image)) {
                $image['SORTORDER'] = $order++;
            }
            $sorted_images[$image['SORTORDER']] = $image;
        }
        unset($image);
        ksort($sorted_images);

        // * * Connect images to post * *
        $is_first_image = true;
        $secondary_images = array();

        foreach ($sorted_images as $image) {
            $image_id = self::$processed_images[$image['MEDIAID'] ?? 'photofile'];
            if (!$image_id) {
                error_log("[VSPI] Error for post #$post_id: Image with SV ID #" . $image_id
                          . " not found in processed images.");
                continue;
            }

            $is_main_image = !empty($partner_data['PHOTOFILE']) && $partner_data['PHOTOFILE'] === $image['MEDIAFILE']
                ? true : false;
            if ($is_main_image || $is_first_image) {
                delete_post_thumbnail($post_id); // Unsets previous thumbnail w/o removing from server
                set_post_thumbnail($post_id, $image_id);
            } else {
                $secondary_images[] = $image_id;
            }

            $is_first_image = false;
        }

        update_post_meta($post_id, 'partner_secondary_images', $secondary_images);
    }

    /**
     * Given a list of image IDs, deletes any unused images.
     *
     * @param array [object] $images_to_remove - List of images up for deletion.
     */
    private static function remove_post_images($images_to_remove)
    {
        foreach ($images_to_remove as $image_key => $image_data) {
            $uses = self::query_image_uses($image_data->ID);
            if (empty($uses)) {
                wp_delete_attachment($image_data->ID, true);
            }
        }
    }

    /**
     * Creates a new image attachment, associated with a given WP post.
     *
     * @param int   $post_id    - The WP ID of the listing to associate with the image.
     * @param array $image_data - The API data for the image.
     * @return false|int - The ID of the attached image. False on error.
     */
    private static function insert_image($post_id, $image_data)
    {
        if (!$file_info = self::create_image($image_data)) return false;

        // Set attachment data
        $wp_filetype = wp_check_filetype($file_info['filename'], null);
        $title = $image_data['MEDIANAME'] ?: self::get_simpleview_image_key($image_data['MEDIAFILE']);
        $attach_data = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        // Insert attachment
        $attach_id = wp_insert_attachment($attach_data, $file_info['filepath'], $post_id);
        if (empty($attach_id)) return false;

        // Required to generate metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Set metadata
        $attach_metadata = wp_generate_attachment_metadata($attach_id, $file_info['filepath']);
        wp_update_attachment_metadata($attach_id, $attach_metadata);

        return $attach_id;
    }

    /**
     * Updates the content of a preexisting image.
     *
     * @param string $attach_id     - ID of the image to update.
     * @param array  $sv_image_data - The API data for the image.
     * @return mixed - If meta doesn't exist, `meta_id`. Otherwise, true on success and false on failure.
     */
    private static function update_image($attach_id, $sv_image_data)
    {
        if (!$file_info = self::create_image($sv_image_data)) return false;

        // Update file path
        update_attached_file($attach_id, $file_info['filepath']);

        // Required to generate metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Set metadata
        $attach_metadata = wp_generate_attachment_metadata($attach_id, $file_info['filepath']);

        return wp_update_attachment_metadata($attach_id, $attach_metadata);
    }

    /**
     * Creates a new image on the server.
     *
     * @param       $image_data - The API data for the image.
     * @return bool|array - Information about the newly created object
     *                          string 'filename'   - The name of the new file.
     *                          string 'filepath'   - The path of the new file.
     *
     */
    private static function create_image($image_data)
    {
        $image_url = self::create_sv_image_url($image_data);

        $raw_image = file_get_contents($image_url);
        if ($raw_image === false) return false;

        $filename = basename($image_url);
        $upload_dir = wp_get_upload_dir();

        // Check folder permissions and define file location
        $file_path = wp_mkdir_p($upload_dir['path']) ? $upload_dir['path'] . '/' . $filename
            : $upload_dir['basedir'] . '/' . $filename;

        if (!file_put_contents($file_path, $raw_image)) return false;

        return ['filename' => $filename, 'filepath' => $file_path];
    }

    /*
     * =================================
     * ====== Database/WP Queries ======
     * =================================
     */

    /* ==== Cache management ==== */

    /**
     * Retrieves a blob of data from the cache.
     *
     * @param string $cache_title - The label for the cached data.
     * @return array|false - Associative array of listings. False on error.
     */
    private static function retrieve_cached_data($cache_title)
    {
        global $wpdb;
        $table_name = VSPI_CACHE_TABLE;
        $results = $wpdb->get_results("SELECT `xmldata` FROM $table_name WHERE `name` = '$cache_title'");

        if (!$results || !$wpdb->num_rows) {
            return false;
        }

        return self::xml2array(gzuncompress(end($results)->xmldata));
    }

    /**
     * Compresses a given chunk of data and stores the results in the cache table.
     *
     * @param string $cache_name - A label for the cached data.
     * @param string $data       - Data to store.
     *
     * @return int|false - Number of affected rows (1). False if unsuccessful.
     */
    private static function save_cached_data($cache_name, $data)
    {
        global $wpdb;
        $data_to_insert = array(
            'name'        => $cache_name,
            'xmldata'     => gzcompress($data),
            'lastupdated' => date('Y-m-d H:i:s')
        );
        $success = $wpdb->replace(VSPI_CACHE_TABLE, $data_to_insert, array('%s', '%s', '%s'));

        return $success;
    }

    /* ==== Images ==== */

    /**
     * Queries the database for all images associated with a given listing and returns their WP_Post objects.
     *
     * @param string $post_id - The listing's WP ID.
     * @return array[WP_Post] - An array of WP post objects.
     */
    private static function query_post_images($post_id)
    {
        $post_images = [];

        // Thumbnail image
        $thumbnail_image_id = get_post_meta($post_id, '_thumbnail_id', true);
        if ($thumb_image = get_post($thumbnail_image_id)) $post_images[] = $thumb_image;

        // Secondary images
        $secondary_image_ids = get_post_meta($post_id, 'partner_secondary_images', true);
        if (!empty($secondary_image_ids)) {
            foreach ($secondary_image_ids as $id) {
                $image = get_post($id);
                if (!empty($image)) $post_images[] = $image;
            }
        }

        return $post_images;
    }

    /**
     * Queries the database for the number of listings using a given WP image attachment.
     *
     * @param string $image_id - The WP ID of the attachment.
     * @return int|false - Number of rows returned by the query (i.e. number of uses). False on error.
     */
    private static function query_image_uses($image_id)
    {
        global $wpdb;

        $query = "SELECT post_id FROM $wpdb->postmeta"
                 . " WHERE meta_key IN ('partner_secondary_images', '_thumbnail_id')"
                 . " AND meta_value LIKE '%$image_id%'";
        $results = $wpdb->query($query);

        return $results;
    }

    /**
     * Queries the database for the WP ID associated with a given file. If multiple attachments are found, attempts to
     * return the most recently updated.
     *
     * @internal The sorting is by file path and assumes the path includes a date. (i.e. /2018/10/01/filename.png)
     *
     * @param string $filename - The name of the file to query for.
     * @return string|null - ID of the most recently updated WP attachment. Null if not found.
     */
    private static function query_image_id($filename)
    {
        global $wpdb;

        $query = "SELECT post_id FROM $wpdb->postmeta"
                 . " WHERE meta_key = '_wp_attached_file' AND meta_value LIKE '%$filename'"
                 . " ORDER BY meta_value DESC";
        $image_ids = $wpdb->get_col($query);

        return empty($image_ids) ? null : $image_ids[0];
    }

    /* ==== Partners ==== */

    /**
     * Determines whether the partner post type is registered in WordPress.
     *
     * @return bool
     */
    private static function partners_exist()
    {
        if (!post_type_exists('partners')) {
            error_log("[VSPI Plugin] Can't run import because post type partners does not exist\n");

            return false;
        }

        return true;
    }

    /**
     * Creates a mapping between SimpleView listing IDs and WordPress post IDs, saved to the
     * `self::$existing_partners` parameter.
     */
    private static function create_partner_id_mapping()
    {
        global $wpdb;

        $partner_query = "SELECT $wpdb->postmeta.meta_value, $wpdb->postmeta.post_id"
                         . " FROM $wpdb->postmeta, $wpdb->posts"
                         . " WHERE $wpdb->postmeta.meta_key = 'listing_id' AND $wpdb->postmeta.post_id = $wpdb->posts.ID";
        $partners = $wpdb->get_results($partner_query, OBJECT);
        foreach ($partners as $partner) {
            self::$existing_partners[$partner->meta_value] = $partner->post_id;
        }
    }

    /* =================================
     * ======= Getters & Setters =======
     * =================================
     */

    /**
     * Gets chunk size.
     * @public
     *
     * @return int
     */
    public static function get_chunk_size()
    {
        return self::CHUNK_SIZE;
    }

    /**
     * Gets status of importer.
     *
     * @return string
     */
    private static function get_import_status()
    {
        wp_cache_delete('vspi_import_status', 'options');

        return get_option('vspi_import_status');
    }

    /**
     * Sets import status.
     *
     * @internal Possible values are 'running', 'free', 'free:canceled', 'busy', and 'error'.
     *
     * @param string $status
     */
    private static function set_import_status($status)
    {
        update_option('vspi_import_status', $status);
    }

    /**
     * Gets importer method (phase).
     *
     * @return string
     */
    private static function get_import_method()
    {
        wp_cache_delete('vspi_import_method', 'options');

        return get_option('vspi_import_method');
    }

    /**
     * Sets importer method (phase).
     *
     * @param string $method
     */
    private static function set_import_method($method)
    {
        update_option('vspi_import_method', $method);
    }

    /**
     * Gets timestamp for run.
     *
     * @return string
     */
    private static function get_import_time()
    {
        wp_cache_delete('vspi_last_updated', 'options');

        return get_option('vspi_last_updated');
    }

    /**
     * Sets timestamp for run.
     *
     * @param string $timestamp
     */
    private static function set_import_time($timestamp)
    {
        update_option('vspi_last_updated', $timestamp);
    }

    /**
     * Gets number of listings processed during current phase.
     *
     * @return int
     */
    private static function get_processed_count()
    {
        wp_cache_delete('vspi_processed_count', 'options');

        return intval(get_option('vspi_processed_count'));
    }

    /**
     * Sets number of listings processed during current phase..
     *
     * @param int $count
     */
    private static function set_processed_count($count)
    {
        update_option('vspi_processed_count', $count);
    }

    /**
     * Gets number of listings added during current phase.
     *
     * @return int
     */
    private static function get_add_count()
    {
        wp_cache_delete('vspi_add_count', 'options');

        return intval(get_option('vspi_add_count'));
    }

    /**
     * Sets number of listings added during current phase.
     *
     * @param int $count
     */
    private static function set_add_count($count)
    {
        update_option('vspi_add_count', $count);
    }

    /**
     * Gets number of listings removed during current phase.
     *
     * @return int
     */
    private static function get_delete_count()
    {
        wp_cache_delete('vspi_delete_count', 'options');

        return intval(get_option('vspi_delete_count'));
    }

    /**
     * Sets number of listings removed during current phase.
     *
     * @param int $count
     */
    private static function set_delete_count($count)
    {
        update_option('vspi_delete_count', $count);
    }

    /**
     * Gets total number of listings to process during current phase.
     *
     * @return int
     */
    private static function get_total_count()
    {
        wp_cache_delete('vspi_total_count', 'options');

        return intval(get_option('vspi_total_count'));
    }

    /**
     * Sets total number of listings to process during current phase.
     *
     * @param int $count
     */
    private static function set_total_count($count)
    {
        update_option('vspi_total_count', $count);
    }

    /**
     * Gets current page/chunk being processed.
     *
     * @return int
     */
    private static function get_current_page()
    {
        wp_cache_delete('vspi_current_page', 'options');

        return intval(get_option('vspi_current_page'));
    }

    /**
     * Sets current page/chunk being processed.
     *
     * @param int $page_num
     */
    private static function set_current_page($page_num)
    {
        update_option('vspi_current_page', $page_num);
    }

    /**
     * Gets data from the last canceled or completed run.
     *
     * `get_last_run_data` retrieves information from the last completed or canceled run. It is currently only used
     *  when resuming an action.
     *
     * @return object
     */
    private static function get_last_run_data()
    {
        wp_cache_delete('vspi_last_run_data', 'options');
        $run_data = get_option('vspi_last_run_data');

        return json_decode($run_data);
    }

    /**
     * Records data from the last completed or canceled run and saves it to the database.
     *
     * `set_last_run_data` is called whenever a process starts, is canceled, or completes. It records data the client
     *  and server required to resume the run.
     *
     * @param array $args - Arguments from the last run.
     *                    string 'action'       - The name of the process that just finished.
     *                    string 'date'         - (optional) The start date for any data fetch required for the run, if required.
     *                    string 'listing_id'   - (optional) The ID of the listing to be imported, if required.
     */
    private static function set_last_run_data($args)
    {
        $last_run_data = array(
            'action'     => $args['action'] ?? 'undefined',
            'fetch_date' => $args['date'] ?? '',
            'page'       => self::get_current_page(),
            'added'      => self::get_add_count(),
            'deleted'    => self::get_delete_count(),
            'processed'  => self::get_processed_count(),
            'listing_id' => $args['listing_id'] ?? ''
        );
        $last_run_json = json_encode($last_run_data) ?: '{}';
        update_option('vspi_last_run_data', $last_run_json);
    }

    /* ============================
     * ===== Helper Functions =====
     * ============================
     */

    /**
     * Given an WordPress-sourced and SimpleView-sourced image, determines whether they are identical.
     *
     * `is_same_image` determines if the internal and remote images are identical by using certain known SimpleView
     * policies. SimpleView generates a GUID and attaches it to the filename of every newly uploaded file. If the file
     * is modified, the GUID is changed. That means the Importer can assume any files with the same name have not
     * been meaningfully modified. Content type and length are checked for safety's sake.
     *
     * @param $og_path  - Path to the internal/original file.
     * @param $new_path - Path to the external/replacement file.
     * @return bool
     */
    private static function is_same_image($og_path, $new_path)
    {
        if ($_SERVER['SERVER_ADDR'] === '127.0.0.1') {
            // TODO: Figure out local certificate issues
            $context_options = [
                "ssl" => [
                    "verify_peer"       => false,
                    "verify_peer_namne" => false
                ]
            ];
            stream_context_set_default($context_options);
        }

        $is_remote = strpos($og_path, '://') !== false;

        if ($is_remote) {
            $og_file_info = get_headers($og_path, 1);
        } else {
            $og_file_info = [
                'Content-Type'   => mime_content_type(basename($og_path)),
                'Content-Length' => filesize($og_path)
            ];
        }

        $new_file_info = get_headers($new_path, 1);

        // If you can't access new file, use original
        if (stripos($new_file_info[0], "200 OK") === false) return true;

        // If you can't access old file, use new
        if (($is_remote && stripos($og_file_info[0], "200 OK") === false)
            || (!$is_remote && !file_exists($og_path))) {
            return false;
        }

        // Check file type match
        if ($og_file_info['Content-Type'] !== $new_file_info['Content-Type']) return false;

        // Check for file size match
        if (intval($og_file_info['Content-Length']) !== intval($new_file_info['Content-Length'])) return false;

        return true;
    }

    /**
     * Creates a URL from SimpleView image data.
     *
     * @param $image_data - The image's SimpleView data.
     * @return string
     */
    private static function create_sv_image_url($image_data)
    {
        return ((array_key_exists('IMGPATH', $image_data) && !empty($image_data['IMGPATH'])) ? $image_data['IMGPATH']
                : self::BASE_IMG_PATH) . $image_data['MEDIAFILE'];
    }

    /**
     * Creates a key to uniquely identify a WP image.
     *
     * @param string $image_id - The image's WP ID.
     * @return string
     */
    private static function get_wordpress_image_key($image_id)
    {
        $file = get_attached_file($image_id, true) ?: '';

        return strtolower(sanitize_file_name(basename($file)));
    }

    /**
     * Creates a key to uniquely identify a SimpleView image.
     *
     * @param string $image_file - The image's external path.
     * @return string
     */
    private static function get_simpleview_image_key($image_file)
    {
        $filename_arr = explode('/', $image_file) ?: [];

        return strtolower(sanitize_file_name(end($filename_arr)));
    }

    /**
     * Returns the standard label for a given partner type.
     *
     * `get_normalized_partner_type` attempts to determine what partner type (data source) is represented by a
     *  given string, then outputs the normalized name for that type.
     *
     * @param string $type - The listing's partner type.
     * @return string - The normalized type label.
     */
    private static function get_normalized_partner_type($type = '')
    {
        if (stripos($type, 'Membership') !== false) {
            return 'Membership Directory';
        }
        if (stripos($type, 'Meeting') !== false) {
            return 'Meeting Planners Guide';
        }
        if (stripos($type, 'Travel') !== false) {
            return 'Travel Planners Guide';
        }

        return 'Visitors Guide';
    }

    /**
     * Gets the suffix associated with a particular partner type.
     *
     * `get_partner_type_suffix` returns a normalized suffix for a given partner type. It is attached to the end of a
     *  partner's URL, to prevent duplicates and to indicate which data source a particular listing comes from.
     *
     * @param $type - The partner's listing type.
     * @return string - The suffix associated with the given type, or an empty string on error.
     */
    private static function get_partner_type_suffix($type)
    {
        $type_mapping = array(
            'Membership Directory'   => '-pd',
            'Meeting Planners Guide' => '-mp',
            'Travel Planners Guide'  => '-tpg'
        );
        $normalized_type = self::get_normalized_partner_type($type);

        return key_exists($normalized_type, $type_mapping) ? $type_mapping[$normalized_type] : '';
    }

    /**
     * Determines the appropriate start date for a given run.
     *
     * @param string $interval - The interval to calculate the date from.
     * @return string - The fetch date.
     */
    private static function calculate_start_date($interval = '-2 days')
    {
        if (!empty($_POST['date'])) return date('Y-m-d', strtotime($_POST['date']));

        $last_run_date = strtotime(self::get_import_time());
        $default_date = strtotime($interval, time());

        if ($last_run_date < $default_date) {
            return date('Y-m-d', $last_run_date);
        }

        return date('Y-m-d', $default_date);
    }

    /**
     * Determines the current paging offset for the given data.
     *
     * @param object $previous_data - Data from the most recent run.
     * @return int
     */
    private static function calculate_current_page($previous_data)
    {
        if (isset($_POST['page'])) return $_POST['page'];
        if (isset($previous_data->page)) return $previous_data->page;

        return 0;
    }

    /**
     * Converts an XML response string to an array.
     *
     * @param string $xml - The XML data to parse.
     * @return array
     */
    private static function xml2array($xml)
    {
        $xml_obj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);

        return @json_decode(@json_encode($xml_obj), 1);
    }
}
