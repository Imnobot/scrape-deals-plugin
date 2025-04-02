<?php
/**
 * Plugin Name:       Deal Scraper Plugin
 * Plugin URI:        https://none.com
 * Description:       Scrapes deal websites and displays them via a shortcode. Includes debug logging. Display the plugin with the Shortcode [deal_scraper_display]
 * Version:           1.0
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
define( 'DSP_OPTION_NAME', 'dsp_settings' ); // For future settings

// --- Load Required Files FIRST ---
// Make sure these are loaded before any functions that might depend on them are called by hooks.
require_once DSP_PLUGIN_DIR . 'includes/db-handler.php';
require_once DSP_PLUGIN_DIR . 'includes/parsers.php';
require_once DSP_PLUGIN_DIR . 'includes/cron-handler.php';
require_once DSP_PLUGIN_DIR . 'includes/shortcode-handler.php';
// require_once DSP_PLUGIN_DIR . 'admin/settings-page.php'; // Optional settings page

// --- Configuration Functions DEFINED EARLY ---
// Define these before hooks that might use them (like dsp_enqueue_assets)
function dsp_get_default_config() {
     // Reset enabled flags here if you were testing one-by-one previously
     return [
        'sites' => [
             "AppSumo" => ["url" => "https://appsumo.com/software/?sort=latest", "parser" => "parse_appsumo_php", "enabled" => true],
             "StackSocial" => ["url" => "https://www.stacksocial.com/collections/apps-software?sort=newest", "parser" => "parse_stacksocial_php", "enabled" => true],
             "DealFuel" => ["url" => "https://www.dealfuel.com/product-category/all/?orderby=date", "parser" => "parse_dealfuel_php", "enabled" => true],
             "DealMirror" => ["url" => "https://dealmirror.com/product-category/new-arrivals/", "parser" => "parse_dealmirror_php", "enabled" => true]
         ],
     ];
}

function dsp_get_config() {
    // $options = get_option(DSP_OPTION_NAME); // Use options if/when settings page is added
    // return $options ? ($options['sites'] ?? []) : dsp_get_default_config()['sites'];
    $default_config = dsp_get_default_config(); // Get defaults
    return $default_config['sites'] ?? []; // Return only the sites array, or empty if structure is wrong
}

// --- Activation / Deactivation Hooks ---
// These generally run in specific contexts where function availability isn't usually an issue.
register_activation_hook( __FILE__, 'dsp_activate_plugin' );
function dsp_activate_plugin() {
    DSP_DB_Handler::create_table();
    dsp_schedule_cron();
     if ( ! get_option( DSP_OPTION_NAME ) ) {
        update_option( DSP_OPTION_NAME, dsp_get_default_config() );
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
        // --- CHANGE THIS LINE ---
        'display'  => esc_html__( 'Deal Scraper Fetch Interval (Daily)', 'deal-scraper-plugin' ), // Updated Display Name
    );
    return $schedules;
}

// Hook the actual cron task function (defined in cron-handler.php)
add_action( DSP_CRON_HOOK, 'dsp_run_deal_fetch_cron' );


// --- Shortcode Registration ---
// The rendering function is defined in shortcode-handler.php
add_shortcode( 'deal_scraper_display', 'dsp_render_shortcode' );

// --- Enqueue Scripts and Styles ---
// This function NOW runs AFTER dsp_get_config is defined above.
add_action( 'wp_enqueue_scripts', 'dsp_enqueue_assets' );
function dsp_enqueue_assets() {
    global $post;
    // Efficient check for shortcode before enqueuing
    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'deal_scraper_display' ) ) {
        wp_enqueue_style( 'dsp-style', DSP_PLUGIN_URL . 'assets/css/deal-display.css', [], '1.1.1' ); // Version bump
        wp_enqueue_script( 'dsp-script', DSP_PLUGIN_URL . 'assets/js/deal-display.js', ['jquery'], '1.1.1', true ); // Version bump

        // Get enabled sources using the now-available function
        $config = dsp_get_config();
        $enabled_sources = array_keys(array_filter($config, function($site){ return !empty($site['enabled']); }));

        wp_localize_script( 'dsp-script', 'dsp_ajax_obj', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'dsp_ajax_nonce' ),
            'loading_text' => __('Loading deals...', 'deal-scraper-plugin'),
            'error_text' => __('Error loading deals.', 'deal-scraper-plugin'),
            'never_text' => __('Never', 'deal-scraper-plugin'),
            'last_updated_text' => __('Last fetched:', 'deal-scraper-plugin'),
             'refreshing_text' => __('Refreshing...', 'deal-scraper-plugin'),
             'show_log_text' => __('Show Debug Log', 'deal-scraper-plugin'),
             'hide_log_text' => __('Hide Debug Log', 'deal-scraper-plugin'),
            'config_sources' => $enabled_sources
        ) );
    }
}

// --- AJAX Handlers ---

// Get deals for the shortcode display
add_action( 'wp_ajax_dsp_get_deals', 'dsp_ajax_get_deals_handler' );
add_action( 'wp_ajax_nopriv_dsp_get_deals', 'dsp_ajax_get_deals_handler' );

function dsp_ajax_get_deals_handler() {
    check_ajax_referer( 'dsp_ajax_nonce', 'nonce' );

    $deals = DSP_DB_Handler::get_deals(); // Add sorting args if needed later
    $last_fetch_time = get_option('dsp_last_fetch_time', 0);

    $processed_deals = [];
    if ($deals) {
        foreach ($deals as $deal) {
             // Ensure deal is an object with expected properties before accessing
            if (is_object($deal) && isset($deal->first_seen)) {
                 $first_seen_ts = strtotime($deal->first_seen); // Returns false on failure
                 $deal->is_new = ($first_seen_ts && $last_fetch_time && $first_seen_ts >= $last_fetch_time);
                 $deal->first_seen_formatted = $first_seen_ts ? date('Y-m-d H:i', $first_seen_ts) : 'N/A';
                 $deal->is_lifetime = dsp_is_lifetime_deal_php($deal); // Check lifetime status
                 $processed_deals[] = $deal;
            }
        }
    }

    wp_send_json_success( [
        'deals' => $processed_deals,
        'last_fetch' => $last_fetch_time ? date('Y-m-d H:i:s', $last_fetch_time) : __('Never', 'deal-scraper-plugin')
    ] );
}

// Trigger manual refresh
add_action( 'wp_ajax_dsp_refresh_deals', 'dsp_ajax_refresh_deals_handler' );
// No nopriv by default

function dsp_ajax_refresh_deals_handler() {
    check_ajax_referer( 'dsp_ajax_nonce', 'nonce' );

    // Optional Capability Check
    // if (!current_user_can('manage_options')) {
    //     wp_send_json_error(['message' => __('Permission denied.', 'deal-scraper-plugin'), 'log' => ['Permission Denied.']], 403);
    // }

    $result = dsp_run_deal_fetch_cron(true); // Run fetch logic

    // Prepare response, always include logs
    $response_data = [
        'log' => isset($result['log']) ? $result['log'] : ['[ERROR] Log data missing from cron handler.'],
        'message' => '',
        'deals' => [], // Will be populated below
        'last_fetch' => get_option('dsp_last_fetch_time') ? date('Y-m-d H:i:s', get_option('dsp_last_fetch_time')) : __('Never', 'deal-scraper-plugin'),
    ];

    // Fetch current deals AFTER the refresh attempt to show updated state
    $current_deals_after_refresh = DSP_DB_Handler::get_deals();
    $processed_deals = [];
    if ($current_deals_after_refresh) {
        $current_last_fetch_time = get_option('dsp_last_fetch_time', 0); // Re-get in case it was updated
        foreach ($current_deals_after_refresh as $deal) {
             if (is_object($deal) && isset($deal->first_seen)) {
                $first_seen_ts = strtotime($deal->first_seen);
                $deal->is_new = ($first_seen_ts && $current_last_fetch_time && $first_seen_ts >= $current_last_fetch_time);
                $deal->first_seen_formatted = $first_seen_ts ? date('Y-m-d H:i', $first_seen_ts) : 'N/A';
                $deal->is_lifetime = dsp_is_lifetime_deal_php($deal);
                $processed_deals[] = $deal;
            }
        }
    }
    $response_data['deals'] = $processed_deals; // Add potentially updated deals list

    // Determine success/error based on $result from cron function
    if (isset($result['error'])) { // Handle early exit error
         $response_data['message'] = $result['error'];
         wp_send_json_error($response_data);

    } elseif (isset($result['error_summary'])) { // Handle errors during processing
        $response_data['message'] = $result['error_summary'];
        // Send success status code (200) but with error message, logs, and current deals
        wp_send_json_success($response_data);

    } else {
        // Successful refresh
        $response_data['message'] = sprintf(__('Refresh successful. Processed %d sites. Found %d new deals.', 'deal-scraper-plugin'), $result['sites_processed'], $result['new_deals_count']);
        wp_send_json_success($response_data);
    }
}


// Helper function to check for lifetime deal
function dsp_is_lifetime_deal_php($deal_obj) {
    if (!is_object($deal_obj)) return false;
    $title_check = isset($deal_obj->title) && $deal_obj->title && stripos($deal_obj->title, 'lifetime') !== false;
    $price_check = isset($deal_obj->price) && $deal_obj->price && stripos($deal_obj->price, 'lifetime') !== false;
    // Optionally check description too if needed
    // $desc_check = isset($deal_obj->description) && $deal_obj->description && stripos($deal_obj->description, 'lifetime') !== false;
    return $title_check || $price_check; // || $desc_check;
}