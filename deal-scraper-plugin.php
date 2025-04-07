<?php
/**
 * Plugin Name:       Deal Scraper Plugin
 * Plugin URI:        https://none.com
 * Description:       Scrapes deal websites and displays them via a shortcode. Includes debug logging, dark mode, email subscription. [deal_scraper_display] or [deal_scraper_display layout="grid"]
 * Version:           1.3.1 // Add Clear Deals Button
 * Author:            ᕦ(ò_óˇ)ᕤ
 * Author URI:        https://none.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       deal-scraper-plugin
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'DSP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DSP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DSP_FETCH_INTERVAL_SECONDS', 24 * 60 * 60 );
define( 'DSP_CRON_HOOK', 'dsp_periodic_deal_fetch' );
define( 'DSP_OPTION_NAME', 'dsp_settings' );
define( 'DSP_ITEMS_PER_PAGE', 30 );

// --- Load Required Files FIRST ---
require_once DSP_PLUGIN_DIR . 'includes/db-handler.php';
require_once DSP_PLUGIN_DIR . 'includes/parsers.php';
require_once DSP_PLUGIN_DIR . 'includes/cron-handler.php';
require_once DSP_PLUGIN_DIR . 'admin/settings-page.php';
require_once DSP_PLUGIN_DIR . 'includes/shortcode-handler.php';

// --- Configuration Functions ---
function dsp_get_default_config() { /* ... same as before ... */ return [ 'managed_sites' => [ "AppSumo" => ["name" => "AppSumo", "url" => "https://appsumo.com/software/?sort=latest", "parser" => "parse_appsumo_php", "enabled" => true], "StackSocial" => ["name" => "StackSocial", "url" => "https://www.stacksocial.com/collections/apps-software?sort=newest", "parser" => "parse_stacksocial_php", "enabled" => true], "DealFuel" => ["name" => "DealFuel", "url" => "https://www.dealfuel.com/product-category/all/?orderby=date", "parser" => "parse_dealfuel_php", "enabled" => true], "DealMirror" => ["name" => "DealMirror", "url" => "https://dealmirror.com/product-category/new-arrivals/", "parser" => "parse_dealmirror_php", "enabled" => true] ], 'email_enabled' => false, 'email_frequency' => 'weekly', 'email_recipients' => [], 'show_debug_button' => true, 'refresh_button_access' => 'all', 'dark_mode_default' => 'light', 'purge_enabled' => false, 'purge_max_age_days' => 90, ]; }
function dsp_get_managed_sites_config() { /* ... same as before ... */ $options = get_option(DSP_OPTION_NAME); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args($options, $defaults); return isset($merged_options['managed_sites']) && is_array($merged_options['managed_sites']) ? $merged_options['managed_sites'] : []; }

// --- Activation / Deactivation Hooks ---
register_activation_hook( __FILE__, 'dsp_activate_plugin' ); function dsp_activate_plugin() { /* ... same as before ... */ DSP_DB_Handler::create_table(); dsp_schedule_cron(); $existing_options = get_option( DSP_OPTION_NAME ); if ($existing_options === false) { update_option( DSP_OPTION_NAME, dsp_get_default_config() ); } else { $defaults = dsp_get_default_config(); $merged_options = wp_parse_args( $existing_options, $defaults ); if (isset($existing_options['managed_sites']) && is_array($existing_options['managed_sites']) && isset($defaults['managed_sites'])) { $merged_options['managed_sites'] = wp_parse_args($existing_options['managed_sites'], $defaults['managed_sites']); } update_option( DSP_OPTION_NAME, $merged_options ); } if ( get_option('dsp_last_fetch_time') === false ) { update_option('dsp_last_fetch_time', 0, 'no'); } }
register_deactivation_hook( __FILE__, 'dsp_deactivate_plugin' ); function dsp_deactivate_plugin() { dsp_unschedule_cron(); }

// --- WP Cron Scheduling Functions ---
function dsp_schedule_cron() { /* ... */ if ( ! wp_next_scheduled( DSP_CRON_HOOK ) ) { wp_schedule_event( time(), 'dsp_fetch_interval', DSP_CRON_HOOK ); } }
function dsp_unschedule_cron() { /* ... */ $timestamp = wp_next_scheduled( DSP_CRON_HOOK ); if ( $timestamp ) { wp_unschedule_event( $timestamp, DSP_CRON_HOOK ); } }
add_filter( 'cron_schedules', 'dsp_add_cron_interval' ); function dsp_add_cron_interval( $schedules ) { /* ... */ $interval = apply_filters('dsp_fetch_interval_seconds', DSP_FETCH_INTERVAL_SECONDS); if ($interval < 60) $interval = 60; $schedules['dsp_fetch_interval'] = array( 'interval' => $interval, 'display' => esc_html__( 'Deal Scraper Fetch Interval', 'deal-scraper-plugin' ) ); return $schedules; }
add_action( DSP_CRON_HOOK, 'dsp_run_deal_fetch_cron' );

// --- Shortcode Registration ---
add_shortcode( 'deal_scraper_display', 'dsp_render_shortcode' );

// --- Enqueue Scripts and Styles ---
add_action( 'wp_enqueue_scripts', 'dsp_enqueue_assets' ); function dsp_enqueue_assets() { /* ... same as before ... */ global $post; if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'deal_scraper_display' ) ) { $plugin_version = '1.3.1'; wp_enqueue_style( 'dsp-style', DSP_PLUGIN_URL . 'assets/css/deal-display.css', [], $plugin_version ); wp_enqueue_script( 'dsp-script', DSP_PLUGIN_URL . 'assets/js/deal-display.js', ['jquery'], $plugin_version, true ); $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args($options, $defaults); $managed_sites = $merged_options['managed_sites'] ?? []; $enabled_sources = []; foreach ($managed_sites as $site_key => $site_details) { if (is_array($site_details) && !empty($site_details['enabled'])) { $enabled_sources[] = (!empty($site_details['name'])) ? $site_details['name'] : $site_key; } } sort($enabled_sources); $items_per_page = defined('DSP_ITEMS_PER_PAGE') ? DSP_ITEMS_PER_PAGE : 15; $localize_data = array( 'ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'dsp_ajax_nonce' ), 'loading_text' => __('Loading deals...', 'deal-scraper-plugin'), 'error_text' => __('Error loading deals.', 'deal-scraper-plugin'), 'never_text' => __('Never', 'deal-scraper-plugin'), 'last_updated_text' => __('Last fetched:', 'deal-scraper-plugin'), 'refreshing_text' => __('Refreshing...', 'deal-scraper-plugin'), 'show_log_text' => __('Show Debug Log', 'deal-scraper-plugin'), 'hide_log_text' => __('Hide Debug Log', 'deal-scraper-plugin'), 'no_deals_found_text' => __('No deals found matching criteria.', 'deal-scraper-plugin'), 'refresh_finished_text' => __('Refresh finished.', 'deal-scraper-plugin'), 'error_refresh_ajax_text' => __('Refresh failed (AJAX Error).', 'deal-scraper-plugin'), 'error_refresh_invalid_resp_text' => __('Refresh failed (Invalid Response).', 'deal-scraper-plugin'), 'yes_text' => __('Yes', 'deal-scraper-plugin'), 'no_text' => __('No', 'deal-scraper-plugin'), 'subscribe_invalid_email_format' => __('Please enter a valid email format.', 'deal-scraper-plugin'), 'subscribe_enter_email' => __('Please enter an email address.', 'deal-scraper-plugin'), 'subscribe_error_generic' => __('Subscription failed. Please try again later.', 'deal-scraper-plugin'), 'subscribe_error_network' => __('Subscription failed due to a network error.', 'deal-scraper-plugin'), 'config_sources' => $enabled_sources, 'dark_mode_default' => $merged_options['dark_mode_default'], 'email_notifications_enabled' => (bool) $merged_options['email_enabled'], 'show_debug_button' => (bool) $merged_options['show_debug_button'], 'refresh_button_access' => $merged_options['refresh_button_access'], 'items_per_page' => $items_per_page, ); wp_localize_script( 'dsp-script', 'dsp_ajax_obj', $localize_data ); } }


// --- AJAX Handlers ---

// Get Deals Handler (Server-Side Pagination Version)
add_action( 'wp_ajax_dsp_get_deals', 'dsp_ajax_get_deals_handler' );
add_action( 'wp_ajax_nopriv_dsp_get_deals', 'dsp_ajax_get_deals_handler' );
function dsp_ajax_get_deals_handler() { /* ... same as before ... */ check_ajax_referer( 'dsp_ajax_nonce', 'nonce' ); $current_page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1; $per_page = defined('DSP_ITEMS_PER_PAGE') ? DSP_ITEMS_PER_PAGE : 15; $orderby = isset($_POST['orderby']) ? sanitize_key($_POST['orderby']) : 'first_seen'; $order = isset($_POST['order']) ? strtoupper(sanitize_key($_POST['order'])) : 'DESC'; $search = isset($_POST['search']) ? sanitize_text_field(trim($_POST['search'])) : ''; $sources = isset($_POST['sources']) && is_array($_POST['sources']) ? array_map('sanitize_text_field', $_POST['sources']) : []; $is_new_only = isset($_POST['is_new_only']) && $_POST['is_new_only'] === 'true'; $last_fetch_time = get_option('dsp_last_fetch_time', 0); $new_since_timestamp = $is_new_only ? $last_fetch_time : 0; $db_args = [ 'search' => $search, 'sources' => $sources, 'is_new_since' => $new_since_timestamp, 'page' => $current_page, 'per_page' => $per_page, 'orderby' => $orderby, 'order' => $order ]; $db_result = DSP_DB_Handler::get_deals($db_args); $deals_for_page = $db_result['deals'] ?? []; $total_items = $db_result['total_items'] ?? 0; $total_pages = ceil($total_items / $per_page); if ($total_pages < 1) $total_pages = 1; $processed_deals = []; if (!empty($deals_for_page)) { foreach ($deals_for_page as $deal) { if (is_object($deal) && isset($deal->first_seen)) { $first_seen_ts = strtotime($deal->first_seen); $p_deal = clone $deal; $p_deal->is_new = ($first_seen_ts && $last_fetch_time && $first_seen_ts >= $last_fetch_time); $p_deal->first_seen_formatted = $first_seen_ts ? date('Y-m-d H:i', $first_seen_ts) : 'N/A'; $p_deal->is_lifetime = dsp_is_lifetime_deal_php($deal); $processed_deals[] = $p_deal; } } } wp_send_json_success( [ 'deals' => $processed_deals, 'total_items' => $total_items, 'total_pages' => $total_pages, 'current_page' => $current_page, 'last_fetch' => $last_fetch_time ? date('Y-m-d H:i:s', $last_fetch_time) : __('Never', 'deal-scraper-plugin') ] ); }

// Refresh Handler (Simplified for Server-Side Pagination)
add_action( 'wp_ajax_dsp_refresh_deals', 'dsp_ajax_refresh_deals_handler' );
add_action( 'wp_ajax_nopriv_dsp_refresh_deals', 'dsp_ajax_refresh_deals_handler' );
function dsp_ajax_refresh_deals_handler() { /* ... same as before ... */ check_ajax_referer( 'dsp_ajax_nonce', 'nonce' ); $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args($options, $defaults); $refresh_access = $merged_options['refresh_button_access']; $allow_refresh = false; switch ( $refresh_access ) { case 'all': $allow_refresh = true; break; case 'logged_in': if ( is_user_logged_in() ) { $allow_refresh = true; } break; case 'admins': if ( current_user_can( 'manage_options' ) ) { $allow_refresh = true; } break; case 'disabled': break; } if ( ! $allow_refresh ) { wp_send_json_error(['message' => __('Permission denied.', 'deal-scraper-plugin'), 'log' => ['Refresh blocked by setting: '.$refresh_access]], 403); return; } $result = dsp_run_deal_fetch_cron(true); $response_data = [ 'log' => $result['log'] ?? ['Log data missing.'], 'message' => '', 'last_fetch' => get_option('dsp_last_fetch_time') ? date('Y-m-d H:i:s', get_option('dsp_last_fetch_time')) : __('Never', 'deal-scraper-plugin') ]; if (isset($result['error'])) { $response_data['message'] = $result['error']; wp_send_json_error($response_data); } elseif (isset($result['error_summary'])) { $response_data['message'] = $result['error_summary']; wp_send_json_success($response_data); } else { $response_data['message'] = sprintf( __('Refresh successful. Processed %d sites. Found %d new deals.', 'deal-scraper-plugin'), $result['sites_processed'] ?? 0, $result['new_deals_count'] ?? 0 ); wp_send_json_success($response_data); } }

// Subscribe Handler (Unchanged)
add_action( 'wp_ajax_dsp_subscribe_email', 'dsp_ajax_subscribe_email_handler' );
add_action( 'wp_ajax_nopriv_dsp_subscribe_email', 'dsp_ajax_subscribe_email_handler' );
function dsp_ajax_subscribe_email_handler() { /* ... same as before ... */ check_ajax_referer( 'dsp_ajax_nonce', 'nonce' ); $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args($options, $defaults); if ( !(bool) $merged_options['email_enabled'] ) { wp_send_json_error(['message' => __('Email notifications are currently disabled by the site administrator.', 'deal-scraper-plugin')], 403); return; } if ( ! isset( $_POST['email'] ) || empty( trim( $_POST['email'] ) ) ) { wp_send_json_error(['message' => __('Please enter an email address.', 'deal-scraper-plugin')], 400); return; } $email_to_add = sanitize_email( trim( $_POST['email'] ) ); if ( ! is_email( $email_to_add ) ) { wp_send_json_error(['message' => __('Invalid email address provided.', 'deal-scraper-plugin')], 400); return; } $current_recipients = is_array( $merged_options['email_recipients'] ) ? $merged_options['email_recipients'] : []; $email_exists = false; foreach ($current_recipients as $existing_email) { if (strcasecmp($existing_email, $email_to_add) === 0) { $email_exists = true; break; } } if ( $email_exists ) { wp_send_json_success(['message' => __('This email address is already subscribed.', 'deal-scraper-plugin')]); return; } $current_recipients[] = $email_to_add; $merged_options['email_recipients'] = array_values(array_unique($current_recipients)); $update_result = update_option( DSP_OPTION_NAME, $merged_options ); if ( $update_result ) { wp_send_json_success(['message' => __('Successfully subscribed! You will receive future deal notifications.', 'deal-scraper-plugin')]); } else { $options_after_attempt = get_option(DSP_OPTION_NAME); if (is_array($options_after_attempt) && isset($options_after_attempt['email_recipients']) && in_array($email_to_add, $options_after_attempt['email_recipients'])) { wp_send_json_success(['message' => __('This email address is already subscribed (verified).', 'deal-scraper-plugin')]); } else { error_log("DSP Subscribe Error: update_option failed for ". DSP_OPTION_NAME); wp_send_json_error(['message' => __('Subscription failed due to a server error. Please try again later.', 'deal-scraper-plugin')], 500); } } }

// -- Admin AJAX Handlers --
add_action( 'wp_ajax_dsp_test_parser', 'dsp_ajax_test_parser_handler' );
function dsp_ajax_test_parser_handler() { /* ... same as before ... */ check_ajax_referer( 'dsp_admin_ajax_nonce', '_ajax_nonce' ); if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( ['message' => __( 'Permission denied.', 'deal-scraper-plugin' )], 403 ); return; } $url = isset( $_POST['url'] ) ? esc_url_raw( trim( $_POST['url'] ) ) : ''; $parser_func = isset( $_POST['parser'] ) ? sanitize_text_field( trim( $_POST['parser'] ) ) : ''; if ( empty( $url ) || filter_var( $url, FILTER_VALIDATE_URL ) === false ) { wp_send_json_error( ['message' => __( 'Invalid or missing URL.', 'deal-scraper-plugin' )], 400 ); return; } if ( empty( $parser_func ) ) { wp_send_json_error( ['message' => __( 'Missing parser function name.', 'deal-scraper-plugin' )], 400 ); return; } if (strpos($parser_func, 'parse_') !== 0 || !ctype_alnum(str_replace('_', '', $parser_func))) { wp_send_json_error( ['message' => sprintf(__('Invalid parser function name format: %s', 'deal-scraper-plugin'), esc_html($parser_func)) ], 400 ); return; } if ( ! function_exists( $parser_func ) ) { wp_send_json_error( ['message' => sprintf( __( 'Parser function "%s" does not exist.', 'deal-scraper-plugin' ), esc_html($parser_func)) ], 404 ); return; } $args = [ 'timeout' => 30, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36', 'sslverify' => false, 'headers' => [ 'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language' => 'en-US,en;q=0.9', 'Accept-Encoding' => 'gzip, deflate', 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ] ]; $response = wp_remote_get( $url, $args ); if ( is_wp_error( $response ) ) { wp_send_json_error( ['message' => sprintf( __( 'Fetch Error: %s', 'deal-scraper-plugin' ), $response->get_error_message() )] ); return; } $status_code = wp_remote_retrieve_response_code( $response ); $html_body = wp_remote_retrieve_body( $response ); if ( $status_code >= 400 ) { wp_send_json_error( ['message' => sprintf( __( 'HTTP Error: %d %s', 'deal-scraper-plugin' ), $status_code, get_status_header_desc( $status_code ) )] ); return; } if ( empty( $html_body ) ) { wp_send_json_error( ['message' => __( 'Fetch Error: Received empty response body.', 'deal-scraper-plugin' )] ); return; } try { $site_deals = call_user_func( $parser_func, $html_body, $url ); if ( is_array( $site_deals ) ) { $deal_count = count( $site_deals ); $message = sprintf( _n( '%d deal found.', '%d deals found.', $deal_count, 'deal-scraper-plugin' ), $deal_count ); if ( $deal_count > 0 && isset($site_deals[0]['title']) ) { $message .= ' ' . sprintf( __('Example: "%s"', 'deal-scraper-plugin'), esc_html( wp_trim_words( $site_deals[0]['title'], 10, '...' ) ) ); } wp_send_json_success( ['message' => $message] ); } else { wp_send_json_error( ['message' => __( 'Parser Error: Function did not return an array.', 'deal-scraper-plugin' )] ); } } catch ( Exception $e ) { wp_send_json_error( ['message' => sprintf( __( 'Parser Exception: %s', 'deal-scraper-plugin' ), $e->getMessage() )] ); } }

// *** NEW: Admin AJAX Handler for Clearing Deals ***
add_action( 'wp_ajax_dsp_clear_all_deals', 'dsp_ajax_clear_all_deals_handler' );

function dsp_ajax_clear_all_deals_handler() {
    // Security Check 1: Nonce
    // Use the same nonce defined for the admin page JS
    check_ajax_referer( 'dsp_admin_ajax_nonce', '_ajax_nonce' );

    // Security Check 2: Capability
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( ['message' => __( 'Permission denied.', 'deal-scraper-plugin' )], 403 );
        return;
    }

    // Call the DB Handler method
    $result = DSP_DB_Handler::clear_all_deals();

    if ( $result === true ) {
        // Also reset the last fetch time as there are no deals now
        update_option('dsp_last_fetch_time', 0, 'no');
        wp_send_json_success( ['message' => __( 'All deals cleared successfully.', 'deal-scraper-plugin' )] );
    } else {
        wp_send_json_error( ['message' => __( 'Error clearing deals. Check PHP error log for DB details.', 'deal-scraper-plugin' )], 500 );
    }
}
// *** END NEW CLEAR DEALS HANDLER ***


// --- Helper Functions ---
function dsp_is_lifetime_deal_php($deal_obj) { /* ... */ if (!is_object($deal_obj)) return false; $title_check = isset($deal_obj->title) && is_string($deal_obj->title) && stripos($deal_obj->title, 'lifetime') !== false; $price_check = isset($deal_obj->price) && is_string($deal_obj->price) && stripos($deal_obj->price, 'lifetime') !== false; return $title_check || $price_check; }

?>