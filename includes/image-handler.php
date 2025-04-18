<?php
// File: includes/image-handler.php (v1.1.42 - Add explicit check before query)

if ( ! defined( 'ABSPATH' ) ) exit;

// Define a transient name for locking the sideload process
define('DSP_SIDELOAD_LOCK_TRANSIENT', 'dsp_sideload_job_running');
// Define how many images to process per cron run
define('DSP_SIDELOAD_BATCH_SIZE', 10); // Adjust as needed (5-15 is usually safe)
// Define timeout for image download attempt
define('DSP_SIDELOAD_DOWNLOAD_TIMEOUT', 60); // Seconds

/**
 * Callback function for the image sideloading cron job (DSP_SIDELOAD_CRON_HOOK).
 * Finds deals needing images and processes them in batches.
 */
function dsp_run_sideload_job() {
    error_log("DSP Sideload Cron: Job starting...");

    // 1. Check if sideloading is enabled in settings
    $options = get_option( DSP_OPTION_NAME );
    $defaults = dsp_get_default_config(); // Ensure defaults are available
    $merged_options = wp_parse_args($options, $defaults);
    $sideload_enabled = isset($merged_options['sideload_images']) ? (bool)$merged_options['sideload_images'] : false;

    if ( ! $sideload_enabled ) {
        error_log("DSP Sideload Cron: Sideloading is disabled in settings. Exiting.");
        return;
    }

    // 2. Prevent overlapping runs with a transient lock
    if ( get_transient( DSP_SIDELOAD_LOCK_TRANSIENT ) ) {
        error_log("DSP Sideload Cron: Another sideload process is already running. Exiting.");
        return;
    }
    set_transient( DSP_SIDELOAD_LOCK_TRANSIENT, true, 15 * MINUTE_IN_SECONDS );

    $processed_count = 0;
    $success_count = 0;
    $error_count = 0;
    $skipped_count = 0;

    try {

        // *** NEW DEBUG STEP: Check a known deal explicitly ***
        global $wpdb;
        $table_name_debug = $wpdb->prefix . 'dsp_deals';
        $known_deal_link = 'https://dealmirror.com/product/virtlx/'; // Example from logs
        $current_id_debug = $wpdb->get_var($wpdb->prepare(
            "SELECT image_attachment_id FROM $table_name_debug WHERE link = %s",
            $known_deal_link
        ));
        error_log("DSP Sideload Cron DEBUG: Current image_attachment_id for '{$known_deal_link}' is: " . ($current_id_debug === null ? 'NULL (Not Found?)' : $current_id_debug) );
        // *** END NEW DEBUG STEP ***


        // 3. Find deals that need images processed
        $deals_to_process = DSP_DB_Handler::get_deals_needing_images( DSP_SIDELOAD_BATCH_SIZE );

        if ( empty( $deals_to_process ) ) {
            error_log("DSP Sideload Cron: No deals found needing image sideloading in this batch (WHERE image_attachment_id = -2).");
            delete_transient( DSP_SIDELOAD_LOCK_TRANSIENT );
            return;
        }

        error_log("DSP Sideload Cron: Found " . count($deals_to_process) . " deals to process images for.");

        // 4. Loop and process each deal
        foreach ( $deals_to_process as $deal ) {
            $processed_count++;
            $deal_link = $deal->link;
            $image_url = $deal->image_url;
            $deal_title = $deal->title;

            if ( empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL) ) {
                error_log("DSP Sideload Cron: Skipping deal '{$deal_link}' due to missing or invalid image URL: " . esc_html($image_url));
                DSP_DB_Handler::update_deal_attachment_id($deal_link, -1); // Mark as skipped
                $skipped_count++;
                continue;
            }

            // Attempt to sideload
            $error_message = ''; // Initialize error message variable
            $attachment_id = dsp_sideload_deal_image( $deal_link, $image_url, $deal_title, $error_message );

            if ( $attachment_id && is_int($attachment_id) && $attachment_id > 0 ) {
                error_log("DSP Sideload Cron: Successfully sideloaded image for '{$deal_link}', Attachment ID: {$attachment_id}");
                $success_count++;
            } else {
                $log_error = !empty($error_message) ? $error_message : 'Unknown failure reason.';
                error_log("DSP Sideload Cron: Failed to sideload image for '{$deal_link}' from URL: " . esc_html($image_url) . ". Reason: " . $log_error);
                $error_count++;
                DSP_DB_Handler::update_deal_attachment_id($deal_link, -1); // Mark as failed
            }

            // Optional: Small delay between requests
            sleep(1);
        }

        error_log("DSP Sideload Cron: Batch finished. Processed: {$processed_count}, Success: {$success_count}, Errors: {$error_count}, Skipped: {$skipped_count}");

    } catch ( Exception $e ) {
        error_log("DSP Sideload Cron: Exception caught: " . $e->getMessage());
        delete_transient( DSP_SIDELOAD_LOCK_TRANSIENT ); // Ensure lock release
        throw $e;
    }

    // 5. Release the lock transient
    delete_transient( DSP_SIDELOAD_LOCK_TRANSIENT );
    error_log("DSP Sideload Cron: Job finished.");
}


/**
 * Downloads an image from a URL, adds it to the Media Library,
 * and updates the deal's attachment ID in the database.
 * Adds a temporary filter to increase HTTP timeout.
 * Captures specific error messages.
 *
 * @param string $deal_link The unique link (PK) of the deal.
 * @param string $image_url The URL of the image to download.
 * @param string $deal_title The title of the deal (used for alt text).
 * @param string &$error_message Passed by reference to store error details.
 * @return int|false Attachment ID on success, false on failure.
 */
function dsp_sideload_deal_image( $deal_link, $image_url, $deal_title = '', &$error_message = '' ) {

    // --- Load required WP Admin files ---
    if (!function_exists('media_sideload_image')) { require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/image.php'; }

    // --- Sanitize inputs ---
    $image_url = esc_url_raw( trim( $image_url ) ); $deal_title = sanitize_text_field( $deal_title ); $error_message = '';
    if ( empty($image_url) ) { $error_message = 'Empty image URL provided.'; return false; }

    // --- Temporarily increase timeout for download_url ---
    add_filter( 'http_request_timeout', 'dsp_sideload_increase_timeout', 1000 );
    // error_log("DSP Sideload: Temporarily increased HTTP timeout for URL: " . esc_html($image_url));

    // --- Download image ---
    $image_desc = $deal_title ?: __('Deal Image', 'deal-scraper-plugin');
    $attachment_id_or_error = media_sideload_image( $image_url, 0, $image_desc, 'id' );

    // --- Remove the temporary timeout filter ---
    remove_filter( 'http_request_timeout', 'dsp_sideload_increase_timeout', 1000 );
    // error_log("DSP Sideload: Removed temporary HTTP timeout filter.");

    // --- Handle results ---
    $attachment_id = 0; $sideload_success = false;

    if ( is_wp_error( $attachment_id_or_error ) ) {
        $error_message = $attachment_id_or_error->get_error_message(); error_log( "DSP Sideload WP_Error for {$deal_link} (URL: {$image_url}): " . $error_message ); return false;
    } else {
        $attachment_id = (int) $attachment_id_or_error;
        if ( $attachment_id <= 0 ) {
             $error_message = 'media_sideload_image did not return a valid Attachment ID.'; error_log( "DSP Sideload Error for {$deal_link} (URL: {$image_url}): " . $error_message );
             global $wpdb; $fallback_id = $wpdb->get_var($wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_source_url' AND meta_value = %s ORDER BY post_id DESC LIMIT 1", $image_url ));
             if ($fallback_id > 0) { $attachment_id = (int) $fallback_id; error_log("DSP Sideload: Found attachment ID {$attachment_id} via fallback query."); $sideload_success = true; $error_message = ''; }
             else { error_log("DSP Sideload Error: Fallback query also failed to find attachment ID for {$image_url}"); return false; }
        } else { $sideload_success = true; }
    }

    if ($sideload_success && $attachment_id > 0) {
        $update_success = DSP_DB_Handler::update_deal_attachment_id( $deal_link, $attachment_id );
        if ( ! $update_success ) { $error_message = "Failed to update database record with attachment ID {$attachment_id}."; error_log( "DSP Sideload Error: " . $error_message . " The image IS in the media library but not linked in the DB for deal '{$deal_link}'." ); wp_delete_attachment($attachment_id, true); error_log( "DSP Sideload: Deleted orphaned attachment ID {$attachment_id}." ); return false; }
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $deal_title );
        return $attachment_id;
    }

    if (empty($error_message)) { $error_message = 'Unknown failure reason after processing.'; }
    error_log( "DSP Sideload Error: Reached end of function unexpectedly for {$deal_link} (URL: {$image_url}). Final Error: {$error_message}" );
    return false;
}

/** Filter callback to temporarily increase HTTP request timeout. */
function dsp_sideload_increase_timeout( $timeout ) { return defined('DSP_SIDELOAD_DOWNLOAD_TIMEOUT') ? DSP_SIDELOAD_DOWNLOAD_TIMEOUT : 60; }

?>