<?php
// File: includes/db-handler.php (v1.1.23 - Add explicit upgrade function)

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

        // Define the target schema including the is_ltd column and index
        $sql = "CREATE TABLE $table_name (
            link VARCHAR(767) NOT NULL,
            title TEXT NOT NULL,
            price VARCHAR(100) DEFAULT '' NOT NULL,
            source VARCHAR(100) NOT NULL,
            description TEXT DEFAULT '' NOT NULL,
            first_seen DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
            last_seen DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
            is_ltd TINYINT(1) DEFAULT 0 NOT NULL,
            PRIMARY KEY (link),
            INDEX source_idx (source),
            INDEX first_seen_idx (first_seen),
            INDEX price_idx (price(10)),
            INDEX is_ltd_idx (is_ltd)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        // dbDelta should handle adding the new column and index if they don't exist
        $result = dbDelta( $sql );
        error_log("DSP DB Check: dbDelta executed for table {$table_name}. Result: " . print_r($result, true));


        // Check if table exists after attempting creation/update
        $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name;
        if ( ! $table_exists ) {
            error_log( "DSP Error: Failed to create/update table: $table_name" );
        } else {
            // Verify if the specific column exists after dbDelta ran
            $column_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_schema = %s AND table_name = %s AND column_name = %s",
                DB_NAME, $table_name, 'is_ltd'
            ) );

            if ( $column_exists == 0 ) {
                 error_log( "DSP Error: dbDelta failed to add 'is_ltd' column to {$table_name}. Manual check required or run add_ltd_column_and_index() explicitly." );
            } else {
                 error_log("DSP Log: Table $table_name structure verified (including is_ltd).");
            }
        }
    }

    /**
     * Explicitly adds the 'is_ltd' column and its index if they don't exist.
     * Used as a fallback or specific upgrade step if dbDelta fails.
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
            // Add the column using ALTER TABLE
            $alter_result = $wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN `is_ltd` TINYINT(1) NOT NULL DEFAULT 0 AFTER `last_seen`;" );
            if ($alter_result === false) {
                error_log("DSP DB Upgrade Error: Failed to add 'is_ltd' column. DB Error: " . $wpdb->last_error);
            } else {
                error_log("DSP DB Upgrade: Successfully added 'is_ltd' column.");
            }
        } else {
             error_log("DSP DB Upgrade: 'is_ltd' column already exists.");
        }

        // Check if index exists
        $index_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = %s AND table_name = %s AND index_name = %s",
            DB_NAME, $table_name, 'is_ltd_idx'
        ) );

        if ( $index_exists == 0 ) {
            error_log("DSP DB Upgrade: 'is_ltd_idx' index not found. Attempting to add...");
            // Add the index using ALTER TABLE
             $alter_index_result = $wpdb->query( "ALTER TABLE `{$table_name}` ADD INDEX `is_ltd_idx` (`is_ltd`);" );
             if ($alter_index_result === false) {
                 error_log("DSP DB Upgrade Error: Failed to add 'is_ltd_idx' index. DB Error: " . $wpdb->last_error);
             } else {
                 error_log("DSP DB Upgrade: Successfully added 'is_ltd_idx' index.");
             }
        } else {
            error_log("DSP DB Upgrade: 'is_ltd_idx' index already exists.");
        }
    }


    /** Adds a new deal or updates an existing one. */
    public static function add_or_update_deal( $deal ) { global $wpdb; $table_name = self::get_table_name(); if ( empty( $deal['link'] ) || $deal['link'] === '#' || strlen($deal['link']) > 767 ) { return false; } if ( empty( $deal['title'] ) ) { return false; } $now_gmt = current_time( 'mysql', true ); $data = ['link' => sanitize_text_field( substr($deal['link'], 0, 767) ), 'title' => sanitize_text_field($deal['title']), 'price' => sanitize_text_field($deal['price'] ?? ''), 'source' => sanitize_text_field($deal['source'] ?? ''), 'description' => sanitize_textarea_field($deal['description'] ?? ''), 'last_seen' => $now_gmt, 'is_ltd' => isset($deal['is_ltd']) ? ( (bool) $deal['is_ltd'] ? 1 : 0 ) : 0, ]; $sql = $wpdb->prepare( "INSERT INTO $table_name (link, title, price, source, description, first_seen, last_seen, is_ltd) VALUES (%s, %s, %s, %s, %s, %s, %s, %d) ON DUPLICATE KEY UPDATE title = VALUES(title), price = VALUES(price), source = VALUES(source), description = VALUES(description), last_seen = VALUES(last_seen), is_ltd = VALUES(is_ltd)", $data['link'], $data['title'], $data['price'], $data['source'], $data['description'], $now_gmt, $data['last_seen'], $data['is_ltd'] ); $result = $wpdb->query( $sql ); if ( $result === false ) { error_log( "DSP DB Error INSERT/UPDATE link {$data['link']}: " . $wpdb->last_error ); return null; } return ( $result === 1 ); }

    /** Retrieves deals from the database */
    public static function get_deals( $args = [] ) { global $wpdb; $table_name = self::get_table_name(); $defaults = [ 'orderby' => 'first_seen', 'order' => 'DESC', 'items_per_page'=> -1, 'page' => 1, 'search' => '', 'sources' => [], 'newer_than_ts' => 0, 'min_price' => null, 'max_price' => null, 'ltd_only' => false ]; $args = wp_parse_args($args, $defaults); $allowed_orderby = ['link', 'title', 'price', 'source', 'first_seen', 'last_seen', 'is_ltd']; $orderby = in_array(strtolower($args['orderby']), $allowed_orderby) ? strtolower($args['orderby']) : $defaults['orderby']; $order = in_array(strtoupper($args['order']), ['ASC', 'DESC']) ? strtoupper($args['order']) : $defaults['order']; $orderby_sql = sanitize_sql_orderby( "{$orderby} {$order}" ); $items_per_page = intval($args['items_per_page']); $page = intval($args['page']); if ($page < 1) $page = 1; $limit_clause = ''; $offset = 0; $do_pagination = ($items_per_page > 0); if ($do_pagination) { $offset = ($page - 1) * $items_per_page; $limit_clause = $wpdb->prepare( "LIMIT %d OFFSET %d", $items_per_page, $offset ); } $where_conditions = []; $prepare_args = []; if ( !empty($args['search']) ) { $search_like = '%' . $wpdb->esc_like( $args['search'] ) . '%'; $where_conditions[] = "(title LIKE %s OR description LIKE %s)"; $prepare_args[] = $search_like; $prepare_args[] = $search_like; } if ( !empty($args['sources']) && is_array($args['sources']) ) { $sanitized_sources = array_map('sanitize_text_field', $args['sources']); if (!empty($sanitized_sources)) { $source_placeholders = implode(', ', array_fill(0, count($sanitized_sources), '%s')); $where_conditions[] = "source IN ($source_placeholders)"; foreach ($sanitized_sources as $source) { $prepare_args[] = $source; } } } if ( !empty($args['newer_than_ts']) && $args['newer_than_ts'] > 0) { $newer_than_datetime = gmdate('Y-m-d H:i:s', (int)$args['newer_than_ts']); $where_conditions[] = "first_seen >= %s"; $prepare_args[] = $newer_than_datetime; } if ($args['ltd_only'] === true) { $where_conditions[] = "is_ltd = 1"; } $min_price_sql = ($args['min_price'] !== null) ? (float) $args['min_price'] : null; $max_price_sql = ($args['max_price'] !== null) ? (float) $args['max_price'] : null; if ($min_price_sql !== null || $max_price_sql !== null) { $numeric_price_expression = " CAST( REPLACE( REPLACE( REPLACE( REPLACE( REPLACE( REPLACE( REGEXP_REPLACE(LOWER(price), '[^0-9.,]+', ''), ',', '' ), 'free', '0'), 'usd', ''), 'eur', ''), 'gbp', ''), '$', '') AS DECIMAL(10, 2)) "; $price_where_parts = []; if ($min_price_sql !== null) { if ( $min_price_sql == 0 ) { $price_where_parts[] = $wpdb->prepare( " ( {$numeric_price_expression} >= %f OR LOWER(price) LIKE %s ) ", $min_price_sql, '%free%' ); $prepare_args[] = $min_price_sql; $prepare_args[] = '%free%'; } else { $price_where_parts[] = $wpdb->prepare( " {$numeric_price_expression} >= %f ", $min_price_sql ); $prepare_args[] = $min_price_sql; } } if ($max_price_sql !== null) { $price_where_parts[] = $wpdb->prepare( " ( {$numeric_price_expression} IS NOT NULL AND {$numeric_price_expression} <= %f ) ", $max_price_sql ); $prepare_args[] = $max_price_sql; } if(!empty($price_where_parts)){ $where_conditions[] = " (" . implode( " AND ", $price_where_parts) . ") "; } } $where_clause = "WHERE 1=1"; if (!empty($where_conditions)) { $where_clause .= " AND " . implode(" AND ", $where_conditions); } $sql_count = "SELECT COUNT(*) FROM $table_name $where_clause"; if (!empty($prepare_args)) { $sql_count = $wpdb->prepare($sql_count, $prepare_args); } $total_deals = $wpdb->get_var( $sql_count ); if ( $total_deals === null || $wpdb->last_error ) { error_log("DSP DB Error get_deals COUNT: " . $wpdb->last_error . " SQL: " . $sql_count); return ['deals' => [], 'total_deals' => 0]; } $total_deals = intval($total_deals); $deals = []; if ($total_deals > 0 || ($page === 1 && empty($where_conditions))) { $sql_deals = "SELECT link, title, price, source, description, first_seen, last_seen, is_ltd FROM $table_name $where_clause ORDER BY $orderby_sql $limit_clause"; if (!empty($prepare_args)) { $sql_deals = $wpdb->prepare($sql_deals, $prepare_args); } $deals = $wpdb->get_results( $sql_deals ); if ( $wpdb->last_error ) { error_log("DSP DB Error get_deals SELECT: " . $wpdb->last_error . " SQL: " . $sql_deals); $deals = []; } } return [ 'deals' => $deals ? $deals : [], 'total_deals' => $total_deals ]; }

    // get_known_links() - No changes needed
    public static function get_known_links() { global $wpdb; $table_name = self::get_table_name(); $results = $wpdb->get_col( "SELECT link FROM $table_name" ); return $results ? $results : []; }
    // purge_old_deals() - No changes needed
    public static function purge_old_deals( $max_age_days ) { global $wpdb; $table_name = self::get_table_name(); $max_age_days = intval( $max_age_days ); if ( $max_age_days < 1 ) { return false; } $cutoff_timestamp_gmt = strtotime( "-{$max_age_days} days", current_time( 'timestamp', true ) ); if ($cutoff_timestamp_gmt === false) { return false; } $cutoff_datetime_gmt = date( 'Y-m-d H:i:s', $cutoff_timestamp_gmt ); $sql = $wpdb->prepare( "DELETE FROM $table_name WHERE first_seen < %s", $cutoff_datetime_gmt ); $rows_affected = $wpdb->query( $sql ); if ( $rows_affected === false ) { error_log( "DSP DB Purge Error: " . $wpdb->last_error ); return false; } if ( $rows_affected > 0 ) { error_log( "DSP DB Purge: Deleted " . $rows_affected . " deals older than " . $max_age_days . " days." ); } return $rows_affected; }
    // get_deals_since() - No changes needed
    public static function get_deals_since( $timestamp_gmt_str, $orderby = 'first_seen', $order = 'DESC' ) { global $wpdb; $table_name = self::get_table_name(); if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp_gmt_str ) ) { return []; } $allowed_orderby = ['link', 'title', 'price', 'source', 'first_seen', 'last_seen', 'is_ltd']; if (!in_array(strtolower($orderby), $allowed_orderby)) { $orderby = 'first_seen'; } if (!in_array(strtoupper($order), ['ASC', 'DESC'])) { $order = 'DESC'; } $orderby_sql = sanitize_sql_orderby( $orderby . ' ' . $order ); $sql = $wpdb->prepare( "SELECT link, title, price, source, description, first_seen, last_seen, is_ltd FROM $table_name WHERE first_seen > %s ORDER BY $orderby_sql", $timestamp_gmt_str ); $results = $wpdb->get_results( $sql ); if ( $wpdb->last_error ) { error_log("DSP DB Error getting deals since {$timestamp_gmt_str}: " . $wpdb->last_error); return []; } return $results ? $results : []; }

} // End Class