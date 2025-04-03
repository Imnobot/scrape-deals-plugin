<?php

/**
 * Uninstall Script for Deal Scraper Plugin
 *
 * This script runs only when the user deletes the plugin
 * from the WordPress admin area. It cleans up database tables and options.
 *
 * @package DealScraperPlugin
 */

// Exit if accessed directly or not during uninstall process.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// --- Load WordPress Database ---
global $wpdb;

// --- Define Plugin-Specific Identifiers ---
// Ensure these match exactly what's used in the plugin!

// 1. Database Table Name (from db-handler.php)
$deal_table_name = $wpdb->prefix . 'dsp_deals'; // Verify this matches get_table_name()

// 2. Option Names (from main plugin file and settings)
$settings_option_name = 'dsp_settings';       // Defined as DSP_OPTION_NAME
$last_fetch_option_name = 'dsp_last_fetch_time'; // Used directly

// 3. Cron Hook Name (from main plugin file)
$cron_hook_name = 'dsp_periodic_deal_fetch'; // Defined as DSP_CRON_HOOK

// 4. Transients (from cron-handler.php)
$transient_fetch_running = 'dsp_fetch_running';

// --- Perform Cleanup Actions ---

// 1. Delete Custom Database Table
// Use "DROP TABLE IF EXISTS" for safety
$wpdb->query( "DROP TABLE IF EXISTS `{$deal_table_name}`" );

// 2. Delete Options from wp_options table
delete_option( $settings_option_name );
delete_option( $last_fetch_option_name );
// Add delete_option() calls for any other options if added later

// 3. Clear Scheduled Cron Event
// This removes all scheduled instances of the hook
wp_clear_scheduled_hook( $cron_hook_name );

// 4. Delete Transients
delete_transient( $transient_fetch_running );
// Add delete_transient() calls for any other transients if added later

// --- Optional: Multisite Cleanup ---
// If your plugin ever adds multisite-specific data, uncomment and adapt this.
/*
if ( is_multisite() ) {
    // Get all site IDs
    $blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );
    $original_blog_id = get_current_blog_id();

    foreach ( $blog_ids as $blog_id ) {
        switch_to_blog( $blog_id );
        // Delete site-specific options (e.g., delete_option('dsp_site_option'))
        // Delete site-specific tables (careful with prefix: $wpdb->prefix)
        // $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}dsp_site_table`" );
    }
    switch_to_blog( $original_blog_id ); // Switch back

    // Delete network-wide options (e.g., delete_site_option('dsp_network_option'))
}
*/

?>