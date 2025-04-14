<?php
/**
 * Plugin Name:       Deal Scraper Plugin
 * Plugin URI:        https://none.com
 * Description:       Scrapes deal websites and displays them via a shortcode. Includes debug logging, dark mode, email subscription. [deal_scraper_display]
 * Version:           1.1.30 // Feature: Admin AJAX Source Actions
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
define( 'DSP_CRON_HOOK', 'dsp_periodic_deal_fetch' ); // Main staggered fetch hook
define( 'DSP_OPTION_NAME', 'dsp_settings' );
define( 'DSP_EMAIL_CRON_HOOK', 'dsp_daily_email_check' ); // Email check hook (remains daily for now)
define( 'DSP_LAST_EMAIL_OPTION', 'dsp_last_email_send_time' );
define( 'DSP_ITEMS_PER_PAGE', 25 ); // Default items per page for frontend display
define( 'DSP_VERSION', '1.1.30' ); // Use this for assets & DB version check
define( 'DSP_DB_VERSION_OPTION', 'dsp_db_version' ); // Option to store DB schema version
define( 'DSP_SOURCE_LIST_TRANSIENT', 'dsp_enabled_sources_list' ); // Transient key

// --- Load Files ---
require_once DSP_PLUGIN_DIR . 'includes/db-handler.php';
require_once DSP_PLUGIN_DIR . 'includes/parser-helpers.php';
require_once DSP_PLUGIN_DIR . 'includes/cron-handler.php';
require_once DSP_PLUGIN_DIR . 'admin/settings-page.php';
require_once DSP_PLUGIN_DIR . 'includes/shortcode-handler.php';
require_once DSP_PLUGIN_DIR . 'includes/email-handler.php';

// --- Autoload Parsers ---
$parser_dir = DSP_PLUGIN_DIR . 'includes/parsers/';
if ( is_dir( $parser_dir ) ) {
    $parser_files = glob( $parser_dir . '*.php' );
    if ( $parser_files ) {
        foreach ( $parser_files as $parser_file ) {
            require_once $parser_file;
        }
    }
}

// --- Config Functions ---
/** Gets the default plugin configuration settings. */
function dsp_get_default_config() {
     // Default configuration remains the same
     return [
        'sites' => [
             [ "name" => "AppSumo", "url" => "https://appsumo.com/browse/?ordering=most-recent", "parser_file" => "appsumo", "enabled" => true, "last_status" => "", "last_run_time" => 0 ],
             [ "name" => "StackSocial", "url" => "https://www.stacksocial.com/collections/apps-software?sort=newest", "parser_file" => "stacksocial", "enabled" => true, "last_status" => "", "last_run_time" => 0 ],
             [ "name" => "DealFuel", "url" => "https://www.dealfuel.com/product-category/all/?orderby=date", "parser_file" => "dealfuel", "enabled" => true, "last_status" => "", "last_run_time" => 0 ],
             [ "name" => "Dealify", "url" => "https://dealify.com/collections/whats-new", "parser_file" => "dealify", "enabled" => true, "last_status" => "", "last_run_time" => 0 ],
             [ "name" => "DealMirror", "url" => "https://dealmirror.com/product-category/new-arrivals/", "parser_file" => "dealmirror", "enabled" => true, "last_status" => "", "last_run_time" => 0 ]
         ],
         'email_enabled' => false, 'email_frequency' => 'weekly', 'email_recipients' => [], 'unsubscribe_salt' => '',
         'show_debug_button' => true, 'refresh_button_access' => 'all', 'dark_mode_default' => 'auto',
         'purge_enabled' => true, 'purge_max_age_days' => 90, 'fetch_frequency' => 'daily',
     ];
}
/** Gets the configured scraping sites. */
function dsp_get_config() {
    // Function remains the same
    $options = get_option(DSP_OPTION_NAME);
    $defaults = dsp_get_default_config();
    $sites_config = isset($options['sites']) && is_array($options['sites']) ? $options['sites'] : $defaults['sites'];
    $validated_sites = [];
    if (is_array($sites_config)) {
        foreach ($sites_config as $site_data) {
            if ( is_array($site_data) && isset($site_data['name'], $site_data['url'], $site_data['parser_file']) && is_string($site_data['name']) && $site_data['name'] !== '' && is_string($site_data['url']) && $site_data['url'] !== '' && is_string($site_data['parser_file']) && $site_data['parser_file'] !== '' ) {
                $site_data['enabled'] = isset($site_data['enabled']) ? (bool) $site_data['enabled'] : false;
                $site_data['last_status'] = isset($site_data['last_status']) ? (string)$site_data['last_status'] : '';
                $site_data['last_run_time'] = isset($site_data['last_run_time']) ? (int)$site_data['last_run_time'] : 0;
                $validated_sites[] = $site_data;
            }
        }
    }
    if (empty($validated_sites) && !empty($sites_config)) { error_log("DSP get_config Warning: No valid sites found after checking saved options. Returning empty array."); }
    elseif (empty($validated_sites) && empty($sites_config)) { error_log("DSP get_config Info: No sites found in options, returning defaults."); return $defaults['sites']; }
    return $validated_sites;
}


// --- Activation / Deactivation ---
register_activation_hook( __FILE__, 'dsp_activate_plugin' );
/** Plugin activation hook. */
function dsp_activate_plugin() {
    // Function remains the same
    error_log("DSP Activation: Running activation routine.");
    DSP_DB_Handler::create_table(); DSP_DB_Handler::add_ltd_column_and_index(); DSP_DB_Handler::add_price_numeric_column_and_index(); update_option( DSP_DB_VERSION_OPTION, DSP_VERSION );
    $options = get_option( DSP_OPTION_NAME, [] ); if ( ! is_array( $options ) ) { $options = []; } $defaults = dsp_get_default_config(); $merged_options = wp_parse_args( $options, $defaults );
    if (!isset($merged_options['sites']) || !is_array($merged_options['sites'])) { $merged_options['sites'] = $defaults['sites']; } if (!isset($merged_options['email_recipients']) || !is_array($merged_options['email_recipients'])) { $merged_options['email_recipients'] = $defaults['email_recipients']; }
    $salt_exists = isset( $merged_options['unsubscribe_salt'] ) && is_string( $merged_options['unsubscribe_salt'] ) && strlen( $merged_options['unsubscribe_salt'] ) > 32; if ( ! $salt_exists ) { $merged_options['unsubscribe_salt'] = wp_generate_password( 64, true, true ); error_log('DSP Activation: Generated new secure unsubscribe salt.'); }
    update_option( DSP_OPTION_NAME, $merged_options );
    $fetch_frequency = isset($merged_options['fetch_frequency']) ? $merged_options['fetch_frequency'] : $defaults['fetch_frequency']; $allowed_schedules = ['twicedaily', 'daily']; if (!in_array($fetch_frequency, $allowed_schedules)) { $fetch_frequency = 'daily'; } wp_clear_scheduled_hook( DSP_CRON_HOOK ); if ( ! wp_next_scheduled( DSP_CRON_HOOK ) ) { wp_schedule_event( time() + 60, $fetch_frequency, DSP_CRON_HOOK ); error_log("DSP Activation: Scheduled main fetch cron ({DSP_CRON_HOOK}) frequency: {$fetch_frequency}"); } else { error_log("DSP Activation Error: Main fetch cron ({DSP_CRON_HOOK}) still scheduled after clearing attempt."); }
    if ( ! wp_next_scheduled( DSP_EMAIL_CRON_HOOK ) ) { wp_schedule_event( time() + 300, 'daily', DSP_EMAIL_CRON_HOOK ); error_log('DSP Activation: Scheduled daily email check (' . DSP_EMAIL_CRON_HOOK . ')'); }
    if ( get_option('dsp_last_fetch_time') === false ) { update_option('dsp_last_fetch_time', 0, 'no'); } if ( get_option(DSP_LAST_EMAIL_OPTION) === false ) { update_option(DSP_LAST_EMAIL_OPTION, 0, 'no'); } delete_transient( DSP_SOURCE_LIST_TRANSIENT ); error_log("DSP Activation: Routine complete.");
}

register_deactivation_hook( __FILE__, 'dsp_deactivate_plugin' );
/** Plugin deactivation hook. */
function dsp_deactivate_plugin() {
    // Function remains the same
    wp_clear_scheduled_hook( DSP_CRON_HOOK ); wp_clear_scheduled_hook( DSP_EMAIL_CRON_HOOK ); delete_transient( DSP_SOURCE_LIST_TRANSIENT ); error_log('DSP Deactivation: Cleared scheduled hooks and source list transient.');
}

// --- DB Upgrade Routine ---
/** Checks the DB version and runs upgrades if needed. */
function dsp_check_db_updates() {
    // Function remains the same
    $current_db_version = get_option( DSP_DB_VERSION_OPTION, '0' ); if ( version_compare( $current_db_version, DSP_VERSION, '<' ) ) { error_log("DSP DB Update Check: Current DB version {$current_db_version} is older than plugin version " . DSP_VERSION . ". Running updates."); DSP_DB_Handler::create_table(); if ( version_compare( $current_db_version, '1.1.22', '<' ) ) { error_log("DSP DB Update: Running specific upgrade steps for < 1.1.22..."); DSP_DB_Handler::add_ltd_column_and_index(); } if ( version_compare( $current_db_version, '1.1.29', '<' ) ) { error_log("DSP DB Update: Running specific upgrade steps for < 1.1.29..."); DSP_DB_Handler::add_price_numeric_column_and_index(); } update_option( DSP_DB_VERSION_OPTION, DSP_VERSION ); error_log("DSP DB Update Check: DB version updated to " . DSP_VERSION); }
}
add_action( 'plugins_loaded', 'dsp_check_db_updates' );

// --- Crons ---
add_action( DSP_CRON_HOOK, 'dsp_run_deal_fetch_cron' );
add_action( DSP_EMAIL_CRON_HOOK, 'dsp_check_and_send_scheduled_email' );

// --- Shortcode ---
add_shortcode( 'deal_scraper_display', 'dsp_render_shortcode' );

// --- Enqueue Scripts and Styles (Frontend) ---
add_action( 'wp_enqueue_scripts', 'dsp_enqueue_assets' );
/** Enqueues scripts and styles for the frontend shortcode display. */
function dsp_enqueue_assets() {
    // Function remains the same
    global $post; if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'deal_scraper_display' ) ) { $plugin_version = defined('DSP_VERSION') ? DSP_VERSION : '1.1.30'; wp_enqueue_style( 'dsp-style', DSP_PLUGIN_URL . 'assets/css/deal-display.css', [], $plugin_version ); wp_enqueue_script( 'dsp-script', DSP_PLUGIN_URL . 'assets/js/deal-display.js', ['jquery'], $plugin_version, true ); $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args($options, $defaults); $enabled_sources = get_transient( DSP_SOURCE_LIST_TRANSIENT ); if ( false === $enabled_sources ) { $enabled_sources = []; $configured_sites = dsp_get_config(); if ( is_array($configured_sites) && !empty($configured_sites) ) { foreach ($configured_sites as $site_data) { if ( !empty($site_data['enabled']) && isset($site_data['name']) && $site_data['name'] !== '' ) { $enabled_sources[] = $site_data['name']; } } } sort($enabled_sources); set_transient( DSP_SOURCE_LIST_TRANSIENT, $enabled_sources, 15 * MINUTE_IN_SECONDS ); } $count_data = DSP_DB_Handler::get_deals(['items_per_page' => 0]); $total_deals_count = $count_data['total_deals'] ?? 0; $localize_data = [ 'ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'dsp_ajax_nonce' ), /* ... text strings ... */ 'loading_text' => __('Loading deals...', 'deal-scraper-plugin'), 'checking_text' => __('Checking for new deals...', 'deal-scraper-plugin'), 'error_text' => __('Error loading deals.', 'deal-scraper-plugin'), 'error_check_text' => __('Error checking for new deals.', 'deal-scraper-plugin'), 'error_check_ajax_text' => __('Error checking for new deals (AJAX).', 'deal-scraper-plugin'), 'never_text' => __('Never', 'deal-scraper-plugin'), 'last_updated_text' => __('Last successful check:', 'deal-scraper-plugin'), 'refreshing_text' => __('Refreshing...', 'deal-scraper-plugin'), 'show_log_text' => __('Show Debug Log', 'deal-scraper-plugin'), 'hide_log_text' => __('Hide Debug Log', 'deal-scraper-plugin'), 'no_deals_found_text' => __('No deals found matching criteria.', 'deal-scraper-plugin'), 'refresh_finished_text' => __('Refresh finished.', 'deal-scraper-plugin'), 'error_refresh_ajax_text' => __('Refresh failed (AJAX Error).', 'deal-scraper-plugin'), 'error_refresh_invalid_resp_text' => __('Refresh failed (Invalid Response).', 'deal-scraper-plugin'), 'yes_text' => __('Yes', 'deal-scraper-plugin'), 'no_text' => __('No', 'deal-scraper-plugin'), 'subscribe_invalid_email_format' => __('Please enter a valid email format.', 'deal-scraper-plugin'), 'subscribe_enter_email' => __('Please enter an email address.', 'deal-scraper-plugin'), 'subscribe_error_generic' => __('Subscription failed. Please try again later.', 'deal-scraper-plugin'), 'subscribe_error_network' => __('Subscription failed due to a network error.', 'deal-scraper-plugin'), 'check_complete_text' => __('Check complete.', 'deal-scraper-plugin'), 'new_deals_found_single_text' => __('Check complete. Found %d new deal.', 'deal-scraper-plugin'), 'new_deals_found_plural_text' => __('Check complete. Found %d new deals.', 'deal-scraper-plugin'), 'no_new_deals_text' => __('Check complete. No new deals found.', 'deal-scraper-plugin'), 'bg_update_notice_single' => __('Found %d new deal! It will appear when you refresh, filter, sort, or change pages.', 'deal-scraper-plugin'), 'bg_update_notice_plural' => __('Found %d new deals! They will appear when you refresh, filter, sort, or change pages.', 'deal-scraper-plugin'), 'dismiss_notice_text' => __('Dismiss', 'deal-scraper-plugin'), 'page_text' => __('Page %d of %d', 'deal-scraper-plugin'), 'Previous' => __('Previous', 'deal-scraper-plugin'), 'Next' => __('Next', 'deal-scraper-plugin'), 'Search' => __('Search', 'deal-scraper-plugin'), 'Sources filtered' => __('Sources filtered', 'deal-scraper-plugin'), 'New only' => __('New only', 'deal-scraper-plugin'), 'Filters' => __('Filters', 'deal-scraper-plugin'), 'Showing deals %d-%d of %d' => __('Showing deals %d-%d of %d', 'deal-scraper-plugin'), 'Showing %d deals' => __('Showing %d deals', 'deal-scraper-plugin'), 'No debug log available.' => __('No debug log available.', 'deal-scraper-plugin'), 'LTD Only' => __('LTD Only', 'deal-scraper-plugin'), 'Price' => __('Price', 'deal-scraper-plugin'), 'Up to' => __('Up to', 'deal-scraper-plugin'), 'no_deals_yet_text' => __('No deals found yet. Please try refreshing or check back later.', 'deal-scraper-plugin'), 'no_sources_selected_text' => __('Please select at least one source to display deals.', 'deal-scraper-plugin'), 'config_sources' => $enabled_sources, 'email_notifications_enabled' => (bool) $merged_options['email_enabled'], 'show_debug_button' => (bool) $merged_options['show_debug_button'], 'refresh_button_access' => $merged_options['refresh_button_access'], 'items_per_page' => DSP_ITEMS_PER_PAGE, 'total_items' => $total_deals_count, ]; wp_localize_script( 'dsp-script', 'dsp_ajax_obj', $localize_data ); }
}

// --- Enqueue Scripts and Styles (Admin) ---
add_action( 'admin_enqueue_scripts', 'dsp_enqueue_admin_assets' );
/** Enqueues scripts and styles for the admin settings page. */
function dsp_enqueue_admin_assets( $hook ) {
    // Function remains the same
    if ( 'settings_page_deal_scraper_settings' !== $hook ) { return; } $plugin_version = defined('DSP_VERSION') ? DSP_VERSION : '1.1.30'; wp_enqueue_script( 'dsp-admin-script', DSP_PLUGIN_URL . 'assets/js/admin-settings.js', ['jquery', 'wp-util'], $plugin_version, true ); wp_localize_script( 'dsp-admin-script', 'dsp_admin_ajax_obj', [ 'ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'dsp_admin_ajax_nonce' ), 'sending_text' => __( 'Sending...', 'deal-scraper-plugin' ), 'error_text' => __( 'An error occurred.', 'deal-scraper-plugin' ), 'testing_text' => __( 'Testing...', 'deal-scraper-plugin' ), 'test_error_text' => __( 'Test failed. Check inputs.', 'deal-scraper-plugin' ), 'delete_confirm_text' => __( 'Are you sure you want to delete this source?', 'deal-scraper-plugin' ), ] ); $admin_css_path = DSP_PLUGIN_DIR . 'assets/css/admin-style.css'; if (file_exists($admin_css_path)) { wp_enqueue_style( 'dsp-admin-style', DSP_PLUGIN_URL . 'assets/css/admin-style.css', [], $plugin_version ); }
}

// --- Common Deal Processing Function ---
/** Processes raw deal objects for AJAX responses. */
function dsp_process_deals_for_ajax( $deals, $last_fetch_time ) {
    // Function remains the same
    $processed_deals = []; if ( empty($deals) || !is_array($deals) ) { return $processed_deals; } $date_format = get_option('date_format'); $time_format = get_option('time_format'); foreach ( $deals as $deal ) { if ( is_object($deal) && isset($deal->first_seen) ) { $first_seen_ts = strtotime($deal->first_seen); if ($first_seen_ts === false) $first_seen_ts = 0; $processed_deal = clone $deal; $processed_deal->is_new = ($first_seen_ts && $last_fetch_time && $first_seen_ts >= $last_fetch_time); $processed_deal->first_seen_formatted = $first_seen_ts ? date_i18n( "{$date_format} {$time_format}", $first_seen_ts ) : 'N/A'; $processed_deal->first_seen_ts = $first_seen_ts; $processed_deal->is_lifetime = isset($deal->is_ltd) ? (bool)$deal->is_ltd : dsp_is_lifetime_deal_php($deal); $processed_deals[] = $processed_deal; } } return $processed_deals;
}

// --- AJAX Handlers ---

// Frontend Handlers
add_action( 'wp_ajax_dsp_get_deals', 'dsp_ajax_get_deals_handler' );
add_action( 'wp_ajax_nopriv_dsp_get_deals', 'dsp_ajax_get_deals_handler' );
/** Handles AJAX requests to fetch deals for the frontend display. */
function dsp_ajax_get_deals_handler() { /* ... Function content remains the same ... */
    check_ajax_referer( 'dsp_ajax_nonce', 'nonce' ); $page = isset($_POST['page']) ? absint($_POST['page']) : 1; $items_per_page = isset($_POST['items_per_page']) ? absint($_POST['items_per_page']) : DSP_ITEMS_PER_PAGE; if ($items_per_page <= 0) $items_per_page = DSP_ITEMS_PER_PAGE; $orderby = isset($_POST['orderby']) ? sanitize_key($_POST['orderby']) : 'first_seen'; $order = isset($_POST['order']) ? sanitize_key($_POST['order']) : 'DESC'; if (!in_array(strtoupper($order), ['ASC', 'DESC'])) $order = 'DESC'; $search_term = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : ''; $sources = isset($_POST['sources']) && is_array($_POST['sources']) ? array_map('sanitize_text_field', $_POST['sources']) : []; $new_only = isset($_POST['new_only']) ? filter_var($_POST['new_only'], FILTER_VALIDATE_BOOLEAN) : false; $ltd_only = isset($_POST['ltd_only']) ? filter_var($_POST['ltd_only'], FILTER_VALIDATE_BOOLEAN) : false; $min_price_input = isset($_POST['min_price']) ? trim(wp_unslash($_POST['min_price'])) : ''; $max_price_input = isset($_POST['max_price']) ? trim(wp_unslash($_POST['max_price'])) : ''; $min_price = ($min_price_input !== '' && is_numeric($min_price_input) && $min_price_input >= 0) ? (float) $min_price_input : null; $max_price = ($max_price_input !== '' && is_numeric($max_price_input) && $max_price_input >= 0) ? (float) $max_price_input : null; $db_args = [ 'orderby' => $orderby, 'order' => $order, 'items_per_page' => $items_per_page, 'page' => $page, 'search' => $search_term, 'sources' => $sources, 'min_price' => $min_price, 'max_price' => $max_price, 'ltd_only' => $ltd_only ]; $last_successful_run_time = 0; if ($new_only) { $last_successful_run_time = dsp_get_last_successful_run_timestamp(); if ($last_successful_run_time > 0) { $db_args['newer_than_ts'] = $last_successful_run_time; } } $deals_to_fetch = true; if ( isset($_POST['sources']) && empty($sources) ) { $deals_to_fetch = false; $fetched_deals = []; $db_result = ['deals' => [], 'total_deals' => 0]; error_log("DSP AJAX: No sources selected, returning empty set."); } else { $db_result = DSP_DB_Handler::get_deals($db_args); $fetched_deals = $db_result['deals'] ?? []; } $timestamp_for_is_new = $last_successful_run_time > 0 ? $last_successful_run_time : dsp_get_last_successful_run_timestamp(); $processed_deals = dsp_process_deals_for_ajax( $fetched_deals, $timestamp_for_is_new ); $display_last_fetch_time = dsp_get_last_successful_run_timestamp(); wp_send_json_success( [ 'deals' => $processed_deals, 'total_items' => $db_result['total_deals'] ?? 0, 'items_per_page' => $items_per_page, 'current_page' => $page, 'last_fetch' => $display_last_fetch_time ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $display_last_fetch_time ) : __('Never', 'deal-scraper-plugin') ] );
}

add_action( 'wp_ajax_dsp_refresh_deals', 'dsp_ajax_refresh_deals_handler' );
add_action( 'wp_ajax_nopriv_dsp_refresh_deals', 'dsp_ajax_refresh_deals_handler' );
/** Handles AJAX requests for manual refresh. */
function dsp_ajax_refresh_deals_handler() { /* ... Function content remains the same ... */
    check_ajax_referer( 'dsp_ajax_nonce', 'nonce' ); $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args($options, $defaults); $refresh_access = $merged_options['refresh_button_access']; $allow_refresh = false; switch ( $refresh_access ) { case 'all': $allow_refresh = true; break; case 'logged_in': if ( is_user_logged_in() ) $allow_refresh = true; break; case 'admins': if ( current_user_can( 'manage_options' ) ) $allow_refresh = true; break; } if ( ! $allow_refresh ) { wp_send_json_error(['message' => __('Permission denied.', 'deal-scraper-plugin'), 'log' => ['Refresh blocked by setting: '.$refresh_access]], 403); return; } $result = dsp_run_deal_fetch_cron(true); $db_result_all = DSP_DB_Handler::get_deals(['orderby' => 'first_seen', 'order' => 'DESC', 'items_per_page' => -1]); $last_successful_run_time = dsp_get_last_successful_run_timestamp(); $processed_deals_all = dsp_process_deals_for_ajax( $db_result_all['deals'] ?? [], $last_successful_run_time ); $response_data = [ 'log' => $result['log'] ?? [], 'message' => '', 'deals' => $processed_deals_all, 'total_items' => $db_result_all['total_deals'] ?? 0, 'last_fetch' => $last_successful_run_time ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_successful_run_time ) : __('Never', 'deal-scraper-plugin') ]; if (isset($result['error'])) { $response_data['message'] = $result['error']; wp_send_json_error($response_data); } elseif (isset($result['error_summary'])) { $response_data['message'] = $result['error_summary']; wp_send_json_success($response_data); } else { $response_data['message'] = sprintf(__('Refresh successful. Processed %d sites. Found %d new deals.', 'deal-scraper-plugin'), $result['sites_processed'] ?? 0, $result['new_deals_count'] ?? 0); wp_send_json_success($response_data); }
}

add_action( 'wp_ajax_dsp_subscribe_email', 'dsp_ajax_subscribe_email_handler' );
add_action( 'wp_ajax_nopriv_dsp_subscribe_email', 'dsp_ajax_subscribe_email_handler' );
/** Handles AJAX requests for users subscribing via the frontend modal. */
function dsp_ajax_subscribe_email_handler() { /* ... Function content remains the same ... */
    check_ajax_referer( 'dsp_ajax_nonce', 'nonce' ); $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args($options, $defaults); if ( !(bool) $merged_options['email_enabled'] ) { wp_send_json_error(['message' => __('Email notifications are currently disabled.', 'deal-scraper-plugin')], 403); return; } if ( ! isset( $_POST['email'] ) || empty( trim( $_POST['email'] ) ) ) { wp_send_json_error(['message' => __('Please enter an email address.', 'deal-scraper-plugin')], 400); return; } $email_to_add = sanitize_email( trim( $_POST['email'] ) ); if ( ! is_email( $email_to_add ) ) { wp_send_json_error(['message' => __('Invalid email address provided.', 'deal-scraper-plugin')], 400); return; } $current_recipients = is_array( $merged_options['email_recipients'] ) ? $merged_options['email_recipients'] : []; $email_exists = false; foreach ($current_recipients as $existing_email) { if (strcasecmp($existing_email, $email_to_add) === 0) { $email_exists = true; break; } } if ( $email_exists ) { wp_send_json_success(['message' => __('This email address is already subscribed.', 'deal-scraper-plugin')]); return; } $current_recipients[] = $email_to_add; $merged_options['email_recipients'] = array_values(array_unique($current_recipients)); $update_result = update_option( DSP_OPTION_NAME, $merged_options ); if ( $update_result ) { wp_send_json_success(['message' => __('Successfully subscribed!', 'deal-scraper-plugin')]); } else { $options_after = get_option(DSP_OPTION_NAME); if (is_array($options_after) && isset($options_after['email_recipients']) && is_array($options_after['email_recipients'])) { $already_in = false; foreach($options_after['email_recipients'] as $existing_after) { if (strcasecmp($existing_after, $email_to_add) === 0) {$already_in = true; break;} } if ($already_in) { wp_send_json_success(['message' => __('Already subscribed (verified).', 'deal-scraper-plugin')]); return; } } error_log("DSP Subscribe Error: update_option failed for " . DSP_OPTION_NAME); wp_send_json_error(['message' => __('Subscription failed (server error).', 'deal-scraper-plugin')], 500); }
}

// Admin Handlers
add_action( 'wp_ajax_dsp_send_manual_email', 'dsp_ajax_send_manual_email_handler' );
/** Handles AJAX request from admin settings to manually send an email digest. */
function dsp_ajax_send_manual_email_handler() { /* ... Function content remains the same ... */
    check_ajax_referer( 'dsp_admin_ajax_nonce', 'nonce' ); if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( ['message' => __( 'Permission denied.', 'deal-scraper-plugin' )], 403 ); return; } $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args( $options, $defaults ); $recipients = $merged_options['email_recipients'] ?? []; if ( ! is_array( $recipients ) || empty( $recipients ) ) { wp_send_json_error( ['message' => __( 'No recipients configured.', 'deal-scraper-plugin' )] ); return; } $valid_recipients = array_filter( $recipients, 'is_email' ); if ( empty( $valid_recipients ) ) { wp_send_json_error( ['message' => __( 'No valid recipients found.', 'deal-scraper-plugin' )] ); return; } $deals_data = DSP_DB_Handler::get_deals(['orderby' => 'first_seen', 'order' => 'DESC', 'items_per_page'=> 10]); $deals = $deals_data['deals'] ?? []; if ( empty( $deals ) ) { wp_send_json_success( ['message' => __( 'No deals found in the database to send.', 'deal-scraper-plugin' )] ); return; } if ( ! function_exists('dsp_format_deals_email') ) { wp_send_json_error( ['message' => __( 'Email format function missing.', 'deal-scraper-plugin' )], 500 ); return; } $email_subject = sprintf( __( '%s Deal Digest (Manual Send)', 'deal-scraper-plugin' ), get_bloginfo( 'name' ) ); $email_body_html = dsp_format_deals_email($deals); if (empty($email_body_html)) { wp_send_json_error( ['message' => __( 'Failed to generate email content.', 'deal-scraper-plugin' )] ); return; } $headers = ['Content-Type: text/html; charset=UTF-8']; $site_name = get_bloginfo('name'); $site_domain = wp_parse_url(home_url(), PHP_URL_HOST); if (!$site_domain) $site_domain = $_SERVER['SERVER_NAME'] ?? 'localhost'; $from_email = 'wordpress@' . $site_domain; $headers[] = "From: {$site_name} <{$from_email}>"; $sent_count = 0; $fail_count = 0; foreach ( $valid_recipients as $recipient_email ) { $unsubscribe_link = dsp_generate_unsubscribe_link($recipient_email); $final_email_body = $email_body_html . dsp_get_unsubscribe_footer_html($unsubscribe_link); $sent = wp_mail( $recipient_email, $email_subject, $final_email_body, $headers ); if ($sent) $sent_count++; else $fail_count++; } if ( $sent_count > 0 && $fail_count === 0 ) { wp_send_json_success( [ 'message' => sprintf( _n( 'Manual email sent to %d recipient.', 'Manual email sent to %d recipients.', $sent_count, 'deal-scraper-plugin' ), $sent_count ) ] ); } elseif ( $sent_count > 0 && $fail_count > 0 ) { wp_send_json_success( [ 'message' => sprintf( __( 'Sent to %d, failed for %d. Check logs.', 'deal-scraper-plugin' ), $sent_count, $fail_count ) ] ); } else { global $phpmailer; $error_info = isset($phpmailer) && $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ? $phpmailer->ErrorInfo : ''; error_log("DSP Manual Email Error: Failed sending. Last wp_mail error: " . $error_info); wp_send_json_error( [ 'message' => __( 'Failed to send emails. Check logs/WP mail config.', 'deal-scraper-plugin' ) . ($error_info ? ' Last Error: ' . esc_html($error_info) : '') ] ); }
}

add_action( 'wp_ajax_dsp_test_parser', 'dsp_ajax_test_parser_handler' );
/** Handles AJAX requests from admin settings to test a specific parser. */
function dsp_ajax_test_parser_handler() { /* ... Function content remains the same ... */
    check_ajax_referer( 'dsp_admin_ajax_nonce', 'nonce' ); if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( ['message' => __( 'Permission denied.', 'deal-scraper-plugin' )], 403 ); return; } $url = isset($_POST['url']) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : ''; $parser_file = isset($_POST['parser_file']) ? sanitize_file_name( strtolower( wp_unslash( $_POST['parser_file'] ) ) ) : ''; $parser_file = preg_replace('/[^a-z0-9_]/', '', $parser_file); $site_name = isset($_POST['site_name']) ? sanitize_text_field( wp_unslash( $_POST['site_name'] ) ) : 'Test Site'; if ( empty($url) || empty($parser_file) ) { wp_send_json_error( ['message' => __( 'Missing URL or Parser File name.', 'deal-scraper-plugin' )] ); return; } $parser_func = 'parse_' . $parser_file . '_php'; if (!function_exists($parser_func)) { wp_send_json_error( ['message' => sprintf( __( 'Parser function %s not found. Ensure file exists in `/includes/parsers/` and function is named correctly.', 'deal-scraper-plugin'), '<code>' . esc_html($parser_func) . '</code>' ) ] ); return; } $args = [ 'timeout' => 30, 'user-agent' => 'Mozilla/5.0 (WordPress Plugin Test; +'. home_url() .')', 'sslverify' => false, 'headers' => [ 'Accept' => 'text/html,application/xhtml+xml;q=0.9', 'Accept-Language' => 'en-US,en;q=0.5', 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ] ]; $response = wp_remote_get( $url, $args ); if ( is_wp_error( $response ) ) { wp_send_json_error( ['message' => sprintf(__( 'Fetch Error: %s', 'deal-scraper-plugin' ), $response->get_error_message() ) ] ); return; } $status_code = wp_remote_retrieve_response_code( $response ); $html_body = wp_remote_retrieve_body( $response ); if ( $status_code >= 400 || empty( $html_body ) ) { wp_send_json_error( ['message' => sprintf( __( 'HTTP Error: %d received from %s', 'deal-scraper-plugin'), $status_code, esc_html($url) ) ] ); return; } try { $start_time = microtime(true); $site_deals = call_user_func($parser_func, $html_body, $url, $site_name); $duration = microtime(true) - $start_time; if (is_array($site_deals)) { $deal_count = count($site_deals); $message = sprintf( _n( 'Test successful! Found %d deal in %.2f seconds.', 'Test successful! Found %d deals in %.2f seconds.', $deal_count, 'deal-scraper-plugin' ), $deal_count, $duration ); if ($deal_count > 0 && isset($site_deals[0]['title'])) { $message .= ' ' . sprintf(__(' Example: %s', 'deal-scraper-plugin'), '<em>' . esc_html(wp_trim_words($site_deals[0]['title'], 10, '...')) . '</em>'); } elseif ($deal_count === 0) { $message .= ' ' . __('(Make sure selectors are correct if deals were expected.)', 'deal-scraper-plugin'); } wp_send_json_success( ['message' => $message] ); } else { wp_send_json_error( ['message' => sprintf( __( 'Parse Error: Parser function %s did not return an array.', 'deal-scraper-plugin' ), '<code>' . esc_html($parser_func) . '</code>' ) ] ); } } catch (Exception $e) { wp_send_json_error( ['message' => sprintf( __( 'Parse Exception: %s', 'deal-scraper-plugin' ), $e->getMessage() ) ] ); }
}

// *** NEW AJAX ACTION: Update single source setting ***
add_action( 'wp_ajax_dsp_update_source_setting', 'dsp_ajax_update_source_setting_handler' );
/** Handles AJAX requests from admin to update 'enabled' or 'parser_file' for a single source. */
function dsp_ajax_update_source_setting_handler() {
    // Security checks
    check_ajax_referer( 'dsp_admin_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( ['message' => __( 'Permission denied.', 'deal-scraper-plugin' )], 403 );
        return;
    }

    // Get and validate inputs
    $source_index = isset( $_POST['source_index'] ) ? absint( $_POST['source_index'] ) : -1;
    $setting_key = isset( $_POST['setting_key'] ) ? sanitize_key( $_POST['setting_key'] ) : '';
    // Don't use wp_unslash here, it's already handled by WordPress for $_POST
    $setting_value = isset( $_POST['setting_value'] ) ? $_POST['setting_value'] : null;

    $allowed_keys = ['enabled', 'parser_file'];
    if ( $source_index < 0 || ! in_array( $setting_key, $allowed_keys, true ) || $setting_value === null ) {
        wp_send_json_error( ['message' => __( 'Invalid input data provided.', 'deal-scraper-plugin' )] );
        return;
    }

    // Get current options
    wp_cache_delete( DSP_OPTION_NAME, 'options' ); // Ensure fresh read
    $options = get_option( DSP_OPTION_NAME, [] );
    $defaults = dsp_get_default_config();
    $merged_options = wp_parse_args($options, $defaults);
    $sites = $merged_options['sites'] ?? [];

    // Check if index exists
    if ( ! isset( $sites[ $source_index ] ) || ! is_array( $sites[ $source_index ] ) ) {
        wp_send_json_error( ['message' => __( 'Source index not found.', 'deal-scraper-plugin' )] );
        return;
    }

    // Validate and sanitize the specific value based on the key
    $validated_value = null;
    $needs_transient_clear = false;

    if ($setting_key === 'enabled') {
        // Value should be 'true' or 'false' string from JS checkbox, convert to boolean
        $validated_value = filter_var($setting_value, FILTER_VALIDATE_BOOLEAN);
        // Check if the value actually changed
        if ( (bool)($sites[$source_index]['enabled'] ?? false) !== $validated_value ) {
            $needs_transient_clear = true; // Clear transient if enabled status changes
        }
    } elseif ($setting_key === 'parser_file') {
        $validated_value = sanitize_key($setting_value); // Sanitize like in main settings save
        if ( empty( $validated_value ) ) {
            wp_send_json_error( ['message' => __( 'Parser file cannot be empty.', 'deal-scraper-plugin' )] );
            return;
        }
        // Check if parser file actually exists physically
        $parser_path = DSP_PLUGIN_DIR . 'includes/parsers/' . $validated_value . '.php';
        if ( ! file_exists($parser_path) ) {
            wp_send_json_error( ['message' => sprintf(__('Parser file `%s` not found.', 'deal-scraper-plugin'), esc_html($validated_value.'.php'))] );
            return;
        }
    }

    // Update the value in the options array
    $sites[ $source_index ][ $setting_key ] = $validated_value;
    $merged_options['sites'] = $sites; // Put the modified sites array back

    // Save the entire options array back using the direct DB update method
    global $wpdb;
    $serialized_data = maybe_serialize($merged_options);
    if ($serialized_data === false && !is_scalar($merged_options)) {
        error_log("DSP AJAX Update Setting Error: Failed to serialize options data.");
        wp_send_json_error( ['message' => __( 'Failed to prepare data for saving.', 'deal-scraper-plugin' )] );
        return;
    }

    $result = $wpdb->update( $wpdb->options, ['option_value' => $serialized_data], ['option_name' => DSP_OPTION_NAME], ['%s'], ['%s'] );

    if ($result !== false) {
        // Clear WP object cache for this option
        wp_cache_delete( DSP_OPTION_NAME, 'options' );
        // Clear our source list transient if needed
        if ($needs_transient_clear) {
            delete_transient( DSP_SOURCE_LIST_TRANSIENT );
            error_log("DSP AJAX Update Setting: Enabled status changed, cleared transient '" . DSP_SOURCE_LIST_TRANSIENT . "'.");
        }
        wp_send_json_success( ['message' => __( 'Setting saved!', 'deal-scraper-plugin' )] );
    } else {
        error_log("DSP AJAX Update Setting Error: \$wpdb->update failed for " . DSP_OPTION_NAME . ". DB Error: " . $wpdb->last_error);
        wp_send_json_error( ['message' => __( 'Failed to save setting to database.', 'deal-scraper-plugin' )] );
    }
}

// *** NEW AJAX ACTION: Delete single source ***
add_action( 'wp_ajax_dsp_delete_source', 'dsp_ajax_delete_source_handler' );
/** Handles AJAX requests from admin to delete a single source. */
function dsp_ajax_delete_source_handler() {
    // Security checks
    check_ajax_referer( 'dsp_admin_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( ['message' => __( 'Permission denied.', 'deal-scraper-plugin' )], 403 );
        return;
    }

    // Get and validate index
    $source_index = isset( $_POST['source_index'] ) ? absint( $_POST['source_index'] ) : -1;
    if ( $source_index < 0 ) {
        wp_send_json_error( ['message' => __( 'Invalid source index provided.', 'deal-scraper-plugin' )] );
        return;
    }

    // Get current options
    wp_cache_delete( DSP_OPTION_NAME, 'options' );
    $options = get_option( DSP_OPTION_NAME, [] );
    $defaults = dsp_get_default_config();
    $merged_options = wp_parse_args($options, $defaults);
    $sites = $merged_options['sites'] ?? [];

    // Check if index exists
    if ( ! isset( $sites[ $source_index ] ) ) {
        // It might have been deleted by another concurrent request or already gone
        // Send success anyway to allow JS to remove the row
        wp_send_json_success( ['message' => __( 'Source already deleted or not found.', 'deal-scraper-plugin' )] );
        return;
    }

    // Remove the site at the specified index
    unset( $sites[ $source_index ] );

    // Re-index the array numerically to avoid potential issues with non-sequential keys
    $merged_options['sites'] = array_values( $sites );

    // Save the modified options array back using the direct DB update method
    global $wpdb;
    $serialized_data = maybe_serialize($merged_options);
    if ($serialized_data === false && !is_scalar($merged_options)) {
        error_log("DSP AJAX Delete Source Error: Failed to serialize options data.");
        wp_send_json_error( ['message' => __( 'Failed to prepare data for saving.', 'deal-scraper-plugin' )] );
        return;
    }

    $result = $wpdb->update( $wpdb->options, ['option_value' => $serialized_data], ['option_name' => DSP_OPTION_NAME], ['%s'], ['%s'] );

    if ($result !== false) {
        // Clear WP object cache and our transient
        wp_cache_delete( DSP_OPTION_NAME, 'options' );
        delete_transient( DSP_SOURCE_LIST_TRANSIENT );
        error_log("DSP AJAX Delete Source: Source deleted, cleared transient '" . DSP_SOURCE_LIST_TRANSIENT . "'.");
        wp_send_json_success( ['message' => __( 'Source deleted.', 'deal-scraper-plugin' )] );
    } else {
        error_log("DSP AJAX Delete Source Error: \$wpdb->update failed for " . DSP_OPTION_NAME . ". DB Error: " . $wpdb->last_error);
        wp_send_json_error( ['message' => __( 'Failed to save changes to database.', 'deal-scraper-plugin' )] );
    }
}
// *** END NEW AJAX ACTIONS ***


// --- Helper Functions ---
/** Checks if a deal object likely represents a lifetime deal. */
function dsp_is_lifetime_deal_php($deal_obj) {
    // Function remains the same
    if (!is_object($deal_obj)) return false; if (isset($deal_obj->is_ltd)) { return (bool)$deal_obj->is_ltd; } $t_check = isset($deal_obj->title) && is_string($deal_obj->title) && stripos($deal_obj->title,'lifetime') !== false; $p_check = isset($deal_obj->price) && is_string($deal_obj->price) && stripos($deal_obj->price,'lifetime') !== false; return $t_check || $p_check;
}

/** Finds the timestamp of the most recent successful run of any source. */
function dsp_get_last_successful_run_timestamp() {
    // Function remains the same
    $sites = dsp_get_config(); $latest_success_time = 0; if ( is_array( $sites ) ) { foreach ( $sites as $site_data ) { $run_time = (int) ($site_data['last_run_time'] ?? 0); $status = strtolower( $site_data['last_status'] ?? '' ); if ( $run_time > $latest_success_time && strpos( $status, 'success' ) === 0 ) { $latest_success_time = $run_time; } } } $global_last_fetch = get_option('dsp_last_fetch_time', 0); return max($latest_success_time, (int)$global_last_fetch);
}

// --- Add Dark Mode Script to Head ---
add_action( 'wp_head', 'dsp_add_dark_mode_head_script' );
/** Adds an inline script to the head to apply dark mode class early. */
function dsp_add_dark_mode_head_script() {
    // Function remains the same
    if ( is_admin() ) return; $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $mode = isset( $options['dark_mode_default'] ) ? $options['dark_mode_default'] : $defaults['dark_mode_default']; if ($mode !== 'dark' && $mode !== 'auto') return; $dark_mode_class = 'dsp-theme-dark'; ?> <script> (function() { try { const mode = '<?php echo esc_js( $mode ); ?>'; const className = '<?php echo esc_js( $dark_mode_class ); ?>'; let applyDark = false; if (mode === 'dark') { applyDark = true; } else if (mode === 'auto') { const hour = new Date().getHours(); if (hour >= 18 || hour < 6) { applyDark = true; } } if (applyDark) { document.documentElement.classList.add(className); } else { document.documentElement.classList.remove(className); } } catch (e) { console.error('DSP Dark Mode Script Error:', e); } })(); </script> <?php
}


// --- Scheduled Email & Unsubscribe Functions ---
// (Included via require_once 'includes/email-handler.php')

// --- Admin Action for Export (Creates Zip Archive) ---
/** Handles the source configuration export action. */
function dsp_handle_export_sources() {
    // Function remains the same
    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'dsp_export_sources_nonce' ) ) { wp_die( __( 'Invalid nonce.', 'deal-scraper-plugin' ) ); } if ( ! current_user_can( 'manage_options' ) ) { wp_die( __( 'Permission denied.', 'deal-scraper-plugin' ) ); } if ( ! class_exists( 'ZipArchive' ) ) { wp_die( __( 'Error: ZipArchive PHP extension is required for export but is not enabled on this server. Please contact your hosting provider.', 'deal-scraper-plugin' ) ); } $options = get_option( DSP_OPTION_NAME, [] ); $sites_config = $options['sites'] ?? []; $export_sites_data = []; $used_parser_files = []; if (is_array($sites_config)) { foreach ($sites_config as $site) { if (is_array($site) && !empty($site['name']) && !empty($site['url']) && !empty($site['parser_file'])) { $export_sites_data[] = [ 'name' => $site['name'], 'url' => $site['url'], 'parser_file' => $site['parser_file'], 'enabled' => isset($site['enabled']) ? (bool)$site['enabled'] : false, ]; if (!in_array($site['parser_file'], $used_parser_files)) { $used_parser_files[] = $site['parser_file']; } } } } $json_data = wp_json_encode( $export_sites_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); if ( $json_data === false ) { wp_die( __( 'Error: Could not encode source data to JSON.', 'deal-scraper-plugin' ) ); } $filename = 'deal-scraper-export-' . date('Y-m-d') . '.zip'; $upload_dir = wp_upload_dir(); $temp_zip_path = trailingslashit($upload_dir['basedir']) . $filename; $zip = new ZipArchive(); $res = $zip->open( $temp_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ); if ( $res !== true ) { wp_die( __( 'Error: Could not create Zip archive. Code: ', 'deal-scraper-plugin' ) . $res ); } $zip->addFromString( 'sources.json', $json_data ); if (!empty($used_parser_files)) { $zip->addEmptyDir('parsers'); $parser_base_dir = DSP_PLUGIN_DIR . 'includes/parsers/'; foreach ($used_parser_files as $parser_filename_base) { $parser_file_php = $parser_filename_base . '.php'; $full_parser_path = $parser_base_dir . $parser_file_php; if ( file_exists($full_parser_path) && is_readable($full_parser_path) ) { $zip->addFile( $full_parser_path, 'parsers/' . $parser_file_php ); } else { error_log("DSP Export Warning: Parser file not found or not readable: " . $full_parser_path); } } } $zip->close(); if ( ! file_exists( $temp_zip_path ) ) { wp_die( __( 'Error: Failed to save the Zip archive to the temporary location.', 'deal-scraper-plugin' ) ); } header( 'Content-Type: application/zip' ); header( 'Content-Disposition: attachment; filename="' . $filename . '"' ); header( 'Content-Length: ' . filesize( $temp_zip_path ) ); header( 'Pragma: no-cache' ); header( 'Expires: 0' ); readfile( $temp_zip_path ); unlink( $temp_zip_path ); exit;
}
add_action( 'admin_action_dsp_export_sources', 'dsp_handle_export_sources' );

?>