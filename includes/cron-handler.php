<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main function executed by WP Cron or manually via Ajax.
 * Collects log messages for debugging.
 *
 * @param bool $manual_trigger Whether this was triggered manually.
 * @return array Status array including counts, errors, and a detailed log.
 */
function dsp_run_deal_fetch_cron( $manual_trigger = false ) {
    // Prevent overlapping runs if triggered frequently via AJAX / short cron interval
    if ( get_transient('dsp_fetch_running') ) {
        $log_messages = ['Fetch process already running. Aborting.'];
        error_log('DSP Cron: Attempted to run while previous fetch was still marked as running.');
        return [
            'error' => 'Fetch process already running. Please wait.',
            'log' => $log_messages
        ];
    }
    // Set a transient to mark the process as running, with a reasonable expiry (e.g., 10 minutes)
    set_transient('dsp_fetch_running', true, 10 * MINUTE_IN_SECONDS);


    $start_time = microtime(true);
    $log_messages = []; // Initialize log collector

    $log_messages[] = "Fetch process starting" . ($manual_trigger ? " (Manual Trigger)" : "") . " at " . date('Y-m-d H:i:s');

    $config = dsp_get_config(); // Assume dsp_get_config is available
    if (!$config) {
         $log_messages[] = "[ERROR] Failed to get configuration.";
         delete_transient('dsp_fetch_running'); // Clear running flag
         return [ 'error' => 'Failed to get configuration.', 'log' => $log_messages ];
    }

    $enabled_sites = array_filter($config, function($site) {
        return !empty($site['enabled']) && !empty($site['url']) && !empty($site['parser']);
    });

    if (empty($enabled_sites)) {
        $log_messages[] = "[ERROR] No enabled sites found in configuration.";
        error_log("DSP Cron: No enabled sites found in configuration.");
        delete_transient('dsp_fetch_running'); // Clear running flag
        return [
            'error' => 'No enabled sites configured.',
            'log' => $log_messages
        ];
    } else {
        $log_messages[] = "Found " . count($enabled_sites) . " enabled sites: " . implode(', ', array_keys($enabled_sites));
    }

    $all_fetched_deals = [];
    $sites_processed_count = 0;
    $sites_with_errors = [];

    foreach ($enabled_sites as $name => $site_info) {
        $url = $site_info['url'];
        $parser_func = $site_info['parser'];

        $log_messages[] = "--- Processing Site: {$name} ---";

        if (!function_exists($parser_func)) {
            $error_msg = "[ERROR] Parser function '{$parser_func}' for site '{$name}' does not exist.";
            $log_messages[] = $error_msg;
            error_log("DSP Cron: " . $error_msg);
            $sites_with_errors[] = $name . '(Bad Parser)';
            continue;
        }

        $log_messages[] = "Fetching URL: {$url}";

        $args = [
            'timeout' => 45,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'sslverify' => false, // Keep this for shared hosting often
             // Add headers that might help avoid blocking
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
            $error_msg = "[ERROR] WP Error fetching {$name}: " . $response->get_error_message();
            $log_messages[] = $error_msg;
            error_log("DSP Cron: " . $error_msg);
            $sites_with_errors[] = $name . '(Fetch Error: ' . $response->get_error_code() . ')';
            continue;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $html_body = wp_remote_retrieve_body( $response ); // WP handles decompression if needed
        $content_length = strlen($html_body);

        $log_messages[] = "Fetch Result: Status Code={$status_code}, Content Length={$content_length} bytes.";

        if ( $status_code >= 400 || empty( $html_body ) ) {
             $error_msg = "[ERROR] HTTP Error {$status_code} or empty body for {$name}.";
             if (!empty($html_body)) {
                 $preview = substr( strip_tags($html_body), 0, 300); // Strip tags for cleaner preview
                 $preview = preg_replace('/\s+/', ' ', $preview); // Consolidate whitespace
                $error_msg .= " Body Preview: " . esc_html($preview) . "...";
             }
             $log_messages[] = $error_msg;
             error_log("DSP Cron: " . $error_msg);
             $sites_with_errors[] = $name . '(HTTP ' . $status_code . ')';
             continue;
        }

        $log_messages[] = "Parsing {$name} using '{$parser_func}'...";
        try {
            $site_deals = call_user_func($parser_func, $html_body, $url);
            if (is_array($site_deals)) {
                $deal_count = count($site_deals);
                $log_messages[] = "Parsing successful. Found {$deal_count} deals from {$name}.";
                 if ($deal_count > 0) {
                     // Encode carefully for logging
                     $example_deal_json = json_encode($site_deals[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                     $log_messages[] = "Example Deal: " . ($example_deal_json ?: 'Error encoding example deal');
                 }
                $all_fetched_deals = array_merge($all_fetched_deals, $site_deals);
                 $sites_processed_count++;
            } else {
                 $error_msg = "[ERROR] Parser '{$parser_func}' for {$name} did not return an array.";
                 $log_messages[] = $error_msg;
                 error_log("DSP Cron: " . $error_msg);
                 $sites_with_errors[] = $name . '(Parse Error)';
            }
        } catch (Exception $e) {
             $error_msg = "[ERROR] Exception during parsing {$name}: " . $e->getMessage();
             $log_messages[] = $error_msg;
             error_log("DSP Cron: " . $error_msg);
             $sites_with_errors[] = $name . '(Parse Exception)';
             continue;
        }
         $log_messages[] = "--- Finished Site: {$name} ---";
    } // End foreach site


    // Process fetched deals into DB
    $new_deals_found_count = 0;
    $deals_processed_db_count = 0;
    $db_errors = 0;
    if (!empty($all_fetched_deals)) {
        $total_to_process = count($all_fetched_deals);
        $log_messages[] = "Processing {$total_to_process} total fetched deals for Database...";
        foreach ($all_fetched_deals as $i => $deal) {
            $deal_identifier = !empty($deal['title']) ? "'{$deal['title']}'" : (!empty($deal['link']) ? "'{$deal['link']}'" : 'Unknown');
             // Basic check for valid deal structure before attempting DB operation
            if (is_array($deal) && !empty($deal['link']) && !empty($deal['title'])) {
                // Log first few deals being processed
                 if ($i < 3 || $i == $total_to_process -1) {
                     $log_messages[] = "DB Proc [".($i+1)."/".$total_to_process."]: Attempting {$deal_identifier}";
                 } elseif ($i == 3) {
                     $log_messages[] = "DB Proc [... skipping detailed logs for brevity ...]";
                 }

                 $add_update_result = DSP_DB_Handler::add_or_update_deal($deal);

                 if ($add_update_result === null) { // DB Error
                     $log_messages[] = "[ERROR] DB Proc [".($i+1)."/".$total_to_process."]: Failed to add/update {$deal_identifier}. Check PHP error log for DB details.";
                     $db_errors++;
                 } elseif ($add_update_result === true) { // New deal inserted
                     $deals_processed_db_count++;
                     $new_deals_found_count++;
                     if ($i < 3 || $i == $total_to_process -1 || $new_deals_found_count < 4) {
                        $log_messages[] = "DB Proc [".($i+1)."/".$total_to_process."]: ---> NEW Deal Added: {$deal_identifier}";
                     }
                 } elseif ($add_update_result === false) { // Existing deal updated or invalid data
                     $deals_processed_db_count++;
                      if ($i < 3 || $i == $total_to_process -1) {
                        $log_messages[] = "DB Proc [".($i+1)."/".$total_to_process."]: ---> Updated existing: {$deal_identifier}";
                      }
                 }
             } else {
                  $bad_deal_info = is_array($deal) ? json_encode($deal) : print_r($deal, true);
                 $log_messages[] = "[WARN] DB Proc [".($i+1)."/".$total_to_process."]: Skipping invalid deal structure or missing link/title: " . $bad_deal_info;
             }
        }
         $log_messages[] = "Finished DB processing. Deals Processed (Add/Update): {$deals_processed_db_count}. New Deals Found: {$new_deals_found_count}. DB Errors: {$db_errors}.";
    } else {
        $log_messages[] = "No valid deals fetched from any site to process in DB.";
    }

    $last_fetch_updated = false;
    $fetch_http_errors = false;
    foreach ($sites_with_errors as $err) {
        if (strpos($err, 'Fetch Error') !== false || strpos($err, 'HTTP') !== false) {
            $fetch_http_errors = true;
            break;
        }
    }

    if (!$fetch_http_errors && !empty($enabled_sites)) {
         update_option('dsp_last_fetch_time', time(), 'no'); // Use 'no' for autoload
         $last_fetch_updated = true;
         $log_messages[] = "Updated 'dsp_last_fetch_time' option.";
    } else {
         $log_messages[] = "Skipped updating 'dsp_last_fetch_time' due to fetch/HTTP errors or no enabled sites.";
    }

    $duration = microtime(true) - $start_time;
    // *** CORRECTED sprintf call - Ensure placeholders match variables ***
    // Format string: "%.2f sec. Attempted: %d. Ok: %d. New: %d. Site Err: %d. DB Err: %d. Updated Time: %s" (7 placeholders)
    // Variables:     $duration, count($enabled_sites), $sites_processed_count, $new_deals_found_count, count($sites_with_errors), $db_errors, ($last_fetch_updated ? 'Yes':'No') (7 variables)
    $final_summary = sprintf("Fetch finished: %.2f sec. Attempted: %d. Ok: %d. New: %d. Site Err: %d. DB Err: %d. Updated Time: %s",
        $duration,
        count($enabled_sites), // Attempted
        $sites_processed_count, // Ok
        $new_deals_found_count, // New
        count($sites_with_errors), // Site Errors
        $db_errors, // DB Errors
        ($last_fetch_updated ? 'Yes' : 'No') // Updated Time
    );
    $log_messages[] = $final_summary;
    error_log("DSP Cron: " . $final_summary);

    if (!empty($sites_with_errors)) {
        $error_details = "Sites with errors: " . implode(', ', $sites_with_errors);
        $log_messages[] = $error_details;
        error_log("DSP Cron: " . $error_details);
    }

    // Clear the running flag transient
    delete_transient('dsp_fetch_running');
    $log_messages[] = "Fetch process completed.";

    // Prepare return status
    $return_status = [
        'sites_processed' => $sites_processed_count,
        'new_deals_count' => $new_deals_found_count,
        'errors' => $sites_with_errors,
        'log' => $log_messages
    ];

    if (!empty($sites_with_errors) || $db_errors > 0) {
         $return_status['error_summary'] = "Refresh completed with errors. Check Debug Log.";
    }

    return $return_status;
}