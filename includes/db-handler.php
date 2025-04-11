<?php
// File: includes/db-handler.php (v1.1.14 - Add Price Filter Logic)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DSP_DB_Handler {

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'dsp_deals';
    }

    /** Creates or updates the database table schema */
    public static function create_table() {
        global $wpdb; $table_name = self::get_table_name(); $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name ( link VARCHAR(767) NOT NULL, title TEXT NOT NULL, price VARCHAR(100) DEFAULT '' NOT NULL, source VARCHAR(100) NOT NULL, description TEXT DEFAULT '' NOT NULL, first_seen DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL, last_seen DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL, PRIMARY KEY (link), INDEX source_idx (source), INDEX first_seen_idx (first_seen), INDEX price_idx (price(10)) ) $charset_collate;";
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' ); dbDelta( $sql );
        $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name; if ( ! $table_exists ) { error_log( "DSP Error: Failed to create table: $table_name" ); } else { $index_check = $wpdb->get_results("SHOW INDEX FROM $table_name WHERE Key_name IN ('source_idx', 'first_seen_idx', 'price_idx')"); if (count($index_check) < 3) { error_log("DSP Warning: Indexes might not be fully created on $table_name."); } else { error_log("DSP Log: Table $table_name and indexes ensured."); } }
    }

    /** Adds a new deal or updates an existing one */
    public static function add_or_update_deal( $deal ) {
        global $wpdb; $table_name = self::get_table_name();
        if ( empty( $deal['link'] ) || $deal['link'] === '#' || strlen($deal['link']) > 767 ) { return false; } if ( empty( $deal['title'] ) ) { return false; }
        $now_gmt = current_time( 'mysql', true ); $data = ['link' => sanitize_text_field( substr($deal['link'], 0, 767) ), 'title' => sanitize_text_field($deal['title']), 'price' => sanitize_text_field($deal['price'] ?? ''), 'source' => sanitize_text_field($deal['source'] ?? ''), 'description' => sanitize_textarea_field($deal['description'] ?? ''), 'last_seen' => $now_gmt ];
        $sql = $wpdb->prepare( "INSERT INTO $table_name (link, title, price, source, description, first_seen, last_seen) VALUES (%s, %s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE title = VALUES(title), price = VALUES(price), source = VALUES(source), description = VALUES(description), last_seen = VALUES(last_seen)", $data['link'], $data['title'], $data['price'], $data['source'], $data['description'], $now_gmt, $data['last_seen'] );
        $result = $wpdb->query( $sql );
        if ( $result === false ) { error_log( "DSP DB Error INSERT/UPDATE link {$data['link']}: " . $wpdb->last_error ); return null; }
        return ( $result === 1 );
    }

    /**
     * Retrieves deals from the database with pagination and filtering support.
     * @param array $args Optional arguments { orderby, order, items_per_page, page, search, sources, newer_than_ts, min_price, max_price }
     * @return array An array containing 'deals' and 'total_deals'.
     */
    public static function get_deals( $args = [] ) {
        global $wpdb; $table_name = self::get_table_name();
        // Defaults & Argument Parsing
        $defaults = [ 'orderby' => 'first_seen', 'order' => 'DESC', 'items_per_page'=> -1, 'page' => 1, 'search' => '', 'sources' => [], 'newer_than_ts' => 0, 'min_price' => null, 'max_price' => null ];
        $args = wp_parse_args($args, $defaults);
        // Sanitize & Validate Sort Args
        $allowed_orderby = ['link', 'title', 'price', 'source', 'first_seen', 'last_seen']; $orderby = in_array(strtolower($args['orderby']), $allowed_orderby) ? strtolower($args['orderby']) : $defaults['orderby']; $order = in_array(strtoupper($args['order']), ['ASC', 'DESC']) ? strtoupper($args['order']) : $defaults['order']; $orderby_sql = sanitize_sql_orderby( "{$orderby} {$order}" );
        // Sanitize Pagination Args
        $items_per_page = intval($args['items_per_page']); $page = intval($args['page']); if ($page < 1) $page = 1; $limit_clause = ''; $offset = 0; $do_pagination = ($items_per_page > 0); if ($do_pagination) { $offset = ($page - 1) * $items_per_page; $limit_clause = $wpdb->prepare( "LIMIT %d OFFSET %d", $items_per_page, $offset ); }

        // Build WHERE clause based on filters
        $where_conditions = []; $prepare_args = [];
        // 1. Search Filter
        if ( !empty($args['search']) ) { $search_like = '%' . $wpdb->esc_like( $args['search'] ) . '%'; $where_conditions[] = "(title LIKE %s OR description LIKE %s)"; $prepare_args[] = $search_like; $prepare_args[] = $search_like; }
        // 2. Source Filter
        if ( !empty($args['sources']) && is_array($args['sources']) ) { $sanitized_sources = array_map('sanitize_text_field', $args['sources']); if (!empty($sanitized_sources)) { $source_placeholders = implode(', ', array_fill(0, count($sanitized_sources), '%s')); $where_conditions[] = "source IN ($source_placeholders)"; foreach ($sanitized_sources as $source) { $prepare_args[] = $source; } } }
        // 3. New Only Filter
        if ( !empty($args['newer_than_ts']) && $args['newer_than_ts'] > 0) { $newer_than_datetime = gmdate('Y-m-d H:i:s', (int)$args['newer_than_ts']); $where_conditions[] = "first_seen >= %s"; $prepare_args[] = $newer_than_datetime; }

        // *** 4. Price Range Filter ***
        $min_price_sql = ($args['min_price'] !== null) ? (float) $args['min_price'] : null;
        $max_price_sql = ($args['max_price'] !== null) ? (float) $args['max_price'] : null;
        $price_conditions_added = false; // Flag to check if we add price conditions

        if ($min_price_sql !== null || $max_price_sql !== null) {
             // This complex extraction aims to get a number from strings like '$99.00', '€49', 'Free', '120 USD' etc.
             // It removes common symbols, handles 'free' as 0, and casts.
             // Note: This might be slow and may not cover all edge cases perfectly.
             // REGEXP_REPLACE is generally better but less universally available on older MySQL/MariaDB.
             // Using nested REPLACE is more compatible. Adjust symbols as needed.
             $numeric_price_expression = "
                CAST(
                    REPLACE(
                    REPLACE(
                    REPLACE(
                    REPLACE(
                    REPLACE(
                    REPLACE(
                        REGEXP_REPLACE(LOWER(price), '[^0-9.,]+', ''), -- Keep only numbers, dots, commas
                        ',', '' -- Remove commas
                    ),
                    'free', '0'),
                    'usd', ''),
                    'eur', ''),
                    'gbp', ''),
                    '$', '')
                AS DECIMAL(10, 2))
             "; // Adjust DECIMAL precision/scale if necessary


             $price_where_parts = [];
             if ($min_price_sql !== null) {
                // Include price = 0 check if min_price is 0 to catch 'Free' accurately
                if ( $min_price_sql == 0 ) {
                    $price_where_parts[] = $wpdb->prepare( " ( {$numeric_price_expression} >= %f OR LOWER(price) LIKE %s ) ", $min_price_sql, '%free%' );
                     $prepare_args[] = $min_price_sql;
                     $prepare_args[] = '%free%';
                } else {
                     $price_where_parts[] = $wpdb->prepare( " {$numeric_price_expression} >= %f ", $min_price_sql );
                     $prepare_args[] = $min_price_sql;
                }
             }
             if ($max_price_sql !== null) {
                // Deals must have a parsable numeric price AND be less than or equal to max
                 $price_where_parts[] = $wpdb->prepare( " ( {$numeric_price_expression} IS NOT NULL AND {$numeric_price_expression} <= %f ) ", $max_price_sql );
                 $prepare_args[] = $max_price_sql;
             }

             if(!empty($price_where_parts)){
                $where_conditions[] = " (" . implode( " AND ", $price_where_parts) . ") ";
                $price_conditions_added = true;
             }
        }
        // *** END Price Range Filter ***


        // Combine WHERE conditions
        $where_clause = "WHERE 1=1"; if (!empty($where_conditions)) { $where_clause .= " AND " . implode(" AND ", $where_conditions); }

        // Query 1: Get Total Count
        $sql_count = "SELECT COUNT(*) FROM $table_name $where_clause";
        if (!empty($prepare_args)) { $sql_count = $wpdb->prepare($sql_count, $prepare_args); }
        $total_deals = $wpdb->get_var( $sql_count );
        if ( $total_deals === null || $wpdb->last_error ) { error_log("DSP DB Error get_deals COUNT: " . $wpdb->last_error . " SQL: " . $sql_count); return ['deals' => [], 'total_deals' => 0]; }
        $total_deals = intval($total_deals);

        // Query 2: Get Deals for the Current Page
        $deals = [];
        if ($total_deals > 0 || ($page === 1 && !$price_conditions_added)) { // Fetch if deals exist OR it's page 1 without price filter (initial load edge case)
             $sql_deals = "SELECT link, title, price, source, description, first_seen, last_seen FROM $table_name $where_clause ORDER BY $orderby_sql $limit_clause";
             if (!empty($prepare_args)) { $sql_deals = $wpdb->prepare($sql_deals, $prepare_args); }
             $deals = $wpdb->get_results( $sql_deals );
             if ( $wpdb->last_error ) { error_log("DSP DB Error get_deals SELECT: " . $wpdb->last_error . " SQL: " . $sql_deals); $deals = []; }
        }

        // Return Results
        return [ 'deals' => $deals ? $deals : [], 'total_deals' => $total_deals ];
    } // End get_deals

    // get_known_links() - No changes needed
    public static function get_known_links() { global $wpdb; $table_name = self::get_table_name(); $results = $wpdb->get_col( "SELECT link FROM $table_name" ); return $results ? $results : []; }
    // purge_old_deals() - No changes needed
    public static function purge_old_deals( $max_age_days ) { global $wpdb; $table_name = self::get_table_name(); $max_age_days = intval( $max_age_days ); if ( $max_age_days < 1 ) { return false; } $cutoff_timestamp_gmt = strtotime( "-{$max_age_days} days", current_time( 'timestamp', true ) ); if ($cutoff_timestamp_gmt === false) { return false; } $cutoff_datetime_gmt = date( 'Y-m-d H:i:s', $cutoff_timestamp_gmt ); $sql = $wpdb->prepare( "DELETE FROM $table_name WHERE first_seen < %s", $cutoff_datetime_gmt ); $rows_affected = $wpdb->query( $sql ); if ( $rows_affected === false ) { error_log( "DSP DB Purge Error: " . $wpdb->last_error ); return false; } if ( $rows_affected > 0 ) { error_log( "DSP DB Purge: Deleted " . $rows_affected . " deals older than " . $max_age_days . " days." ); } return $rows_affected; }
    // get_deals_since() - No changes needed
    public static function get_deals_since( $timestamp_gmt_str, $orderby = 'first_seen', $order = 'DESC' ) { global $wpdb; $table_name = self::get_table_name(); if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp_gmt_str ) ) { return []; } $allowed_orderby = ['link', 'title', 'price', 'source', 'first_seen', 'last_seen']; if (!in_array(strtolower($orderby), $allowed_orderby)) { $orderby = 'first_seen'; } if (!in_array(strtoupper($order), ['ASC', 'DESC'])) { $order = 'DESC'; } $orderby_sql = sanitize_sql_orderby( $orderby . ' ' . $order ); $sql = $wpdb->prepare( "SELECT link, title, price, source, description, first_seen, last_seen FROM $table_name WHERE first_seen > %s ORDER BY $orderby_sql", $timestamp_gmt_str ); $results = $wpdb->get_results( $sql ); if ( $wpdb->last_error ) { error_log("DSP DB Error getting deals since {$timestamp_gmt_str}: " . $wpdb->last_error); return []; } return $results ? $results : []; }

} // End Class