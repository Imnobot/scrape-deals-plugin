<?php
/**
 * Plugin Name:       Deal Scraper Plugin
 * Plugin URI:        https://none.com
 * Description:       Scrapes deal websites and displays them via a shortcode. Includes debug logging, dark mode, email subscription. [deal_scraper_display]
 * Version:           1.1.5 // Fix pagination rendering & date handling
 * Author:            ᕦ(ò_óˇ)ᕤ
 * Author URI:        https://none.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       deal-scraper-plugin
 * Domain Path:       /languages
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) exit;

// --- Defines ---
define( 'DSP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DSP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DSP_FETCH_INTERVAL_SECONDS', 24 * 60 * 60 );
define( 'DSP_CRON_HOOK', 'dsp_periodic_deal_fetch' );
define( 'DSP_OPTION_NAME', 'dsp_settings' );
define( 'DSP_EMAIL_CRON_HOOK', 'dsp_daily_email_check' );
define( 'DSP_LAST_EMAIL_OPTION', 'dsp_last_email_send_time' );
// *** IMPORTANT: Replace the default salt with your own random string for security! ***
define( 'DSP_UNSUBSCRIBE_SALT', 'YOUR_UNIQUE_RANDOM_SALT_GOES_HERE_3453t6e!' ); // *** CHANGE THIS ***
define( 'DSP_ITEMS_PER_PAGE', 25 );

// --- Load Files ---
require_once DSP_PLUGIN_DIR . 'includes/db-handler.php';
require_once DSP_PLUGIN_DIR . 'includes/parser-helpers.php';
require_once DSP_PLUGIN_DIR . 'includes/cron-handler.php';
require_once DSP_PLUGIN_DIR . 'admin/settings-page.php';
require_once DSP_PLUGIN_DIR . 'includes/shortcode-handler.php';
require_once DSP_PLUGIN_DIR . 'includes/email-handler.php';

// --- Autoload Parsers ---
$parser_dir = DSP_PLUGIN_DIR . 'includes/parsers/';
if ( is_dir( $parser_dir ) ) { $parser_files = glob( $parser_dir . '*.php' ); if ( $parser_files ) { foreach ( $parser_files as $parser_file ) { require_once $parser_file; } } }

// --- Config Functions ---
function dsp_get_default_config() {
     return [
        'sites' => [
             md5("AppSumohttps://appsumo.com/browse/?ordering=most-recent") => [ "name" => "AppSumo", "url" => "https://appsumo.com/browse/?ordering=most-recent", "parser_file" => "appsumo", "enabled" => true ],
             md5("StackSocialhttps://www.stacksocial.com/collections/apps-software?sort=newest") => [ "name" => "StackSocial", "url" => "https://www.stacksocial.com/collections/apps-software?sort=newest", "parser_file" => "stacksocial", "enabled" => true ],
             md5("DealFuelhttps://www.dealfuel.com/product-category/all/?orderby=date") => [ "name" => "DealFuel", "url" => "https://www.dealfuel.com/product-category/all/?orderby=date", "parser_file" => "dealfuel", "enabled" => true ],
             md5("DealMirrorhttps://dealmirror.com/product-category/new-arrivals/") => [ "name" => "DealMirror", "url" => "https://dealmirror.com/product-category/new-arrivals/", "parser_file" => "dealmirror", "enabled" => true ]
         ],
         'email_enabled' => false, 'email_frequency' => 'weekly', 'email_recipients' => [],
         'show_debug_button' => true, 'refresh_button_access' => 'all', 'dark_mode_default' => 'light',
         'purge_enabled' => false, 'purge_max_age_days' => 90,
     ];
}
function dsp_get_config() {
    $options = get_option(DSP_OPTION_NAME); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args($options, $defaults);
    $validated_sites = []; $sites_to_check = (isset($merged_options['sites']) && is_array($merged_options['sites'])) ? $merged_options['sites'] : $defaults['sites'];
    if (is_array($sites_to_check)) {
        foreach ($sites_to_check as $key => $site_data) {
            if ( is_array($site_data) && isset($site_data['name'], $site_data['url'], $site_data['parser_file'], $site_data['enabled']) && is_string($site_data['name']) && $site_data['name'] !== '' && is_string($site_data['url']) && $site_data['url'] !== '' && is_string($site_data['parser_file']) && $site_data['parser_file'] !== '' ) {
                $site_data['enabled'] = (bool) $site_data['enabled']; $validated_sites[$key] = $site_data;
            }
        }
    }
    // Return default site config if validation fails completely, otherwise return validated sites
    if (empty($validated_sites) && $sites_to_check === $defaults['sites']) { return $defaults['sites']; }
    elseif (empty($validated_sites)) { error_log("DSP get_config Warning: No valid sites found after checking saved options."); return []; } // Return empty if user config was invalid
    return $validated_sites;
}

// --- Activation / Deactivation ---
register_activation_hook( __FILE__, 'dsp_activate_plugin' );
function dsp_activate_plugin() {
    DSP_DB_Handler::create_table();
    if ( ! wp_next_scheduled( DSP_CRON_HOOK ) ) { wp_schedule_event( time(), 'dsp_fetch_interval', DSP_CRON_HOOK ); }
    if ( ! wp_next_scheduled( DSP_EMAIL_CRON_HOOK ) ) { wp_schedule_event( time() + 300, 'daily', DSP_EMAIL_CRON_HOOK ); }
    $existing_options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args( $existing_options, $defaults );
    // Ensure 'sites' exists and is an array, falling back to default if needed during merge
    if (!isset($merged_options['sites']) || !is_array($merged_options['sites'])) { $merged_options['sites'] = $defaults['sites']; }
    update_option( DSP_OPTION_NAME, $merged_options );
    if ( get_option('dsp_last_fetch_time') === false ) { update_option('dsp_last_fetch_time', 0, 'no'); }
    if ( get_option(DSP_LAST_EMAIL_OPTION) === false ) { update_option(DSP_LAST_EMAIL_OPTION, 0, 'no'); }
}

register_deactivation_hook( __FILE__, 'dsp_deactivate_plugin' );
function dsp_deactivate_plugin() {
    $timestamp_fetch = wp_next_scheduled( DSP_CRON_HOOK ); if ( $timestamp_fetch ) { wp_unschedule_event( $timestamp_fetch, DSP_CRON_HOOK ); }
    $timestamp_email = wp_next_scheduled( DSP_EMAIL_CRON_HOOK ); if ( $timestamp_email ) { wp_unschedule_event( $timestamp_email, DSP_EMAIL_CRON_HOOK ); }
}

// --- Crons ---
add_filter( 'cron_schedules', 'dsp_add_cron_interval' );
function dsp_add_cron_interval( $schedules ) {
    $interval = apply_filters('dsp_fetch_interval_seconds', DSP_FETCH_INTERVAL_SECONDS); if ($interval < 60) $interval = 60;
    $schedules['dsp_fetch_interval'] = ['interval' => $interval, 'display' => esc_html__( 'Deal Scraper Fetch Interval', 'deal-scraper-plugin' )];
    return $schedules;
}
add_action( DSP_CRON_HOOK, 'dsp_run_deal_fetch_cron' );
add_action( DSP_EMAIL_CRON_HOOK, 'dsp_check_and_send_scheduled_email' );

// --- Shortcode ---
add_shortcode( 'deal_scraper_display', 'dsp_render_shortcode' );

// --- Enqueue ---
add_action( 'wp_enqueue_scripts', 'dsp_enqueue_assets' );
function dsp_enqueue_assets() {
    global $post;
    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'deal_scraper_display' ) ) {
        $plugin_version = '1.1.5'; // Version Bump
        wp_enqueue_style( 'dsp-style', DSP_PLUGIN_URL . 'assets/css/deal-display.css', [], $plugin_version );
        wp_enqueue_script( 'dsp-script', DSP_PLUGIN_URL . 'assets/js/deal-display.js', ['jquery'], $plugin_version, true );

        $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args($options, $defaults);
        $enabled_sources = []; $validated_sites = dsp_get_config();
        if ( is_array($validated_sites) && !empty($validated_sites) ) { foreach ($validated_sites as $site_data) { if ( !empty($site_data['enabled']) && isset($site_data['name']) && $site_data['name'] !== '' ) { $enabled_sources[] = $site_data['name']; } } } sort($enabled_sources);
        $initial_db_data = DSP_DB_Handler::get_deals(['items_per_page' => 1]); $total_deals_count = $initial_db_data['total_deals'] ?? 0;

        // Data passed to JavaScript
        $localize_data = [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'dsp_ajax_nonce' ),
            // Text strings
            'loading_text' => __('Loading deals...', 'deal-scraper-plugin'),
            'checking_text' => __('Checking for new deals...', 'deal-scraper-plugin'),
            'error_text' => __('Error loading deals.', 'deal-scraper-plugin'),
            'error_check_text' => __('Error checking for new deals.', 'deal-scraper-plugin'),
            'error_check_ajax_text' => __('Error checking for new deals (AJAX).', 'deal-scraper-plugin'),
            'never_text' => __('Never', 'deal-scraper-plugin'),
            'last_updated_text' => __('Last fetched:', 'deal-scraper-plugin'),
            'refreshing_text' => __('Refreshing...', 'deal-scraper-plugin'),
            'show_log_text' => __('Show Debug Log', 'deal-scraper-plugin'),
            'hide_log_text' => __('Hide Debug Log', 'deal-scraper-plugin'),
            'no_deals_found_text' => __('No deals found matching criteria.', 'deal-scraper-plugin'),
            'refresh_finished_text' => __('Refresh finished.', 'deal-scraper-plugin'),
            'error_refresh_ajax_text' => __('Refresh failed (AJAX Error).', 'deal-scraper-plugin'),
            'error_refresh_invalid_resp_text' => __('Refresh failed (Invalid Response).', 'deal-scraper-plugin'),
            'yes_text' => __('Yes', 'deal-scraper-plugin'),
            'no_text' => __('No', 'deal-scraper-plugin'),
            'subscribe_invalid_email_format' => __('Please enter a valid email format.', 'deal-scraper-plugin'),
            'subscribe_enter_email' => __('Please enter an email address.', 'deal-scraper-plugin'),
            'subscribe_error_generic' => __('Subscription failed. Please try again later.', 'deal-scraper-plugin'),
            'subscribe_error_network' => __('Subscription failed due to a network error.', 'deal-scraper-plugin'),
            'check_complete_text' => __('Check complete.', 'deal-scraper-plugin'),
            'new_deals_found_single_text' => __('Check complete. Found %d new deal.', 'deal-scraper-plugin'),
            'new_deals_found_plural_text' => __('Check complete. Found %d new deals.', 'deal-scraper-plugin'),
            'no_new_deals_text' => __('Check complete. No new deals found.', 'deal-scraper-plugin'),
            'page_text' => __('Page %d of %d', 'deal-scraper-plugin'),
            'Previous' => __('Previous', 'deal-scraper-plugin'), // Added for pagination JS __()
            'Next' => __('Next', 'deal-scraper-plugin'),         // Added for pagination JS __()
            'Search' => __('Search', 'deal-scraper-plugin'),     // Added for JS __()
            'Sources filtered' => __('Sources filtered', 'deal-scraper-plugin'), // Added for JS __()
            'New only' => __('New only', 'deal-scraper-plugin'), // Added for JS __()
            'Filters' => __('Filters', 'deal-scraper-plugin'),   // Added for JS __()
            'Showing deals %d-%d of %d' => __('Showing deals %d-%d of %d', 'deal-scraper-plugin'), // Added for JS sprintf
            'Showing %d deals' => __('Showing %d deals', 'deal-scraper-plugin'), // Added for JS sprintf
            'No debug log available.' => __('No debug log available.', 'deal-scraper-plugin'), // Added for JS __()

            // Config data
            'config_sources' => $enabled_sources,
            'dark_mode_default' => $merged_options['dark_mode_default'],
            'email_notifications_enabled' => (bool) $merged_options['email_enabled'],
            'show_debug_button' => (bool) $merged_options['show_debug_button'],
            'refresh_button_access' => $merged_options['refresh_button_access'],
            'items_per_page' => DSP_ITEMS_PER_PAGE,
            'total_items' => $total_deals_count,
        ];
        wp_localize_script( 'dsp-script', 'dsp_ajax_obj', $localize_data );
    }
}

// --- Admin Enqueue ---
add_action( 'admin_enqueue_scripts', 'dsp_enqueue_admin_assets' );
function dsp_enqueue_admin_assets( $hook ) {
    if ( 'settings_page_deal_scraper_settings' !== $hook ) { return; } $plugin_version = '1.1.5';
    wp_enqueue_script( 'dsp-admin-script', DSP_PLUGIN_URL . 'assets/js/admin-settings.js', ['jquery', 'wp-util'], $plugin_version, true );
    wp_localize_script( 'dsp-admin-script', 'dsp_admin_ajax_obj', ['ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'dsp_admin_ajax_nonce' ), 'sending_text' => __( 'Sending...', 'deal-scraper-plugin' ), 'error_text' => __( 'An error occurred.', 'deal-scraper-plugin' ),] );
    $admin_css_path = DSP_PLUGIN_DIR . 'assets/css/admin-style.css'; if (file_exists($admin_css_path)) { wp_enqueue_style( 'dsp-admin-style', DSP_PLUGIN_URL . 'assets/css/admin-style.css', [], $plugin_version ); }
}


// --- Common Deal Processing Function (NEW) ---
/**
 * Processes raw deal objects from the DB for sending via AJAX.
 * Adds is_new, is_lifetime, first_seen_formatted, first_seen_ts.
 *
 * @param array $deals Array of deal objects from DB.
 * @param int $last_fetch_time Timestamp of last fetch.
 * @return array Processed array of deal objects.
 */
function dsp_process_deals_for_ajax( $deals, $last_fetch_time ) {
    $processed_deals = [];
    if ( empty($deals) || !is_array($deals) ) {
        return $processed_deals;
    }

    foreach ( $deals as $deal ) {
        // Ensure it's an object and has the necessary property
        if ( is_object($deal) && isset($deal->first_seen) ) {
             $first_seen_ts = strtotime($deal->first_seen); // Get timestamp
             if ($first_seen_ts === false) $first_seen_ts = 0; // Handle potential parse error

             $processed_deal = clone $deal; // Clone to avoid modifying original object if reused
             $processed_deal->is_new = ($first_seen_ts && $last_fetch_time && $first_seen_ts >= $last_fetch_time);
             // Use localized date format for display string
             $processed_deal->first_seen_formatted = $first_seen_ts ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $first_seen_ts ) : 'N/A';
             // *** Add raw timestamp for JS ***
             $processed_deal->first_seen_ts = $first_seen_ts;
             $processed_deal->is_lifetime = dsp_is_lifetime_deal_php($deal); // Call helper

             $processed_deals[] = $processed_deal;
        }
    }
    return $processed_deals;
}

// --- AJAX Handlers ---

// Get deals (Generic handler for fetching pages - MODIFIED FOR FILTERS)
add_action( 'wp_ajax_dsp_get_deals', 'dsp_ajax_get_deals_handler' );
add_action( 'wp_ajax_nopriv_dsp_get_deals', 'dsp_ajax_get_deals_handler' );
function dsp_ajax_get_deals_handler() {
    check_ajax_referer( 'dsp_ajax_nonce', 'nonce' );

    // --- Get Pagination & Sort Args ---
    $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
    $items_per_page = isset($_POST['items_per_page']) ? absint($_POST['items_per_page']) : DSP_ITEMS_PER_PAGE;
    if ($items_per_page <= 0) $items_per_page = DSP_ITEMS_PER_PAGE;
    $orderby = isset($_POST['orderby']) ? sanitize_key($_POST['orderby']) : 'first_seen'; // Use sanitize_key for column names
    $order = isset($_POST['order']) ? sanitize_key($_POST['order']) : 'DESC';
    if (!in_array(strtoupper($order), ['ASC', 'DESC'])) $order = 'DESC'; // Ensure valid order

    // --- Get Filter Args ---
    $search_term = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $sources = isset($_POST['sources']) && is_array($_POST['sources']) ? array_map('sanitize_text_field', $_POST['sources']) : [];
    $new_only = isset($_POST['new_only']) ? (bool) absint($_POST['new_only']) : false;

    // --- Prepare Args for DB Handler ---
    $db_args = [
        'orderby' => $orderby,
        'order' => $order,
        'items_per_page' => $items_per_page,
        'page' => $page,
        // *** Pass Filter Args ***
        'search' => $search_term,
        'sources' => $sources,
        // 'new_only' => $new_only, // We handle this via newer_than_ts
    ];

    $last_fetch_time = get_option('dsp_last_fetch_time', 0);

    // If filtering by 'new_only', pass the timestamp to the DB handler
    if ($new_only && $last_fetch_time > 0) {
        $db_args['newer_than_ts'] = $last_fetch_time;
    }

    // --- Call DB Handler ---
    $db_result = DSP_DB_Handler::get_deals($db_args);
    // *** Use common processing function ***
    $processed_deals = dsp_process_deals_for_ajax( $db_result['deals'] ?? [], $last_fetch_time );

    // --- Send Response ---
    wp_send_json_success( [
        'deals' => $processed_deals, // Processed deals for the requested page
        'total_items' => $db_result['total_deals'] ?? 0, // Total matching filters
        'items_per_page' => $items_per_page,
        'current_page' => $page,
        'last_fetch' => $last_fetch_time ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_fetch_time ) : __('Never', 'deal-scraper-plugin')
    ] );
}

// Manual Refresh Button Handler
add_action( 'wp_ajax_dsp_refresh_deals', 'dsp_ajax_refresh_deals_handler' );
add_action( 'wp_ajax_nopriv_dsp_refresh_deals', 'dsp_ajax_refresh_deals_handler' );
function dsp_ajax_refresh_deals_handler() {
    check_ajax_referer( 'dsp_ajax_nonce', 'nonce' );
    $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args($options, $defaults); $refresh_access = $merged_options['refresh_button_access']; $allow_refresh = false; switch ( $refresh_access ) { case 'all': $allow_refresh = true; break; case 'logged_in': if ( is_user_logged_in() ) $allow_refresh = true; break; case 'admins': if ( current_user_can( 'manage_options' ) ) $allow_refresh = true; break; } if ( ! $allow_refresh ) { wp_send_json_error(['message' => __('Permission denied.', 'deal-scraper-plugin'), 'log' => ['Refresh blocked by setting: '.$refresh_access]], 403); return; }

    $result = dsp_run_deal_fetch_cron(true);
    $db_result_all = DSP_DB_Handler::get_deals(['orderby' => 'first_seen', 'order' => 'DESC', 'items_per_page' => -1]); // Get ALL deals
    $last_fetch_time = get_option('dsp_last_fetch_time', 0);
    // *** Use common processing function ***
    $processed_deals_all = dsp_process_deals_for_ajax( $db_result_all['deals'] ?? [], $last_fetch_time );

    $response_data = [
        'log' => $result['log'] ?? [], 'message' => '',
        'deals' => $processed_deals_all, // Return ALL processed deals
        'total_items' => $db_result_all['total_deals'] ?? 0,
        'last_fetch' => $last_fetch_time ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_fetch_time ) : __('Never', 'deal-scraper-plugin')
    ];
    if (isset($result['error'])) { $response_data['message'] = $result['error']; wp_send_json_error($response_data); }
    elseif (isset($result['error_summary'])) { $response_data['message'] = $result['error_summary']; wp_send_json_success($response_data); }
    else { $response_data['message'] = sprintf(__('Refresh successful. Processed %d sites. Found %d new deals.', 'deal-scraper-plugin'), $result['sites_processed'] ?? 0, $result['new_deals_count'] ?? 0); wp_send_json_success($response_data); }
}

// Background Check Handler
add_action( 'wp_ajax_dsp_check_for_new_deals', 'dsp_ajax_check_for_new_deals_handler' );
add_action( 'wp_ajax_nopriv_dsp_check_for_new_deals', 'dsp_ajax_check_for_new_deals_handler' );
function dsp_ajax_check_for_new_deals_handler() {
    check_ajax_referer( 'dsp_ajax_nonce', 'nonce' );
    $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
    $items_per_page = isset($_POST['items_per_page']) ? absint($_POST['items_per_page']) : DSP_ITEMS_PER_PAGE;
    if ($items_per_page <= 0) $items_per_page = DSP_ITEMS_PER_PAGE;
    $orderby = isset($_POST['orderby']) ? sanitize_key($_POST['orderby']) : 'first_seen';
    $order = isset($_POST['order']) ? sanitize_key($_POST['order']) : 'DESC';
    if (!in_array(strtoupper($order), ['ASC', 'DESC'])) $order = 'DESC';

    // TODO: Read filter params from $_POST if needed for the background check response?
    // For now, the response just gives the *current* requested page data after the check.

    $result = dsp_run_deal_fetch_cron(false);
    if ( isset( $result['error'] ) ) { $current_db_data = DSP_DB_Handler::get_deals(['items_per_page' => 1]); wp_send_json_error(['message' => $result['error'], 'log' => $result['log'] ?? [], 'deals' => [], 'total_items' => $current_db_data['total_deals'] ?? 0, 'items_per_page' => $items_per_page, 'current_page' => $page, 'last_fetch' => get_option('dsp_last_fetch_time') ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), get_option('dsp_last_fetch_time') ) : __('Never', 'deal-scraper-plugin'), ]); return; }

    $last_fetch_time_after_run = get_option('dsp_last_fetch_time', 0);
    // Get deals for the *requested* page AFTER the cron run, using any passed sort params
    $db_args_after = [ 'orderby' => $orderby, 'order' => $order, 'items_per_page' => $items_per_page, 'page' => $page ];
    // Apply filters if they were sent (currently they aren't, but could be added)
    // $db_args_after['search'] = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    // etc...

    $db_result_after = DSP_DB_Handler::get_deals($db_args_after);
    // *** Use common processing function ***
    $processed_deals_page = dsp_process_deals_for_ajax( $db_result_after['deals'] ?? [], $last_fetch_time_after_run );
    $total_deals_after = $db_result_after['total_deals'] ?? 0;

    $new_deal_count_from_cron = $result['new_deals_count'] ?? 0; $message = '';
    if (isset($result['error_summary'])) { $message = $result['error_summary']; }
    elseif ($new_deal_count_from_cron > 0) { $message = sprintf( _n( 'Check complete. Found %d new deal.', 'Check complete. Found %d new deals.', $new_deal_count_from_cron, 'deal-scraper-plugin' ), $new_deal_count_from_cron ); }
    else { $message = __('Check complete. No new deals found.', 'deal-scraper-plugin'); }

    $response_data = [
        'deals' => $processed_deals_page, // Processed deals for the requested page
        'total_items' => $total_deals_after,
        'items_per_page' => $items_per_page,
        'current_page' => $page,
        'log' => $result['log'] ?? [],
        'message' => $message,
        'last_fetch' => $last_fetch_time_after_run ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_fetch_time_after_run ) : __('Never', 'deal-scraper-plugin'),
    ];
    wp_send_json_success( $response_data );
}

// --- Other Handlers ---
add_action( 'wp_ajax_dsp_subscribe_email', 'dsp_ajax_subscribe_email_handler' ); add_action( 'wp_ajax_nopriv_dsp_subscribe_email', 'dsp_ajax_subscribe_email_handler' );
function dsp_ajax_subscribe_email_handler() { check_ajax_referer( 'dsp_ajax_nonce', 'nonce' ); $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args($options, $defaults); if ( !(bool) $merged_options['email_enabled'] ) { wp_send_json_error(['message' => __('Email notifications are currently disabled.', 'deal-scraper-plugin')], 403); return; } if ( ! isset( $_POST['email'] ) || empty( trim( $_POST['email'] ) ) ) { wp_send_json_error(['message' => __('Please enter an email address.', 'deal-scraper-plugin')], 400); return; } $email_to_add = sanitize_email( trim( $_POST['email'] ) ); if ( ! is_email( $email_to_add ) ) { wp_send_json_error(['message' => __('Invalid email address provided.', 'deal-scraper-plugin')], 400); return; } $current_recipients = is_array( $merged_options['email_recipients'] ) ? $merged_options['email_recipients'] : []; $email_exists = false; foreach ($current_recipients as $existing_email) { if (strcasecmp($existing_email, $email_to_add) === 0) { $email_exists = true; break; } } if ( $email_exists ) { wp_send_json_success(['message' => __('This email address is already subscribed.', 'deal-scraper-plugin')]); return; } $current_recipients[] = $email_to_add; $merged_options['email_recipients'] = array_values(array_unique($current_recipients)); $update_result = update_option( DSP_OPTION_NAME, $merged_options ); if ( $update_result ) { wp_send_json_success(['message' => __('Successfully subscribed!', 'deal-scraper-plugin')]); } else { $options_after = get_option(DSP_OPTION_NAME); if (is_array($options_after) && isset($options_after['email_recipients']) && in_array($email_to_add, $options_after['email_recipients'])) { wp_send_json_success(['message' => __('Already subscribed (verified).', 'deal-scraper-plugin')]); } else { error_log("DSP Subscribe Error: update_option failed."); wp_send_json_error(['message' => __('Subscription failed (server error).', 'deal-scraper-plugin')], 500); } } }

add_action( 'wp_ajax_dsp_send_manual_email', 'dsp_ajax_send_manual_email_handler' );
function dsp_ajax_send_manual_email_handler() { check_ajax_referer( 'dsp_admin_ajax_nonce', 'nonce' ); if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( ['message' => __( 'Permission denied.', 'deal-scraper-plugin' )], 403 ); return; } $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args( $options, $defaults ); $recipients = $merged_options['email_recipients'] ?? []; if ( ! is_array( $recipients ) || empty( $recipients ) ) { wp_send_json_error( ['message' => __( 'No recipients configured.', 'deal-scraper-plugin' )] ); return; } $valid_recipients = array_filter( $recipients, 'is_email' ); if ( empty( $valid_recipients ) ) { wp_send_json_error( ['message' => __( 'No valid recipients found.', 'deal-scraper-plugin' )] ); return; } $deals_data = DSP_DB_Handler::get_deals(['limit' => 10, 'orderby' => 'first_seen', 'order' => 'DESC', 'items_per_page'=>10, 'page'=>1]); $deals = $deals_data['deals'] ?? []; if ( empty( $deals ) ) { wp_send_json_success( ['message' => __( 'No recent deals to email.', 'deal-scraper-plugin' )] ); return; } if ( ! function_exists('dsp_format_deals_email') ) { wp_send_json_error( ['message' => __( 'Email format function missing.', 'deal-scraper-plugin' )], 500 ); return; } $email_subject = sprintf( __( '%s Deal Digest', 'deal-scraper-plugin' ), get_bloginfo( 'name' ) ); $email_body_html = dsp_format_deals_email($deals); if (empty($email_body_html)) { wp_send_json_error( ['message' => __( 'Failed to generate email.', 'deal-scraper-plugin' )] ); return; } $headers = ['Content-Type: text/html; charset=UTF-8']; $site_name = get_bloginfo('name'); $site_domain = wp_parse_url(home_url(), PHP_URL_HOST); $from_email = 'wordpress@' . $site_domain; $headers[] = "From: {$site_name} <{$from_email}>"; $sent_count = 0; $fail_count = 0; foreach ( $valid_recipients as $recipient_email ) { $unsubscribe_link = dsp_generate_unsubscribe_link($recipient_email); $final_email_body = $email_body_html . dsp_get_unsubscribe_footer_html($unsubscribe_link); $sent = wp_mail( $recipient_email, $email_subject, $final_email_body, $headers ); if ($sent) $sent_count++; else $fail_count++; } if ( $sent_count > 0 && $fail_count === 0 ) { wp_send_json_success( [ 'message' => sprintf( _n( 'Email sent to %d recipient.', 'Email sent to %d recipients.', $sent_count, 'deal-scraper-plugin' ), $sent_count ) ] ); } elseif ( $sent_count > 0 && $fail_count > 0 ) { wp_send_json_success( [ 'message' => sprintf( __( 'Sent to %d, failed for %d. Check logs.', 'deal-scraper-plugin' ), $sent_count, $fail_count ) ] ); } else { global $phpmailer; $error_info = isset($phpmailer) && is_object($phpmailer) ? $phpmailer->ErrorInfo : ''; wp_send_json_error( [ 'message' => __( 'Failed to send emails. Check logs/WP mail config.', 'deal-scraper-plugin' ) . ($error_info ? ' Last Error: ' . esc_html($error_info) : '') ] ); } }

// --- Helper Functions ---
function dsp_is_lifetime_deal_php($deal_obj) { if (!is_object($deal_obj)) return false; $t=isset($deal_obj->title) && is_string($deal_obj->title) && stripos($deal_obj->title,'lifetime')!==false; $p=isset($deal_obj->price) && is_string($deal_obj->price) && stripos($deal_obj->price,'lifetime')!==false; return $t||$p; }

// --- Scheduled Email & Unsubscribe Functions ---
function dsp_check_and_send_scheduled_email() { error_log("DSP Log: Running scheduled email check..."); $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args( $options, $defaults ); if ( empty( $merged_options['email_enabled'] ) ) { error_log("DSP Log: Scheduled email skipped - Disabled."); return; } $frequency = $merged_options['email_frequency'] ?? 'weekly'; $last_send_timestamp = (int) get_option( DSP_LAST_EMAIL_OPTION, 0 ); $current_timestamp = time(); $threshold = 0; if ( $frequency === 'weekly' ) { $threshold = strtotime( '+7 days', $last_send_timestamp ); } elseif ( $frequency === 'biweekly' ) { $threshold = strtotime( '+15 days', $last_send_timestamp ); } else { error_log("DSP Log: Scheduled email skipped - Invalid frequency: " . $frequency); return; } if ( $current_timestamp < $threshold ) { error_log("DSP Log: Scheduled email skipped - Not time yet. Last: " . ($last_send_timestamp > 0 ? date('Y-m-d H:i:s', $last_send_timestamp) : 'Never') . ", Threshold: " . date('Y-m-d H:i:s', $threshold)); return; } error_log("DSP Log: Time to send scheduled email (Frequency: {$frequency})."); $recipients = $merged_options['email_recipients'] ?? []; if ( ! is_array( $recipients ) || empty( $recipients ) ) { error_log("DSP Log: Aborted - No recipients."); return; } $valid_recipients = array_filter( $recipients, 'is_email' ); if ( empty( $valid_recipients ) ) { error_log("DSP Log: Aborted - No valid recipients."); return; } if (!method_exists('DSP_DB_Handler', 'get_deals_since')) { error_log("DSP Log: Aborted - DB method get_deals_since not found."); return; } $last_send_datetime_gmt = $last_send_timestamp > 0 ? gmdate('Y-m-d H:i:s', $last_send_timestamp) : '0000-00-00 00:00:00'; $new_deals = DSP_DB_Handler::get_deals_since($last_send_datetime_gmt); if ( empty( $new_deals ) ) { error_log("DSP Log: Aborted - No new deals since " . $last_send_datetime_gmt); update_option( DSP_LAST_EMAIL_OPTION, $current_timestamp, 'no' ); error_log("DSP Log: Updated last email send time anyway."); return; } error_log("DSP Log: Found " . count($new_deals) . " new deals."); if ( ! function_exists('dsp_format_deals_email') ) { error_log("DSP Log: Aborted - dsp_format_deals_email function not found."); return; } $email_subject = sprintf( __( '%s Deal Digest', 'deal-scraper-plugin' ), get_bloginfo( 'name' ) ); $email_body_html = dsp_format_deals_email($new_deals); if (empty($email_body_html)) { error_log("DSP Log: Aborted - Failed to generate email content."); return; } $headers = ['Content-Type: text/html; charset=UTF-8']; $site_name = get_bloginfo('name'); $site_domain = wp_parse_url(home_url(), PHP_URL_HOST); $from_email = 'wordpress@' . $site_domain; $headers[] = "From: {$site_name} <{$from_email}>"; $total_sent = 0; $total_failed = 0; foreach ( $valid_recipients as $recipient_email ) { $unsubscribe_link = dsp_generate_unsubscribe_link($recipient_email); $final_email_body = $email_body_html . dsp_get_unsubscribe_footer_html($unsubscribe_link); $sent = wp_mail( $recipient_email, $email_subject, $final_email_body, $headers ); if ($sent) $total_sent++; else $total_failed++; } error_log("DSP Log: Scheduled send complete. Sent: {$total_sent}, Failed: {$total_failed}."); if ($total_sent > 0 || $total_failed > 0) { update_option( DSP_LAST_EMAIL_OPTION, $current_timestamp, 'no' ); error_log("DSP Log: Updated last email send time to " . date('Y-m-d H:i:s', $current_timestamp)); } }
function dsp_generate_unsubscribe_token( $email ) { $email = strtolower( trim( $email ) ); $secret = wp_salt('auth') . DSP_UNSUBSCRIBE_SALT; return hash_hmac( 'sha256', $email, $secret ); }
function dsp_generate_unsubscribe_link( $email ) { $token = dsp_generate_unsubscribe_token( $email ); $args = [ 'dsp_unsubscribe' => 1, 'email' => rawurlencode($email), 'token' => $token, ]; return add_query_arg( $args, home_url( '/' ) ); }
function dsp_get_unsubscribe_footer_html( $unsubscribe_link ) { $style='text-align:center;margin-top:20px;padding-top:15px;border-top:1px solid #eee;font-size:12px;color:#888;'; $link_style='color:#069;text-decoration:underline;'; $text = sprintf(__( 'Don\'t want these emails? %s.', 'deal-scraper-plugin'),'<a href="'.esc_url($unsubscribe_link).'" style="'.$link_style.'">'.esc_html__('Unsubscribe here', 'deal-scraper-plugin').'</a>'); return '<div style="'.$style.'"><p>'.$text.'</p></div>'; }
function dsp_handle_unsubscribe_request() { if(!isset($_GET['dsp_unsubscribe'],$_GET['email'],$_GET['token'])) return; $email=sanitize_email(rawurldecode($_GET['email'])); $token=sanitize_text_field($_GET['token']); if(!is_email($email)||empty($token)) { wp_redirect(home_url()); exit; } $expected=dsp_generate_unsubscribe_token($email); if(!hash_equals($expected,$token)) { wp_die(esc_html__('Invalid/expired link.','deal-scraper-plugin'),esc_html__('Error','deal-scraper-plugin'),400); } $opts=get_option(DSP_OPTION_NAME); if(!is_array($opts)||!isset($opts['email_recipients'])||!is_array($opts['email_recipients'])) { wp_die(esc_html__('Unsubscribed (or not subscribed).','deal-scraper-plugin'),esc_html__('Success','deal-scraper-plugin'),200); } $recipients=$opts['email_recipients']; $found=false; $new_recipients=[]; foreach($recipients as $rec){ if(strcasecmp(trim($rec),$email)!==0) $new_recipients[]=$rec; else $found=true; } if($found){ $opts['email_recipients']=array_values($new_recipients); $updated=update_option(DSP_OPTION_NAME,$opts); if($updated) wp_die(esc_html__('Unsubscribed successfully.','deal-scraper-plugin'),esc_html__('Success','deal-scraper-plugin'),200); else wp_die(esc_html__('Error processing request.','deal-scraper-plugin'),esc_html__('Error','deal-scraper-plugin'),500); } else { wp_die(esc_html__('Already unsubscribed.','deal-scraper-plugin'),esc_html__('Info','deal-scraper-plugin'),200); } }
add_action( 'init', 'dsp_handle_unsubscribe_request' );

?>