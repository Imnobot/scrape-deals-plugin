<?php
// File: admin/settings-page.php (v1.1.40 - Clear cache before reading status)

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Add settings page */
function dsp_add_admin_menu() {
    add_options_page(
        __( 'Deal Scraper Settings', 'deal-scraper-plugin' ),
        __( 'Deal Scraper', 'deal-scraper-plugin' ),
        'manage_options',
        'deal_scraper_settings',
        'dsp_render_settings_page'
    );
}
add_action( 'admin_menu', 'dsp_add_admin_menu' );

/** Register settings */
function dsp_register_settings() {
    register_setting( 'dsp_settings_group', DSP_OPTION_NAME, 'dsp_sanitize_settings' );

    // Source Management Section
    add_settings_section(
        'dsp_source_management_section',
        __( 'Manage Scraping Sources', 'deal-scraper-plugin' ),
        'dsp_source_management_section_callback',
        'deal_scraper_settings'
    );

    // Import/Export Section
    add_settings_section(
        'dsp_import_export_section',
        __( 'Import / Export Sources', 'deal-scraper-plugin' ),
        'dsp_import_export_section_callback',
        'deal_scraper_settings'
    );
    add_settings_field(
        'dsp_export_sources_field',
        __( 'Export Sources', 'deal-scraper-plugin' ),
        'dsp_render_export_sources_field',
        'deal_scraper_settings',
        'dsp_import_export_section'
    );
    add_settings_field(
        'dsp_import_sources_field',
        __( 'Import Sources', 'deal-scraper-plugin' ),
        'dsp_render_import_sources_field',
        'deal_scraper_settings',
        'dsp_import_export_section'
    );

    // Email Section
    add_settings_section(
        'dsp_email_settings_section',
        __('Email Notifications', 'deal-scraper-plugin'),
        'dsp_email_settings_section_callback',
        'deal_scraper_settings'
    );
    add_settings_field(
        'dsp_email_enabled',
        __('Enable Notifications', 'deal-scraper-plugin'),
        'dsp_render_email_enabled_field',
        'deal_scraper_settings',
        'dsp_email_settings_section'
    );
    add_settings_field(
        'dsp_email_frequency',
        __('Notification Frequency', 'deal-scraper-plugin'),
        'dsp_render_email_frequency_field',
        'deal_scraper_settings',
        'dsp_email_settings_section'
    );
    add_settings_field(
        'dsp_email_recipients',
        __('Recipient Emails', 'deal-scraper-plugin'),
        'dsp_render_email_recipients_field',
        'deal_scraper_settings',
        'dsp_email_settings_section'
    );
    add_settings_field(
        'dsp_manual_email_send_button',
        '', // No label needed for a button field usually
        'dsp_render_manual_email_send_button_field',
        'deal_scraper_settings',
        'dsp_email_settings_section'
    );

    // Frontend Section
    add_settings_section(
        'dsp_frontend_settings_section',
        __('Frontend Display Options', 'deal-scraper-plugin'),
        'dsp_frontend_settings_section_callback',
        'deal_scraper_settings'
    );
    add_settings_field(
        'dsp_show_debug_button',
        __('Debug Button', 'deal-scraper-plugin'),
        'dsp_render_show_debug_button_field',
        'deal_scraper_settings',
        'dsp_frontend_settings_section'
    );
    add_settings_field(
        'dsp_refresh_button_access',
        __('Refresh Button Access', 'deal-scraper-plugin'),
        'dsp_render_refresh_button_access_field',
        'deal_scraper_settings',
        'dsp_frontend_settings_section'
    );
    add_settings_field(
        'dsp_dark_mode_default',
        __('Default Color Mode', 'deal-scraper-plugin'),
        'dsp_render_dark_mode_default_field',
        'deal_scraper_settings',
        'dsp_frontend_settings_section'
    );

    // Data Management Section
    add_settings_section(
        'dsp_data_management_section',
        __('Data Management & Scheduling', 'deal-scraper-plugin'),
        'dsp_data_management_section_callback',
        'deal_scraper_settings'
    );
    add_settings_field(
        'dsp_fetch_frequency',
        __('Deal Check Frequency', 'deal-scraper-plugin'),
        'dsp_render_fetch_frequency_field',
        'deal_scraper_settings',
        'dsp_data_management_section'
    );
    add_settings_field(
        'dsp_purge_enabled',
        __('Auto-Purge Old Deals', 'deal-scraper-plugin'),
        'dsp_render_purge_enabled_field',
        'deal_scraper_settings',
        'dsp_data_management_section'
    );
    add_settings_field(
        'dsp_purge_max_age_days',
        __('Purge Deals Older Than', 'deal-scraper-plugin'),
        'dsp_render_purge_max_age_days_field',
        'deal_scraper_settings',
        'dsp_data_management_section'
    );
    // Sideload Image Setting Field (Already added in your provided code)
    add_settings_field(
        'dsp_sideload_images',                       // Field ID
        __('Download & Store Images', 'deal-scraper-plugin'), // Field Title
        'dsp_render_sideload_images_field',          // Render callback
        'deal_scraper_settings',                     // Page slug
        'dsp_data_management_section'                // Section ID
    );
}
add_action( 'admin_init', 'dsp_register_settings' );

// --- Callbacks ---

/** Callback for Source Management Section */
function dsp_source_management_section_callback() {
    echo '<p>' . esc_html__( 'Add, edit, or remove websites to scrape for deals.', 'deal-scraper-plugin' ) . '</p>';
    echo '<p>' . sprintf( wp_kses_post( __( 'Select the corresponding Parser File. Ensure the file exists in `%s` and contains the function `parse_%s_php()`. Changes to Enabled status, Parser File, and Delete actions save immediately via AJAX.', 'deal-scraper-plugin' ) ), esc_html( str_replace(ABSPATH, '', DSP_PLUGIN_DIR) . 'includes/parsers/' ) ,'<i><parser_file_name></i>' ) . '</p>';

    // --- *** ADDED: Clear cache before getting options for display *** ---
    wp_cache_delete( DSP_OPTION_NAME, 'options' );
    error_log("DSP Admin Settings: Cleared object cache for " . DSP_OPTION_NAME . " before rendering sources table.");
    // --- *** END ADDED *** ---

    // Fetch available parsers
    $available_parsers = [];
    $parser_dir = DSP_PLUGIN_DIR . 'includes/parsers/';
    if ( is_dir( $parser_dir ) && is_readable( $parser_dir ) ) {
        $parser_files = glob( $parser_dir . '*.php' );
        if ( $parser_files ) {
            foreach ( $parser_files as $parser_file_path ) {
                $base_name = basename( $parser_file_path, '.php' );
                if ( !empty($base_name) && strpos($base_name, '.') === false ) {
                    $available_parsers[] = $base_name;
                }
            }
        }
    }
    sort($available_parsers); // Sort alphabetically

    // Get current site configurations (should be fresh now)
    $current_options = get_option(DSP_OPTION_NAME, []);
    if (!is_array($current_options)) { $current_options = []; } // Ensure it's an array
    $sites = $current_options['sites'] ?? [];
    ?>
    <div id="dsp-source-manager-ui">
        <table class="wp-list-table widefat fixed striped dsp-sources-table-responsive" id="dsp-sources-table">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-source-name" style="width: 20%;"><?php esc_html_e( 'Source Name', 'deal-scraper-plugin' ); ?></th>
                    <th scope="col" class="manage-column column-source-url" style="width: 25%;"><?php esc_html_e( 'URL', 'deal-scraper-plugin' ); ?></th>
                    <th scope="col" class="manage-column column-source-parser" style="width: 15%;"><?php esc_html_e( 'Parser File', 'deal-scraper-plugin' ); ?></th>
                    <th scope="col" class="manage-column column-source-enabled" style="width: 8%;"><?php esc_html_e( 'Enabled', 'deal-scraper-plugin' ); ?></th>
                    <th scope="col" class="manage-column column-source-status" style="width: 17%;"><?php esc_html_e( 'Last Status', 'deal-scraper-plugin' ); ?></th>
                    <th scope="col" class="manage-column column-source-actions" style="width: 15%;"><?php esc_html_e( 'Actions', 'deal-scraper-plugin' ); ?></th>
                </tr>
            </thead>
            <tbody id="dsp-sources-list">
                <?php if ( ! empty( $sites ) && is_array($sites) ) : ?>
                    <?php foreach ( $sites as $index => $site_data ) : ?>
                        <?php
                        if (!is_array($site_data)) continue;
                        $site_name = $site_data['name'] ?? ''; $site_url = $site_data['url'] ?? ''; $parser_file = $site_data['parser_file'] ?? ''; $is_enabled = isset($site_data['enabled']) ? (bool)$site_data['enabled'] : false; $last_status = $site_data['last_status'] ?? ''; $last_run_time = isset($site_data['last_run_time']) ? (int)$site_data['last_run_time'] : 0;
                        $status_class = ''; $status_display_text = $last_status;
                        // Determine status class based on text content
                        if (!empty($last_status)) {
                            $is_error = (stripos($last_status, 'error') !== false || stripos($last_status, 'fail') !== false);
                            $is_success = stripos($last_status, 'success') === 0;
                            if ($is_error) {
                                $status_class = 'dsp-status-error';
                                // Extract only the first part before potential details for display
                                $error_parts = explode(':', $last_status, 2);
                                $status_display_text = trim($error_parts[0]);
                            } elseif ($is_success) {
                                $status_class = 'dsp-status-success';
                            } else {
                                $status_class = 'dsp-status-warning'; // Treat other statuses as warning/neutral
                            }
                        }
                        ?>
                        <tr class="dsp-source-row" data-index="<?php echo esc_attr( $index ); ?>">
                            <td class="column-source-name" data-label="<?php esc_attr_e( 'Source Name', 'deal-scraper-plugin' ); ?>"><input type="text" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][{$index}][name]" ); ?>" value="<?php echo esc_attr( $site_name ); ?>" class="large-text dsp-source-input-name" required placeholder="<?php esc_attr_e( 'e.g., My Deal Site', 'deal-scraper-plugin' ); ?>"></td>
                            <td class="column-source-url" data-label="<?php esc_attr_e( 'URL', 'deal-scraper-plugin' ); ?>"><input type="url" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][{$index}][url]" ); ?>" value="<?php echo esc_url( $site_url ); ?>" class="large-text dsp-source-input-url" required placeholder="<?php esc_attr_e( 'https://...', 'deal-scraper-plugin' ); ?>"></td>
                            <td class="column-source-parser" data-label="<?php esc_attr_e( 'Parser File', 'deal-scraper-plugin' ); ?>">
                                <select name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][{$index}][parser_file]" ); ?>" class="dsp-source-input-parser">
                                    <?php echo '<option value="">' . esc_html__( '-- Select Parser --', 'deal-scraper-plugin' ) . '</option>'; if ( ! empty( $available_parsers ) ) { foreach ( $available_parsers as $parser_name ) { echo '<option value="' . esc_attr( $parser_name ) . '" ' . selected( $parser_file, $parser_name, false ) . '>' . esc_html( $parser_name . '.php' ) . '</option>'; } } if ( ! empty( $parser_file ) && ! in_array( $parser_file, $available_parsers, true ) ) { echo '<option value="' . esc_attr( $parser_file ) . '" selected>' . esc_html( $parser_file . '.php' ) . ' (' . esc_html__( 'Not Found', 'deal-scraper-plugin' ) . ')</option>'; } ?>
                                </select>
                                <span class="spinner dsp-input-spinner"></span> <span class="dsp-input-save-status"></span>
                            </td>
                            <td class="column-source-enabled" data-label="<?php esc_attr_e( 'Enabled', 'deal-scraper-plugin' ); ?>">
                                <input type="hidden" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][{$index}][enabled]" ); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][{$index}][enabled]" ); ?>" value="1" class="dsp-source-input-enabled" <?php checked( $is_enabled, true ); ?>>
                                <span class="spinner dsp-input-spinner"></span> <span class="dsp-input-save-status"></span>
                            </td>
                            <td class="column-source-status" data-label="<?php esc_attr_e( 'Last Status', 'deal-scraper-plugin' ); ?>">
                                <?php if ( $last_run_time > 0 ) : ?>
                                    <span class="dsp-status-text <?php echo esc_attr($status_class); ?>" title="<?php echo esc_attr($last_status); // Full status in title ?>">
                                        <?php echo wp_kses_post( $status_display_text ); // Display potentially shortened status ?>
                                    </span><br>
                                    <small class="dsp-status-time" title="<?php echo esc_attr( date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_run_time, true ) . ' (' . wp_timezone_string() . ')' ); ?>">
                                        <?php
                                            // Use current_time('timestamp', true) for accurate comparison with stored UTC timestamp
                                            printf( esc_html__( '%s ago', 'deal-scraper-plugin' ), esc_html( human_time_diff( $last_run_time, current_time( 'timestamp', true ) ) ) );
                                        ?>
                                    </small>
                                <?php else : ?>
                                    <span class="dsp-status-text dsp-status-never"><?php esc_html_e('Never run', 'deal-scraper-plugin'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="column-source-actions" data-label="<?php esc_attr_e( 'Actions', 'deal-scraper-plugin' ); ?>">
                                <button type="button" class="button button-secondary dsp-test-source-button" title="<?php esc_attr_e( 'Test fetching and parsing this source', 'deal-scraper-plugin'); ?>"><?php esc_html_e( 'Test', 'deal-scraper-plugin' ); ?></button>
                                <button type="button" class="button button-link-delete dsp-delete-source-button" style="margin-left: 5px;"><?php esc_html_e( 'Delete', 'deal-scraper-plugin' ); ?></button>
                                <span class="spinner dsp-test-source-spinner"></span> <div class="dsp-test-source-result"></div>
                                <span class="spinner dsp-delete-spinner"></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr class="dsp-no-sources-row"><td colspan="6"><?php esc_html_e( 'No sources configured yet. Click "Add Source" below.', 'deal-scraper-plugin' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="6"><button type="button" class="button button-secondary" id="dsp-add-source-button"><?php esc_html_e( '+ Add Source', 'deal-scraper-plugin' ); ?></button></td></tr>
            </tfoot>
        </table>
        <?php // Template Row for adding new sources via JS ?>
        <template id="dsp-source-row-template">
            <tr class="dsp-source-row" data-index="__INDEX__">
                <td class="column-source-name" data-label="<?php esc_attr_e( 'Source Name', 'deal-scraper-plugin' ); ?>"><input type="text" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][__INDEX__][name]" ); ?>" value="" class="large-text dsp-source-input-name" required placeholder="<?php esc_attr_e( 'e.g., My Deal Site', 'deal-scraper-plugin' ); ?>"></td>
                <td class="column-source-url" data-label="<?php esc_attr_e( 'URL', 'deal-scraper-plugin' ); ?>"><input type="url" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][__INDEX__][url]" ); ?>" value="" class="large-text dsp-source-input-url" required placeholder="<?php esc_attr_e( 'https://...', 'deal-scraper-plugin' ); ?>"></td>
                <td class="column-source-parser" data-label="<?php esc_attr_e( 'Parser File', 'deal-scraper-plugin' ); ?>">
                    <select name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][__INDEX__][parser_file]" ); ?>" class="dsp-source-input-parser">
                        <?php echo '<option value="">' . esc_html__( '-- Select Parser --', 'deal-scraper-plugin' ) . '</option>'; if ( ! empty( $available_parsers ) ) { foreach ( $available_parsers as $parser_name ) { echo '<option value="' . esc_attr( $parser_name ) . '">' . esc_html( $parser_name . '.php' ) . '</option>'; } } ?>
                    </select>
                    <span class="spinner dsp-input-spinner"></span><span class="dsp-input-save-status"></span>
                </td>
                <td class="column-source-enabled" data-label="<?php esc_attr_e( 'Enabled', 'deal-scraper-plugin' ); ?>">
                    <input type="hidden" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][__INDEX__][enabled]" ); ?>" value="0">
                    <input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][__INDEX__][enabled]" ); ?>" value="1" class="dsp-source-input-enabled" checked>
                    <span class="spinner dsp-input-spinner"></span><span class="dsp-input-save-status"></span>
                </td>
                <td class="column-source-status" data-label="<?php esc_attr_e( 'Last Status', 'deal-scraper-plugin' ); ?>"><span class="dsp-status-text dsp-status-never"><?php esc_html_e('Not yet run', 'deal-scraper-plugin'); ?></span></td>
                <td class="column-source-actions" data-label="<?php esc_attr_e( 'Actions', 'deal-scraper-plugin' ); ?>">
                    <button type="button" class="button button-secondary dsp-test-source-button" title="<?php esc_attr_e( 'Test fetching and parsing this source', 'deal-scraper-plugin'); ?>" disabled><?php esc_html_e( 'Test', 'deal-scraper-plugin' ); ?></button>
                    <button type="button" class="button button-link-delete dsp-delete-source-button" style="margin-left: 5px;"><?php esc_html_e( 'Delete', 'deal-scraper-plugin' ); ?></button>
                    <span class="spinner dsp-test-source-spinner"></span><div class="dsp-test-source-result"></div>
                    <span class="spinner dsp-delete-spinner"></span>
                </td>
            </tr>
        </template>
    </div>
    <?php
}


/** Callback for Import/Export Section */
function dsp_import_export_section_callback() { echo '<hr style="margin: 20px 0;">'; echo '<p>' . esc_html__( 'Export or import your scraping source configurations.', 'deal-scraper-plugin' ) . '</p>'; }
/** Renders the Export button */
function dsp_render_export_sources_field() { $export_url = add_query_arg( [ 'action' => 'dsp_export_sources', '_wpnonce' => wp_create_nonce( 'dsp_export_sources_nonce' ) ], admin_url( 'admin.php' ) ); ?> <a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary"> <?php esc_html_e( 'Export Sources & Parsers (ZIP)', 'deal-scraper-plugin' ); ?> </a> <p class="description"><?php esc_html_e( 'Click this button to download a ZIP archive containing a `sources.json` file and the corresponding PHP parser files from `includes/parsers/`.', 'deal-scraper-plugin' ); ?></p> <?php if (!class_exists('ZipArchive')) { echo '<p style="color: red;"><strong>' . esc_html__('Warning:', 'deal-scraper-plugin') . '</strong> ' . esc_html__('The ZipArchive PHP extension is not enabled on your server. Export will likely fail.', 'deal-scraper-plugin') . '</p>'; } ?> <?php }
/** Renders the Import file input field and mode checkbox */
function dsp_render_import_sources_field() { ?> <input type="file" name="dsp_import_file" id="dsp_import_file" accept=".json,.zip"> <br> <label style="margin-top: 10px; display: inline-block;"> <input type="checkbox" name="dsp_import_mode" value="merge" id="dsp_import_mode"> <?php esc_html_e( 'Merge with existing sources', 'deal-scraper-plugin' ); ?> </label> <p class="description"> <?php esc_html_e( 'Upload a `.json` file (sources only) or a `.zip` archive (containing `sources.json` and `parsers/` folder) previously exported from this plugin.', 'deal-scraper-plugin' ); ?> <br> <strong><?php esc_html_e( 'Import Mode:', 'deal-scraper-plugin' ); ?></strong> <?php esc_html_e( 'If "Merge" is checked, only sources not already present (by name) will be added. If unchecked (default), importing will overwrite ALL existing sources listed above.', 'deal-scraper-plugin' ); ?> <br> <strong><?php esc_html_e( 'Important (Zip Import):', 'deal-scraper-plugin' ); ?></strong> <?php esc_html_e( 'If importing from a `.zip` file, you MUST manually upload the contents of the `parsers` folder within the zip to your plugin\'s `includes/parsers/` directory via FTP/SFTP/File Manager AFTER saving settings.', 'deal-scraper-plugin' ); ?> </p> <?php if (!class_exists('ZipArchive')) { echo '<p style="color: red;"><strong>' . esc_html__('Note:', 'deal-scraper-plugin') . '</strong> ' . esc_html__('The ZipArchive PHP extension is not enabled. You can only import `.json` files directly.', 'deal-scraper-plugin') . '</p>'; } ?> <?php }

// --- Other Callbacks (Email, Frontend, Purge) ---
function dsp_email_settings_section_callback() { echo '<hr style="margin: 20px 0;">'; echo '<p>' . esc_html__( 'Configure settings for the new deals email notification.', 'deal-scraper-plugin' ) . '</p>'; }
function dsp_render_email_enabled_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $value = isset( $options['email_enabled'] ) ? (bool) $options['email_enabled'] : $defaults['email_enabled']; ?><label><input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_enabled]" value="1" <?php checked( $value, true ); ?> /> <?php esc_html_e( 'Enable sending email digests of new deals.', 'deal-scraper-plugin' ); ?></label><?php }
function dsp_render_email_frequency_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $current_frequency = isset( $options['email_frequency'] ) ? $options['email_frequency'] : $defaults['email_frequency']; $frequencies = ['weekly'=> __( 'Weekly', 'deal-scraper-plugin' ), 'biweekly'=> __( 'Every 15 Days', 'deal-scraper-plugin' ),]; ?> <select name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_frequency]"> <?php foreach ( $frequencies as $value => $label ) : ?> <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_frequency, $value ); ?>><?php echo esc_html( $label ); ?></option> <?php endforeach; ?> </select> <p class="description"><?php esc_html_e( 'Select how often the automatic email digest should be sent (checked daily).', 'deal-scraper-plugin' ); ?></p> <?php }
function dsp_render_email_recipients_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $recipients_array = isset( $options['email_recipients'] ) && is_array($options['email_recipients']) ? $options['email_recipients'] : $defaults['email_recipients']; $value = implode( "\n", $recipients_array ); ?> <textarea name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_recipients]" class="large-text" rows="5" placeholder="<?php esc_attr_e( 'Enter email addresses, one per line.', 'deal-scraper-plugin' ); ?>"><?php echo esc_textarea( $value ); ?></textarea> <p class="description"><?php esc_html_e( 'Enter email addresses (one per line) to send notifications to.', 'deal-scraper-plugin' ); ?></p> <?php }
function dsp_render_manual_email_send_button_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $recipients = isset( $options['email_recipients'] ) && is_array( $options['email_recipients'] ) ? $options['email_recipients'] : $defaults['email_recipients']; $can_send = ! empty( $recipients ); $button_text = __( 'Send Now', 'deal-scraper-plugin' ); ?> <button type="button" id="dsp-send-manual-email-button" class="button" <?php disabled( ! $can_send ); ?>> <?php echo esc_html( $button_text ); ?> </button> <span id="dsp-manual-email-spinner" class="spinner" style="float: none; visibility: hidden; margin-left: 5px; vertical-align: middle;"></span> <p class="description"> <?php if ( $can_send ) { printf( esc_html( _n( 'Manually trigger sending the email digest (containing the 10 most recently seen deals) to the %d configured recipient.', 'Manually trigger sending the email digest (containing the 10 most recently seen deals) to the %d configured recipients.', count( $recipients ), 'deal-scraper-plugin' ) ), count( $recipients ) ); } else { esc_html_e( 'You must enter and save at least one recipient email address above before you can send.', 'deal-scraper-plugin' ); } ?> <br><em><?php esc_html_e( 'Note: Ensure your WordPress site is configured to send emails correctly.', 'deal-scraper-plugin' ); ?></em> </p> <div id="dsp-manual-email-status" style="margin-top: 10px;"></div> <?php }
function dsp_frontend_settings_section_callback() { echo '<hr style="margin: 20px 0;">'; echo '<p>' . esc_html__( 'Control the appearance and functionality of the shortcode display.', 'deal-scraper-plugin' ) . '</p>'; }
function dsp_render_show_debug_button_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $value = isset( $options['show_debug_button'] ) ? (bool) $options['show_debug_button'] : $defaults['show_debug_button']; ?> <label> <input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[show_debug_button]" value="1" <?php checked( $value, true ); ?> /> <?php esc_html_e( 'Show the "Show Debug Log" button on the frontend.', 'deal-scraper-plugin' ); ?> </label> <p class="description"><?php esc_html_e( 'When checked, the "Show Debug Log" button will be visible on the frontend only for Administrators.', 'deal-scraper-plugin' ); ?></p> <?php }
function dsp_render_refresh_button_access_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $current_value = isset( $options['refresh_button_access'] ) ? $options['refresh_button_access'] : $defaults['refresh_button_access']; $access_options = [ 'all'=> __( 'Show for all users', 'deal-scraper-plugin' ), 'logged_in'=> __( 'Show only for logged-in users', 'deal-scraper-plugin' ), 'admins'=> __( 'Show only for Administrators', 'deal-scraper-plugin' ), 'disabled'=> __( 'Disable for everyone', 'deal-scraper-plugin' ), ]; ?> <fieldset><legend class="screen-reader-text"><span><?php esc_html_e( 'Refresh Button Access', 'deal-scraper-plugin' ); ?></span></legend> <?php foreach ($access_options as $value => $label) : ?> <label style="display: block; margin-bottom: 5px;"> <input type="radio" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[refresh_button_access]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current_value, $value ); ?> /> <?php echo esc_html( $label ); ?> </label> <?php endforeach; ?> </fieldset> <p class="description"><?php esc_html_e( 'Control who can see and use the "Refresh Now" button.', 'deal-scraper-plugin' ); ?></p> <?php }
function dsp_render_dark_mode_default_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $current_value = isset( $options['dark_mode_default'] ) ? $options['dark_mode_default'] : $defaults['dark_mode_default']; $mode_options = [ 'light'=> __( 'Light Mode', 'deal-scraper-plugin' ), 'dark'=> __( 'Dark Mode', 'deal-scraper-plugin' ), 'auto'=> __( 'Auto (Day/Night based on time)', 'deal-scraper-plugin' ), ]; ?> <fieldset><legend class="screen-reader-text"><span><?php esc_html_e( 'Default Color Mode', 'deal-scraper-plugin' ); ?></span></legend> <?php foreach ($mode_options as $value => $label) : ?> <label style="display: block; margin-bottom: 5px;"> <input type="radio" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[dark_mode_default]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current_value, $value ); ?> /> <?php echo esc_html( $label ); ?> </label> <?php endforeach; ?> </fieldset> <p class="description"> <?php esc_html_e( 'Select the default color scheme for the deal display.', 'deal-scraper-plugin' ); ?> <br><em><?php esc_html_e( 'Note: "Auto" mode uses visitor\'s browser time (approx. 6 AM - 6 PM as day).', 'deal-scraper-plugin' ); ?></em> </p> <?php }
function dsp_data_management_section_callback() { echo '<hr style="margin: 20px 0;">'; echo '<p>' . esc_html__( 'Control how deal data is managed and how often checks are performed.', 'deal-scraper-plugin' ) . '</p>'; }
function dsp_render_fetch_frequency_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $current_frequency = isset( $options['fetch_frequency'] ) ? $options['fetch_frequency'] : $defaults['fetch_frequency']; $schedules = wp_get_schedules(); $allowed_frequencies = [ 'twicedaily' => isset($schedules['twicedaily']['display']) ? $schedules['twicedaily']['display'] : __('Twice Daily'), 'daily' => isset($schedules['daily']['display']) ? $schedules['daily']['display'] : __('Daily'), ]; ?> <select name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[fetch_frequency]"> <?php foreach ( $allowed_frequencies as $value => $label ) : ?> <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_frequency, $value ); ?>><?php echo esc_html( $label ); ?></option> <?php endforeach; ?> </select> <p class="description"> <?php esc_html_e( 'How often should the plugin check the next source for new deals? (Staggered check)', 'deal-scraper-plugin' ); ?> <br><em><?php esc_html_e( 'More frequent checks process sources faster but increase server load.', 'deal-scraper-plugin' ); ?></em> </p> <?php $timestamp = wp_next_scheduled( DSP_CRON_HOOK ); if ($timestamp) { $local_time = date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $timestamp, true ); echo '<p><small>' . sprintf(esc_html__('Next check scheduled for: %s (Site Time)', 'deal-scraper-plugin'), $local_time) . '</small></p>'; } else { echo '<p><small>' . esc_html__('Cron job not currently scheduled. Save settings to schedule.', 'deal-scraper-plugin') . '</small></p>'; } }
function dsp_render_purge_enabled_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $value = isset( $options['purge_enabled'] ) ? (bool) $options['purge_enabled'] : $defaults['purge_enabled']; ?> <label> <input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[purge_enabled]" value="1" <?php checked( $value, true ); ?> /> <?php esc_html_e( 'Automatically delete old deals from the database.', 'deal-scraper-plugin' ); ?> </label> <p class="description"><?php esc_html_e( 'When enabled, deals older than the specified age below will be deleted during the regular cron run.', 'deal-scraper-plugin' ); ?></p> <?php }
function dsp_render_purge_max_age_days_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $value = isset( $options['purge_max_age_days'] ) ? intval( $options['purge_max_age_days'] ) : $defaults['purge_max_age_days']; $value = max(1, $value); ?> <input type="number" min="1" step="1" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[purge_max_age_days]" value="<?php echo esc_attr( $value ); ?>" class="small-text" /> <?php esc_html_e( 'days', 'deal-scraper-plugin' ); ?> <p class="description"><?php esc_html_e( 'Enter the maximum age (in days) for deals to keep. Deals first seen before this many days ago will be deleted if auto-purge is enabled.', 'deal-scraper-plugin' ); ?></p> <?php }
function dsp_render_sideload_images_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $value = isset( $options['sideload_images'] ) ? (bool) $options['sideload_images'] : $defaults['sideload_images']; ?> <label> <input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[sideload_images]" value="1" <?php checked( $value, true ); ?> /> <?php esc_html_e( 'Download deal images to the WordPress Media Library.', 'deal-scraper-plugin' ); ?> </label> <p class="description"> <?php esc_html_e( 'If checked, the plugin will attempt to download images found by parsers and store them locally.', 'deal-scraper-plugin' ); ?> <br><strong><?php esc_html_e( 'Note:', 'deal-scraper-plugin' ); ?></strong> <?php esc_html_e( 'This can consume significant disk space and may slow down scraping if source images are large or slow to download. Only enable if needed.', 'deal-scraper-plugin' ); ?> <br><em><?php esc_html_e( 'Images associated with purged deals will also be deleted from the Media Library if Auto-Purge is enabled.', 'deal-scraper-plugin' ); ?></em> </p> <?php if ( ! function_exists( 'media_sideload_image' ) ) { echo '<p style="color: orange;"><strong>' . esc_html__( 'Recommendation:', 'deal-scraper-plugin' ) . '</strong> ' . esc_html__( 'The core WordPress function `media_sideload_image` requires `wp-admin/includes/media.php`, `wp-admin/includes/file.php`, and `wp-admin/includes/image.php`. Ensure these can be loaded by the cron job for this feature to work reliably.', 'deal-scraper-plugin' ) . '</p>'; } }

/** Sanitization Function - Includes Import Merge and Sideload Image */
function dsp_sanitize_settings( $input ) { $old_options = get_option( DSP_OPTION_NAME, [] ); if ( ! is_array( $old_options ) ) { $old_options = []; } $output = $old_options; $defaults = dsp_get_default_config(); $import_processed = false; $sources_changed = false; if ( isset( $_FILES['dsp_import_file'] ) && !empty( $_FILES['dsp_import_file']['tmp_name'] ) && $_FILES['dsp_import_file']['error'] === UPLOAD_ERR_OK ) { if (!current_user_can('manage_options')) { add_settings_error('dsp_import_file', 'dsp_import_permission_error', __('Permission denied for import.', 'deal-scraper-plugin'), 'error'); } else { $file = $_FILES['dsp_import_file']; $filename = $file['name']; $tmp_name = $file['tmp_name']; $file_type = $file['type']; $file_ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ); $allowed_types = ['application/json', 'application/zip', 'application/octet-stream']; $allowed_exts = ['json', 'zip']; if ( ! in_array( $file_ext, $allowed_exts ) || ! in_array( $file_type, $allowed_types ) ) { add_settings_error('dsp_import_file', 'dsp_import_type_error', __('Invalid file type. Please upload a .json or .zip file.', 'deal-scraper-plugin'), 'error'); } elseif ( $file['size'] > 5 * MB_IN_BYTES ) { add_settings_error('dsp_import_file', 'dsp_import_size_error', __('File is too large (max 5MB).', 'deal-scraper-plugin'), 'error'); } else { $json_content = false; $import_source_type = 'json'; if ($file_ext === 'zip') { $import_source_type = 'zip'; if ( ! class_exists( 'ZipArchive' ) ) { add_settings_error('dsp_import_file', 'dsp_import_zip_error', __('ZipArchive PHP extension not available. Cannot process .zip files.', 'deal-scraper-plugin'), 'error'); } else { $zip = new ZipArchive(); $res = $zip->open($tmp_name); if ($res === true) { $json_index = $zip->locateName('sources.json', ZipArchive::FL_NOCASE); if ($json_index !== false) { $json_content = $zip->getFromIndex($json_index); } else { add_settings_error('dsp_import_file', 'dsp_import_zip_content_error', __('Could not find `sources.json` inside the Zip archive.', 'deal-scraper-plugin'), 'error'); } $zip->close(); } else { add_settings_error('dsp_import_file', 'dsp_import_zip_open_error', __('Could not open the Zip archive.', 'deal-scraper-plugin'), 'error'); } } } else { $json_content = file_get_contents( $tmp_name ); if ( $json_content === false ) { add_settings_error('dsp_import_file', 'dsp_import_read_error', __('Could not read uploaded JSON file.', 'deal-scraper-plugin'), 'error'); } } if ($json_content !== false) { $imported_sites = json_decode( $json_content, true ); if ( $imported_sites === null && json_last_error() !== JSON_ERROR_NONE ) { add_settings_error('dsp_import_file', 'dsp_import_json_error', __('Invalid JSON format: ', 'deal-scraper-plugin') . json_last_error_msg(), 'error'); } elseif ( !is_array($imported_sites) ) { add_settings_error('dsp_import_file', 'dsp_import_format_error', __('Invalid format: Imported data is not an array of sources.', 'deal-scraper-plugin'), 'error'); } else { $validated_imported_sites = []; $has_invalid_entry = false; $parser_errors = []; $available_parsers = []; $parser_dir = DSP_PLUGIN_DIR . 'includes/parsers/'; if ( is_dir( $parser_dir ) && is_readable( $parser_dir ) ) { $parser_files = glob( $parser_dir . '*.php' ); if ( $parser_files ) { foreach ( $parser_files as $parser_file_path ) { $base_name = basename( $parser_file_path, '.php' ); if ( !empty($base_name) && strpos($base_name, '.') === false ) { $available_parsers[] = $base_name; } } } } foreach ($imported_sites as $index => $site_data) { $is_entry_valid = true; $entry_error = ''; if ( !is_array( $site_data ) || empty($site_data['name']) || !is_string($site_data['name']) || empty($site_data['url']) || !is_string($site_data['url']) || filter_var($site_data['url'], FILTER_VALIDATE_URL) === false || empty($site_data['parser_file']) || !is_string($site_data['parser_file']) ) { $is_entry_valid = false; $entry_error = "Missing/invalid basic fields"; } elseif ($import_source_type === 'json' && !in_array($site_data['parser_file'], $available_parsers, true) ) { $is_entry_valid = false; $entry_error = "Parser file '{$site_data['parser_file']}.php' not found locally"; $parser_errors[] = $site_data['parser_file']; } if (!$is_entry_valid) { $has_invalid_entry = true; error_log("DSP Import Warning: Skipping invalid site entry at index {$index} ({$entry_error}): " . print_r($site_data, true)); continue; } $validated_imported_sites[] = [ 'name' => sanitize_text_field( $site_data['name'] ), 'url' => esc_url_raw( $site_data['url'] ), 'parser_file' => sanitize_key( $site_data['parser_file'] ), 'enabled' => isset($site_data['enabled']) ? (bool)$site_data['enabled'] : false, 'last_status' => '', 'last_run_time' => 0 ]; } if (!empty($validated_imported_sites)) { $import_mode = isset( $_POST['dsp_import_mode'] ) ? sanitize_key( $_POST['dsp_import_mode'] ) : 'overwrite'; if ( $import_mode === 'merge' ) { $current_sources = $output['sites'] ?? []; $current_source_names = array_map('strtolower', array_column($current_sources, 'name')); $added_count = 0; $skipped_count = 0; foreach ($validated_imported_sites as $imported_site) { if (!in_array(strtolower($imported_site['name']), $current_source_names, true)) { $current_sources[] = $imported_site; $added_count++; } else { $skipped_count++; } } $output['sites'] = $current_sources; $sources_changed = ($added_count > 0); $merge_msg = sprintf( _n( 'Merge complete. Added %d new source.', 'Merge complete. Added %d new sources.', $added_count, 'deal-scraper-plugin' ), $added_count ); if ($skipped_count > 0) { $merge_msg .= ' ' . sprintf( _n( 'Skipped %d duplicate source (by name).', 'Skipped %d duplicate sources (by name).', $skipped_count, 'deal-scraper-plugin' ), $skipped_count ); } if ($has_invalid_entry) { $merge_msg .= ' ' . __('Some invalid entries in the file were also skipped.', 'deal-scraper-plugin'); } if ($import_source_type === 'zip') { $merge_msg .= ' ' . __('Remember to manually upload parser files.', 'deal-scraper-plugin'); } add_settings_error('dsp_import_file', 'dsp_import_merged', $merge_msg, $has_invalid_entry || $skipped_count > 0 ? 'warning' : 'updated'); } else { $output['sites'] = $validated_imported_sites; $sources_changed = true; $overwrite_msg = sprintf( _n( 'Successfully imported and overwrote configuration with %d source.', 'Successfully imported and overwrote configuration with %d sources.', count($validated_imported_sites), 'deal-scraper-plugin' ), count($validated_imported_sites) ); if ($has_invalid_entry) { $overwrite_msg .= ' ' . __('Some invalid entries were skipped.', 'deal-scraper-plugin'); } if ($import_source_type === 'zip') { $overwrite_msg .= ' ' . __('Remember to manually upload parser files.', 'deal-scraper-plugin'); } add_settings_error('dsp_import_file', 'dsp_import_overwritten', $overwrite_msg, $has_invalid_entry ? 'warning' : 'updated'); } } elseif ($has_invalid_entry) { add_settings_error('dsp_import_file', 'dsp_import_all_invalid', __('Import failed. All entries in the file were invalid or unusable.', 'deal-scraper-plugin'), 'error'); } else { add_settings_error('dsp_import_file', 'dsp_import_empty', __('Import file was valid but contained no source entries.', 'deal-scraper-plugin'), 'warning'); } if (!empty($parser_errors)) { add_settings_error('dsp_import_file', 'dsp_import_parser_missing', __('Warning: The following required parser files were not found locally: ', 'deal-scraper-plugin') . implode(', ', array_unique($parser_errors)) . '.php', 'warning'); } $import_processed = true; } } } } } if (!$import_processed && isset($input['sites'])) { $current_sites = $old_options['sites'] ?? []; $current_sites_map = []; if (is_array($current_sites)) { foreach ($current_sites as $index => $site) { if (is_array($site) && isset($site['name'])) { $current_sites_map[strtolower($site['name'])] = $site; } } } $sanitized_sites = []; $original_indices = array_keys($input['sites']); $has_manual_error = false; if ( is_array( $input['sites'] ) ) { $available_parsers = []; $parser_dir = DSP_PLUGIN_DIR . 'includes/parsers/'; if ( is_dir( $parser_dir ) && is_readable( $parser_dir ) ) { $parser_files = glob( $parser_dir . '*.php' ); if ( $parser_files ) { foreach ( $parser_files as $parser_file_path ) { $base_name = basename( $parser_file_path, '.php' ); if ( !empty($base_name) && strpos($base_name, '.') === false ) { $available_parsers[] = $base_name; } } } } $submitted_names = []; foreach ( $original_indices as $index ) { if (!isset($input['sites'][$index]) || !is_array($input['sites'][$index])) continue; $site_data = $input['sites'][$index]; $name = isset($site_data['name']) ? sanitize_text_field( wp_unslash( $site_data['name'] ) ) : ''; $url = isset($site_data['url']) ? esc_url_raw( wp_unslash( $site_data['url'] ) ) : ''; $parser_file = isset($site_data['parser_file']) ? sanitize_key( wp_unslash( $site_data['parser_file'] ) ) : ''; $enabled = isset( $site_data['enabled'] ) && ( $site_data['enabled'] == '1' || $site_data['enabled'] === true ); if ( empty( $name ) || empty( $url ) || empty( $parser_file ) ) { add_settings_error('dsp_sites', 'dsp_missing_fields', sprintf(__('Missing required fields (Name, URL, Parser) for source index %s. Row skipped.', 'deal-scraper-plugin'), $index), 'warning'); $has_manual_error = true; continue; } if ( ! in_array( $parser_file, $available_parsers, true ) ) { add_settings_error('dsp_sites', 'dsp_invalid_parser_selection', sprintf(__('Invalid or non-existent parser file (`%s`) selected for source `%s`. Row skipped.', 'deal-scraper-plugin'), esc_html($parser_file.'.php'), esc_html($name)), 'warning'); $has_manual_error = true; continue; } if (in_array(strtolower($name), $submitted_names, true)) { add_settings_error('dsp_sites', 'dsp_duplicate_name', sprintf(__('Duplicate source name (`%s`) submitted. Please use unique names. Row skipped.', 'deal-scraper-plugin'), esc_html($name)), 'warning'); $has_manual_error = true; continue; } $submitted_names[] = strtolower($name); $existing_site_data = $current_sites_map[strtolower($name)] ?? null; $sanitized_site = [ 'name' => $name, 'url' => $url, 'parser_file' => $parser_file, 'enabled' => $enabled, 'last_status' => isset($existing_site_data['last_status']) ? $existing_site_data['last_status'] : '', 'last_run_time' => isset($existing_site_data['last_run_time']) ? (int)$existing_site_data['last_run_time'] : 0, ]; $sanitized_sites[] = $sanitized_site; } } else { $sanitized_sites = []; } if (wp_json_encode($sanitized_sites) !== wp_json_encode($old_options['sites'] ?? [])) { $sources_changed = true; } $output['sites'] = $sanitized_sites; } elseif (!$import_processed && !isset($input['sites'])) { if (!empty($old_options['sites'])) { $sources_changed = true; } $output['sites'] = []; } $output['email_enabled'] = ( isset( $input['email_enabled'] ) && $input['email_enabled'] == '1' ); $allowed_email_frequencies = ['weekly', 'biweekly']; if ( isset( $input['email_frequency'] ) && in_array( $input['email_frequency'], $allowed_email_frequencies, true ) ) { $output['email_frequency'] = $input['email_frequency']; } else { $output['email_frequency'] = $defaults['email_frequency']; } $valid_emails = []; $invalid_entries_found = false; if ( isset( $input['email_recipients'] ) && is_string($input['email_recipients']) ) { $emails_raw = preg_split( '/\r\n|\r|\n/', $input['email_recipients'] ); foreach ( $emails_raw as $email_raw ) { $email_trimmed = trim( $email_raw ); if ( empty( $email_trimmed ) ) continue; $sanitized_email = sanitize_email( $email_trimmed ); if ( is_email( $sanitized_email ) ) { $exists = false; foreach($valid_emails as $existing) { if(strcasecmp($existing, $sanitized_email) === 0) {$exists = true; break;} } if (!$exists) { $valid_emails[] = $sanitized_email; } } elseif ( !is_email($sanitized_email) ) { $invalid_entries_found = true; } } } elseif ( isset( $input['email_recipients'] ) && is_array($input['email_recipients']) ) { foreach ($input['email_recipients'] as $email_item) { if (is_string($email_item)) { $email_trimmed = trim($email_item); if (empty($email_trimmed)) continue; $sanitized_email = sanitize_email($email_trimmed); if (is_email($sanitized_email)) { $exists = false; foreach($valid_emails as $existing) { if(strcasecmp($existing, $sanitized_email) === 0) {$exists = true; break;} } if (!$exists) { $valid_emails[] = $sanitized_email; } } elseif (!is_email($sanitized_email)) { $invalid_entries_found = true; } } else { $invalid_entries_found = true; } } $valid_emails = array_values(array_unique($valid_emails)); } else { $valid_emails = $output['email_recipients'] ?? $defaults['email_recipients']; } $output['email_recipients'] = $valid_emails; if ($invalid_entries_found) { add_settings_error('dsp_email_recipients', 'dsp_invalid_email_entries', __('One or more invalid email addresses were provided and ignored.', 'deal-scraper-plugin'), 'warning'); } $output['show_debug_button'] = ( isset( $input['show_debug_button'] ) && $input['show_debug_button'] == '1' ); $allowed_refresh_access = ['all', 'logged_in', 'admins', 'disabled']; if ( isset( $input['refresh_button_access'] ) && in_array( $input['refresh_button_access'], $allowed_refresh_access, true ) ) { $output['refresh_button_access'] = $input['refresh_button_access']; } else { $output['refresh_button_access'] = $defaults['refresh_button_access']; } $allowed_dark_modes = ['light', 'dark', 'auto']; if ( isset( $input['dark_mode_default'] ) && in_array( $input['dark_mode_default'], $allowed_dark_modes, true ) ) { $output['dark_mode_default'] = $input['dark_mode_default']; } else { $output['dark_mode_default'] = $defaults['dark_mode_default']; } $output['purge_enabled'] = ( isset( $input['purge_enabled'] ) && $input['purge_enabled'] == '1' ); $current_purge_age = $output['purge_max_age_days'] ?? $defaults['purge_max_age_days']; if ( isset( $input['purge_max_age_days'] ) ) { $age = intval( $input['purge_max_age_days'] ); $output['purge_max_age_days'] = ( $age >= 1 ) ? $age : $defaults['purge_max_age_days']; } else { $output['purge_max_age_days'] = $current_purge_age; } $old_frequency = $old_options['fetch_frequency'] ?? $defaults['fetch_frequency']; $new_frequency = $defaults['fetch_frequency']; $allowed_fetch_frequencies = ['twicedaily', 'daily']; if ( isset( $input['fetch_frequency'] ) && in_array( $input['fetch_frequency'], $allowed_fetch_frequencies, true ) ) { $new_frequency = $input['fetch_frequency']; } else { if (in_array($old_frequency, $allowed_fetch_frequencies, true)) { $new_frequency = $old_frequency; if (isset($input['fetch_frequency'])) { add_settings_error('dsp_fetch_frequency', 'dsp_invalid_frequency', __('Invalid fetch frequency selected. Setting unchanged.', 'deal-scraper-plugin'), 'error'); } } else { $new_frequency = $defaults['fetch_frequency']; if (isset($input['fetch_frequency'])) { add_settings_error('dsp_fetch_frequency', 'dsp_invalid_frequency', __('Invalid fetch frequency selected. Reverted to default.', 'deal-scraper-plugin'), 'error'); } } } $output['fetch_frequency'] = $new_frequency; if ($new_frequency !== $old_frequency) { $timestamp = wp_next_scheduled( DSP_CRON_HOOK ); wp_clear_scheduled_hook( DSP_CRON_HOOK ); $next_run = $timestamp ? $timestamp : (time() + 60); wp_schedule_event( $next_run, $new_frequency, DSP_CRON_HOOK ); if ( ! wp_next_scheduled( DSP_CRON_HOOK ) ) { error_log("DSP Settings Save Error: Failed to reschedule fetch cron ({DSP_CRON_HOOK}) after clearing."); add_settings_error( 'dsp_fetch_frequency', 'dsp_cron_reschedule_fail', __('Error rescheduling the deal check cron job.', 'deal-scraper-plugin'), 'error' ); } else { error_log("DSP Settings Save: Rescheduled fetch cron ({DSP_CRON_HOOK}) to run '{$new_frequency}'."); $schedules = wp_get_schedules(); $display_name = isset($schedules[$new_frequency]['display']) ? $schedules[$new_frequency]['display'] : $new_frequency; add_settings_error( 'dsp_fetch_frequency', 'dsp_cron_rescheduled', sprintf(__('Fetch schedule updated to: %s', 'deal-scraper-plugin'), esc_html($display_name)), 'updated' ); } } $output['sideload_images'] = ( isset( $input['sideload_images'] ) && $input['sideload_images'] == '1' ); if ($sources_changed) { delete_transient( DSP_SOURCE_LIST_TRANSIENT ); error_log("DSP Settings Save: Sources list changed, cleared transient '" . DSP_SOURCE_LIST_TRANSIENT . "'."); } $final_output = wp_parse_args($output, $defaults); if (isset($old_options['unsubscribe_salt']) && !empty($old_options['unsubscribe_salt'])) { $final_output['unsubscribe_salt'] = $old_options['unsubscribe_salt']; } elseif (!isset($final_output['unsubscribe_salt']) || empty($final_output['unsubscribe_salt'])) { $final_output['unsubscribe_salt'] = wp_generate_password(64, true, true); error_log("DSP Sanitize: Unsubscribe salt was missing, regenerated."); } return array_intersect_key($final_output, $defaults); }

// --- Render Page ---
function dsp_render_settings_page() { ?> <div class="wrap"> <h1><?php esc_html_e( 'Deal Scraper Settings', 'deal-scraper-plugin' ); ?></h1> <?php settings_errors(); ?> <form action="options.php" method="post" id="dsp-settings-form" enctype="multipart/form-data"> <?php settings_fields( 'dsp_settings_group' ); do_settings_sections( 'deal_scraper_settings' ); submit_button( __( 'Save Settings', 'deal-scraper-plugin' ) ); ?> </form> </div> <?php }

// --- Fallback Config Function (Safety) ---
if (!function_exists('dsp_get_default_config')) { function dsp_get_default_config() { return [ 'sites' => [], 'email_enabled' => false, 'email_frequency' => 'weekly', 'email_recipients' => [], 'unsubscribe_salt' => '', 'show_debug_button' => true, 'refresh_button_access' => 'all', 'dark_mode_default' => 'light', 'purge_enabled' => false, 'purge_max_age_days' => 90, 'fetch_frequency' => 'daily', 'sideload_images' => false, ]; } }

?>