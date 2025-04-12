<?php
// File: includes/db-handler.php (v1.1.29 - Add explicit price_numeric update function)

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

        // Define the target schema including the price_numeric column and index
        $sql = "CREATE TABLE $table_name (
            link VARCHAR(767) NOT NULL,
            title TEXT NOT NULL,
            price VARCHAR(100) DEFAULT '' NOT NULL,
            source VARCHAR(100) NOT NULL,
            description TEXT DEFAULT '' NOT NULL,
            first_seen DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
            last_seen DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
            is_ltd TINYINT(1) DEFAULT 0 NOT NULL,
            price_numeric DECIMAL(10, 2) NULL DEFAULT NULL, -- Store parsed price or NULL
            PRIMARY KEY (link),
            INDEX source_idx (source),
            INDEX first_seen_idx (first_seen),
            INDEX price_idx (price(10)),
            INDEX is_ltd_idx (is_ltd),
            INDEX price_numeric_idx (price_numeric) -- Index for numeric price filtering
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        $result = dbDelta( $sql );
        error_log("DSP DB Check: dbDelta executed for table {$table_name}. Result: " . print_r($result, true));

        // Check if table exists after attempting creation/update
        $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name;
        if ( ! $table_exists ) {
            error_log( "DSP Error: Failed to create/update table: $table_name" );
        } else {
            // Verify if the specific columns exist after dbDelta ran
            $columns_to_check = ['is_ltd', 'price_numeric'];
            foreach ($columns_to_check as $column_name) {
                $column_exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = %s AND table_name = %s AND column_name = %s",
                    DB_NAME, $table_name, $column_name
                ) );

                if ( $column_exists == 0 ) {
                     error_log( "DSP Warning: dbDelta may not have added '{$column_name}' column to {$table_name}. Update routine should handle it." );
                } else {
                     error_log("DSP Log: Column '{$column_name}' verified in {$table_name}.");
                }
            }
        }
    }

    /**
     * Explicitly adds the 'is_ltd' column and its index if they don't exist.
     */
    public static function add_ltd_column_and_index() {
        global $wpdb;
        $table_name = self::get_table_name();

        // Check if column exists
        $column_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = %s AND table_name = %s AND column_name = %s",
            DB_NAME, $table_name, 'is_ltd'
        ) );

        if ( $column_exists == 0 ) {
            error_log("DSP DB Upgrade: 'is_ltd' column not found in {$table_name}. Attempting to add...");
            $alter_result = $wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN `is_ltd` TINYINT(1) NOT NULL DEFAULT 0 AFTER `last_seen`;" );
            if ($alter_result === false) { error_log("DSP DB Upgrade Error: Failed to add 'is_ltd' column. DB Error: " . $wpdb->last_error); }
            else { error_log("DSP DB Upgrade: Successfully added 'is_ltd' column."); }
        } else { error_log("DSP DB Upgrade: 'is_ltd' column already exists."); }

        // Check if index exists
        $index_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = %s AND table_name = %s AND index_name = %s",
            DB_NAME, $table_name, 'is_ltd_idx'
        ) );

        if ( $index_exists == 0 ) {
            error_log("DSP DB Upgrade: 'is_ltd_idx' index not found. Attempting to add...");
             $alter_index_result = $wpdb->query( "ALTER TABLE `{$table_name}` ADD INDEX `is_ltd_idx` (`is_ltd`);" );
             if ($alter_index_result === false) { error_log("DSP DB Upgrade Error: Failed to add 'is_ltd_idx' index. DB Error: " . $wpdb->last_error); }
             else { error_log("DSP DB Upgrade: Successfully added 'is_ltd_idx' index."); }
        } else { error_log("DSP DB Upgrade: 'is_ltd_idx' index already exists."); }
    }

    /**
     * *** NEW FUNCTION ***
     * Explicitly adds the 'price_numeric' column and its index if they don't exist.
     * Used as a fallback or specific upgrade step if dbDelta fails.
     */
    public static function add_price_numeric_column_and_index() {
        global $wpdb;
        $table_name = self::get_table_name();

        // Check if column exists
        $column_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = %s AND table_name = %s AND column_name = %s",
            DB_NAME, $table_name, 'price_numeric'
        ) );

        if ( $column_exists == 0 ) {
            error_log("DSP DB Upgrade: 'price_numeric' column not found in {$table_name}. Attempting to add...");
            // Add the column using ALTER TABLE - place it after is_ltd
            $alter_result = $wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN `price_numeric` DECIMAL(10, 2) NULL DEFAULT NULL AFTER `is_ltd`;" );
            if ($alter_result === false) {
                error_log("DSP DB Upgrade Error: Failed to add 'price_numeric' column. DB Error: " . $wpdb->last_error);
            } else {
                error_log("DSP DB Upgrade: Successfully added 'price_numeric' column.");
                // Optional: Attempt to backfill existing rows immediately after adding column
                // This can be slow on large tables, run with caution or separately.
                // self::backfill_price_numeric();
            }
        } else {
             error_log("DSP DB Upgrade: 'price_numeric' column already exists.");
        }

        // Check if index exists
        $index_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = %s AND table_name = %s AND index_name = %s",
            DB_NAME, $table_name, 'price_numeric_idx'
        ) );

        if ( $index_exists == 0 ) {
            error_log("DSP DB Upgrade: 'price_numeric_idx' index not found. Attempting to add...");
            // Add the index using ALTER TABLE
             $alter_index_result = $wpdb->query( "ALTER TABLE `{$table_name}` ADD INDEX `price_numeric_idx` (`price_numeric`);" );
             if ($alter_index_result === false) {
                 error_log("DSP DB Upgrade Error: Failed to add 'price_numeric_idx' index. DB Error: " . $wpdb->last_error);
             } else {
                 error_log("DSP DB Upgrade: Successfully added 'price_numeric_idx' index.");
             }
        } else {
            error_log("DSP DB Upgrade: 'price_numeric_idx' index already exists.");
        }
    }
    // *** END NEW FUNCTION ***


    /** Adds a new deal or updates an existing one. Includes numeric price parsing. */
    public static function add_or_update_deal( $deal ) {
        global $wpdb;
        $table_name = self::get_table_name();

        // Basic validation
        if ( empty( $deal['link'] ) || $deal['link'] === '#' || strlen($deal['link']) > 767 ) { return false; }
        if ( empty( $deal['title'] ) ) { return false; }

        $now_gmt = current_time( 'mysql', true );

        // Parse price string to numeric
        $price_numeric = self::parse_price_to_numeric($deal['price'] ?? '');

        // Prepare data for insert/update
        $data = [
            'link'          => sanitize_text_field( substr($deal['link'], 0, 767) ),
            'title'         => sanitize_text_field($deal['title']),
            'price'         => sanitize_text_field($deal['price'] ?? ''), // Keep original price string
            'source'        => sanitize_text_field($deal['source'] ?? ''),
            'description'   => sanitize_textarea_field($deal['description'] ?? ''),
            'last_seen'     => $now_gmt,
            'is_ltd'        => isset($deal['is_ltd']) ? ( (bool) $deal['is_ltd'] ? 1 : 0 ) : 0,
            'price_numeric' => $price_numeric, // Store the parsed numeric price (or null)
        ];

        // Use INSERT ... ON DUPLICATE KEY UPDATE
        $sql = $wpdb->prepare(
            "INSERT INTO $table_name (link, title, price, source, description, first_seen, last_seen, is_ltd, price_numeric)
             VALUES (%s, %s, %s, %s, %s, %s, %s, %d, %f)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                price = VALUES(price),
                source = VALUES(source),
                description = VALUES(description),
                last_seen = VALUES(last_seen),
                is_ltd = VALUES(is_ltd),
                price_numeric = VALUES(price_numeric)", // Update numeric price too
            $data['link'], $data['title'], $data['price'], $data['source'], $data['description'],
            $now_gmt, $data['last_seen'], $data['is_ltd'], $data['price_numeric'] // Use %f for float/decimal, WP handles NULL correctly
        );

        $result = $wpdb->query( $sql );

        if ( $result === false ) {
            error_log( "DSP DB Error INSERT/UPDATE link {$data['link']}: " . $wpdb->last_error );
            return null; // Indicate DB error
        }

        // Returns 1 if inserted, 2 if updated, 0 if unchanged
        return ( $result === 1 ); // True only if newly inserted
    }

    /** Retrieves deals from the database */
    public static function get_deals( $args = [] ) {
        global $wpdb;
        $table_name = self::get_table_name();

        // Defaults and parsing args
        $defaults = [ 'orderby' => 'first_seen', 'order' => 'DESC', 'items_per_page'=> -1, 'page' => 1, 'search' => '', 'sources' => [], 'newer_than_ts' => 0, 'min_price' => null, 'max_price' => null, 'ltd_only' => false ];
        $args = wp_parse_args($args, $defaults);

        // Validate orderby and order
        $allowed_orderby = ['link', 'title', 'price', 'source', 'first_seen', 'last_seen', 'is_ltd', 'price_numeric']; // Added price_numeric
        $orderby = in_array(strtolower($args['orderby']), $allowed_orderby) ? strtolower($args['orderby']) : $defaults['orderby'];
        $order = in_array(strtoupper($args['order']), ['ASC', 'DESC']) ? strtoupper($args['order']) : $defaults['order'];
        $orderby_sql = sanitize_sql_orderby( "{$orderby} {$order}" );

        // Pagination
        $items_per_page = intval($args['items_per_page']);
        $page = intval($args['page']); if ($page < 1) $page = 1;
        $limit_clause = ''; $offset = 0;
        $do_pagination = ($items_per_page > 0);
        if ($do_pagination) { $offset = ($page - 1) * $items_per_page; $limit_clause = $wpdb->prepare( "LIMIT %d OFFSET %d", $items_per_page, $offset ); }

        // Build WHERE clause
        $where_conditions = [];
        $prepare_args = [];

        // Search Filter
        if ( !empty($args['search']) ) {
            $search_like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where_conditions[] = "(title LIKE %s OR description LIKE %s)";
            $prepare_args[] = $search_like;
            $prepare_args[] = $search_like;
        }

        // Source Filter
        if ( !empty($args['sources']) && is_array($args['sources']) ) {
            $sanitized_sources = array_map('sanitize_text_field', $args['sources']);
            if (!empty($sanitized_sources)) {
                $source_placeholders = implode(', ', array_fill(0, count($sanitized_sources), '%s'));
                $where_conditions[] = "source IN ($source_placeholders)";
                foreach ($sanitized_sources as $source) { $prepare_args[] = $source; }
            }
        }

        // Date Filter
        if ( !empty($args['newer_than_ts']) && $args['newer_than_ts'] > 0) {
            $newer_than_datetime = gmdate('Y-m-d H:i:s', (int)$args['newer_than_ts']);
            $where_conditions[] = "first_seen >= %s";
            $prepare_args[] = $newer_than_datetime;
        }

        // LTD Filter
        if ($args['ltd_only'] === true) {
            $where_conditions[] = "is_ltd = 1";
        }

        // Price Filter using price_numeric
        $min_price_sql = ($args['min_price'] !== null) ? (float) $args['min_price'] : null;
        $max_price_sql = ($args['max_price'] !== null) ? (float) $args['max_price'] : null;

        if ($min_price_sql !== null || $max_price_sql !== null) {
            $price_where_parts = [];
            if ($min_price_sql !== null) {
                // >= comparison handles 0 correctly
                $price_where_parts[] = $wpdb->prepare("price_numeric >= %f", $min_price_sql);
                $prepare_args[] = $min_price_sql;
            }
            if ($max_price_sql !== null) {
                // Add check for IS NOT NULL to avoid matching rows where price couldn't be parsed
                $price_where_parts[] = $wpdb->prepare("(price_numeric IS NOT NULL AND price_numeric <= %f)", $max_price_sql);
                 $prepare_args[] = $max_price_sql;
            }
            if(!empty($price_where_parts)){
                $where_conditions[] = " (" . implode( " AND ", $price_where_parts) . ") ";
            }
        }

        // Combine WHERE conditions
        $where_clause = "WHERE 1=1";
        if (!empty($where_conditions)) {
            $where_clause .= " AND " . implode(" AND ", $where_conditions);
        }

        // --- Get Total Count ---
        $sql_count = "SELECT COUNT(*) FROM $table_name $where_clause";
        if (!empty($prepare_args)) {
            $sql_count = $wpdb->prepare($sql_count, $prepare_args);
        }
        $total_deals = $wpdb->get_var( $sql_count );
        if ( $total_deals === null || $wpdb->last_error ) {
            error_log("DSP DB Error get_deals COUNT: " . $wpdb->last_error . " SQL: " . $sql_count);
            return ['deals' => [], 'total_deals' => 0];
        }
        $total_deals = intval($total_deals);

        // --- Get Deals for the current page ---
        $deals = [];
        if ($total_deals > 0) {
            // Fetch all columns, including the new price_numeric
            $sql_deals = "SELECT link, title, price, source, description, first_seen, last_seen, is_ltd, price_numeric
                          FROM $table_name $where_clause
                          ORDER BY $orderby_sql $limit_clause";
            if (!empty($prepare_args)) {
                $sql_deals = $wpdb->prepare($sql_deals, $prepare_args);
            }
            $deals = $wpdb->get_results( $sql_deals );
            if ( $wpdb->last_error ) {
                error_log("DSP DB Error get_deals SELECT: " . $wpdb->last_error . " SQL: " . $sql_deals);
                $deals = []; // Reset deals on error
            }
        }

        // Return results in an array
        return [
            'deals' => $deals ? $deals : [],
            'total_deals' => $total_deals
        ];
    }

    // get_known_links() - No changes needed
    public static function get_known_links() { global $wpdb; $table_name = self::get_table_name(); $results = $wpdb->get_col( "SELECT link FROM $table_name" ); return $results ? $results : []; }
    // purge_old_deals() - No changes needed
    public static function purge_old_deals( $max_age_days ) { global $wpdb; $table_name = self::get_table_name(); $max_age_days = intval( $max_age_days ); if ( $max_age_days < 1 ) { return false; } $cutoff_timestamp_gmt = strtotime( "-{$max_age_days} days", current_time( 'timestamp', true ) ); if ($cutoff_timestamp_gmt === false) { return false; } $cutoff_datetime_gmt = date( 'Y-m-d H:i:s', $cutoff_timestamp_gmt ); $sql = $wpdb->prepare( "DELETE FROM $table_name WHERE first_seen < %s", $cutoff_datetime_gmt ); $rows_affected = $wpdb->query( $sql ); if ( $rows_affected === false ) { error_log( "DSP DB Purge Error: " . $wpdb->last_error ); return false; } if ( $rows_affected > 0 ) { error_log( "DSP DB Purge: Deleted " . $rows_affected . " deals older than " . $max_age_days . " days." ); } return $rows_affected; }
    // get_deals_since() - No changes needed
    public static function get_deals_since( $timestamp_gmt_str, $orderby = 'first_seen', $order = 'DESC' ) { global $wpdb; $table_name = self::get_table_name(); if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp_gmt_str ) ) { return []; } $allowed_orderby = ['link', 'title', 'price', 'source', 'first_seen', 'last_seen', 'is_ltd', 'price_numeric']; if (!in_array(strtolower($orderby), $allowed_orderby)) { $orderby = 'first_seen'; } if (!in_array(strtoupper($order), ['ASC', 'DESC'])) { $order = 'DESC'; } $orderby_sql = sanitize_sql_orderby( $orderby . ' ' . $order ); $sql = $wpdb->prepare( "SELECT link, title, price, source, description, first_seen, last_seen, is_ltd, price_numeric FROM $table_name WHERE first_seen > %s ORDER BY $orderby_sql", $timestamp_gmt_str ); $results = $wpdb->get_results( $sql ); if ( $wpdb->last_error ) { error_log("DSP DB Error getting deals since {$timestamp_gmt_str}: " . $wpdb->last_error); return []; } return $results ? $results : []; }


    /**
     * Parses a price string into a sortable numeric value (float or null).
     * Handles 'Free', currency symbols, commas. Returns NULL if unparsable.
     *
     * @param string $priceStr The price string to parse.
     * @return float|null Sortable numeric value (0.00 for Free) or NULL.
     */
    private static function parse_price_to_numeric($priceStr) {
        if ($priceStr === null || trim($priceStr) === '' || strcasecmp(trim($priceStr), 'n/a') === 0) {
            return null; // Treat empty, null, or N/A as unparseable
        }

        $priceStr = strtolower(trim($priceStr));

        // Handle "Free" explicitly
        if (strpos($priceStr, 'free') !== false) {
            return 0.00;
        }

        // Remove common currency symbols and grouping separators (commas)
        $cleanedPrice = preg_replace('/[^\d.,]+/', '', $priceStr);
        // Handle potential European decimal commas by replacing the *last* comma with a dot
        $lastCommaPos = strrpos($cleanedPrice, ',');
        $lastDotPos = strrpos($cleanedPrice, '.');
        if ($lastCommaPos !== false && ($lastDotPos === false || $lastCommaPos > $lastDotPos)) {
             $cleanedPrice = substr_replace($cleanedPrice, '.', $lastCommaPos, 1);
        }
        // Remove any remaining commas (which should now be thousands separators)
        $cleanedPrice = str_replace(',', '', $cleanedPrice);


        // Attempt to find a valid number (integer or decimal) at the beginning
        if (preg_match('/^[-+]?\d+(\.\d+)?/', $cleanedPrice, $matches)) {
             // Validate if the extracted number is actually numeric after casting
            if (is_numeric($matches[0])) {
                 return (float) $matches[0];
             }
        }

        // If no valid number found after cleaning
        return null;
    }

} // End Class