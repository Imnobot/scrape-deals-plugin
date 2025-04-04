<?php
/**
 * Plugin Name:       Deal Scraper Plugin
 * Plugin URI:        https://none.com
 * Description:       Scrapes deal websites and displays them via a shortcode. Includes debug logging, dark mode, email subscription. [deal_scraper_display]
 * Version:           1.0.4 // Version Bump - Frontend Subscribe Feature
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
define( 'DSP_FETCH_INTERVAL_SECONDS', 24 * 60 * 60 ); // Default: 24 hours
define( 'DSP_CRON_HOOK', 'dsp_periodic_deal_fetch' );
define( 'DSP_OPTION_NAME', 'dsp_settings' ); // Option name for settings

// --- Load Required Files FIRST ---
require_once DSP_PLUGIN_DIR . 'includes/db-handler.php';
require_once DSP_PLUGIN_DIR . 'includes/parsers.php';
require_once DSP_PLUGIN_DIR . 'includes/cron-handler.php';
require_once DSP_PLUGIN_DIR . 'admin/settings-page.php'; // Include settings page
require_once DSP_PLUGIN_DIR . 'includes/shortcode-handler.php'; // Includes dsp_render_shortcode

// --- Configuration Functions ---
// Define default settings structure here, including new ones
function dsp_get_default_config() {
     return [
        'sites' => [
             "AppSumo" => ["url" => "https://appsumo.com/software/?sort=latest", "parser" => "parse_appsumo_php", "enabled" => true],
             "StackSocial" => ["url" => "https://www.stacksocial.com/collections/apps-software?sort=newest", "parser" => "parse_stacksocial_php", "enabled" => true],
             "DealFuel" => ["url" => "https://www.dealfuel.com/product-category/all/?orderby=date", "parser" => "parse_dealfuel_php", "enabled" => true],
             "DealMirror" => ["url" => "https://dealmirror.com/product-category/new-arrivals/", "parser" => "parse_dealmirror_php", "enabled" => true]
         ],
         'email_enabled' => false,
         'email_frequency' => 'weekly',
         'email_recipients' => [],
         'show_debug_button' => true,
         'refresh_button_access' => 'all', // Default for refresh button
         'dark_mode_default' => 'light', // Default for dark mode
     ];
}

// Function to get only the site configurations for scraping
function dsp_get_config() {
    $options = get_option(DSP_OPTION_NAME);
    $defaults = dsp_get_default_config();
    $merged_options = wp_parse_args($options, $defaults);
    return $merged_options['sites'] ?? []; // Return only the sites array
}

// --- Activation / Deactivation Hooks ---
register_activation_hook( __FILE__, 'dsp_activate_plugin' );
function dsp_activate_plugin() {
    DSP_DB_Handler::create_table();
    dsp_schedule_cron();
    // Set default options ONLY if the option does not exist at all
    if ( false === get_option( DSP_OPTION_NAME ) ) {
        update_option( DSP_OPTION_NAME, dsp_get_default_config() );
    }
     // Ensure last fetch time exists on activation
     if ( get_option('dsp_last_fetch_time') === false ) {
         update_option('dsp_last_fetch_time', 0, 'no');
     }
}

register_deactivation_hook( __FILE__, 'dsp_deactivate_plugin' );
function dsp_deactivate_plugin() {
    dsp_unschedule_cron();
}

// --- WP Cron Scheduling Functions ---
function dsp_schedule_cron() {
    if ( ! wp_next_scheduled( DSP_CRON_HOOK ) ) {
        wp_schedule_event( time(), 'dsp_fetch_interval', DSP_CRON_HOOK );
    }
}

function dsp_unschedule_cron() {
    $timestamp = wp_next_scheduled( DSP_CRON_HOOK );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, DSP_CRON_HOOK );
    }
}

// Add custom cron schedule interval
add_filter( 'cron_schedules', 'dsp_add_cron_interval' );
function dsp_add_cron_interval( $schedules ) {
    $interval = apply_filters('dsp_fetch_interval_seconds', DSP_FETCH_INTERVAL_SECONDS);
    if ($interval < 60) $interval = 60; // Minimum 60 seconds
    $schedules['dsp_fetch_interval'] = array(
        'interval' => $interval,
        'display'  => esc_html__( 'Deal Scraper Fetch Interval', 'deal-scraper-plugin' ),
    );
    return $schedules;
}

// Hook the actual cron task function
add_action( DSP_CRON_HOOK, 'dsp_run_deal_fetch_cron' );


// --- Shortcode Registration ---
add_shortcode( 'deal_scraper_display', 'dsp_render_shortcode' );

// --- Enqueue Scripts and Styles ---
add_action( 'wp_enqueue_scripts', 'dsp_enqueue_assets' );
function dsp_enqueue_assets() {
    global $post;
    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'deal_scraper_display' ) ) {
        $plugin_version = '1.0.4'; // Match current version
        wp_enqueue_style( 'dsp-style', DSP_PLUGIN_URL . 'assets/css/deal-display.css', [], $plugin_version );
        wp_enqueue_script( 'dsp-script', DSP_PLUGIN_URL . 'assets/js/deal-display.js', ['jquery'], $plugin_version, true );

        // Get config for sources
        $config_sites = dsp_get_config();
        $enabled_sources = array_keys(array_filter($config_sites, function($site){ return !empty($site['enabled']); }));

        // --- Get ALL settings for localization ---
        $options = get_option( DSP_OPTION_NAME );
        $defaults = dsp_get_default_config();
        $dark_mode_setting = isset( $options['dark_mode_default'] ) ? $options['dark_mode_default'] : $defaults['dark_mode_default'];
        // Add more strings needed by JS
        $email_enabled = isset( $options['email_enabled'] ) ? (bool) $options['email_enabled'] : false;

        wp_localize_script( 'dsp-script', 'dsp_ajax_obj', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dsp_ajax_nonce' ),
            // --- Text strings for JS ---
            'loading_text' => __('Loading deals...', 'deal-scraper-plugin'),
            'error_text' => __('Error loading deals.', 'deal-scraper-plugin'),
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
            // Subscription Modal Strings
            'subscribe_invalid_email_format' => __('Please enter a valid email format.', 'deal-scraper-plugin'),
            'subscribe_enter_email' => __('Please enter an email address.', 'deal-scraper-plugin'),
            'subscribe_error_generic' => __('Subscription failed. Please try again later.', 'deal-scraper-plugin'),
            'subscribe_error_network' => __('Subscription failed due to a network error.', 'deal-scraper-plugin'),


            // --- Config data for JS ---
            'config_sources' => $enabled_sources,
            'dark_mode_default' => $dark_mode_setting,
            'email_notifications_enabled' => $email_enabled, // Let JS know if sub feature active
        ) );
    }
}
// add_action( 'wp_enqueue_scripts', 'dsp_enqueue_assets' ); // Ensure hook is present


// --- AJAX Handlers ---

// Get deals for the shortcode display (Used by v1.0.x JS on initial load)
add_action( 'wp_ajax_dsp_get_deals', 'dsp_ajax_get_deals_handler' );
add_action( 'wp_ajax_nopriv_dsp_get_deals', 'dsp_ajax_get_deals_handler' );

function dsp_ajax_get_deals_handler() {
    check_ajax_referer( 'dsp_ajax_nonce', 'nonce' );
    $deals = DSP_DB_Handler::get_deals();
    $last_fetch_time = get_option('dsp_last_fetch_time', 0);
    $processed_deals = [];
    if ($deals) {
        foreach ($deals as $deal) {
            if (is_object($deal) && isset($deal->first_seen)) {
                 $first_seen_ts = strtotime($deal->first_seen);
                 $deal->is_new = ($first_seen_ts && $last_fetch_time && $first_seen_ts >= $last_fetch_time);
                 $deal->first_seen_formatted = $first_seen_ts ? date('Y-m-d H:i', $first_seen_ts) : 'N/A';
                 $deal->is_lifetime = dsp_is_lifetime_deal_php($deal);
                 $processed_deals[] = $deal;
            }
        }
    }
    wp_send_json_success( [
        'deals' => $processed_deals,
        'last_fetch' => $last_fetch_time ? date('Y-m-d H:i:s', $last_fetch_time) : __('Never', 'deal-scraper-plugin')
    ] );
}

// Trigger manual refresh (AJAX Handler)
add_action( 'wp_ajax_dsp_refresh_deals', 'dsp_ajax_refresh_deals_handler' );
add_action( 'wp_ajax_nopriv_dsp_refresh_deals', 'dsp_ajax_refresh_deals_handler' ); // Allow guest refresh trigger

function dsp_ajax_refresh_deals_handler() {
    check_ajax_referer( 'dsp_ajax_nonce', 'nonce' );

    // Server-side access check based on settings
    $options = get_option( DSP_OPTION_NAME );
    $defaults = dsp_get_default_config();
    $refresh_access = isset( $options['refresh_button_access'] ) ? $options['refresh_button_access'] : $defaults['refresh_button_access'];
    $allow_refresh = false;
    switch ( $refresh_access ) {
        case 'all': $allow_refresh = true; break;
        case 'logged_in': if ( is_user_logged_in() ) { $allow_refresh = true; } break;
        case 'admins': if ( current_user_can( 'manage_options' ) ) { $allow_refresh = true; } break;
    }
    if ( ! $allow_refresh ) {
        wp_send_json_error(['message' => __('Permission denied.', 'deal-scraper-plugin'), 'log' => ['Refresh blocked by setting: '.$refresh_access]], 403);
        return;
    }

    // Proceed with refresh
    $result = dsp_run_deal_fetch_cron(true);
    $response_data = ['log' => $result['log'] ?? [], 'message' => '', 'deals' => [], 'last_fetch' => get_option('dsp_last_fetch_time') ? date('Y-m-d H:i:s', get_option('dsp_last_fetch_time')) : __('Never', 'deal-scraper-plugin')];
    $current_deals = DSP_DB_Handler::get_deals();
    $processed_deals = [];
    if ($current_deals) {
        $last_fetch = get_option('dsp_last_fetch_time', 0);
        foreach ($current_deals as $deal) {
             if (is_object($deal) && isset($deal->first_seen)) {
                $ts = strtotime($deal->first_seen);
                $p_deal = clone $deal;
                $p_deal->is_new = ($ts && $last_fetch && $ts >= $last_fetch);
                $p_deal->first_seen_formatted = $ts ? date('Y-m-d H:i', $ts) : 'N/A';
                $p_deal->is_lifetime = dsp_is_lifetime_deal_php($deal);
                $processed_deals[] = $p_deal;
            }
        }
    }
    $response_data['deals'] = $processed_deals;
    if (isset($result['error'])) { $response_data['message'] = $result['error']; wp_send_json_error($response_data); }
    elseif (isset($result['error_summary'])) { $response_data['message'] = $result['error_summary']; wp_send_json_success($response_data); }
    else { $response_data['message'] = sprintf(__('Refresh successful. Processed %d sites. Found %d new deals.', 'deal-scraper-plugin'), $result['sites_processed'] ?? 0, $result['new_deals_count'] ?? 0); wp_send_json_success($response_data); }
}


// *** NEW: AJAX Handler for Frontend Email Subscription ***
add_action( 'wp_ajax_dsp_subscribe_email', 'dsp_ajax_subscribe_email_handler' );
add_action( 'wp_ajax_nopriv_dsp_subscribe_email', 'dsp_ajax_subscribe_email_handler' ); // Allow guests

function dsp_ajax_subscribe_email_handler() {
    // Verify nonce
    check_ajax_referer( 'dsp_ajax_nonce', 'nonce' );

    // Get current settings
    $options = get_option( DSP_OPTION_NAME );
    $defaults = dsp_get_default_config();
    // Ensure options is an array
    if (!is_array($options)) {
         $options = $defaults;
    }

    // Check if email notifications are enabled globally first
    $email_enabled = isset( $options['email_enabled'] ) ? (bool) $options['email_enabled'] : $defaults['email_enabled'];
    if (!$email_enabled) {
         wp_send_json_error(['message' => __('Email notifications are currently disabled by the site administrator.', 'deal-scraper-plugin')], 403); // Use 403 Permission Denied
         return;
    }

    // Get and validate email from POST data
    if ( ! isset( $_POST['email'] ) || empty( trim( $_POST['email'] ) ) ) {
        wp_send_json_error(['message' => __('Please enter an email address.', 'deal-scraper-plugin')], 400); // Use 400 Bad Request
        return;
    }

    $email_to_add = sanitize_email( trim( $_POST['email'] ) );

    if ( ! is_email( $email_to_add ) ) {
        wp_send_json_error(['message' => __('Invalid email address provided.', 'deal-scraper-plugin')], 400); // Use 400 Bad Request
        return;
    }

    // Get current recipients list, ensuring it's an array
    $current_recipients = isset( $options['email_recipients'] ) && is_array( $options['email_recipients'] ) ? $options['email_recipients'] : $defaults['email_recipients'];

    // Check if email already exists (case-insensitive)
    $email_exists = false;
    foreach ($current_recipients as $existing_email) {
        if (strcasecmp($existing_email, $email_to_add) === 0) {
            $email_exists = true;
            break;
        }
    }

    if ( $email_exists ) {
        // It's not an error, just feedback
        wp_send_json_success(['message' => __('This email address is already subscribed.', 'deal-scraper-plugin')]);
        return;
    }

    // Add the new email and update the option
    $current_recipients[] = $email_to_add;
    // Ensure uniqueness although the check above mostly handles it
    $options['email_recipients'] = array_unique($current_recipients);

    $update_result = update_option( DSP_OPTION_NAME, $options );

    if ( $update_result ) {
        wp_send_json_success(['message' => __('Successfully subscribed! You will receive future deal notifications.', 'deal-scraper-plugin')]);
    } else {
        // Check if the failure was because the option didn't actually change (which means the email was already there - race condition?)
        $options_after_attempt = get_option(DSP_OPTION_NAME);
         if (is_array($options_after_attempt) && isset($options_after_attempt['email_recipients']) && in_array($email_to_add, $options_after_attempt['email_recipients'])) {
            wp_send_json_success(['message' => __('This email address is already subscribed (verified).', 'deal-scraper-plugin')]);
         } else {
            // Genuine update error
            error_log("DSP Subscribe Error: update_option failed for ". DSP_OPTION_NAME . " - potentially database issue.");
            wp_send_json_error(['message' => __('Subscription failed due to a server error. Please try again later.', 'deal-scraper-plugin')], 500); // Use 500 Internal Server Error
         }
    }
}
// *** END NEW AJAX Handler ***


// --- Helper Functions ---
function dsp_is_lifetime_deal_php($deal_obj) {
    if (!is_object($deal_obj)) return false;
    $title_check = isset($deal_obj->title) && is_string($deal_obj->title) && stripos($deal_obj->title, 'lifetime') !== false;
    $price_check = isset($deal_obj->price) && is_string($deal_obj->price) && stripos($deal_obj->price, 'lifetime') !== false;
    return $title_check || $price_check;
}

?>