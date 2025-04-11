<?php
// File: includes/cron-handler.php (v1.1.15 - Staggered Cron Logic)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main function executed by WP Cron or manually via Ajax.
 * Processes ONE enabled site per run, choosing the one least recently checked.
 * Records the status for the processed site.
 *
 * @param bool $manual_trigger If true, processes ALL enabled sites (like old behavior).
 * @return array Status array including counts, errors, and a detailed log.
 */
function dsp_run_deal_fetch_cron( $manual_trigger = false ) {
    // --- Prevent overlapping runs (more important with frequent schedule) ---
    if ( !$manual_trigger && get_transient('dsp_fetch_running') ) {
        // Don't log error if it's just frequent schedule overlap, only if manually triggered
        // error_log('DSP Cron: Staggered run skipped - previous run potentially still active.');
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
    $defaults = dsp_get_default_config();
    $merged_options = wp_parse_args($options, $defaults);
    $all_sites = $merged_options['sites'] ?? []; // Use merged to get status fields too

    if (empty($all_sites) || !is_array($all_sites)) {
        $log_messages[] = __("[ERROR] No site configurations found.", 'deal-scraper-plugin');
        delete_transient('dsp_fetch_running');
        return [ 'error' => __('No site configurations found.', 'deal-scraper-plugin'), 'log' => $log_messages ];
    }

    $enabled_sites = [];
    foreach ($all_sites as $index => $site_data) {
        if (!empty($site_data['enabled'])) {
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
        // Manual trigger processes ALL enabled sites
        $sites_to_process_this_run = $enabled_sites;
        $log_messages[] = sprintf( __("Manual trigger: Processing all %d enabled sites.", 'deal-scraper-plugin'), count($sites_to_process_this_run) );
    } else {
        // Staggered run: Find the ONE enabled site with the oldest 'last_run_time'
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
            // Add only the selected site to the list to process
            $sites_to_process_this_run[$site_index_to_run] = $enabled_sites[$site_index_to_run];
            $log_messages[] = sprintf( __("Staggered run: Selected site '%s' (Index: %d, Last Run: %s) to process.", 'deal-scraper-plugin'),
                $enabled_sites[$site_index_to_run]['name'] ?? 'Unknown',
                $site_index_to_run,
                $oldest_time > 0 ? date('Y-m-d H:i:s', $oldest_time) : 'Never'
            );
        } else {
            $log_messages[] = __("[WARN] Could not determine which site to process in staggered run.", 'deal-scraper-plugin');
        }
    }

    // --- Fetching and Parsing Loop (Processes only selected sites) ---
    $all_fetched_deals = []; $sites_processed_count = 0; $sites_with_errors = [];
    $site_run_results = []; // Store results for the sites processed *in this run*

    foreach ($sites_to_process_this_run as $site_index => $site_info) {
        // Basic info needed
        $site_name = $site_info['name'] ?? 'Unknown Site ' . $site_index; $url = $site_info['url']; $parser_file = $site_info['parser_file']; $parser_func = 'parse_' . strtolower( $parser_file ) . '_php'; $current_site_status = '';

        $log_messages[] = "--- " . sprintf(__("Processing Site: %s", 'deal-scraper-plugin'), $site_name) . " ---";

        if (!function_exists($parser_func)) { $error_msg = sprintf(__("[ERROR] Parser function '%s' for site '%s' missing.", 'deal-scraper-plugin'), $parser_func, $site_name); $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg); $sites_with_errors[] = $site_name . __(' (Bad Parser)', 'deal-scraper-plugin'); $current_site_status = sprintf(__('Error: Parser `%s` missing', 'deal-scraper-plugin'), esc_html($parser_func)); $site_run_results[$site_index] = ['status' => $current_site_status, 'time' => $run_timestamp]; continue; }
        $log_messages[] = sprintf(__("Fetching URL: %s", 'deal-scraper-plugin'), $url); $args = [ 'timeout' => 45, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36', 'sslverify' => false, 'headers' => [ 'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7', 'Accept-Language' => 'en-US,en;q=0.9', 'Accept-Encoding' => 'gzip, deflate', 'Upgrade-Insecure-Requests' => '1', 'Cache-Control' => 'max-age=0', ] ]; $response = wp_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) { $error_msg = sprintf(__("[ERROR] WP Error fetching %s: %s", 'deal-scraper-plugin'), $site_name, $response->get_error_message()); $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg); $sites_with_errors[] = $site_name . sprintf(__(' (Fetch Error: %s)', 'deal-scraper-plugin'), $response->get_error_code()); $current_site_status = sprintf(__('Error: Fetch failed (%s)', 'deal-scraper-plugin'), esc_html($response->get_error_code())); $site_run_results[$site_index] = ['status' => $current_site_status, 'time' => $run_timestamp]; continue; }
        $status_code = wp_remote_retrieve_response_code( $response ); $html_body = wp_remote_retrieve_body( $response ); $content_length = strlen($html_body); $log_messages[] = sprintf(__("Fetch Result: Status Code=%d, Content Length=%d bytes.", 'deal-scraper-plugin'), $status_code, $content_length);
        if ( $status_code >= 400 || empty( $html_body ) ) { $error_msg = sprintf(__("[ERROR] HTTP Error %d or empty body for %s.", 'deal-scraper-plugin'), $status_code, $site_name); if (!empty($html_body)) { $preview = substr( strip_tags($html_body), 0, 300); $preview = preg_replace('/\s+/', ' ', $preview); $error_msg .= " " . __("Body Preview:", 'deal-scraper-plugin') . " " . esc_html($preview) . "..."; } $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg); $sites_with_errors[] = $site_name . sprintf(__(' (HTTP %d)', 'deal-scraper-plugin'), $status_code); $current_site_status = sprintf(__('Error: HTTP %d', 'deal-scraper-plugin'), $status_code); $site_run_results[$site_index] = ['status' => $current_site_status, 'time' => $run_timestamp]; continue; }
        $log_messages[] = sprintf(__("Parsing %s using '%s'...", 'deal-scraper-plugin'), $site_name, $parser_func);
        try { $site_deals = call_user_func($parser_func, $html_body, $url, $site_name); if (is_array($site_deals)) { $deal_count = count($site_deals); $log_messages[] = sprintf(__("Parsing successful. Found %d deals from %s.", 'deal-scraper-plugin'), $deal_count, $site_name); if ($deal_count > 0 && isset($site_deals[0])) { foreach ($site_deals as &$deal_ref) { if (is_array($deal_ref)) { $deal_ref['source'] = $site_name; } } unset($deal_ref); $example_deal_json = json_encode($site_deals[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); $log_messages[] = __("Example Deal:", 'deal-scraper-plugin') . " " . ($example_deal_json ?: 'Error encoding example deal'); } $all_fetched_deals = array_merge($all_fetched_deals, $site_deals); $sites_processed_count++; $current_site_status = sprintf(_n('Success: Found %d deal', 'Success: Found %d deals', $deal_count, 'deal-scraper-plugin'), $deal_count); } else { $error_msg = sprintf(__("[ERROR] Parser '%s' for %s did not return an array.", 'deal-scraper-plugin'), $parser_func, $site_name); $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg); $sites_with_errors[] = $site_name . __(' (Parse Error)', 'deal-scraper-plugin'); $current_site_status = sprintf(__('Error: Parser `%s` failed', 'deal-scraper-plugin'), esc_html($parser_func)); }
        } catch (Exception $e) { $error_msg = sprintf(__("[ERROR] Exception during parsing %s: %s", 'deal-scraper-plugin'), $site_name, $e->getMessage()); $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg); $sites_with_errors[] = $site_name . __(' (Parse Exception)', 'deal-scraper-plugin'); $current_site_status = __('Error: Parse Exception', 'deal-scraper-plugin'); }
        $site_run_results[$site_index] = ['status' => $current_site_status, 'time' => $run_timestamp]; $log_messages[] = "--- " . sprintf(__("Finished Site: %s", 'deal-scraper-plugin'), $site_name) . " ---";
    } // End foreach site_to_process

    // --- Process fetched deals into DB (Only deals from processed sites) ---
    $new_deals_found_count = 0; $deals_processed_db_count = 0; $db_errors = 0;
    if (!empty($all_fetched_deals)) { $total_to_process = count($all_fetched_deals); $log_messages[] = sprintf(__("Processing %d total fetched deals for Database...", 'deal-scraper-plugin'), $total_to_process); foreach ($all_fetched_deals as $i => $deal) { if (is_array($deal) && !empty($deal['link']) && !empty($deal['title']) && isset($deal['source'])) { $deal_identifier = "'". esc_html($deal['title']) ."' from ". esc_html($deal['source']); if ($i < 3 || $i == $total_to_process -1) { $log_messages[] = sprintf(__("DB Proc [%d/%d]: Attempting %s", 'deal-scraper-plugin'), $i+1, $total_to_process, $deal_identifier); } elseif ($i == 3) { $log_messages[] = __("DB Proc [... skipping detailed logs ...]", 'deal-scraper-plugin'); } $add_update_result = DSP_DB_Handler::add_or_update_deal($deal); if ($add_update_result === null) { $log_messages[] = sprintf(__("[ERROR] DB Proc [%d/%d]: Failed to add/update %s. Check PHP error log.", 'deal-scraper-plugin'), $i+1, $total_to_process, $deal_identifier); $db_errors++; } elseif ($add_update_result === true) { $deals_processed_db_count++; $new_deals_found_count++; if ($i < 3 || $i == $total_to_process -1 || $new_deals_found_count < 4) { $log_messages[] = sprintf(__("DB Proc [%d/%d]: ---> NEW Deal Added: %s", 'deal-scraper-plugin'), $i+1, $total_to_process, $deal_identifier); } } elseif ($add_update_result === false) { $deals_processed_db_count++; if ($i < 3 || $i == $total_to_process -1) { $log_messages[] = sprintf(__("DB Proc [%d/%d]: ---> Updated existing: %s", 'deal-scraper-plugin'), $i+1, $total_to_process, $deal_identifier); } } } else { $bad_deal_info = is_array($deal) ? json_encode($deal, JSON_PARTIAL_OUTPUT_ON_ERROR) : print_r($deal, true); $log_messages[] = sprintf(__("[WARN] DB Proc [%d/%d]: Skipping invalid deal structure or missing source: %s", 'deal-scraper-plugin'), $i+1, $total_to_process, esc_html($bad_deal_info)); } } $log_messages[] = sprintf( __("Finished DB processing. Processed (Add/Update): %d. New Deals Found: %d. DB Errors: %d.", 'deal-scraper-plugin'), $deals_processed_db_count, $new_deals_found_count, $db_errors ); }
    else { $log_messages[] = __("No valid deals fetched from processed site(s) to process in DB.", 'deal-scraper-plugin'); }

    // --- Update Last Fetch Time (No longer updated globally) ---
    // $last_fetch_updated = false; ... update_option('dsp_last_fetch_time', ...);
    $log_messages[] = __("Global 'dsp_last_fetch_time' option is no longer updated in staggered mode.", 'deal-scraper-plugin');
    $last_fetch_updated = false; // Keep variable for summary consistency

    // --- Auto-Purge Old Deals ---
    // Read options needed for purge check
    $purge_check_options = get_option( DSP_OPTION_NAME, [] );
    $purge_defaults = dsp_get_default_config();
    $purge_merged_options = wp_parse_args($purge_check_options, $purge_defaults);
    $log_messages[] = "--- " . __("Checking Auto-Purge Settings", 'deal-scraper-plugin') . " ---"; $purge_enabled = isset($purge_merged_options['purge_enabled']) ? (bool) $purge_merged_options['purge_enabled'] : false; $purge_max_age_days = isset($purge_merged_options['purge_max_age_days']) ? intval($purge_merged_options['purge_max_age_days']) : 90;
    if ($purge_enabled && $purge_max_age_days >= 1) { $log_messages[] = sprintf(__('Auto-purge enabled. Attempting to delete deals older than %d days.', 'deal-scraper-plugin'), $purge_max_age_days); $purge_result = DSP_DB_Handler::purge_old_deals($purge_max_age_days); if ($purge_result === false) { $log_messages[] = __("[ERROR] Auto-purge failed. See logs.", 'deal-scraper-plugin'); } elseif (is_int($purge_result)) { $log_messages[] = sprintf(__('Auto-purge completed. Deleted %d deals.', 'deal-scraper-plugin'), $purge_result); } } elseif ($purge_enabled && $purge_max_age_days < 1) { $log_messages[] = sprintf(__("[WARN] Auto-purge enabled but max age (%d) invalid. Skipped.", 'deal-scraper-plugin'), $purge_max_age_days); } else { $log_messages[] = __("Auto-purge disabled. Skipping.", 'deal-scraper-plugin'); } $log_messages[] = "--- " . __("Finished Auto-Purge Check", 'deal-scraper-plugin') . " ---";


    // *** Update Site Status in Options (Using $wpdb->update) ***
    $log_messages[] = "--- " . __("Updating Site Statuses in Options", 'deal-scraper-plugin') . " ---";
    $update_failed = false;
    wp_cache_delete( DSP_OPTION_NAME, 'options' ); $options_before_status_save = get_option(DSP_OPTION_NAME, []); if ( !is_array($options_before_status_save) ) { $options_before_status_save = []; } $defaults_for_status = dsp_get_default_config(); $sites_to_update = isset($options_before_status_save['sites']) && is_array($options_before_status_save['sites']) ? $options_before_status_save['sites'] : $defaults_for_status['sites'];
    $save_needed = false; $updated_count = 0;

    foreach ($site_run_results as $site_index => $result_data) { // Only loop through sites processed THIS RUN
        if (isset($sites_to_update[$site_index])) {
            $new_status = sanitize_text_field($result_data['status']); $new_time = intval($result_data['time']);
            $old_status = $sites_to_update[$site_index]['last_status'] ?? ''; $old_time = isset($sites_to_update[$site_index]['last_run_time']) ? (int)$sites_to_update[$site_index]['last_run_time'] : 0;
            if ($old_status !== $new_status || $old_time !== $new_time) { $sites_to_update[$site_index]['last_status'] = $new_status; $sites_to_update[$site_index]['last_run_time'] = $new_time; $save_needed = true; $updated_count++; }
        } else { $log_messages[] = sprintf(__("[WARN] Could not update status for site index %d - index not found.", 'deal-scraper-plugin'), $site_index); }
    }

    if ($save_needed) {
         $options_to_save = $options_before_status_save; $options_to_save['sites'] = $sites_to_update;
         $serialized_data = maybe_serialize($options_to_save);
         if ($serialized_data === false && !is_scalar($options_to_save)) { $log_messages[] = __("[ERROR] Failed to serialize site status data before saving.", 'deal-scraper-plugin'); error_log("DSP Cron Error: maybe_serialize returned false."); $update_failed = true; $sites_with_errors[] = 'Serialization Failed';
         } else {
             $data_to_save_size = strlen($serialized_data); $log_messages[] = sprintf(__("Attempting to save updated site statuses using direct DB update. Data size: %d bytes.", 'deal-scraper-plugin'), $data_to_save_size); error_log(sprintf("DSP Cron Debug: Attempting \$wpdb->update for %s. Size: %d bytes.", DSP_OPTION_NAME, $data_to_save_size));
             global $wpdb; $result = $wpdb->update( $wpdb->options, ['option_value' => $serialized_data], ['option_name' => DSP_OPTION_NAME], ['%s'], ['%s'] );
             if ($result !== false) { $log_messages[] = sprintf(__("Successfully saved status updates for %d sites (DB rows affected: %d).", 'deal-scraper-plugin'), $updated_count, $result); error_log(sprintf("DSP Cron Debug: \$wpdb->update for %s returned: %s.", DSP_OPTION_NAME, print_r($result, true))); wp_cache_delete( DSP_OPTION_NAME, 'options' ); }
             else { $log_messages[] = __("[ERROR] Failed to save site status updates to options using \$wpdb->update. Check DB logs.", 'deal-scraper-plugin'); error_log(sprintf("DSP Cron Debug: \$wpdb->update for %s FAILED (\$wpdb->last_error: %s).", DSP_OPTION_NAME, $wpdb->last_error)); $update_failed = true; $sites_with_errors[] = 'Options DB Save Failed'; }
         }
    } else { $log_messages[] = __("No site status changes needed to be saved for processed sites.", 'deal-scraper-plugin'); }
    // *** END Update Site Status ***


    // --- Final Summary & Cleanup ---
    $duration = microtime(true) - $start_time;
    // Adjust summary for potentially processing only one site
    $attempted_count = count($sites_to_process_this_run); // How many we TRIED this run
    $final_summary_format = __("Cron Run finished: %.2f sec. Processed: %d site(s). New Deals Found: %d. Site Errors: %d. DB Errors: %d.", 'deal-scraper-plugin');
    $final_summary = sprintf($final_summary_format, $duration, $attempted_count, $new_deals_found_count, count($sites_with_errors), $db_errors);
    $log_messages[] = $final_summary; error_log("DSP Cron: " . $final_summary);
    if (!empty($sites_with_errors)) { $error_details = __("Sites with errors this run:", 'deal-scraper-plugin') . " " . implode(', ', $sites_with_errors); $log_messages[] = $error_details; error_log("DSP Cron: " . $error_details); }
    delete_transient('dsp_fetch_running'); $log_messages[] = __("Cron process completed.", 'deal-scraper-plugin');

    // --- Prepare return status ---
    $return_status = [ 'sites_processed' => $sites_processed_count, 'new_deals_count' => $new_deals_found_count, 'errors' => $sites_with_errors, 'log' => $log_messages ];
    if ($update_failed) { // Make sure save failure is reported
        $sites_with_errors[] = 'Options Save Failed';
        $return_status['errors'] = $sites_with_errors;
    }
    if (!empty($sites_with_errors) || $db_errors > 0 ) { $return_status['error_summary'] = __("Cron run completed with errors. Check Debug Log.", 'deal-scraper-plugin'); }

    return $return_status;
}