<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DSP_DB_Handler {

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'dsp_deals'; // e.g., wp_dsp_deals
    }

    public static function create_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // Use VARCHAR with a reasonable indexable length for the link as PRIMARY KEY
        $sql = "CREATE TABLE $table_name (
            link VARCHAR(767) NOT NULL, -- Max key length for utf8mb4
            title TEXT NOT NULL,
            price VARCHAR(100) DEFAULT '' NOT NULL,
            source VARCHAR(100) NOT NULL,
            description TEXT DEFAULT '' NOT NULL,
            first_seen DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
            last_seen DATETIME DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY (link)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql ); // Handles creation and updates

        // Check if table exists after attempting creation
        $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name;
        if ( ! $table_exists ) {
            error_log( "Deal Scraper Plugin: Failed to create database table: $table_name" );
        } else {
             error_log("Deal Scraper Plugin: Database table $table_name ensured.");
        }
    }

    /**
     * Adds a new deal or updates an existing one.
     *
     * @param array $deal Associative array containing deal data ('link', 'title', 'price', 'source', 'description').
     * @return bool|null Returns true if a new deal was inserted, false if updated or invalid, null on DB error.
     */
    public static function add_or_update_deal( $deal ) {
        global $wpdb;
        $table_name = self::get_table_name();

        if ( empty( $deal['link'] ) || $deal['link'] === '#' || strlen($deal['link']) > 767 ) {
             error_log("DSP DB: Invalid or too long link provided: " . ($deal['link'] ?? 'NULL'));
            return false; // Invalid link or too long for key
        }
         if ( empty( $deal['title'] ) ) {
             error_log("DSP DB: Skipping deal due to empty title for link: " . $deal['link']);
            return false; // Title is required
        }


        $now_gmt = current_time( 'mysql', true ); // Get current time in GMT/UTC

        // Prepare data, ensuring all keys exist and sanitize
        $data = array(
            'link'        => sanitize_text_field( substr($deal['link'], 0, 767) ), // Ensure max length
            'title'       => sanitize_text_field($deal['title']),
            'price'       => sanitize_text_field($deal['price'] ?? ''),
            'source'      => sanitize_text_field($deal['source'] ?? ''),
            'description' => sanitize_textarea_field($deal['description'] ?? ''),
            'last_seen'   => $now_gmt,
        );

        // Check if the deal exists using a performant query
        $existing_first_seen = $wpdb->get_var(
            $wpdb->prepare( "SELECT first_seen FROM $table_name WHERE link = %s", $data['link'] )
        );

        $is_new = false;

        // Using INSERT ... ON DUPLICATE KEY UPDATE for atomicity and efficiency
        $sql = $wpdb->prepare(
            "INSERT INTO $table_name (link, title, price, source, description, first_seen, last_seen)
             VALUES (%s, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                price = VALUES(price),
                description = VALUES(description),
                last_seen = VALUES(last_seen)",
            $data['link'],
            $data['title'],
            $data['price'],
            $data['source'],
            $data['description'],
            $now_gmt, // Set first_seen on initial insert attempt
            $data['last_seen'] // Set last_seen always
        );

        $result = $wpdb->query( $sql );

        if ( $result === false ) {
            error_log( "DSP DB Error on INSERT/UPDATE for link {$data['link']}: " . $wpdb->last_error );
            return null; // Indicate DB error specifically
        }

        // $result from $wpdb->query() for INSERT...ON DUPLICATE KEY UPDATE:
        // Returns 1 if a row is inserted.
        // Returns 2 if an existing row is updated.
        // Returns 0 if an existing row is updated with the same data (no change).
        if ( $result === 1 ) {
            $is_new = true; // It was newly inserted
        }
        // If $result is 0 or 2, it means the row existed (was updated or unchanged). Not new.

        return $is_new; // Return true if newly inserted, false otherwise
    }


    // Simple fetch, could add sorting/filtering parameters later
    public static function get_deals( $args = [] ) {
        global $wpdb;
        $table_name = self::get_table_name();

        // Defaults
        $defaults = [
            'orderby' => 'first_seen',
            'order'   => 'DESC',
            'limit'   => -1, // No limit by default
            'offset'  => 0,
        ];
        $args = wp_parse_args($args, $defaults);

        // Basic security: Ensure orderby is based on expected columns
        $allowed_orderby = ['link', 'title', 'price', 'source', 'first_seen', 'last_seen'];
        if (!in_array(strtolower($args['orderby']), $allowed_orderby)) {
            $args['orderby'] = 'first_seen'; // Fallback to default if invalid column
        }
         if (!in_array(strtoupper($args['order']), ['ASC', 'DESC'])) {
            $args['order'] = 'DESC'; // Fallback to default if invalid order
        }

        $orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] ); // Use validated args
        $limit_clause = $args['limit'] > 0 ? $wpdb->prepare( "LIMIT %d OFFSET %d", intval($args['limit']), intval($args['offset']) ) : '';


        $sql = "SELECT link, title, price, source, description, first_seen, last_seen FROM $table_name ORDER BY $orderby $limit_clause";

        $results = $wpdb->get_results( $sql );

        if ( $wpdb->last_error ) {
             error_log("DSP DB Error getting deals: " . $wpdb->last_error);
             return []; // Return empty array on error
        }

        return $results ? $results : [];
    }

     // Optimization: Get only links to check against before inserting/updating (Not used currently with INSERT ON DUPLICATE)
     public static function get_known_links() {
        global $wpdb;
        $table_name = self::get_table_name();
        $results = $wpdb->get_col( "SELECT link FROM $table_name" );
        return $results ? $results : [];
    }
}