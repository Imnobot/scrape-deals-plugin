<?php
// File: includes/db-handler.php (v1.1.50 - Correct if/else logic for attachment ID; Removed redundant price_idx)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DSP_DB_Handler {

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'dsp_deals';
    }

    /** Creates or updates the database table schema using dbDelta */
    public static function create_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // Define table structure with optimized indexes
        $sql = "CREATE TABLE $table_name (
            link VARCHAR(767) NOT NULL,
            title TEXT NOT NULL,
            price VARCHAR(100) DEFAULT '' NOT NULL,          -- Original price string (kept for display)
            source VARCHAR(100) NOT NULL,
            description TEXT NOT NULL,
            image_url VARCHAR(2048) DEFAULT '' NOT NULL,
            image_attachment_id BIGINT(20) DEFAULT 0 NOT NULL,
            first_seen DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
            last_seen DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
            is_ltd TINYINT(1) DEFAULT 0 NOT NULL,          -- Indexed for LTD filter
            price_numeric DECIMAL(10, 2) NULL DEFAULT NULL, -- Indexed for price filter/sort
            PRIMARY KEY (link),                             -- Primary index on unique deal link
            INDEX source_idx (source),                      -- Index for source filter
            INDEX first_seen_idx (first_seen),              -- Index for sorting/purging/new
            -- Removed INDEX price_idx (price(10)) as it's redundant with price_numeric_idx
            INDEX is_ltd_idx (is_ltd),                      -- Index for LTD filter
            INDEX price_numeric_idx (price_numeric),        -- Index for price range filter/sort
            INDEX attachment_id_idx (image_attachment_id)   -- Index for image processing status
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        // Log planned changes (optional for debugging dbDelta)
        $delta_results = dbDelta( $sql, false );
        error_log("DSP DB Check: dbDelta planned SQL changes: " . print_r($delta_results, true));
        // Execute dbDelta
        dbDelta( $sql );
        error_log("DSP DB Check: dbDelta executed for table {$table_name}.");

        // Verify table existence
        $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name;
        if ( ! $table_exists ) {
            error_log( "DSP Error: Failed to create/update table: $table_name" );
        }
        else {
            // Specific check/fix for image_attachment_id UNSIGNED issue (if needed from previous versions)
             $col_type = $wpdb->get_row("SHOW COLUMNS FROM `{$table_name}` LIKE 'image_attachment_id'", ARRAY_A);
             if (isset($col_type['Type']) && strpos(strtolower($col_type['Type']), 'unsigned') !== false) {
                 error_log("DSP DB Warning: image_attachment_id column is still UNSIGNED. Attempting ALTER...");
                 $alter_success = $wpdb->query("ALTER TABLE `{$table_name}` MODIFY COLUMN `image_attachment_id` BIGINT(20) NOT NULL DEFAULT 0");
                 if ($alter_success === false) {
                     error_log("DSP DB Error: Failed to modify image_attachment_id to remove UNSIGNED. DB Error: " . $wpdb->last_error);
                 } else {
                     error_log("DSP DB Success: Modified image_attachment_id column to remove UNSIGNED.");
                 }
             } else {
                 // error_log("DSP DB Info: image_attachment_id column type appears correct (not UNSIGNED).");
             }
        }
    }

    /** Explicitly adds the 'is_ltd' column and index */
    public static function add_ltd_column_and_index() {
        global $wpdb; $table_name = self::get_table_name();
        $column_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = %s AND table_name = %s AND column_name = %s", DB_NAME, $table_name, 'is_ltd' ) );
        if ( $column_exists == 0 ) {
            error_log("DSP DB Upgrade: 'is_ltd' column not found. Adding...");
            $alter_result = $wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN `is_ltd` TINYINT(1) NOT NULL DEFAULT 0 AFTER `last_seen`;" );
            if ($alter_result === false) { error_log("DSP DB Upgrade Error adding 'is_ltd': " . $wpdb->last_error); }
            else { error_log("DSP DB Upgrade: Added 'is_ltd'."); }
        } else { /* error_log("DSP DB Upgrade: 'is_ltd' column exists."); */ }

        // Add index if column exists but index doesn't
        if ($column_exists > 0) {
            $index_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = %s AND table_name = %s AND index_name = %s", DB_NAME, $table_name, 'is_ltd_idx' ) );
            if ( $index_exists == 0 ) {
                error_log("DSP DB Upgrade: 'is_ltd_idx' index not found. Adding...");
                $alter_index_result = $wpdb->query( "ALTER TABLE `{$table_name}` ADD INDEX `is_ltd_idx` (`is_ltd`);" );
                if ($alter_index_result === false) { error_log("DSP DB Upgrade Error adding 'is_ltd_idx': " . $wpdb->last_error); }
                else { error_log("DSP DB Upgrade: Added 'is_ltd_idx'."); }
            } else { /* error_log("DSP DB Upgrade: 'is_ltd_idx' index exists."); */ }
        }
    }

    /** Explicitly adds the 'price_numeric' column and index */
    public static function add_price_numeric_column_and_index() {
        global $wpdb; $table_name = self::get_table_name();
        $column_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = %s AND table_name = %s AND column_name = %s", DB_NAME, $table_name, 'price_numeric' ) );
        if ( $column_exists == 0 ) {
            error_log("DSP DB Upgrade: 'price_numeric' column not found. Adding...");
            $alter_result = $wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN `price_numeric` DECIMAL(10, 2) NULL DEFAULT NULL AFTER `is_ltd`;" );
            if ($alter_result === false) { error_log("DSP DB Upgrade Error adding 'price_numeric': " . $wpdb->last_error); }
            else { error_log("DSP DB Upgrade: Added 'price_numeric'."); }
        } else { /* error_log("DSP DB Upgrade: 'price_numeric' column exists."); */ }

        // Add index if column exists but index doesn't
        if ($column_exists > 0) {
            $index_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = %s AND table_name = %s AND index_name = %s", DB_NAME, $table_name, 'price_numeric_idx' ) );
            if ( $index_exists == 0 ) {
                error_log("DSP DB Upgrade: 'price_numeric_idx' index not found. Adding...");
                $alter_index_result = $wpdb->query( "ALTER TABLE `{$table_name}` ADD INDEX `price_numeric_idx` (`price_numeric`);" );
                if ($alter_index_result === false) { error_log("DSP DB Upgrade Error adding 'price_numeric_idx': " . $wpdb->last_error); }
                else { error_log("DSP DB Upgrade: Added 'price_numeric_idx'."); }
            } else { /* error_log("DSP DB Upgrade: 'price_numeric_idx' index exists."); */ }
        }
    }

    /** Explicitly adds the 'image_url' column */
    public static function add_image_url_column() {
        global $wpdb; $table_name = self::get_table_name();
        $column_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = %s AND table_name = %s AND column_name = %s", DB_NAME, $table_name, 'image_url' ) );
        if ( $column_exists == 0 ) {
            error_log("DSP DB Upgrade: 'image_url' column not found. Adding...");
            $alter_result = $wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN `image_url` VARCHAR(2048) NOT NULL DEFAULT '' AFTER `description`;" );
            if ($alter_result === false) { error_log("DSP DB Upgrade Error adding 'image_url': " . $wpdb->last_error); }
            else { error_log("DSP DB Upgrade: Added 'image_url'."); }
        } else { /* error_log("DSP DB Upgrade: 'image_url' column exists."); */ }
    }

    /** Explicitly adds the 'image_attachment_id' column and index */
    public static function add_image_attachment_id_column() {
        global $wpdb; $table_name = self::get_table_name();
        $column_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = %s AND table_name = %s AND column_name = %s", DB_NAME, $table_name, 'image_attachment_id' ) );
        if ( $column_exists == 0 ) {
            error_log("DSP DB Upgrade: 'image_attachment_id' column not found. Adding...");
            $alter_result = $wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN `image_attachment_id` BIGINT(20) NOT NULL DEFAULT 0 AFTER `image_url`;" );
            if ($alter_result === false) { error_log("DSP DB Upgrade Error adding 'image_attachment_id': " . $wpdb->last_error); }
            else { error_log("DSP DB Upgrade: Added 'image_attachment_id'."); }
        } else { /* error_log("DSP DB Upgrade: 'image_attachment_id' column exists."); */ }

        // Check/Fix UNSIGNED attribute (as done in create_table)
        if ($column_exists > 0) {
             $col_type = $wpdb->get_row("SHOW COLUMNS FROM `{$table_name}` LIKE 'image_attachment_id'", ARRAY_A);
             if (isset($col_type['Type']) && strpos(strtolower($col_type['Type']), 'unsigned') !== false) {
                 error_log("DSP DB Upgrade: Modifying 'image_attachment_id' to remove UNSIGNED...");
                 $alter_success = $wpdb->query("ALTER TABLE `{$table_name}` MODIFY COLUMN `image_attachment_id` BIGINT(20) NOT NULL DEFAULT 0");
                 if ($alter_success === false) { error_log("DSP DB Error: Failed to modify image_attachment_id. DB Error: " . $wpdb->last_error); }
                 else { error_log("DSP DB Success: Modified image_attachment_id column."); }
             } else { /* error_log("DSP DB Info: image_attachment_id column type appears correct (not UNSIGNED)."); */ }

            // Add index if column exists but index doesn't
            $index_exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = %s AND table_name = %s AND index_name = %s", DB_NAME, $table_name, 'attachment_id_idx' ) );
            if ( $index_exists == 0 ) {
                error_log("DSP DB Upgrade: 'attachment_id_idx' index not found. Adding...");
                $alter_index_result = $wpdb->query( "ALTER TABLE `{$table_name}` ADD INDEX `attachment_id_idx` (`image_attachment_id`);" );
                if ($alter_index_result === false) { error_log("DSP DB Upgrade Error adding 'attachment_id_idx': " . $wpdb->last_error); }
                else { error_log("DSP DB Upgrade: Added 'attachment_id_idx'."); }
            } else { /* error_log("DSP DB Upgrade: 'attachment_id_idx' index exists."); */ }
        }
    }

    /** Adds a new deal or updates an existing one. Flags deals for image processing using -2. */
    public static function add_or_update_deal( $deal ) {
        global $wpdb;
        $table_name = self::get_table_name();

        // Basic validation
        if ( empty( $deal['link'] ) || $deal['link'] === '#' || strlen($deal['link']) > 767 ) { return false; }
        if ( empty( $deal['title'] ) ) { return false; }

        $now_gmt = current_time( 'mysql', true );
        $link_sanitized = sanitize_text_field( substr($deal['link'], 0, 767) );
        $title_sanitized = sanitize_text_field($deal['title']);
        $price_numeric = self::parse_price_to_numeric($deal['price'] ?? '');
        $is_ltd_flag = isset($deal['is_ltd']) ? ( (bool) $deal['is_ltd'] ? 1 : 0 ) : 0; // Calculate LTD flag

        // Sanitize image URL
        $image_url_sanitized = '';
        if ( !empty($deal['image_url']) && is_string($deal['image_url']) ) {
            $potential_url = esc_url_raw( trim($deal['image_url']) );
            // Basic URL format check + length check
            if ( filter_var($potential_url, FILTER_VALIDATE_URL) && strlen($potential_url) <= 2048 ) {
                $image_url_sanitized = $potential_url;
            } else {
                // Log invalid URL but continue without it
                error_log("DSP DB Warning: Invalid or overly long image URL skipped for deal '" . esc_html($title_sanitized) . "': " . substr( ($deal['image_url'] ?? ''), 0, 100) );
            }
        }

        // *** Get existing data BEFORE determining the ID to save ***
        $cache_key = 'dsp_deal_read_' . md5($link_sanitized);
        wp_cache_delete( $cache_key, 'dsp_deals' ); // Try invalidating specific cache if possible
        // error_log("DSP Add/Update: Cleared cache before reading existing data for link: {$link_sanitized}");

        $existing_data = $wpdb->get_row( $wpdb->prepare( "SELECT image_url, image_attachment_id FROM $table_name WHERE link = %s", $link_sanitized ), ARRAY_A ); // Use ARRAY_A for consistency
        $existing_attachment_id = isset($existing_data['image_attachment_id']) ? (int) $existing_data['image_attachment_id'] : 0;
        $existing_image_url = $existing_data['image_url'] ?? '';
        // error_log("DSP Add/Update DEBUG: Read Existing Data for {$link_sanitized}: URL='{$existing_image_url}', ID={$existing_attachment_id}");

        // Determine the attachment ID to save (-2 = needs processing, 0 = no image, >0 = processed)
        $attachment_id_to_save;
        $status_changed = false; // Flag if attachment status changes

        if ( !empty($image_url_sanitized) ) { // If we have a valid new image URL from the parser
            if ( $image_url_sanitized !== $existing_image_url ) {
                // URL is new or changed, mark for processing
                $attachment_id_to_save = -2;
                $status_changed = true;
                // error_log("DSP Add/Update: Image URL changed/found for '{$title_sanitized}'. Setting attachment ID to {$attachment_id_to_save}.");
            } else {
                // URL is the same as before, keep the existing attachment ID
                $attachment_id_to_save = $existing_attachment_id;
                // If the existing ID was already processing/failed (-1 or -2), keep it that way unless a new URL forces -2
                // if ($existing_attachment_id < 0) { error_log("DSP Add/Update: Image URL same for '{$title_sanitized}', keeping existing non-positive ID: {$attachment_id_to_save}"); }
            }
        } else { // No valid image URL provided by the parser this time
            if ( $existing_attachment_id != 0 ) { // Only change if it wasn't already 0
                $attachment_id_to_save = 0; // Reset to 0 if URL removed
                $status_changed = true;
                // error_log("DSP Add/Update: Image URL removed for '{$title_sanitized}'. Setting attachment ID to 0.");
            } else {
                 $attachment_id_to_save = 0; // Stays 0
            }
        }

        // --- Step 1: Try INSERT IGNORE to establish the row and first_seen ---
        // This avoids overwriting first_seen on subsequent updates
        $insert_data = [ 'link' => $link_sanitized, 'title' => $title_sanitized, 'first_seen' => $now_gmt, 'last_seen' => $now_gmt ];
        $insert_formats = ['%s', '%s', '%s', '%s'];
        $sql_insert = "INSERT IGNORE INTO `$table_name` (`" . implode( '`, `', array_keys( $insert_data ) ) . "`) VALUES (" . implode( ", ", $insert_formats ) . ")";
        $prepared_sql_insert = $wpdb->prepare($sql_insert, array_values($insert_data));
        $newly_inserted = false;

        if ($prepared_sql_insert) {
            $insert_result = $wpdb->query( $prepared_sql_insert );
            if ($insert_result === 1) {
                $newly_inserted = true; // Successfully inserted a new row
                // error_log("DSP DB Add/Update: Inserted new deal: {$link_sanitized}");
            } elseif ($insert_result === false) {
                // Log DB error on insert
                error_log("DSP DB Error on INSERT IGNORE for link {$link_sanitized}: " . $wpdb->last_error);
                // Don't return yet, still attempt update
            }
            // If $insert_result is 0, it means the key existed, no insert happened, proceed to update
        } else {
            error_log("DSP DB Error: Failed to prepare INSERT IGNORE. Error: " . $wpdb->last_error);
            return null; // Cannot proceed without insert prep
        }


        // --- Step 2: Always perform an UPDATE to set/refresh all other fields ---
        $data_for_update = [
            'title' => $title_sanitized,
            'price' => sanitize_text_field($deal['price'] ?? ''), // Keep original price string
            'source' => sanitize_text_field($deal['source'] ?? ''),
            'description' => sanitize_textarea_field($deal['description'] ?? ''),
            'image_url' => $image_url_sanitized, // Save the validated URL
            'image_attachment_id' => $attachment_id_to_save, // Save the determined ID
            'last_seen' => $now_gmt, // Always update last_seen
            'is_ltd' => $is_ltd_flag, // Save calculated LTD flag
            'price_numeric' => $price_numeric // Save calculated numeric price
        ];
        // Ensure formats match data types (use %f for price_numeric if it's not NULL)
        $update_formats = ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', ($price_numeric === null ? '%s' : '%f')];

        $update_result = $wpdb->update(
            $table_name,
            $data_for_update,
            ['link' => $link_sanitized], // WHERE clause
            $update_formats, // Format for values
            ['%s'] // Format for WHERE clause
        );

        if ($update_result === false) {
            error_log("DSP DB Error on UPDATE for link {$link_sanitized}: " . $wpdb->last_error);
            return null; // Return null on update error
        }

        // Clear object cache if attachment status changed
        // if ($status_changed) { wp_cache_flush(); error_log("DSP DB Add/Update: Flushed object cache because attachment status changed for link: {$link_sanitized}"); }

        return $newly_inserted; // Return true only if INSERT IGNORE added a row
    }


    /** Retrieves deals from the database with filtering, sorting, pagination */
    public static function get_deals( $args = [] ) {
        global $wpdb; $table_name = self::get_table_name();

        // Define defaults and parse args
        $defaults = [
            'orderby' => 'first_seen', 'order' => 'DESC',
            'items_per_page'=> -1, 'page' => 1,
            'search' => '', 'sources' => [],
            'newer_than_ts' => 0,
            'min_price' => null, 'max_price' => null,
            'ltd_only' => false
        ];
        $args = wp_parse_args($args, $defaults);

        // Validate orderby and order
        $allowed_orderby = ['link', 'title', 'price', 'source', 'first_seen', 'last_seen', 'is_ltd', 'price_numeric', 'image_url', 'image_attachment_id'];
        $orderby = in_array(strtolower($args['orderby']), $allowed_orderby) ? strtolower($args['orderby']) : $defaults['orderby'];
        $order = in_array(strtoupper($args['order']), ['ASC', 'DESC']) ? strtoupper($args['order']) : $defaults['order'];
        $orderby_sql = sanitize_sql_orderby( "{$orderby} {$order}" ); // Use WordPress's sanitization

        // Prepare pagination
        $items_per_page = intval($args['items_per_page']);
        $page = intval($args['page']);
        if ($page < 1) $page = 1;
        $limit_clause = ''; $offset = 0;
        $do_pagination = ($items_per_page > 0);
        if ($do_pagination) {
            $offset = ($page - 1) * $items_per_page;
            $limit_clause = $wpdb->prepare( "LIMIT %d OFFSET %d", $items_per_page, $offset );
        }

        // Build WHERE conditions
        $where_conditions = [];
        $prepare_args = []; // Collect args for the main $wpdb->prepare call

        // Search Condition
        if ( !empty($args['search']) ) {
            $search_like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where_conditions[] = "(title LIKE %s OR description LIKE %s)";
            $prepare_args[] = $search_like;
            $prepare_args[] = $search_like;
        }

        // Source Condition
        if ( !empty($args['sources']) && is_array($args['sources']) ) {
            $sanitized_sources = array_map('sanitize_text_field', $args['sources']);
            if (!empty($sanitized_sources)) {
                $source_placeholders = implode(', ', array_fill(0, count($sanitized_sources), '%s'));
                $where_conditions[] = "source IN ($source_placeholders)";
                $prepare_args = array_merge($prepare_args, $sanitized_sources);
            }
        }

        // New Deals Condition
        if ( !empty($args['newer_than_ts']) && $args['newer_than_ts'] > 0) {
            $newer_than_datetime = gmdate('Y-m-d H:i:s', (int)$args['newer_than_ts']);
            $where_conditions[] = "first_seen >= %s";
            $prepare_args[] = $newer_than_datetime;
        }

        // LTD Only Condition
        if ($args['ltd_only'] === true) {
            $where_conditions[] = "is_ltd = 1";
        }

        // Price Range Condition
        $min_price_sql = ($args['min_price'] !== null) ? (float) $args['min_price'] : null;
        $max_price_sql = ($args['max_price'] !== null) ? (float) $args['max_price'] : null;
        if ($min_price_sql !== null || $max_price_sql !== null) {
            $price_where_parts = [];
            if ($min_price_sql !== null) {
                $price_where_parts[] = "price_numeric >= %f";
                $prepare_args[] = $min_price_sql;
            }
            if ($max_price_sql !== null) {
                // Include check for IS NOT NULL because comparison with NULL is tricky
                $price_where_parts[] = "(price_numeric IS NOT NULL AND price_numeric <= %f)";
                $prepare_args[] = $max_price_sql;
            }
            if(!empty($price_where_parts)){
                 // Combine with OR only if both min and max are set, otherwise AND
                $combiner = ($min_price_sql !== null && $max_price_sql !== null) ? " AND " : "";
                $where_conditions[] = " (" . implode( $combiner, $price_where_parts) . ") ";
            }
        }

        // Construct final WHERE clause
        $where_clause = "WHERE 1=1";
        if (!empty($where_conditions)) {
            $where_clause .= " AND " . implode(" AND ", $where_conditions);
        }

        // Get total count
        $sql_count = "SELECT COUNT(*) FROM $table_name $where_clause";
        $prepared_sql_count = empty($prepare_args) ? $sql_count : $wpdb->prepare($sql_count, $prepare_args);
        $total_deals = $wpdb->get_var( $prepared_sql_count );

        if ( $total_deals === null || $wpdb->last_error ) {
            error_log("DSP DB Error get_deals COUNT: " . $wpdb->last_error . " SQL: " . $prepared_sql_count);
            return ['deals' => [], 'total_deals' => 0];
        }
        $total_deals = intval($total_deals);

        // Get deals for the current page
        $deals = [];
        if ($total_deals > 0 || !$do_pagination ) { // Fetch even if 0 total if pagination is off (items_per_page=-1)
             $sql_deals = "SELECT link, title, price, source, description, image_url, image_attachment_id, first_seen, last_seen, is_ltd, price_numeric
                          FROM $table_name $where_clause ORDER BY $orderby_sql $limit_clause";
             $prepared_sql_deals = empty($prepare_args) ? $sql_deals : $wpdb->prepare($sql_deals, $prepare_args);
             $deals = $wpdb->get_results( $prepared_sql_deals );

             if ( $wpdb->last_error ) {
                error_log("DSP DB Error get_deals SELECT: " . $wpdb->last_error . " SQL: " . $prepared_sql_deals);
                $deals = []; // Reset deals on error
             }
        }

        // Return results structure
        return [
            'deals' => $deals ? $deals : [],
            'total_deals' => $total_deals
        ];
    }


    /** Gets all known deal links (URLs) from the database */
    public static function get_known_links() {
        global $wpdb; $table_name = self::get_table_name();
        $results = $wpdb->get_col( "SELECT link FROM $table_name" );
        return $results ? $results : [];
    }

    /** Deletes deals from the database older than a specified number of days and their associated attachments. */
    public static function purge_old_deals( $max_age_days ) {
        global $wpdb; $table_name = self::get_table_name();

        // Validate input
        $max_age_days = intval( $max_age_days );
        if ( $max_age_days < 1 ) {
            error_log("DSP DB Purge Error: Invalid max_age_days: " . $max_age_days);
            return false; // Do not proceed with invalid age
        }

        // Calculate the cutoff date/time in GMT/UTC
        $cutoff_timestamp_gmt = strtotime( "-{$max_age_days} days", current_time( 'timestamp', true ) );
        if ($cutoff_timestamp_gmt === false) {
            error_log("DSP DB Purge Error: Failed to calculate cutoff timestamp.");
            return false;
        }
        $cutoff_datetime_gmt = date( 'Y-m-d H:i:s', $cutoff_timestamp_gmt );

        // --- Step 1: Find deals to purge and their attachments ---
        $deals_to_purge = $wpdb->get_results( $wpdb->prepare( "SELECT link, image_attachment_id FROM $table_name WHERE first_seen < %s", $cutoff_datetime_gmt ) );

        if ($deals_to_purge === null || $wpdb->last_error) {
            error_log("DSP DB Purge Error: Failed query deals to purge. DB Error: " . $wpdb->last_error);
            return false;
        }
        $deals_found_count = count($deals_to_purge);

        if ($deals_found_count === 0) {
            error_log( "DSP DB Purge: No deals found older than " . $max_age_days . " days (before " . $cutoff_datetime_gmt . " GMT) to delete." );
            return 0; // Return 0 rows affected
        }

        // Prepare lists for deletion
        error_log( "DSP DB Purge: Found {$deals_found_count} deals older than {$max_age_days} days to potentially delete." );
        $attachment_ids_to_delete = [];
        $deal_links_to_delete = [];
        foreach ($deals_to_purge as $deal) {
            $deal_links_to_delete[] = $deal->link;
            $attachment_id = (int) $deal->image_attachment_id;
            // Only add valid positive IDs for deletion
            if ( $attachment_id > 0 ) {
                $attachment_ids_to_delete[] = $attachment_id;
            }
        }

        // --- Step 2: Delete associated attachments ---
        $attachments_deleted_count = 0;
        if ( ! empty( $attachment_ids_to_delete ) ) {
            if (!function_exists('wp_delete_attachment')) { require_once( ABSPATH . 'wp-admin/includes/post.php' ); }

            $unique_attachment_ids = array_unique( $attachment_ids_to_delete );
            error_log("DSP DB Purge: Attempting to delete " . count($unique_attachment_ids) . " unique attachments.");
            foreach ( $unique_attachment_ids as $att_id ) {
                // Check if the post exists and is an attachment before deleting
                $post_exists = get_post_status($att_id);
                if ( $post_exists && get_post_type($att_id) === 'attachment' ) {
                    $delete_result = wp_delete_attachment( $att_id, true ); // true = force delete
                    if ( $delete_result !== false ) {
                        $attachments_deleted_count++;
                    } else {
                        error_log("DSP DB Purge Warning: Failed to delete attachment ID {$att_id}. It might be in use elsewhere or protected.");
                    }
                } else {
                     error_log("DSP DB Purge Info: Attachment ID {$att_id} not found or not an attachment (already deleted?).");
                }
            }
            error_log("DSP DB Purge: Successfully deleted {$attachments_deleted_count} attachments.");
        } else {
            error_log("DSP DB Purge: No associated attachments found to delete.");
        }

        // --- Step 3: Delete the deal records from the database ---
        $rows_affected = 0;
        if (!empty($deal_links_to_delete)) {
            $chunk_size = 100; // Delete in chunks to avoid very large queries
            $link_chunks = array_chunk($deal_links_to_delete, $chunk_size);
            foreach ($link_chunks as $chunk) {
                $link_placeholders = implode(', ', array_fill(0, count($chunk), '%s'));
                $sql_delete_deals = $wpdb->prepare( "DELETE FROM $table_name WHERE link IN ($link_placeholders)", $chunk );
                $deleted_in_chunk = $wpdb->query( $sql_delete_deals );

                if ( $deleted_in_chunk === false ) {
                    error_log( "DSP DB Purge Error: Failed to delete chunk of old deal records. DB Error: " . $wpdb->last_error );
                    // Continue to next chunk if one fails? Or stop? Let's continue for now.
                } else {
                    $rows_affected += $deleted_in_chunk;
                }
            }
        } else {
             error_log("DSP DB Purge: No deal links collected for deletion (this shouldn't happen if deals were found).");
        }

        // Final log based on rows affected vs found
        if ( $rows_affected > 0 ) {
            error_log( "DSP DB Purge: Successfully deleted " . $rows_affected . " deal records older than " . $max_age_days . " days." );
        } elseif ($deals_found_count > 0 && $rows_affected === 0) {
            // This might happen if the delete query failed after finding deals
            error_log( "DSP DB Purge Warning: Found {$deals_found_count} deals to purge, but 0 rows were deleted from DB. Check for previous errors." );
        }

        return $rows_affected; // Return the number of deal records deleted
    }

    /** Gets deals added since a specific GMT timestamp */
    public static function get_deals_since( $timestamp_gmt_str, $orderby = 'first_seen', $order = 'DESC' ) {
        global $wpdb; $table_name = self::get_table_name();
        // Validate timestamp format
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp_gmt_str ) ) {
             error_log("DSP DB Error get_deals_since: Invalid timestamp format: " . $timestamp_gmt_str);
             return [];
        }
        // Validate orderby/order
        $allowed_orderby = ['link', 'title', 'price', 'source', 'first_seen', 'last_seen', 'is_ltd', 'price_numeric', 'image_url', 'image_attachment_id'];
        if (!in_array(strtolower($orderby), $allowed_orderby)) { $orderby = 'first_seen'; }
        if (!in_array(strtoupper($order), ['ASC', 'DESC'])) { $order = 'DESC'; }
        $orderby_sql = sanitize_sql_orderby( $orderby . ' ' . $order );

        // Prepare and execute query
        $sql = $wpdb->prepare(
            "SELECT link, title, price, source, description, image_url, image_attachment_id, first_seen, last_seen, is_ltd, price_numeric
             FROM $table_name WHERE first_seen > %s ORDER BY $orderby_sql",
            $timestamp_gmt_str
        );
        $results = $wpdb->get_results( $sql );

        if ( $wpdb->last_error ) {
            error_log("DSP DB Error getting deals since {$timestamp_gmt_str}: " . $wpdb->last_error);
            return [];
        }
        return $results ? $results : [];
    }

    /** Parses a price string into a sortable numeric value (private helper) */
    private static function parse_price_to_numeric($priceStr) {
        // Handle null, empty, or 'n/a' input
        if ($priceStr === null || trim($priceStr) === '' || strcasecmp(trim($priceStr), 'n/a') === 0) {
            return null; // Return null for non-numeric/empty values
        }

        $priceStr = strtolower(trim($priceStr));

        // Check for "free" variations
        if (strpos($priceStr, 'free') !== false) {
            return 0.00;
        }

        // --- Improved Cleaning for International Numbers ---
        // 1. Remove currency symbols and thousands separators (common ones)
        $cleanedPrice = preg_replace('/[$\€\£¥₹,\s]+/', '', $priceStr);
        // 2. Standardize decimal separator: if comma is present *after* the last dot, treat comma as decimal
        $lastCommaPos = strrpos($cleanedPrice, ',');
        $lastDotPos = strrpos($cleanedPrice, '.');
        if ($lastCommaPos !== false && ($lastDotPos === false || $lastCommaPos > $lastDotPos)) {
            // If comma is the decimal separator, remove dots (thousands) and replace comma with dot
            $cleanedPrice = str_replace('.', '', $cleanedPrice);
            $cleanedPrice = str_replace(',', '.', $cleanedPrice);
        } else {
             // Otherwise, assume dot is decimal and remove commas (thousands)
             $cleanedPrice = str_replace(',', '', $cleanedPrice);
        }
        // 3. Remove any remaining non-numeric characters except decimal point and minus sign at start
        $cleanedPrice = preg_replace('/[^-0-9.]/', '', $cleanedPrice);
        // --- End Improved Cleaning ---


        // Extract the first valid number found
        if (preg_match('/^[-+]?\d+(\.\d+)?/', $cleanedPrice, $matches)) {
             if (is_numeric($matches[0])) {
                return (float) $matches[0];
             }
        }

        // Return null if no valid number could be extracted
        return null;
    }


    /** Gets a batch of deals needing image processing (ID = -2) */
    public static function get_deals_needing_images( $limit = 10 ) {
        global $wpdb; $table_name = self::get_table_name();

        // Do not flush cache here, it can cause performance issues if called frequently
        // wp_cache_flush(); error_log("DSP DB Query (get_deals_needing_images): Flushed WP Object Cache before querying.");

        $limit = absint($limit);
        if ($limit <= 0) { $limit = 10; } // Default limit

        // Query for deals marked with -2, having a valid image_url, ordered by first_seen (process oldest first)
        $sql = $wpdb->prepare(
            "SELECT link, title, image_url
             FROM $table_name
             WHERE image_attachment_id = -2
             AND image_url != '' AND image_url IS NOT NULL
             ORDER BY first_seen ASC
             LIMIT %d",
            $limit
        );
        // error_log("DSP DB Query (get_deals_needing_images): " . $sql);

        $results = $wpdb->get_results( $sql );

        if ( $wpdb->last_error ) {
            error_log("DSP DB Error getting deals needing images: " . $wpdb->last_error);
            return []; // Return empty array on error
        }

        $count = count($results);
        // error_log("DSP DB Result (get_deals_needing_images): Found {$count} deals needing processing (ID = -2).");
        // if ($count > 0) { $found_links = array_column($results, 'link'); error_log("DSP DB Result (get_deals_needing_images): Links found: " . implode(', ', $found_links)); }

        return $results ? $results : [];
    }

    /** Updates the image_attachment_id for a specific deal */
    public static function update_deal_attachment_id( $deal_link, $attachment_id ) {
        global $wpdb; $table_name = self::get_table_name();

        // Sanitize inputs
        $deal_link = sanitize_text_field($deal_link);
        // Attachment ID can be positive (success), 0 (no image/reset), or -1 (error)
        $attachment_id = is_numeric($attachment_id) ? intval($attachment_id) : 0;

        if ( empty($deal_link) ) {
            error_log("DSP DB Error update_deal_attachment_id: Empty deal link provided.");
            return false;
        }
        // error_log("DSP DB Update: Setting image_attachment_id to {$attachment_id} for link {$deal_link}");

        // Perform the update
        $result = $wpdb->update(
            $table_name,
            [ 'image_attachment_id' => $attachment_id ], // Data to update
            [ 'link' => $deal_link ],                   // WHERE clause
            [ '%d' ],                                   // Format for data
            [ '%s' ]                                    // Format for WHERE
        );

        // Check for errors
        if ( $result === false ) {
            error_log("DSP DB Error update_deal_attachment_id for link {$deal_link}: " . $wpdb->last_error);
            return false; // Return false on DB error
        }

        // Log success or no rows affected
        // if ($result > 0) { error_log("DSP DB Update: Successfully updated image_attachment_id for link {$deal_link}. Rows affected: {$result}"); }
        // else { error_log("DSP DB Update: No rows affected updating image_attachment_id for link {$deal_link} (value might be the same or link not found)."); }

        // Do not flush cache here frequently, can impact performance significantly
        // if ($result !== false) { wp_cache_flush(); error_log("DSP DB Update: Flushed WP Object Cache after updating link: {$deal_link}"); }

        return true; // Return true on success (even if 0 rows affected)
    }

} // End Class