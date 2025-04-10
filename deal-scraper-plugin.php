<?php
/**
 * Plugin Name:       Deal Scraper Plugin
 * Plugin URI:        https://none.com
 * Description:       Scrapes deal websites and displays them via a shortcode. Includes debug logging, dark mode, email subscription. [deal_scraper_display]
 * Version:           1.1.3 // Add background check AJAX for instant load
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

// --- Defines ---
define( 'DSP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DSP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DSP_FETCH_INTERVAL_SECONDS', 24 * 60 * 60 ); // Default: 24 hours
define( 'DSP_CRON_HOOK', 'dsp_periodic_deal_fetch' );
define( 'DSP_OPTION_NAME', 'dsp_settings' ); // Option name for settings
define( 'DSP_EMAIL_CRON_HOOK', 'dsp_daily_email_check' ); // New hook for email checks
define( 'DSP_LAST_EMAIL_OPTION', 'dsp_last_email_send_time' ); // Option for last send time
// IMPORTANT: Replace the default salt with your own random string for security!
// You can generate one here: https://api.wordpress.org/secret-key/1.1/salt/
define( 'DSP_UNSUBSCRIBE_SALT', 'dSP_uNSub_sAlT_kEy_r3Pl4c3_w1tH_s0m3Th1nG_r4nd0m' ); // Unique salt - CHANGE THIS!

// --- Load Required Files FIRST ---
require_once DSP_PLUGIN_DIR . 'includes/db-handler.php';
require_once DSP_PLUGIN_DIR . 'includes/parser-helpers.php'; // Load Helpers
require_once DSP_PLUGIN_DIR . 'includes/cron-handler.php';
require_once DSP_PLUGIN_DIR . 'admin/settings-page.php'; // Include settings page
require_once DSP_PLUGIN_DIR . 'includes/shortcode-handler.php'; // Includes dsp_render_shortcode
require_once DSP_PLUGIN_DIR . 'includes/email-handler.php'; // Need email formatting logic

// --- Autoload Individual Parsers ---
$parser_dir = DSP_PLUGIN_DIR . 'includes/parsers/';
if ( is_dir( $parser_dir ) ) {
    $parser_files = glob( $parser_dir . '*.php' );
    if ( $parser_files ) {
        foreach ( $parser_files as $parser_file ) { require_once $parser_file; }
        // error_log("DSP Log: Loaded " . count($parser_files) . " parser files.");
    } else { error_log("DSP Log: No parser files found in " . $parser_dir); }
} else { error_log("DSP Error: Parsers directory not found at " . $parser_dir); }


// --- Configuration Functions ---
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
            if ( is_array($site_data) && isset($site_data['name'], $site_data['url'], $site_data['parser_file'], $site_data['enabled']) &&
                 is_string($site_data['name']) && $site_data['name'] !== '' && is_string($site_data['url']) && $site_data['url'] !== '' &&
                 is_string($site_data['parser_file']) && $site_data['parser_file'] !== '' ) {
                $site_data['enabled'] = (bool) $site_data['enabled']; $validated_sites[$key] = $site_data;
            } else { error_log("DSP get_config Warning: Invalid site structure for key '{$key}'. Discarding."); }
        }
    }
    if (empty($validated_sites)) { error_log("DSP get_config Warning: No valid sites found. Returning defaults."); return $defaults['sites']; }
    return $validated_sites;
}


// --- Activation / Deactivation Hooks ---
register_activation_hook( __FILE__, 'dsp_activate_plugin' );
function dsp_activate_plugin() {
    DSP_DB_Handler::create_table();
    if ( ! wp_next_scheduled( DSP_CRON_HOOK ) ) { wp_schedule_event( time(), 'dsp_fetch_interval', DSP_CRON_HOOK ); }
    if ( ! wp_next_scheduled( DSP_EMAIL_CRON_HOOK ) ) { wp_schedule_event( time() + 300, 'daily', DSP_EMAIL_CRON_HOOK ); }
    $existing_options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args( $existing_options, $defaults );
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

// --- WP Cron Scheduling Functions ---
add_filter( 'cron_schedules', 'dsp_add_cron_interval' );
function dsp_add_cron_interval( $schedules ) { $interval = apply_filters('dsp_fetch_interval_seconds', DSP_FETCH_INTERVAL_SECONDS); if ($interval < 60) $interval = 60; $schedules['dsp_fetch_interval'] = ['interval' => $interval, 'display' => esc_html__( 'Deal Scraper Fetch Interval', 'deal-scraper-plugin' )]; return $schedules; }
add_action( DSP_CRON_HOOK, 'dsp_run_deal_fetch_cron' );
add_action( DSP_EMAIL_CRON_HOOK, 'dsp_check_and_send_scheduled_email' );


// --- Shortcode Registration ---
add_shortcode( 'deal_scraper_display', 'dsp_render_shortcode' );

// --- Enqueue Frontend Scripts and Styles ---
add_action( 'wp_enqueue_scripts', 'dsp_enqueue_assets' );
function dsp_enqueue_assets() {
    global $post;
    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'deal_scraper_display' ) ) {
        $plugin_version = '1.1.3'; // Match current version
        wp_enqueue_style( 'dsp-style', DSP_PLUGIN_URL . 'assets/css/deal-display.css', [], $plugin_version );
        wp_enqueue_script( 'dsp-script', DSP_PLUGIN_URL . 'assets/js/deal-display.js', ['jquery'], $plugin_version, true );

        // Get ALL settings for localization
        $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args($options, $defaults);
        // Get Enabled Sources using the robust function
        $enabled_sources = []; $validated_sites = dsp_get_config();
        if ( is_array($validated_sites) && !empty($validated_sites) ) {
            foreach ($validated_sites as $site_key => $site_data) {
                if ( !empty($site_data['enabled']) && isset($site_data['name']) && $site_data['name'] !== '' ) { $enabled_sources[] = $site_data['name']; }
            }
        }
        sort($enabled_sources);

        // Prepare data for JS
        $localize_data = [
            'ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'dsp_ajax_nonce' ),
            // Text strings
            'loading_text' => __('Loading deals...', 'deal-scraper-plugin'), 'checking_text' => __('Checking for new deals...', 'deal-scraper-plugin'),
            'error_text' => __('Error loading deals.', 'deal-scraper-plugin'), 'error_check_text' => __('Error checking for new deals.', 'deal-scraper-plugin'),
            'error_check_ajax_text' => __('Error checking for new deals (AJAX).', 'deal-scraper-plugin'), 'never_text' => __('Never', 'deal-scraper-plugin'),
            'last_updated_text' => __('Last fetched:', 'deal-scraper-plugin'), 'refreshing_text' => __('Refreshing...', 'deal-scraper-plugin'),
            'show_log_text' => __('Show Debug Log', 'deal-scraper-plugin'), 'hide_log_text' => __('Hide Debug Log', 'deal-scraper-plugin'),
            'no_deals_found_text' => __('No deals found matching criteria.', 'deal-scraper-plugin'),
            'refresh_finished_text' => __('Refresh finished.', 'deal-scraper-plugin'), 'error_refresh_ajax_text' => __('Refresh failed (AJAX Error).', 'deal-scraper-plugin'),
            'error_refresh_invalid_resp_text' => __('Refresh failed (Invalid Response).', 'deal-scraper-plugin'),
            'yes_text' => __('Yes', 'deal-scraper-plugin'), 'no_text' => __('No', 'deal-scraper-plugin'),
            'subscribe_invalid_email_format' => __('Please enter a valid email format.', 'deal-scraper-plugin'),
            'subscribe_enter_email' => __('Please enter an email address.', 'deal-scraper-plugin'),
            'subscribe_error_generic' => __('Subscription failed. Please try again later.', 'deal-scraper-plugin'),
            'subscribe_error_network' => __('Subscription failed due to a network error.', 'deal-scraper-plugin'),
            'check_complete_text' => __('Check complete.', 'deal-scraper-plugin'), // Added for new handler
            'new_deals_found_single_text' => __('Check complete. Found %d new deal.', 'deal-scraper-plugin'), // Added for new handler (singular)
            'new_deals_found_plural_text' => __('Check complete. Found %d new deals.', 'deal-scraper-plugin'), // Added for new handler (plural)
            'no_new_deals_text' => __('Check complete. No new deals found.', 'deal-scraper-plugin'), // Added for new handler

            // Config data
            'config_sources' => $enabled_sources, 'dark_mode_default' => $merged_options['dark_mode_default'],
            'email_notifications_enabled' => (bool) $merged_options['email_enabled'], 'show_debug_button' => (bool) $merged_options['show_debug_button'],
            'refresh_button_access' => $merged_options['refresh_button_access'],
        ];
        wp_localize_script( 'dsp-script', 'dsp_ajax_obj', $localize_data );
    }
}

// --- Enqueue Admin Scripts and Styles ---
add_action( 'admin_enqueue_scripts', 'dsp_enqueue_admin_assets' );
function dsp_enqueue_admin_assets( $hook ) { /* ... admin enqueue logic ... */
    if ( 'settings_page_deal_scraper_settings' !== $hook ) { return; }
    $plugin_version = '1.1.3';
    wp_enqueue_script( 'dsp-admin-script', DSP_PLUGIN_URL . 'assets/js/admin-settings.js', ['jquery', 'wp-util'], $plugin_version, true );
    wp_localize_script( 'dsp-admin-script', 'dsp_admin_ajax_obj', ['ajax_url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'dsp_admin_ajax_nonce' ), 'sending_text' => __( 'Sending...', 'deal-scraper-plugin' ), 'error_text' => __( 'An error occurred.', 'deal-scraper-plugin' ),] );
    $admin_css_path = DSP_PLUGIN_DIR . 'assets/css/admin-style.css'; if (file_exists($admin_css_path)) { wp_enqueue_style( 'dsp-admin-style', DSP_PLUGIN_URL . 'assets/css/admin-style.css', [], $plugin_version ); }
}


// --- AJAX Handlers ---

// Get deals (maybe used by something else, but not primary shortcode load now)
add_action( 'wp_ajax_dsp_get_deals', 'dsp_ajax_get_deals_handler' );
add_action( 'wp_ajax_nopriv_dsp_get_deals', 'dsp_ajax_get_deals_handler' );
function dsp_ajax_get_deals_handler() { /* ... existing handler code ... */
    check_ajax_referer( 'dsp_ajax_nonce', 'nonce' );
    $deals = DSP_DB_Handler::get_deals(['orderby' => 'first_seen', 'order' => 'DESC']);
    $last_fetch_time = get_option('dsp_last_fetch_time', 0); $processed_deals = [];
    if ($deals) {
        foreach ($deals as $deal) {
            if (is_object($deal) && isset($deal->first_seen)) {
                 $first_seen_ts = strtotime($deal->first_seen);
                 $deal->is_new = ($first_seen_ts && $last_fetch_time && $first_seen_ts >= $last_fetch_time);
                 $deal->first_seen_formatted = $first_seen_ts ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $first_seen_ts ) : 'N/A';
                 $deal->is_lifetime = dsp_is_lifetime_deal_php($deal);
                 $processed_deals[] = $deal;
            }
        }
    }
    wp_send_json_success( [ 'deals' => $processed_deals, 'last_fetch' => $last_fetch_time ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_fetch_time ) : __('Never', 'deal-scraper-plugin') ] );
}

// Manual Refresh Button Handler
add_action( 'wp_ajax_dsp_refresh_deals', 'dsp_ajax_refresh_deals_handler' );
add_action( 'wp_ajax_nopriv_dsp_refresh_deals', 'dsp_ajax_refresh_deals_handler' );
function dsp_ajax_refresh_deals_handler() { /* ... existing handler code ... */
    check_ajax_referer( 'dsp_ajax_nonce', 'nonce' );
    // Access check
    $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args($options, $defaults); $refresh_access = $merged_options['refresh_button_access'];
    $allow_refresh = false; switch ( $refresh_access ) { case 'all': $allow_refresh = true; break; case 'logged_in': if ( is_user_logged_in() ) $allow_refresh = true; break; case 'admins': if ( current_user_can( 'manage_options' ) ) $allow_refresh = true; break; }
    if ( ! $allow_refresh ) { wp_send_json_error(['message' => __('Permission denied.', 'deal-scraper-plugin'), 'log' => ['Refresh blocked by setting: '.$refresh_access]], 403); return; }
    // Run cron
    $result = dsp_run_deal_fetch_cron(true);
    // Prepare response
    $response_data = [ 'log' => $result['log'] ?? [], 'message' => '', 'deals' => [], 'last_fetch' => get_option('dsp_last_fetch_time') ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), get_option('dsp_last_fetch_time') ) : __('Never', 'deal-scraper-plugin') ];
    $current_deals = DSP_DB_Handler::get_deals(['orderby' => 'first_seen', 'order' => 'DESC']); $processed_deals = [];
    if ($current_deals) {
        $last_fetch_time = get_option('dsp_last_fetch_time', 0);
        foreach ($current_deals as $deal) {
             if (is_object($deal) && isset($deal->first_seen)) {
                $ts = strtotime($deal->first_seen); $p_deal = clone $deal; $p_deal->is_new = ($ts && $last_fetch_time && $ts >= $last_fetch_time);
                $p_deal->first_seen_formatted = $ts ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $ts ) : 'N/A';
                $p_deal->is_lifetime = dsp_is_lifetime_deal_php($deal); $processed_deals[] = $p_deal;
            }
        }
    }
    $response_data['deals'] = $processed_deals;
    // Send response based on cron result
    if (isset($result['error'])) { $response_data['message'] = $result['error']; wp_send_json_error($response_data); }
    elseif (isset($result['error_summary'])) { $response_data['message'] = $result['error_summary']; wp_send_json_success($response_data); }
    else { $response_data['message'] = sprintf(__('Refresh successful. Processed %d sites. Found %d new deals.', 'deal-scraper-plugin'), $result['sites_processed'] ?? 0, $result['new_deals_count'] ?? 0); wp_send_json_success($response_data); }
}

// Frontend Email Subscription Handler
add_action( 'wp_ajax_dsp_subscribe_email', 'dsp_ajax_subscribe_email_handler' );
add_action( 'wp_ajax_nopriv_dsp_subscribe_email', 'dsp_ajax_subscribe_email_handler' );
function dsp_ajax_subscribe_email_handler() { /* ... existing handler code ... */
    check_ajax_referer( 'dsp_ajax_nonce', 'nonce' );
    $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args($options, $defaults);
    if ( !(bool) $merged_options['email_enabled'] ) { wp_send_json_error(['message' => __('Email notifications are currently disabled.', 'deal-scraper-plugin')], 403); return; }
    if ( ! isset( $_POST['email'] ) || empty( trim( $_POST['email'] ) ) ) { wp_send_json_error(['message' => __('Please enter an email address.', 'deal-scraper-plugin')], 400); return; }
    $email_to_add = sanitize_email( trim( $_POST['email'] ) ); if ( ! is_email( $email_to_add ) ) { wp_send_json_error(['message' => __('Invalid email address provided.', 'deal-scraper-plugin')], 400); return; }
    $current_recipients = is_array( $merged_options['email_recipients'] ) ? $merged_options['email_recipients'] : []; $email_exists = false;
    foreach ($current_recipients as $existing_email) { if (strcasecmp($existing_email, $email_to_add) === 0) { $email_exists = true; break; } }
    if ( $email_exists ) { wp_send_json_success(['message' => __('This email address is already subscribed.', 'deal-scraper-plugin')]); return; }
    $current_recipients[] = $email_to_add; $merged_options['email_recipients'] = array_values(array_unique($current_recipients));
    $update_result = update_option( DSP_OPTION_NAME, $merged_options );
    if ( $update_result ) { wp_send_json_success(['message' => __('Successfully subscribed!', 'deal-scraper-plugin')]); }
    else {
        $options_after = get_option(DSP_OPTION_NAME);
        if (is_array($options_after) && isset($options_after['email_recipients']) && in_array($email_to_add, $options_after['email_recipients'])) { wp_send_json_success(['message' => __('Already subscribed (verified).', 'deal-scraper-plugin')]); }
        else { error_log("DSP Subscribe Error: update_option failed."); wp_send_json_error(['message' => __('Subscription failed (server error).', 'deal-scraper-plugin')], 500); }
    }
}

// Admin Manual Email Send Handler
add_action( 'wp_ajax_dsp_send_manual_email', 'dsp_ajax_send_manual_email_handler' );
function dsp_ajax_send_manual_email_handler() { /* ... existing handler code ... */
    check_ajax_referer( 'dsp_admin_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( ['message' => __( 'Permission denied.', 'deal-scraper-plugin' )], 403 ); return; }
    $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args( $options, $defaults ); $recipients = $merged_options['email_recipients'] ?? [];
    if ( ! is_array( $recipients ) || empty( $recipients ) ) { wp_send_json_error( ['message' => __( 'No recipients configured.', 'deal-scraper-plugin' )] ); return; }
    $valid_recipients = array_filter( $recipients, 'is_email' ); if ( empty( $valid_recipients ) ) { wp_send_json_error( ['message' => __( 'No valid recipients found.', 'deal-scraper-plugin' )] ); return; }
    $deals = DSP_DB_Handler::get_deals(['limit' => 10, 'orderby' => 'first_seen', 'order' => 'DESC']);
    if ( empty( $deals ) ) { wp_send_json_success( ['message' => __( 'No recent deals to email.', 'deal-scraper-plugin' )] ); return; }
    if ( ! function_exists('dsp_format_deals_email') ) { wp_send_json_error( ['message' => __( 'Email format function missing.', 'deal-scraper-plugin' )], 500 ); return; }
    $email_subject = sprintf( __( '%s Deal Digest', 'deal-scraper-plugin' ), get_bloginfo( 'name' ) ); $email_body_html = dsp_format_deals_email($deals);
    if (empty($email_body_html)) { wp_send_json_error( ['message' => __( 'Failed to generate email.', 'deal-scraper-plugin' )] ); return; }
    $headers = ['Content-Type: text/html; charset=UTF-8']; $site_name = get_bloginfo('name'); $site_domain = wp_parse_url(home_url(), PHP_URL_HOST); $from_email = 'wordpress@' . $site_domain; $headers[] = "From: {$site_name} <{$from_email}>";
    $sent_count = 0; $fail_count = 0;
    foreach ( $valid_recipients as $recipient_email ) { $unsubscribe_link = dsp_generate_unsubscribe_link($recipient_email); $final_email_body = $email_body_html . dsp_get_unsubscribe_footer_html($unsubscribe_link); $sent = wp_mail( $recipient_email, $email_subject, $final_email_body, $headers ); if ($sent) $sent_count++; else $fail_count++; }
    if ( $sent_count > 0 && $fail_count === 0 ) { wp_send_json_success( [ 'message' => sprintf( _n( 'Email sent to %d recipient.', 'Email sent to %d recipients.', $sent_count, 'deal-scraper-plugin' ), $sent_count ) ] ); }
    elseif ( $sent_count > 0 && $fail_count > 0 ) { wp_send_json_success( [ 'message' => sprintf( __( 'Sent to %d, failed for %d. Check logs.', 'deal-scraper-plugin' ), $sent_count, $fail_count ) ] ); }
    else { global $phpmailer; $error_info = isset($phpmailer) ? $phpmailer->ErrorInfo : ''; wp_send_json_error( [ 'message' => __( 'Failed to send emails. Check logs/WP mail config.', 'deal-scraper-plugin' ) . ($error_info ? ' Last Error: ' . esc_html($error_info) : '') ] ); }
}

// *** NEW AJAX HANDLER for Background Check ***
add_action( 'wp_ajax_dsp_check_for_new_deals', 'dsp_ajax_check_for_new_deals_handler' );
add_action( 'wp_ajax_nopriv_dsp_check_for_new_deals', 'dsp_ajax_check_for_new_deals_handler' ); // Allow non-logged-in users

/**
 * AJAX handler for the automatic background check for new deals after initial page load.
 * Runs the scraping process and returns only newly added deals + full list.
 */
function dsp_ajax_check_for_new_deals_handler() {
    check_ajax_referer( 'dsp_ajax_nonce', 'nonce' );

    // Run the main fetching logic (not manual trigger)
    $result = dsp_run_deal_fetch_cron(false);

    // --- Handle potential blocking errors from cron ---
    if ( isset( $result['error'] ) ) {
        wp_send_json_error([
            'message' => $result['error'], // e.g., "Fetch already running"
            'log' => $result['log'] ?? ['Cron handler returned blocking error.'],
            'new_deals' => [], // Keep structure consistent for JS error handling
            'all_deals' => [],
            'last_fetch' => get_option('dsp_last_fetch_time') ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), get_option('dsp_last_fetch_time') ) : __('Never', 'deal-scraper-plugin'),
        ]);
        return;
    }

    // --- If cron ran, get the state AFTER the run ---
    $last_fetch_time_after_run = get_option('dsp_last_fetch_time', 0);
    // Get all deals, ordered newest first to match initial load if needed
    $all_deals_after_run = DSP_DB_Handler::get_deals(['orderby' => 'first_seen', 'order' => 'DESC']);

    $newly_added_deals = [];
    $processed_all_deals = [];

    if ( $all_deals_after_run ) {
        foreach ( $all_deals_after_run as $deal ) {
            // Basic object validation
            if ( is_object($deal) && isset($deal->first_seen) && isset($deal->title) && isset($deal->link) ) {
                 $first_seen_ts = strtotime($deal->first_seen);

                 // Identify deals added specifically IN THIS RUN
                 $is_new_from_this_run = ($first_seen_ts && $last_fetch_time_after_run && $first_seen_ts >= $last_fetch_time_after_run);

                 // Prepare deal object for JSON response (consistent format)
                 $processed_deal = clone $deal;
                 $processed_deal->is_new = $is_new_from_this_run; // Key for JS: Was it new in *this* check?
                 $processed_deal->first_seen_formatted = $first_seen_ts
                                                         ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $first_seen_ts )
                                                         : 'N/A';
                 $processed_deal->is_lifetime = dsp_is_lifetime_deal_php($deal);

                 // Add to the list of ALL deals returned
                 $processed_all_deals[] = $processed_deal;

                 // If it was identified as new in this run, add to the separate 'new' list
                 if ( $is_new_from_this_run ) {
                    $newly_added_deals[] = $processed_deal;
                 }
            }
        }
    }

    // --- Prepare Success Response ---
    $new_deal_count_this_run = count($newly_added_deals);
    $message = '';
    if (isset($result['error_summary'])) {
        // If cron completed with non-blocking errors, report that
        $message = $result['error_summary'];
    } elseif ($new_deal_count_this_run > 0) {
        // Use _n for proper pluralization
        $message = sprintf(
            _n( 'Check complete. Found %d new deal.', 'Check complete. Found %d new deals.', $new_deal_count_this_run, 'deal-scraper-plugin' ),
            $new_deal_count_this_run
        );
    } else {
         $message = __('Check complete. No new deals found.', 'deal-scraper-plugin');
    }

    $response_data = [
        'new_deals' => $newly_added_deals,   // Only deals added in this specific check
        'all_deals' => $processed_all_deals, // The *complete*, processed list after this check
        'last_fetch' => $last_fetch_time_after_run ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_fetch_time_after_run ) : __('Never', 'deal-scraper-plugin'),
        'log' => $result['log'] ?? [],       // Log from the cron run (JS might ignore this)
        'message' => $message               // User-friendly status message
    ];

    wp_send_json_success( $response_data );
}
// *** END NEW AJAX HANDLER ***


// --- Helper Functions ---
function dsp_is_lifetime_deal_php($deal_obj) { /* ... existing helper code ... */
    if (!is_object($deal_obj)) return false;
    $title_check = isset($deal_obj->title) && is_string($deal_obj->title) && stripos($deal_obj->title, 'lifetime') !== false;
    $price_check = isset($deal_obj->price) && is_string($deal_obj->price) && stripos($deal_obj->price, 'lifetime') !== false;
    return $title_check || $price_check;
}

// --- Scheduled Email & Unsubscribe Functions ---
function dsp_check_and_send_scheduled_email() { /* ... existing function code ... */
    error_log("DSP Log: Running scheduled email check...");
    $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args( $options, $defaults );
    if ( empty( $merged_options['email_enabled'] ) ) { error_log("DSP Log: Scheduled email skipped - Disabled."); return; }
    $frequency = $merged_options['email_frequency'] ?? 'weekly'; $last_send_timestamp = (int) get_option( DSP_LAST_EMAIL_OPTION, 0 ); $current_timestamp = time(); $threshold = 0;
    if ( $frequency === 'weekly' ) { $threshold = strtotime( '+7 days', $last_send_timestamp ); } elseif ( $frequency === 'biweekly' ) { $threshold = strtotime( '+15 days', $last_send_timestamp ); } else { error_log("DSP Log: Scheduled email skipped - Invalid frequency: " . $frequency); return; }
    if ( $current_timestamp < $threshold ) { error_log("DSP Log: Scheduled email skipped - Not time yet. Last: " . ($last_send_timestamp > 0 ? date('Y-m-d H:i:s', $last_send_timestamp) : 'Never') . ", Threshold: " . date('Y-m-d H:i:s', $threshold)); return; }
    error_log("DSP Log: Time to send scheduled email (Frequency: {$frequency}).");
    $recipients = $merged_options['email_recipients'] ?? []; if ( ! is_array( $recipients ) || empty( $recipients ) ) { error_log("DSP Log: Aborted - No recipients."); return; } $valid_recipients = array_filter( $recipients, 'is_email' ); if ( empty( $valid_recipients ) ) { error_log("DSP Log: Aborted - No valid recipients."); return; }
    if (!method_exists('DSP_DB_Handler', 'get_deals_since')) { error_log("DSP Log: Aborted - DB method get_deals_since not found."); return; }
    $last_send_datetime_gmt = $last_send_timestamp > 0 ? gmdate('Y-m-d H:i:s', $last_send_timestamp) : '0000-00-00 00:00:00'; $new_deals = DSP_DB_Handler::get_deals_since($last_send_datetime_gmt);
    if ( empty( $new_deals ) ) { error_log("DSP Log: Aborted - No new deals since " . $last_send_datetime_gmt); update_option( DSP_LAST_EMAIL_OPTION, $current_timestamp, 'no' ); error_log("DSP Log: Updated last email send time anyway."); return; }
    error_log("DSP Log: Found " . count($new_deals) . " new deals.");
    if ( ! function_exists('dsp_format_deals_email') ) { error_log("DSP Log: Aborted - dsp_format_deals_email function not found."); return; }
    $email_subject = sprintf( __( '%s Deal Digest', 'deal-scraper-plugin' ), get_bloginfo( 'name' ) ); $email_body_html = dsp_format_deals_email($new_deals); if (empty($email_body_html)) { error_log("DSP Log: Aborted - Failed to generate email content."); return; }
    $headers = ['Content-Type: text/html; charset=UTF-8']; $site_name = get_bloginfo('name'); $site_domain = wp_parse_url(home_url(), PHP_URL_HOST); $from_email = 'wordpress@' . $site_domain; $headers[] = "From: {$site_name} <{$from_email}>";
    $total_sent = 0; $total_failed = 0;
    foreach ( $valid_recipients as $recipient_email ) { $unsubscribe_link = dsp_generate_unsubscribe_link($recipient_email); $final_email_body = $email_body_html . dsp_get_unsubscribe_footer_html($unsubscribe_link); $sent = wp_mail( $recipient_email, $email_subject, $final_email_body, $headers ); if ($sent) $total_sent++; else $total_failed++; }
    error_log("DSP Log: Scheduled send complete. Sent: {$total_sent}, Failed: {$total_failed}.");
    if ($total_sent > 0 || $total_failed > 0) { update_option( DSP_LAST_EMAIL_OPTION, $current_timestamp, 'no' ); error_log("DSP Log: Updated last email send time to " . date('Y-m-d H:i:s', $current_timestamp)); }
}
function dsp_generate_unsubscribe_token( $email ) { /* ... existing code ... */ $email = strtolower( trim( $email ) ); $secret = wp_salt('auth') . DSP_UNSUBSCRIBE_SALT; return hash_hmac( 'sha256', $email, $secret ); }
function dsp_generate_unsubscribe_link( $email ) { /* ... existing code ... */ $token = dsp_generate_unsubscribe_token( $email ); $args = [ 'dsp_unsubscribe' => 1, 'email' => rawurlencode($email), 'token' => $token, ]; return add_query_arg( $args, home_url( '/' ) ); }
function dsp_get_unsubscribe_footer_html( $unsubscribe_link ) { /* ... existing code ... */ $style='text-align:center;margin-top:20px;padding-top:15px;border-top:1px solid #eee;font-size:12px;color:#888;'; $link_style='color:#069;text-decoration:underline;'; $text = sprintf(__( 'Don\'t want these emails? %s.', 'deal-scraper-plugin'),'<a href="'.esc_url($unsubscribe_link).'" style="'.$link_style.'">'.esc_html__('Unsubscribe here', 'deal-scraper-plugin').'</a>'); return '<div style="'.$style.'"><p>'.$text.'</p></div>'; }
function dsp_handle_unsubscribe_request() { /* ... existing code ... */
    if(!isset($_GET['dsp_unsubscribe'],$_GET['email'],$_GET['token'])) return;
    $email=sanitize_email(rawurldecode($_GET['email'])); $token=sanitize_text_field($_GET['token']);
    if(!is_email($email)||empty($token)) { wp_redirect(home_url()); exit; }
    $expected=dsp_generate_unsubscribe_token($email);
    if(!hash_equals($expected,$token)) { wp_die(esc_html__('Invalid/expired link.','deal-scraper-plugin'),esc_html__('Error','deal-scraper-plugin'),400); }
    $opts=get_option(DSP_OPTION_NAME); if(!is_array($opts)||!isset($opts['email_recipients'])||!is_array($opts['email_recipients'])) { wp_die(esc_html__('Unsubscribed (or not subscribed).','deal-scraper-plugin'),esc_html__('Success','deal-scraper-plugin'),200); }
    $recipients=$opts['email_recipients']; $found=false; $new_recipients=[];
    foreach($recipients as $rec){ if(strcasecmp(trim($rec),$email)!==0) $new_recipients[]=$rec; else $found=true; }
    if($found){ $opts['email_recipients']=array_values($new_recipients); $updated=update_option(DSP_OPTION_NAME,$opts); if($updated) wp_die(esc_html__('Unsubscribed successfully.','deal-scraper-plugin'),esc_html__('Success','deal-scraper-plugin'),200); else wp_die(esc_html__('Error processing request.','deal-scraper-plugin'),esc_html__('Error','deal-scraper-plugin'),500); }
    else { wp_die(esc_html__('Already unsubscribed.','deal-scraper-plugin'),esc_html__('Info','deal-scraper-plugin'),200); }
}
add_action( 'init', 'dsp_handle_unsubscribe_request' );

?>