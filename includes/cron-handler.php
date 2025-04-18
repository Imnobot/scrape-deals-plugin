<?php
// File: includes/cron-handler.php (v1.1.39 - Use $wpdb->update for Status Save)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main function executed by WP Cron or manually via Ajax.
 * Processes ONE enabled site per run, choosing the one least recently checked.
 * Records the status (with more error detail) for the processed site.
 *
 * @param bool $manual_trigger If true, processes ALL enabled sites (like old behavior).
 * @return array Status array including counts, errors, and a detailed log.
 */
function dsp_run_deal_fetch_cron( $manual_trigger = false ) {
    // --- Prevent overlapping runs (more important with frequent schedule) ---
    if ( !$manual_trigger && get_transient('dsp_fetch_running') ) {
        return ['message' => 'Skipped (Overlap)', 'log' => []]; // Quiet exit for normal overlap
    }
    set_transient('dsp_fetch_running', true, 10 * MINUTE_IN_SECONDS); // Keep lock short

    $start_time = microtime(true);
    $run_timestamp = time();
    $log_messages = [];
    $log_messages[] = sprintf(
        __("Cron process starting%s at %s", 'deal-scraper-plugin'),
        ($manual_trigger ? __(" (Manual Full Refresh Trigger)", 'deal-scraper-plugin') : __(" (Staggered Run)", 'deal-scraper-plugin')),
        date('Y-m-d H:i:s', $run_timestamp)
    );

    // --- Get Configuration & Identify Site(s) to Process ---
    $options = get_option(DSP_OPTION_NAME, []);
    $defaults = dsp_get_default_config(); // Ensure defaults are available
    $merged_options = wp_parse_args($options, $defaults); // Use this array for the entire process

    $all_sites = $merged_options['sites'] ?? []; // Get sites array from merged options

    if (empty($all_sites) || !is_array($all_sites)) {
        $log_messages[] = __("[ERROR] No site configurations found.", 'deal-scraper-plugin');
        delete_transient('dsp_fetch_running');
        return [ 'error' => __('No site configurations found.', 'deal-scraper-plugin'), 'log' => $log_messages ];
    }

    $enabled_sites = [];
    foreach ($all_sites as $index => $site_data) {
        if (is_array($site_data) && !empty($site_data['enabled'])) {
            $enabled_sites[$index] = $site_data; // Keep original index as key
        }
    }

    if (empty($enabled_sites)) {
        $log_messages[] = __("[INFO] No enabled sites found to process.", 'deal-scraper-plugin');
        delete_transient('dsp_fetch_running');
        return [ 'message' => __('No enabled sites.', 'deal-scraper-plugin'), 'log' => $log_messages ];
    }

    $sites_to_process_this_run = [];
    if ($manual_trigger) {
        $sites_to_process_this_run = $enabled_sites;
        $log_messages[] = sprintf( __("Manual trigger: Processing all %d enabled sites.", 'deal-scraper-plugin'), count($sites_to_process_this_run) );
    } else {
        $oldest_time = PHP_INT_MAX;
        $site_index_to_run = -1;
        foreach ($enabled_sites as $index => $site_data) {
            $last_run = isset($site_data['last_run_time']) ? (int)$site_data['last_run_time'] : 0;
            if ($last_run < $oldest_time) {
                $oldest_time = $last_run;
                $site_index_to_run = $index;
            }
        }
        if ($site_index_to_run !== -1) {
            $sites_to_process_this_run[$site_index_to_run] = $enabled_sites[$site_index_to_run];
            $log_messages[] = sprintf( __("Staggered run: Selected site '%s' (Index: %d, Last Run: %s) to process.", 'deal-scraper-plugin'),
                $enabled_sites[$site_index_to_run]['name'] ?? 'Unknown',
                $site_index_to_run,
                $oldest_time > 0 ? date('Y-m-d H:i:s', $oldest_time) : __('Never', 'deal-scraper-plugin')
            );
        } else {
            $log_messages[] = __("[WARN] Could not determine which site to process in staggered run.", 'deal-scraper-plugin');
        }
    }

    // --- Fetching and Parsing Loop (Processes only selected sites) ---
    $all_fetched_deals = []; $sites_processed_count = 0; $sites_with_errors = [];
    $site_run_results = []; // Store results for the sites processed *in this run*

    foreach ($sites_to_process_this_run as $site_index => $site_info) {
        $site_name = $site_info['name'] ?? 'Unknown Site ' . $site_index;
        $url = $site_info['url'] ?? '';
        $parser_file = $site_info['parser_file'] ?? '';
        $parser_func = !empty($parser_file) ? 'parse_' . strtolower( $parser_file ) . '_php' : '';
        $current_site_status = '';

        $log_messages[] = "--- " . sprintf(__("Processing Site: %s", 'deal-scraper-plugin'), $site_name) . " ---";

        // Validate essential info
        if (empty($url) || empty($parser_func)) {
            $error_msg = sprintf(__("[ERROR] Missing URL or Parser for site '%s'. Skipping.", 'deal-scraper-plugin'), $site_name);
            $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg);
            $sites_with_errors[] = $site_name . __(' (Config Error)', 'deal-scraper-plugin');
            $current_site_status = __('Error: Missing URL or Parser', 'deal-scraper-plugin');
            $site_run_results[$site_index] = ['status' => $current_site_status, 'time' => $run_timestamp];
            continue;
        }

        // Check if parser function exists
        if (!function_exists($parser_func)) {
            $error_msg = sprintf(__("[ERROR] Parser function '%s' for site '%s' missing.", 'deal-scraper-plugin'), esc_html($parser_func), $site_name);
            $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg);
            $sites_with_errors[] = $site_name . __(' (Bad Parser)', 'deal-scraper-plugin');
            $current_site_status = sprintf(__('Error: Parser function `%s` missing', 'deal-scraper-plugin'), esc_html($parser_func));
            $site_run_results[$site_index] = ['status' => $current_site_status, 'time' => $run_timestamp];
            continue;
        }

        // Fetching
        $log_messages[] = sprintf(__("Fetching URL: %s", 'deal-scraper-plugin'), $url);
        $args = [ 'timeout' => 45, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36', 'sslverify' => false, 'headers' => [ 'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7', 'Accept-Language' => 'en-US,en;q=0.9', 'Accept-Encoding' => 'gzip, deflate', 'Upgrade-Insecure-Requests' => '1', 'Cache-Control' => 'max-age=0', ] ];
        $response = wp_remote_get( $url, $args );

        // Handle WP_Error during fetch
        if ( is_wp_error( $response ) ) {
            $wp_error_code = $response->get_error_code(); $wp_error_message = $response->get_error_message(); $error_msg = sprintf(__("[ERROR] WP Error fetching %s: [%s] %s", 'deal-scraper-plugin'), $site_name, $wp_error_code, $wp_error_message); $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg); $sites_with_errors[] = $site_name . sprintf(__(' (Fetch Error: %s)', 'deal-scraper-plugin'), $wp_error_code); $current_site_status = sprintf(__('Error: Fetch failed (%s)', 'deal-scraper-plugin'), esc_html($wp_error_code)); $site_run_results[$site_index] = ['status' => $current_site_status, 'time' => $run_timestamp]; continue; }

        // Handle HTTP Status Code errors
        $status_code = wp_remote_retrieve_response_code( $response ); $html_body = wp_remote_retrieve_body( $response ); $content_length = strlen($html_body); $log_messages[] = sprintf(__("Fetch Result: Status Code=%d, Content Length=%d bytes.", 'deal-scraper-plugin'), $status_code, $content_length);
        if ( $status_code >= 400 || empty( $html_body ) ) {
             $error_msg = sprintf(__("[ERROR] HTTP Status %d or empty body for %s.", 'deal-scraper-plugin'), $status_code, $site_name); if (!empty($html_body) && $status_code >= 400) { $preview = substr( strip_tags($html_body), 0, 300); $preview = preg_replace('/\s+/', ' ', $preview); $error_msg .= " " . __("Body Preview:", 'deal-scraper-plugin') . " " . esc_html($preview) . "..."; } $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg); $sites_with_errors[] = $site_name . sprintf(__(' (HTTP %d)', 'deal-scraper-plugin'), $status_code); $current_site_status = sprintf(__('Error: HTTP %d received', 'deal-scraper-plugin'), $status_code); if (empty($html_body)) { $current_site_status = __('Error: Empty response body', 'deal-scraper-plugin'); } $site_run_results[$site_index] = ['status' => $current_site_status, 'time' => $run_timestamp]; continue; }

        // Parsing
        $log_messages[] = sprintf(__("Parsing %s using '%s'...", 'deal-scraper-plugin'), $site_name, esc_html($parser_func));
        try {
            $site_deals = call_user_func($parser_func, $html_body, $url, $site_name);
            if (is_array($site_deals)) {
                $deal_count = count($site_deals); $log_messages[] = sprintf(__("Parsing successful. Found %d deals from %s.", 'deal-scraper-plugin'), $deal_count, $site_name); if ($deal_count > 0 && isset($site_deals[0])) { foreach ($site_deals as &$deal_ref) { if (is_array($deal_ref)) { $deal_ref['source'] = $site_name; } } unset($deal_ref); $example_deal_json = json_encode($site_deals[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); $log_messages[] = __("Example Deal:", 'deal-scraper-plugin') . " " . ($example_deal_json ?: 'Error encoding example deal'); } $all_fetched_deals = array_merge($all_fetched_deals, $site_deals); $sites_processed_count++; $current_site_status = sprintf(_n('Success: Found %d deal', 'Success: Found %d deals', $deal_count, 'deal-scraper-plugin'), $deal_count);
            } else { $error_msg = sprintf(__("[ERROR] Parser '%s' for %s did not return an array.", 'deal-scraper-plugin'), esc_html($parser_func), $site_name); $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg); $sites_with_errors[] = $site_name . __(' (Parse Error)', 'deal-scraper-plugin'); $current_site_status = sprintf(__('Error: Parser `%s` bad return type', 'deal-scraper-plugin'), esc_html($parser_func)); }
        } catch (Exception $e) { $exception_message = $e->getMessage(); $error_msg = sprintf(__("[ERROR] Exception during parsing %s: %s", 'deal-scraper-plugin'), $site_name, $exception_message); $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg); $sites_with_errors[] = $site_name . __(' (Parse Exception)', 'deal-scraper-plugin'); $current_site_status = sprintf(__('Error: Parse Exception - %s', 'deal-scraper-plugin'), esc_html(substr($exception_message, 0, 100))); }

        $site_run_results[$site_index] = ['status' => $current_site_status, 'time' => $run_timestamp];
        $log_messages[] = "--- " . sprintf(__("Finished Site: %s", 'deal-scraper-plugin'), $site_name) . " ---";
    } // End foreach site_to_process


    // --- Process fetched deals into DB ---
    $new_deals_found_count = 0; $deals_processed_db_count = 0; $db_errors = 0;
    if (!empty($all_fetched_deals)) {
        $total_to_process = count($all_fetched_deals); $log_messages[] = sprintf(__("Processing %d total fetched deals for Database...", 'deal-scraper-plugin'), $total_to_process);
        foreach ($all_fetched_deals as $i => $deal) {
            if (is_array($deal) && !empty($deal['link']) && !empty($deal['title']) && isset($deal['source'])) {
                $deal_identifier = "'". esc_html($deal['title']) ."' from ". esc_html($deal['source']); if ($i < 3 || $i == $total_to_process -1) { $log_messages[] = sprintf(__("DB Proc [%d/%d]: Attempting %s", 'deal-scraper-plugin'), $i+1, $total_to_process, $deal_identifier); } elseif ($i == 3) { $log_messages[] = __("DB Proc [... skipping detailed logs ...]", 'deal-scraper-plugin'); }
                $add_update_result = DSP_DB_Handler::add_or_update_deal($deal);
                if ($add_update_result === null) { $log_messages[] = sprintf(__("[ERROR] DB Proc [%d/%d]: Failed to add/update %s. Check PHP error log.", 'deal-scraper-plugin'), $i+1, $total_to_process, $deal_identifier); $db_errors++; }
                elseif ($add_update_result === true) { $deals_processed_db_count++; $new_deals_found_count++; if ($i < 3 || $i == $total_to_process -1 || $new_deals_found_count < 4) { $log_messages[] = sprintf(__("DB Proc [%d/%d]: ---> NEW Deal Added: %s", 'deal-scraper-plugin'), $i+1, $total_to_process, $deal_identifier); } }
                elseif ($add_update_result === false) { $deals_processed_db_count++; if ($i < 3 || $i == $total_to_process -1) { $log_messages[] = sprintf(__("DB Proc [%d/%d]: ---> Updated existing: %s", 'deal-scraper-plugin'), $i+1, $total_to_process, $deal_identifier); } }
            } else { $bad_deal_info = is_array($deal) ? json_encode($deal, JSON_PARTIAL_OUTPUT_ON_ERROR) : print_r($deal, true); $log_messages[] = sprintf(__("[WARN] DB Proc [%d/%d]: Skipping invalid deal structure or missing source: %s", 'deal-scraper-plugin'), $i+1, $total_to_process, esc_html($bad_deal_info)); }
        }
         $log_messages[] = sprintf( __("Finished DB processing. Processed (Add/Update): %d. New Deals Found: %d. DB Errors: %d.", 'deal-scraper-plugin'), $deals_processed_db_count, $new_deals_found_count, $db_errors );
    } else { $log_messages[] = __("No valid deals fetched from processed site(s) to process in DB.", 'deal-scraper-plugin'); }

    // --- Auto-Purge Old Deals ---
    $log_messages[] = "--- " . __("Checking Auto-Purge Settings", 'deal-scraper-plugin') . " ---";
    $purge_enabled = isset($merged_options['purge_enabled']) ? (bool) $merged_options['purge_enabled'] : false; $purge_max_age_days = isset($merged_options['purge_max_age_days']) ? intval($merged_options['purge_max_age_days']) : 90;
    if ($purge_enabled && $purge_max_age_days >= 1) {
        $log_messages[] = sprintf(__('Auto-purge enabled. Attempting to delete deals older than %d days.', 'deal-scraper-plugin'), $purge_max_age_days); $purge_result = DSP_DB_Handler::purge_old_deals($purge_max_age_days); if ($purge_result === false) { $log_messages[] = __("[ERROR] Auto-purge failed. See logs.", 'deal-scraper-plugin'); } elseif (is_int($purge_result)) { $log_messages[] = sprintf(__('Auto-purge completed. Deleted %d deals.', 'deal-scraper-plugin'), $purge_result); }
    } elseif ($purge_enabled && $purge_max_age_days < 1) { $log_messages[] = sprintf(__("[WARN] Auto-purge enabled but max age (%d) invalid. Skipped.", 'deal-scraper-plugin'), $purge_max_age_days);
    } else { $log_messages[] = __("Auto-purge disabled. Skipping.", 'deal-scraper-plugin'); }
    $log_messages[] = "--- " . __("Finished Auto-Purge Check", 'deal-scraper-plugin') . " ---";


    // --- *** UPDATED: Update Site Status using $wpdb->update *** ---
    $log_messages[] = "--- " . __("Updating Site Statuses in Options", 'deal-scraper-plugin') . " ---";
    $update_failed = false;
    global $wpdb; // Make sure $wpdb is available

    // Get a fresh copy of options just before saving
    wp_cache_delete( DSP_OPTION_NAME, 'options' );
    $options_before_save = get_option(DSP_OPTION_NAME, []);
    if (!is_array($options_before_save)) { $options_before_save = []; }
    $options_to_save = wp_parse_args($options_before_save, dsp_get_default_config()); // Merge with defaults

    $save_needed = false;
    $updated_count = 0;

    // Apply results from this run to the fresh options array
    foreach ($site_run_results as $site_index => $result_data) {
        if (isset($options_to_save['sites'][$site_index])) {
            $new_status = sanitize_text_field($result_data['status']);
            $new_time = intval($result_data['time']);
            $old_status = $options_to_save['sites'][$site_index]['last_status'] ?? '';
            $old_time = isset($options_to_save['sites'][$site_index]['last_run_time']) ? (int)$options_to_save['sites'][$site_index]['last_run_time'] : 0;

            if ($old_status !== $new_status || $old_time !== $new_time) {
                $options_to_save['sites'][$site_index]['last_status'] = $new_status;
                $options_to_save['sites'][$site_index]['last_run_time'] = $new_time;
                $save_needed = true;
                $updated_count++;
            }
        } else {
            $log_messages[] = sprintf(__("[WARN] Could not update status for site index %d - index not found.", 'deal-scraper-plugin'), $site_index);
        }
    }

    if ($save_needed) {
         error_log("DSP Cron: Attempting direct DB update for status of {$updated_count} sites.");

         // Serialize the ENTIRE options array
         $serialized_options = maybe_serialize($options_to_save);

         // Check for serialization errors
         if ($serialized_options === false && !is_scalar($options_to_save)) {
             error_log("DSP Cron Error: FAILED TO SERIALIZE options_to_save array before direct DB update.");
             $update_failed = true;
             $log_messages[] = __("[ERROR] Failed to serialize options data before saving. Check logs.", 'deal-scraper-plugin');
             $sites_with_errors[] = 'Options Serialization Failed (DB)';
         } else {
            // Use direct $wpdb->update on the options table
            $update_result = $wpdb->update(
                $wpdb->options,                          // Table name
                [ 'option_value' => $serialized_options ], // Data to update (serialized string)
                [ 'option_name' => DSP_OPTION_NAME ],    // WHERE clause
                [ '%s' ],                                // Format for data (%s for string)
                [ '%s' ]                                 // Format for WHERE (%s for string)
            );

            if ($update_result !== false) { // $wpdb->update returns num rows affected or false on error
                $log_messages[] = sprintf(__("Successfully saved status updates for %d sites (using direct DB update). Rows affected: %d", 'deal-scraper-plugin'), $updated_count, $update_result);
                wp_cache_delete( DSP_OPTION_NAME, 'options' ); // Clear WP object cache
                delete_transient( DSP_SOURCE_LIST_TRANSIENT );
                error_log("DSP Cron: Site status update saved via direct DB. Cleared source transient.");
            } else {
                // Direct DB update failed
                $db_error = $wpdb->last_error;
                $log_messages[] = __("[ERROR] Failed to save site status updates using direct DB update. Check DB permissions/size limits.", 'deal-scraper-plugin');
                error_log(sprintf("DSP Cron Error: Direct DB update for %s FAILED. DB Error: %s", DSP_OPTION_NAME, $db_error));
                $update_failed = true;
                $sites_with_errors[] = 'Options DB Save Failed';
            }
         }
    } else {
        $log_messages[] = __("No site status changes needed to be saved for processed sites.", 'deal-scraper-plugin');
    }
    // --- *** END UPDATED SECTION *** ---


    // --- Final Summary & Cleanup ---
    $duration = microtime(true) - $start_time;
    $attempted_count = count($sites_to_process_this_run);
    $final_summary_format = __("Cron Run finished: %.2f sec. Processed: %d site(s). New Deals Found: %d. Site Errors: %d. DB Errors: %d.", 'deal-scraper-plugin');
    $final_summary = sprintf($final_summary_format, $duration, $attempted_count, $new_deals_found_count, count($sites_with_errors), $db_errors);
    $log_messages[] = $final_summary; error_log("DSP Cron: " . $final_summary);
    if (!empty($sites_with_errors)) { $error_details = __("Sites with errors this run:", 'deal-scraper-plugin') . " " . implode(', ', $sites_with_errors); $log_messages[] = $error_details; error_log("DSP Cron: " . $error_details); }
    delete_transient('dsp_fetch_running'); $log_messages[] = __("Cron process completed.", 'deal-scraper-plugin');

    // --- Prepare return status ---
    $return_status = [ 'sites_processed' => $sites_processed_count, 'new_deals_count' => $new_deals_found_count, 'errors' => $sites_with_errors, 'log' => $log_messages ];
    if ($update_failed || !empty($sites_with_errors) || $db_errors > 0) {
        $return_status['error_summary'] = __("Cron run completed with errors. Check Debug Log.", 'deal-scraper-plugin');
    }

    return $return_status;
}