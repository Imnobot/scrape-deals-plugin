<?php
// File: includes/db-handler.php (v1.0.9 - Added get_deals_since)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DSP_DB_Handler {

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'dsp_deals'; // e.g., wp_dsp_deals
    }

    /**
     * Creates or updates the database table schema.
     * Adds indexes for performance.
     */
    public static function create_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            link VARCHAR(767) NOT NULL,
            title TEXT NOT NULL,
            price VARCHAR(100) DEFAULT '' NOT NULL,
            source VARCHAR(100) NOT NULL,
            description TEXT DEFAULT '' NOT NULL,
            first_seen DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
            last_seen DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY (link),
            INDEX source_idx (source),
            INDEX first_seen_idx (first_seen)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name;
        if ( ! $table_exists ) {
            error_log( "Deal Scraper Plugin: Failed to create database table: $table_name" );
        } else {
             $index_check = $wpdb->get_results("SHOW INDEX FROM $table_name WHERE Key_name IN ('source_idx', 'first_seen_idx')");
             if (count($index_check) < 2) { error_log("Deal Scraper Plugin: Indexes might not be fully created on $table_name."); }
             else { error_log("Deal Scraper Plugin: Database table $table_name and indexes ensured."); }
        }
    }

    /**
     * Adds a new deal or updates an existing one.
     *
     * @param array $deal Associative array containing deal data.
     * @return bool|null True if new, false if updated/invalid, null on DB error.
     */
    public static function add_or_update_deal( $deal ) {
        global $wpdb; $table_name = self::get_table_name();

        if ( empty( $deal['link'] ) || $deal['link'] === '#' || strlen($deal['link']) > 767 ) { return false; }
        if ( empty( $deal['title'] ) ) { return false; }

        $now_gmt = current_time( 'mysql', true );
        $data = array(
            'link' => sanitize_text_field( substr($deal['link'], 0, 767) ), 'title' => sanitize_text_field($deal['title']),
            'price' => sanitize_text_field($deal['price'] ?? ''), 'source' => sanitize_text_field($deal['source'] ?? ''),
            'description' => sanitize_textarea_field($deal['description'] ?? ''), 'last_seen' => $now_gmt,
        );

        $sql = $wpdb->prepare(
            "INSERT INTO $table_name (link, title, price, source, description, first_seen, last_seen)
             VALUES (%s, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title), price = VALUES(price), source = VALUES(source),
                description = VALUES(description), last_seen = VALUES(last_seen)",
            $data['link'], $data['title'], $data['price'], $data['source'], $data['description'], $now_gmt, $data['last_seen']
        );
        $result = $wpdb->query( $sql );

        if ( $result === false ) { error_log( "DSP DB Error INSERT/UPDATE link {$data['link']}: " . $wpdb->last_error ); return null; }
        return ( $result === 1 ); // True if inserted
    }


    /**
     * Retrieves deals from the database.
     *
     * @param array $args Optional arguments for sorting, limiting.
     * @return array Array of deal objects or empty array.
     */
    public static function get_deals( $args = [] ) {
        global $wpdb; $table_name = self::get_table_name();
        $defaults = [ 'orderby' => 'first_seen', 'order' => 'DESC', 'limit' => -1, 'offset' => 0, ];
        $args = wp_parse_args($args, $defaults);
        $allowed_orderby = ['link', 'title', 'price', 'source', 'first_seen', 'last_seen'];
        if (!in_array(strtolower($args['orderby']), $allowed_orderby)) { $args['orderby'] = $defaults['orderby']; }
        if (!in_array(strtoupper($args['order']), ['ASC', 'DESC'])) { $args['order'] = $defaults['order']; }
        $orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
        $limit_clause = $args['limit'] > 0 ? $wpdb->prepare( "LIMIT %d OFFSET %d", intval($args['limit']), intval($args['offset']) ) : '';
        $sql = "SELECT link, title, price, source, description, first_seen, last_seen FROM $table_name ORDER BY $orderby $limit_clause";
        $results = $wpdb->get_results( $sql );
        if ( $wpdb->last_error ) { error_log("DSP DB Error get_deals: " . $wpdb->last_error); return []; }
        return $results ? $results : [];
    }

     // get_known_links() - Not actively used but can remain
     public static function get_known_links() { /* ... same ... */ }

    /**
     * Deletes deals from the database older than a specified number of days.
     *
     * @param int $max_age_days The maximum age in days for deals to keep.
     * @return int|false The number of rows deleted, or false on error.
     */
    public static function purge_old_deals( $max_age_days ) {
        global $wpdb; $table_name = self::get_table_name();
        $max_age_days = intval( $max_age_days ); if ( $max_age_days < 1 ) { return false; }
        $cutoff_timestamp_gmt = strtotime( "-{$max_age_days} days", current_time( 'timestamp', true ) );
        if ($cutoff_timestamp_gmt === false) { return false; }
        $cutoff_datetime_gmt = date( 'Y-m-d H:i:s', $cutoff_timestamp_gmt );
        $sql = $wpdb->prepare( "DELETE FROM $table_name WHERE first_seen < %s", $cutoff_datetime_gmt );
        $rows_affected = $wpdb->query( $sql );
        if ( $rows_affected === false ) { error_log( "DSP DB Purge Error: " . $wpdb->last_error ); return false; }
        if ( $rows_affected > 0 ) { error_log( "DSP DB Purge: Deleted " . $rows_affected . " deals older than " . $max_age_days . " days." ); }
        return $rows_affected;
    }


    /**
     * Retrieves deals first seen *after* a specific GMT timestamp.
     *
     * @param string $timestamp_gmt_str A GMT timestamp string in 'Y-m-d H:i:s' format.
     * @param string $orderby Column to order by.
     * @param string $order Sort order ('ASC' or 'DESC').
     * @return array Array of deal objects or empty array.
     */
    public static function get_deals_since( $timestamp_gmt_str, $orderby = 'first_seen', $order = 'DESC' ) {
        global $wpdb;
        $table_name = self::get_table_name();

        // Validate timestamp format (basic check)
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $timestamp_gmt_str ) ) {
             error_log("DSP DB Error get_deals_since: Invalid timestamp format provided: " . esc_html($timestamp_gmt_str));
             return [];
        }

        // Basic security for orderby/order
         $allowed_orderby = ['link', 'title', 'price', 'source', 'first_seen', 'last_seen'];
         if (!in_array(strtolower($orderby), $allowed_orderby)) { $orderby = 'first_seen'; } // Default
         if (!in_array(strtoupper($order), ['ASC', 'DESC'])) { $order = 'DESC'; } // Default
         $orderby_sql = sanitize_sql_orderby( $orderby . ' ' . $order );

        // Prepare SQL to select deals newer than the given time
        $sql = $wpdb->prepare(
            "SELECT link, title, price, source, description, first_seen, last_seen
             FROM $table_name
             WHERE first_seen > %s
             ORDER BY $orderby_sql",
            $timestamp_gmt_str // Compare against the GMT timestamp
        );

        $results = $wpdb->get_results( $sql );

        if ( $wpdb->last_error ) {
             error_log("DSP DB Error getting deals since {$timestamp_gmt_str}: " . $wpdb->last_error);
             return []; // Return empty array on error
        }

        return $results ? $results : [];
    }

} // End Class