<?php
// File: includes/db-handler.php (v1.3.1+ - Add check/create FULLTEXT index explicitly)

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
     * Explicitly checks and adds the FULLTEXT index.
     */
    public static function create_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $index_name = 'title_desc_ft'; // Name of the FULLTEXT index

        // Base table structure
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
            -- FULLTEXT index potentially added by dbDelta OR below
        ) $charset_collate;"; // Removed FULLTEXT from initial CREATE for separate check

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql ); // Run dbDelta first for base structure and other indexes

        // Check if table exists now
        $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name;

        if ( $table_exists ) {
            error_log("Deal Scraper Plugin: Base table structure for $table_name ensured.");

            // --- Explicitly Check and Create FULLTEXT Index ---
            $index_exists = $wpdb->get_row( $wpdb->prepare(
                "SHOW INDEX FROM `$table_name` WHERE Key_name = %s AND Index_type = 'FULLTEXT'",
                $index_name
            ));

            if ( ! $index_exists ) {
                error_log("Deal Scraper Plugin: FULLTEXT index '$index_name' not found on $table_name. Attempting to create...");
                // IMPORTANT: ALTER TABLE requires specific DB privileges. May fail on some hosts.
                // Ensure the columns are TEXT or VARCHAR for FULLTEXT.
                $alter_sql = "ALTER TABLE `$table_name` ADD FULLTEXT KEY `$index_name` (`title`, `description`)";
                $result = $wpdb->query( $alter_sql );

                if ( $result === false ) {
                    error_log("Deal Scraper Plugin: FAILED to create FULLTEXT index '$index_name'. DB Error: " . $wpdb->last_error);
                    // Log a notice for the admin? Could add an admin notice here.
                } else {
                    error_log("Deal Scraper Plugin: Successfully created FULLTEXT index '$index_name'.");
                }
            } else {
                error_log("Deal Scraper Plugin: FULLTEXT index '$index_name' already exists on $table_name.");
            }
            // --- End Explicit Index Check ---

        } else {
            error_log( "Deal Scraper Plugin: Failed to create database table: $table_name using dbDelta." );
        }
    }

    /**
     * Adds a new deal or updates an existing one.
     */
    public static function add_or_update_deal( $deal ) { /* ... same as before ... */
        global $wpdb; $table_name = self::get_table_name();
        if ( empty( $deal['link'] ) || $deal['link'] === '#' || strlen($deal['link']) > 767 ) { error_log("DSP DB: Invalid link: ".($deal['link'] ?? 'NULL')); return false; }
        if ( empty( $deal['title'] ) ) { error_log("DSP DB: Empty title: ".($deal['link'] ?? 'Unknown Link')); return false; }
        $now_gmt = current_time( 'mysql', true );
        $data = [ 'link' => sanitize_text_field( substr($deal['link'], 0, 767) ), 'title' => sanitize_text_field($deal['title']), 'price' => sanitize_text_field($deal['price'] ?? ''), 'source' => sanitize_text_field($deal['source'] ?? ''), 'description' => sanitize_textarea_field($deal['description'] ?? ''), 'last_seen' => $now_gmt ];
        $sql = $wpdb->prepare( "INSERT INTO $table_name (link, title, price, source, description, first_seen, last_seen) VALUES (%s, %s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE title = VALUES(title), price = VALUES(price), source = VALUES(source), description = VALUES(description), last_seen = VALUES(last_seen)", $data['link'], $data['title'], $data['price'], $data['source'], $data['description'], $now_gmt, $data['last_seen'] );
        $result = $wpdb->query( $sql ); if ( $result === false ) { error_log( "DSP DB Error on INSERT/UPDATE: " . $wpdb->last_error ); return null; }
        return ( $result === 1 );
    }


    /**
     * Retrieves deals from the database with server-side filtering and pagination.
     * Uses FULLTEXT search if search term is provided.
     */
    public static function get_deals( $args = [] ) { /* ... code remains identical to the previous version with FULLTEXT search ... */
        global $wpdb; $table_name = self::get_table_name();
        $defaults = [ 'search' => '', 'sources' => [], 'is_new_since' => 0, 'page' => 1, 'per_page' => 15, 'orderby' => 'first_seen', 'order' => 'DESC' ];
        $args = wp_parse_args($args, $defaults);

        // Sanitize inputs
        $search_term = trim( $args['search'] );
        $sources = is_array( $args['sources'] ) ? array_map( 'sanitize_text_field', $args['sources'] ) : [];
        $new_since_ts = intval( $args['is_new_since'] );
        $page = max( 1, intval( $args['page'] ) );
        $per_page = max( 1, intval( $args['per_page'] ) );
        $offset = ( $page - 1 ) * $per_page;

        // Define allowed orderby columns (including relevance for search)
        $allowed_orderby = ['link', 'title', 'price', 'source', 'first_seen', 'last_seen', 'relevance'];
        $orderby_col_input = strtolower( $args['orderby'] );
        $orderby_col = in_array( $orderby_col_input, $allowed_orderby, true ) ? $orderby_col_input : $defaults['orderby'];
        $order_dir = in_array( strtoupper( $args['order'] ), ['ASC', 'DESC'], true ) ? strtoupper( $args['order'] ) : $defaults['order'];

        $where_clauses = ['1=1'];
        $sql_params = []; // Will hold all parameters for $wpdb->prepare
        $select_fields = "`link`, `title`, `price`, `source`, `description`, `first_seen`, `last_seen`"; // Use backticks
        $is_searching = ! empty( $search_term );

        // --- Search Logic using FULLTEXT ---
        if ( $is_searching ) {
            $select_fields .= ", MATCH(title, description) AGAINST (%s IN NATURAL LANGUAGE MODE) AS relevance";
            $sql_params[] = $search_term;
            $where_clauses[] = "MATCH(title, description) AGAINST (%s IN NATURAL LANGUAGE MODE)";
            $sql_params[] = $search_term;
            if ($orderby_col_input === $defaults['orderby']) { $orderby_col = 'relevance'; $order_dir = 'DESC'; }
        }

        // --- Other Filters ---
        if ( ! empty( $sources ) ) {
            $source_placeholders = implode( ', ', array_fill( 0, count( $sources ), '%s' ) );
            $where_clauses[] = "`source` IN ( " . $source_placeholders . " )";
            foreach ( $sources as $source ) { $sql_params[] = $source; }
        }
        if ( $new_since_ts > 0 ) {
            $new_since_datetime = date( 'Y-m-d H:i:s', $new_since_ts );
            $where_clauses[] = "`first_seen` >= %s";
            $sql_params[] = $new_since_datetime;
        }

        // --- Build ORDER BY ---
         $orderby_col = in_array( $orderby_col, $allowed_orderby, true ) ? $orderby_col : $defaults['orderby'];
        if ($orderby_col === 'relevance') {
            $orderby_sql = "relevance " . $order_dir;
        } else {
            $orderby_sql = sanitize_sql_orderby( "`" . $orderby_col . "` " . $order_dir );
        }

        // --- Build Final Query ---
        $where_sql = implode( ' AND ', $where_clauses );

        // Get Total Count (Requires only WHERE parameters)
        $count_where_params = [];
        if ($is_searching) $count_where_params[] = $sql_params[1]; // Get the WHERE search term
        if (!empty($sources)) $count_where_params = array_merge($count_where_params, $sources);
        if ($new_since_ts > 0) $count_where_params[] = $new_since_datetime;

        $total_items_sql = "SELECT COUNT(*) FROM `$table_name` WHERE $where_sql";
        $total_items = $wpdb->get_var( $wpdb->prepare( $total_items_sql, $count_where_params ) );
        if ( $wpdb->last_error ) {
            error_log("DSP DB Error getting total count: " . $wpdb->last_error);
            // Check if error is missing index
             if (strpos($wpdb->last_error, 'FULLTEXT index') !== false) {
                 error_log("DSP DB Hint: FULLTEXT index 'title_desc_ft' might be missing or needs creation on the $table_name table.");
             }
            return ['deals' => [], 'total_items' => 0, 'total_pages' => 0]; // Return total_pages 0 on error
        }
        $total_items = intval($total_items);
        $total_pages = ($per_page > 0 && $total_items > 0) ? ceil($total_items / $per_page) : 1;


        // Get Deals for Page (Requires ALL parameters + LIMIT/OFFSET)
        $deals = [];
        if ( $total_items > 0 && $offset < $total_items) {
            $deals_sql = "SELECT $select_fields FROM `$table_name` WHERE $where_sql ORDER BY $orderby_sql LIMIT %d OFFSET %d";
            $sql_params_with_limit_offset = array_merge( $sql_params, [$per_page, $offset] );
            $deals = $wpdb->get_results( $wpdb->prepare( $deals_sql, $sql_params_with_limit_offset ) );
            if ( $wpdb->last_error ) {
                 error_log("DSP DB Error getting deals page: " . $wpdb->last_error);
                 if (strpos($wpdb->last_error, 'FULLTEXT index') !== false) {
                     error_log("DSP DB Hint: FULLTEXT index 'title_desc_ft' might be missing or needs creation on the $table_name table.");
                 }
                 return ['deals' => [], 'total_items' => $total_items, 'total_pages' => $total_pages ];
            }
        }

        // Return deals for the page and pagination info
        return [
            'deals'       => $deals ? $deals : [],
            'total_items' => $total_items,
            'total_pages' => $total_pages
        ];
    }


     public static function get_known_links() { /* ... */ }
     public static function purge_old_deals( $max_age_days ) { /* ... */ }
     public static function clear_all_deals() { /* ... */ }

} // End Class