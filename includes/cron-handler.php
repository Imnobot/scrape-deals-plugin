<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main function executed by WP Cron or manually via Ajax.
 * Fetches deals, processes them, and optionally purges old deals.
 * Collects log messages for debugging.
 *
 * @param bool $manual_trigger Whether this was triggered manually.
 * @return array Status array including counts, errors, and a detailed log.
 */
function dsp_run_deal_fetch_cron( $manual_trigger = false ) {
    // --- Prevent overlapping runs ---
    if ( get_transient('dsp_fetch_running') ) {
        $log_messages = ['Fetch process already running. Aborting.'];
        error_log('DSP Cron: Attempted to run while previous fetch was still marked as running.');
        return [
            'error' => __('Fetch process already running. Please wait.', 'deal-scraper-plugin'),
            'log' => $log_messages
        ];
    }
    set_transient('dsp_fetch_running', true, 10 * MINUTE_IN_SECONDS);

    $start_time = microtime(true);
    $log_messages = []; // Initialize log collector
    $log_messages[] = sprintf(
        __("Fetch process starting%s at %s", 'deal-scraper-plugin'),
        ($manual_trigger ? __(" (Manual Trigger)", 'deal-scraper-plugin') : ""),
        date('Y-m-d H:i:s')
    );

    // --- Get Configuration ---
    // Get ALL options here to use for purge settings later
    $options = get_option( DSP_OPTION_NAME );
    $defaults = dsp_get_default_config(); // Make sure dsp_get_default_config is available
    $merged_options = wp_parse_args( $options, $defaults );

    $config_sites = $merged_options['sites'] ?? []; // Site specific config
    if (empty($config_sites)) {
         $log_messages[] = __("[ERROR] Failed to get site configuration.", 'deal-scraper-plugin');
         delete_transient('dsp_fetch_running');
         return [ 'error' => __('Failed to get configuration.', 'deal-scraper-plugin'), 'log' => $log_messages ];
    }

    $enabled_sites = array_filter($config_sites, function($site) {
        return !empty($site['enabled']) && !empty($site['url']) && !empty($site['parser']);
    });

    if (empty($enabled_sites)) {
        $log_messages[] = __("[INFO] No enabled sites found in configuration.", 'deal-scraper-plugin');
        // Don't necessarily return an error, just note it. We might still want to purge.
    } else {
        $log_messages[] = sprintf(
            __("Found %d enabled sites: %s", 'deal-scraper-plugin'),
            count($enabled_sites),
            implode(', ', array_keys($enabled_sites))
        );
    }

    // --- Fetching and Parsing Loop ---
    $all_fetched_deals = [];
    $sites_processed_count = 0;
    $sites_with_errors = [];

    foreach ($enabled_sites as $name => $site_info) {
        $url = $site_info['url'];
        $parser_func = $site_info['parser'];

        $log_messages[] = "--- " . sprintf(__("Processing Site: %s", 'deal-scraper-plugin'), $name) . " ---";

        if (!function_exists($parser_func)) {
            $error_msg = sprintf(__("[ERROR] Parser function '%s' for site '%s' does not exist.", 'deal-scraper-plugin'), $parser_func, $name);
            $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg);
            $sites_with_errors[] = $name . __('(Bad Parser)', 'deal-scraper-plugin');
            continue;
        }

        $log_messages[] = sprintf(__("Fetching URL: %s", 'deal-scraper-plugin'), $url);

        // Fetching arguments
        $args = [
            'timeout' => 45,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'sslverify' => false, // Keep this for shared hosting often
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept-Encoding' => 'gzip, deflate', // Let WP handle decompression
                'Upgrade-Insecure-Requests' => '1',
                'Cache-Control' => 'max-age=0',
            ]
        ];
        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            $error_msg = sprintf(__("[ERROR] WP Error fetching %s: %s", 'deal-scraper-plugin'), $name, $response->get_error_message());
            $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg);
            $sites_with_errors[] = $name . sprintf(__(' (Fetch Error: %s)', 'deal-scraper-plugin'), $response->get_error_code());
            continue;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $html_body = wp_remote_retrieve_body( $response );
        $content_length = strlen($html_body);
        $log_messages[] = sprintf(__("Fetch Result: Status Code=%d, Content Length=%d bytes.", 'deal-scraper-plugin'), $status_code, $content_length);

        if ( $status_code >= 400 || empty( $html_body ) ) {
             $error_msg = sprintf(__("[ERROR] HTTP Error %d or empty body for %s.", 'deal-scraper-plugin'), $status_code, $name);
             if (!empty($html_body)) {
                 $preview = substr( strip_tags($html_body), 0, 300);
                 $preview = preg_replace('/\s+/', ' ', $preview);
                 $error_msg .= " " . __("Body Preview:", 'deal-scraper-plugin') . " " . esc_html($preview) . "...";
             }
             $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg);
             $sites_with_errors[] = $name . sprintf(__(' (HTTP %d)', 'deal-scraper-plugin'), $status_code);
             continue;
        }

        $log_messages[] = sprintf(__("Parsing %s using '%s'...", 'deal-scraper-plugin'), $name, $parser_func);
        try {
            $site_deals = call_user_func($parser_func, $html_body, $url);
            if (is_array($site_deals)) {
                $deal_count = count($site_deals);
                $log_messages[] = sprintf(__("Parsing successful. Found %d deals from %s.", 'deal-scraper-plugin'), $deal_count, $name);
                 if ($deal_count > 0 && isset($site_deals[0])) { // Check if at least one deal exists
                     $example_deal_json = json_encode($site_deals[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                     $log_messages[] = __("Example Deal:", 'deal-scraper-plugin') . " " . ($example_deal_json ?: 'Error encoding example deal');
                 }
                $all_fetched_deals = array_merge($all_fetched_deals, $site_deals);
                 $sites_processed_count++;
            } else {
                 $error_msg = sprintf(__("[ERROR] Parser '%s' for %s did not return an array.", 'deal-scraper-plugin'), $parser_func, $name);
                 $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg);
                 $sites_with_errors[] = $name . __(' (Parse Error)', 'deal-scraper-plugin');
            }
        } catch (Exception $e) {
             $error_msg = sprintf(__("[ERROR] Exception during parsing %s: %s", 'deal-scraper-plugin'), $name, $e->getMessage());
             $log_messages[] = $error_msg; error_log("DSP Cron: " . $error_msg);
             $sites_with_errors[] = $name . __(' (Parse Exception)', 'deal-scraper-plugin');
             continue; // Continue to next site on exception
        }
         $log_messages[] = "--- " . sprintf(__("Finished Site: %s", 'deal-scraper-plugin'), $name) . " ---";
    } // End foreach site


    // --- Process fetched deals into DB ---
    $new_deals_found_count = 0;
    $deals_processed_db_count = 0;
    $db_errors = 0; // Initialize DB error counter
    if (!empty($all_fetched_deals)) {
        $total_to_process = count($all_fetched_deals);
        $log_messages[] = sprintf(__("Processing %d total fetched deals for Database...", 'deal-scraper-plugin'), $total_to_process);
        foreach ($all_fetched_deals as $i => $deal) {
             if (is_array($deal) && !empty($deal['link']) && !empty($deal['title'])) {
                 $deal_identifier = "'". esc_html($deal['title']) ."'"; // Sanitize for logging
                 if ($i < 3 || $i == $total_to_process -1) { $log_messages[] = sprintf(__("DB Proc [%d/%d]: Attempting %s", 'deal-scraper-plugin'), $i+1, $total_to_process, $deal_identifier); }
                 elseif ($i == 3) { $log_messages[] = __("DB Proc [... skipping detailed logs ...]", 'deal-scraper-plugin'); }

                 $add_update_result = DSP_DB_Handler::add_or_update_deal($deal);

                 if ($add_update_result === null) { // DB Error
                     $log_messages[] = sprintf(__("[ERROR] DB Proc [%d/%d]: Failed to add/update %s. Check PHP error log.", 'deal-scraper-plugin'), $i+1, $total_to_process, $deal_identifier);
                     $db_errors++; // Increment error count
                 } elseif ($add_update_result === true) { // New deal inserted
                     $deals_processed_db_count++; $new_deals_found_count++;
                     if ($i < 3 || $i == $total_to_process -1 || $new_deals_found_count < 4) { $log_messages[] = sprintf(__("DB Proc [%d/%d]: ---> NEW Deal Added: %s", 'deal-scraper-plugin'), $i+1, $total_to_process, $deal_identifier); }
                 } elseif ($add_update_result === false) { // Existing deal updated/invalid
                     $deals_processed_db_count++;
                      if ($i < 3 || $i == $total_to_process -1) { $log_messages[] = sprintf(__("DB Proc [%d/%d]: ---> Updated existing: %s", 'deal-scraper-plugin'), $i+1, $total_to_process, $deal_identifier); }
                 }
             } else {
                 $bad_deal_info = is_array($deal) ? json_encode($deal, JSON_PARTIAL_OUTPUT_ON_ERROR) : print_r($deal, true);
                 $log_messages[] = sprintf(__("[WARN] DB Proc [%d/%d]: Skipping invalid deal structure: %s", 'deal-scraper-plugin'), $i+1, $total_to_process, esc_html($bad_deal_info));
             }
        }
         $log_messages[] = sprintf(
             __("Finished DB processing. Processed (Add/Update): %d. New Deals Found: %d. DB Errors: %d.", 'deal-scraper-plugin'),
             $deals_processed_db_count, $new_deals_found_count, $db_errors
         );
    } else {
        $log_messages[] = __("No valid deals fetched from any site to process in DB.", 'deal-scraper-plugin');
    }

    // --- Update Last Fetch Time ---
    $last_fetch_updated = false;
    $fetch_http_errors = false;
    foreach ($sites_with_errors as $err) { if (strpos($err, __('Fetch Error', 'deal-scraper-plugin')) !== false || strpos($err, 'HTTP') !== false) { $fetch_http_errors = true; break; } }

    // Update time only if no major fetch errors occurred AND there were enabled sites to check
    if (!$fetch_http_errors && !empty($enabled_sites)) {
         update_option('dsp_last_fetch_time', time(), 'no'); // Use 'no' for autoload
         $last_fetch_updated = true;
         $log_messages[] = __("Updated 'dsp_last_fetch_time' option.", 'deal-scraper-plugin');
    } else {
         $log_messages[] = __("Skipped updating 'dsp_last_fetch_time' due to fetch/HTTP errors or no enabled sites.", 'deal-scraper-plugin');
    }


    // *** NEW: Auto-Purge Old Deals ***
    $log_messages[] = "--- " . __("Checking Auto-Purge Settings", 'deal-scraper-plugin') . " ---";
    // Use the $merged_options array retrieved earlier
    $purge_enabled = isset($merged_options['purge_enabled']) ? (bool) $merged_options['purge_enabled'] : false;
    $purge_max_age_days = isset($merged_options['purge_max_age_days']) ? intval($merged_options['purge_max_age_days']) : 90; // Default 90 from defaults

    if ($purge_enabled && $purge_max_age_days >= 1) {
        $log_messages[] = sprintf(__('Auto-purge enabled. Attempting to delete deals older than %d days.', 'deal-scraper-plugin'), $purge_max_age_days);

        // Call the static method from the DB Handler class
        $purge_result = DSP_DB_Handler::purge_old_deals($purge_max_age_days);

        if ($purge_result === false) {
            // DB Handler already logs detailed errors, just note it here
            $log_messages[] = __("[ERROR] Auto-purge failed. See previous DB Handler log entries or PHP error log.", 'deal-scraper-plugin');
            // Optionally increment overall error count if desired
            // $db_errors++; // Decide if purge failure counts as a main DB error
        } elseif (is_int($purge_result)) {
            // Success, log how many were deleted
            $log_messages[] = sprintf(__('Auto-purge completed. Deleted %d deals.', 'deal-scraper-plugin'), $purge_result);
        }
    } elseif ($purge_enabled && $purge_max_age_days < 1) {
         // Log if enabled but setting is invalid
         $log_messages[] = sprintf(__("[WARN] Auto-purge is enabled but max age (%d days) is invalid. Purge skipped.", 'deal-scraper-plugin'), $purge_max_age_days);
    } else {
        // Log if disabled
        $log_messages[] = __("Auto-purge is disabled. Skipping.", 'deal-scraper-plugin');
    }
    $log_messages[] = "--- " . __("Finished Auto-Purge Check", 'deal-scraper-plugin') . " ---";
    // *** END Auto-Purge ***


    // --- Final Summary & Cleanup ---
    $duration = microtime(true) - $start_time;
    // Final summary format string
    $final_summary_format = __("Fetch finished: %.2f sec. Attempted: %d. Ok: %d. New: %d. Site Err: %d. DB Err: %d. Updated Time: %s", 'deal-scraper-plugin');
    $final_summary = sprintf($final_summary_format,
        $duration,
        count($enabled_sites),          // Attempted
        $sites_processed_count,         // Ok
        $new_deals_found_count,         // New
        count($sites_with_errors),      // Site Errors
        $db_errors,                     // DB Errors (Add/Update errors primarily)
        ($last_fetch_updated ? __('Yes', 'deal-scraper-plugin') : __('No', 'deal-scraper-plugin')) // Updated Time
    );
    $log_messages[] = $final_summary;
    error_log("DSP Cron: " . $final_summary); // Log summary to PHP error log too

    if (!empty($sites_with_errors)) {
        $error_details = __("Sites with errors:", 'deal-scraper-plugin') . " " . implode(', ', $sites_with_errors);
        $log_messages[] = $error_details;
        error_log("DSP Cron: " . $error_details);
    }

    // Clear the running flag transient
    delete_transient('dsp_fetch_running');
    $log_messages[] = __("Fetch process completed.", 'deal-scraper-plugin');

    // --- Prepare return status ---
    $return_status = [
        'sites_processed' => $sites_processed_count,
        'new_deals_count' => $new_deals_found_count,
        'errors' => $sites_with_errors, // Array of site names with errors
        'log' => $log_messages
    ];

    // Add a summary error message if any errors occurred (site or DB)
    if (!empty($sites_with_errors) || $db_errors > 0) {
         $return_status['error_summary'] = __("Refresh completed with errors. Check Debug Log.", 'deal-scraper-plugin');
    }

    return $return_status;
}