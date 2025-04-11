<?php
// File: admin/settings-page.php (v1.1.11 - Add Last Status Column & Preserve in Sanitize)

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Add settings page */
function dsp_add_admin_menu() { add_options_page( __( 'Deal Scraper Settings', 'deal-scraper-plugin' ), __( 'Deal Scraper', 'deal-scraper-plugin' ), 'manage_options', 'deal_scraper_settings', 'dsp_render_settings_page' ); }
add_action( 'admin_menu', 'dsp_add_admin_menu' );

/** Register settings */
function dsp_register_settings() {
    register_setting( 'dsp_settings_group', DSP_OPTION_NAME, 'dsp_sanitize_settings' );

    // Source Management Section
    add_settings_section( 'dsp_source_management_section', __( 'Manage Scraping Sources', 'deal-scraper-plugin' ), 'dsp_source_management_section_callback', 'deal_scraper_settings' );
    // Email Section
    add_settings_section('dsp_email_settings_section', __('Email Notifications', 'deal-scraper-plugin'), 'dsp_email_settings_section_callback', 'deal_scraper_settings');
    add_settings_field('dsp_email_enabled', __('Enable Notifications', 'deal-scraper-plugin'), 'dsp_render_email_enabled_field', 'deal_scraper_settings', 'dsp_email_settings_section');
    add_settings_field('dsp_email_frequency', __('Notification Frequency', 'deal-scraper-plugin'), 'dsp_render_email_frequency_field', 'deal_scraper_settings', 'dsp_email_settings_section');
    add_settings_field('dsp_email_recipients', __('Recipient Emails', 'deal-scraper-plugin'), 'dsp_render_email_recipients_field', 'deal_scraper_settings', 'dsp_email_settings_section');
    add_settings_field('dsp_manual_email_send_button', '', 'dsp_render_manual_email_send_button_field', 'deal_scraper_settings', 'dsp_email_settings_section');
    // Frontend Section
    add_settings_section('dsp_frontend_settings_section', __('Frontend Display Options', 'deal-scraper-plugin'), 'dsp_frontend_settings_section_callback', 'deal_scraper_settings');
    add_settings_field('dsp_show_debug_button', __('Debug Button', 'deal-scraper-plugin'), 'dsp_render_show_debug_button_field', 'deal_scraper_settings', 'dsp_frontend_settings_section');
    add_settings_field('dsp_refresh_button_access', __('Refresh Button Access', 'deal-scraper-plugin'), 'dsp_render_refresh_button_access_field', 'deal_scraper_settings', 'dsp_frontend_settings_section');
    add_settings_field('dsp_dark_mode_default', __('Default Color Mode', 'deal-scraper-plugin'), 'dsp_render_dark_mode_default_field', 'deal_scraper_settings', 'dsp_frontend_settings_section');
    // Data Management Section
    add_settings_section('dsp_data_management_section', __('Data Management', 'deal-scraper-plugin'), 'dsp_data_management_section_callback', 'deal_scraper_settings');
    add_settings_field('dsp_purge_enabled', __('Auto-Purge Old Deals', 'deal-scraper-plugin'), 'dsp_render_purge_enabled_field', 'deal_scraper_settings', 'dsp_data_management_section');
    add_settings_field('dsp_purge_max_age_days', __('Purge Deals Older Than', 'deal-scraper-plugin'), 'dsp_render_purge_max_age_days_field', 'deal_scraper_settings', 'dsp_data_management_section');
}
add_action( 'admin_init', 'dsp_register_settings' );

// --- Callbacks ---

/**
 * Callback for Source Management Section.
 * Renders the table UI for managing sources.
 */
function dsp_source_management_section_callback() {
    echo '<p>' . esc_html__( 'Add, edit, or remove websites to scrape for deals.', 'deal-scraper-plugin' ) . '</p>';
    echo '<p>' . sprintf( wp_kses_post( __( 'Select the corresponding Parser File. Ensure the file exists in `%s` and contains the function `parse_%s_php()`.', 'deal-scraper-plugin' ) ), esc_html( str_replace(ABSPATH, '', DSP_PLUGIN_DIR) . 'includes/parsers/' ) ,'<i><parser_file_name></i>' ) . '</p>';

    // Scan for available parser files
    $available_parsers = []; $parser_dir = DSP_PLUGIN_DIR . 'includes/parsers/'; if ( is_dir( $parser_dir ) && is_readable( $parser_dir ) ) { $parser_files = glob( $parser_dir . '*.php' ); if ( $parser_files ) { foreach ( $parser_files as $parser_file_path ) { $base_name = basename( $parser_file_path, '.php' ); if ( !empty($base_name) && strpos($base_name, '.') === false ) { $available_parsers[] = $base_name; } } } } sort($available_parsers);

    // Get saved sites (which *should* include last_status/last_run_time after cron runs)
    $current_options = get_option(DSP_OPTION_NAME, []);
    $sites = $current_options['sites'] ?? []; // Get sites directly from saved option

    ?>
    <div id="dsp-source-manager-ui">
        <?php // Note: Column widths adjusted ?>
        <table class="wp-list-table widefat fixed striped dsp-sources-table-responsive" id="dsp-sources-table">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-source-name" style="width: 20%;"><?php esc_html_e( 'Source Name', 'deal-scraper-plugin' ); ?></th>
                    <th scope="col" class="manage-column column-source-url" style="width: 25%;"><?php esc_html_e( 'URL', 'deal-scraper-plugin' ); ?></th>
                    <th scope="col" class="manage-column column-source-parser" style="width: 15%;"><?php esc_html_e( 'Parser File', 'deal-scraper-plugin' ); ?></th>
                    <th scope="col" class="manage-column column-source-enabled" style="width: 8%;"><?php esc_html_e( 'Enabled', 'deal-scraper-plugin' ); ?></th>
                    <?php // Status Column Header ?>
                    <th scope="col" class="manage-column column-source-status" style="width: 17%;"><?php esc_html_e( 'Last Status', 'deal-scraper-plugin' ); ?></th>
                    <th scope="col" class="manage-column column-source-actions" style="width: 15%;"><?php esc_html_e( 'Actions', 'deal-scraper-plugin' ); ?></th>
                </tr>
            </thead>
            <tbody id="dsp-sources-list">
                <?php if ( ! empty( $sites ) && is_array($sites) ) : ?>
                    <?php foreach ( $sites as $index => $site_data ) : ?>
                        <?php
                            if (!is_array($site_data)) continue; // Skip malformed entries
                            $site_name = $site_data['name'] ?? ''; $site_url = $site_data['url'] ?? ''; $parser_file = $site_data['parser_file'] ?? ''; $is_enabled = isset($site_data['enabled']) ? (bool)$site_data['enabled'] : false;
                            $last_status = $site_data['last_status'] ?? ''; $last_run_time = isset($site_data['last_run_time']) ? (int)$site_data['last_run_time'] : 0;
                            $status_class = ''; if (!empty($last_status)) { $status_class = strpos(strtolower($last_status), 'error') !== false || strpos(strtolower($last_status), 'fail') !== false ? 'dsp-status-error' : 'dsp-status-success'; }
                        ?>
                        <tr class="dsp-source-row" data-index="<?php echo esc_attr( $index ); ?>">
                            <td class="column-source-name" data-label="<?php esc_attr_e( 'Source Name', 'deal-scraper-plugin' ); ?>"><input type="text" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][{$index}][name]" ); ?>" value="<?php echo esc_attr( $site_name ); ?>" class="large-text dsp-source-input-name" required placeholder="<?php esc_attr_e( 'e.g., My Deal Site', 'deal-scraper-plugin' ); ?>"></td>
                            <td class="column-source-url" data-label="<?php esc_attr_e( 'URL', 'deal-scraper-plugin' ); ?>"><input type="url" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][{$index}][url]" ); ?>" value="<?php echo esc_url( $site_url ); ?>" class="large-text dsp-source-input-url" required placeholder="<?php esc_attr_e( 'https://...', 'deal-scraper-plugin' ); ?>"></td>
                            <td class="column-source-parser" data-label="<?php esc_attr_e( 'Parser File', 'deal-scraper-plugin' ); ?>"><select name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][{$index}][parser_file]" ); ?>" class="dsp-source-input-parser"><?php echo '<option value="">' . esc_html__( '-- Select Parser --', 'deal-scraper-plugin' ) . '</option>'; if ( ! empty( $available_parsers ) ) { foreach ( $available_parsers as $parser_name ) { echo '<option value="' . esc_attr( $parser_name ) . '" ' . selected( $parser_file, $parser_name, false ) . '>' . esc_html( $parser_name . '.php' ) . '</option>'; } } if ( ! empty( $parser_file ) && ! in_array( $parser_file, $available_parsers, true ) ) { echo '<option value="' . esc_attr( $parser_file ) . '" selected>' . esc_html( $parser_file . '.php' ) . ' (' . esc_html__( 'Not Found', 'deal-scraper-plugin' ) . ')</option>'; } ?></select></td>
                            <td class="column-source-enabled" data-label="<?php esc_attr_e( 'Enabled', 'deal-scraper-plugin' ); ?>"><input type="hidden" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][{$index}][enabled]" ); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][{$index}][enabled]" ); ?>" value="1" <?php checked( $is_enabled, true ); ?>></td>
                             <?php // Status Column Cell ?>
                            <td class="column-source-status" data-label="<?php esc_attr_e( 'Last Status', 'deal-scraper-plugin' ); ?>">
                                <?php if ( $last_run_time > 0 ) : ?>
                                    <span class="dsp-status-text <?php echo esc_attr($status_class); ?>" title="<?php echo esc_attr(date('Y-m-d H:i:s', $last_run_time)); // Show exact time on hover ?>">
                                        <?php echo wp_kses_post( $last_status ); // Use wp_kses_post to allow basic html like <code> or <em> ?>
                                    </span><br>
                                    <small class="dsp-status-time"><?php printf( esc_html__( '%s ago', 'deal-scraper-plugin' ), esc_html( human_time_diff( $last_run_time, current_time( 'timestamp' ) ) ) ); ?></small>
                                <?php else : ?>
                                    <span class="dsp-status-text dsp-status-never"><?php esc_html_e('Never run', 'deal-scraper-plugin'); ?></span>
                                <?php endif; ?>
                                <?php // Hidden fields removed - Sanitizer preserves status ?>
                            </td>
                            <td class="column-source-actions" data-label="<?php esc_attr_e( 'Actions', 'deal-scraper-plugin' ); ?>"><button type="button" class="button button-secondary dsp-test-source-button" title="<?php esc_attr_e( 'Test fetching and parsing this source', 'deal-scraper-plugin'); ?>"><?php esc_html_e( 'Test', 'deal-scraper-plugin' ); ?></button><button type="button" class="button button-link-delete dsp-delete-source-button" style="margin-left: 5px;"><?php esc_html_e( 'Delete', 'deal-scraper-plugin' ); ?></button><span class="dsp-test-source-spinner spinner"></span><div class="dsp-test-source-result"></div></td>
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

        <template id="dsp-source-row-template">
             <tr class="dsp-source-row" data-index="__INDEX__">
                 <td class="column-source-name" data-label="<?php esc_attr_e( 'Source Name', 'deal-scraper-plugin' ); ?>"><input type="text" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][__INDEX__][name]" ); ?>" value="" class="large-text dsp-source-input-name" required placeholder="<?php esc_attr_e( 'e.g., My Deal Site', 'deal-scraper-plugin' ); ?>"></td>
                 <td class="column-source-url" data-label="<?php esc_attr_e( 'URL', 'deal-scraper-plugin' ); ?>"><input type="url" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][__INDEX__][url]" ); ?>" value="" class="large-text dsp-source-input-url" required placeholder="<?php esc_attr_e( 'https://...', 'deal-scraper-plugin' ); ?>"></td>
                 <td class="column-source-parser" data-label="<?php esc_attr_e( 'Parser File', 'deal-scraper-plugin' ); ?>"><select name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][__INDEX__][parser_file]" ); ?>" class="dsp-source-input-parser"><?php echo '<option value="">' . esc_html__( '-- Select Parser --', 'deal-scraper-plugin' ) . '</option>'; if ( ! empty( $available_parsers ) ) { foreach ( $available_parsers as $parser_name ) { echo '<option value="' . esc_attr( $parser_name ) . '">' . esc_html( $parser_name . '.php' ) . '</option>'; } } ?></select></td>
                 <td class="column-source-enabled" data-label="<?php esc_attr_e( 'Enabled', 'deal-scraper-plugin' ); ?>"><input type="hidden" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][__INDEX__][enabled]" ); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME . "[sites][__INDEX__][enabled]" ); ?>" value="1" checked></td>
                 <?php // Status Column Cell (Template) ?>
                 <td class="column-source-status" data-label="<?php esc_attr_e( 'Last Status', 'deal-scraper-plugin' ); ?>"><span class="dsp-status-text dsp-status-never"><?php esc_html_e('Not yet run', 'deal-scraper-plugin'); ?></span></td>
                 <td class="column-source-actions" data-label="<?php esc_attr_e( 'Actions', 'deal-scraper-plugin' ); ?>"><button type="button" class="button button-secondary dsp-test-source-button" title="<?php esc_attr_e( 'Test fetching and parsing this source', 'deal-scraper-plugin'); ?>"><?php esc_html_e( 'Test', 'deal-scraper-plugin' ); ?></button><button type="button" class="button button-link-delete dsp-delete-source-button" style="margin-left: 5px;"><?php esc_html_e( 'Delete', 'deal-scraper-plugin' ); ?></button><span class="dsp-test-source-spinner spinner"></span><div class="dsp-test-source-result"></div></td>
             </tr>
        </template>
    </div>
    <?php
}

// --- Other Callbacks (Email, Frontend, Purge - Remain the same) ---
function dsp_email_settings_section_callback() { echo '<hr style="margin: 20px 0;"><p>' . esc_html__( 'Configure settings for the new deals email notification.', 'deal-scraper-plugin' ) . '</p>'; }
function dsp_render_email_enabled_field() { $options = get_option( DSP_OPTION_NAME ); $value = isset( $options['email_enabled'] ) ? (bool) $options['email_enabled'] : false; ?><label><input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_enabled]" value="1" <?php checked( $value, true ); ?> /> <?php esc_html_e( 'Enable sending email digests of new deals.', 'deal-scraper-plugin' ); ?></label><?php }
function dsp_render_email_frequency_field() { $options = get_option( DSP_OPTION_NAME ); $current_frequency = isset( $options['email_frequency'] ) ? $options['email_frequency'] : 'weekly'; $frequencies = ['weekly'=> __( 'Weekly', 'deal-scraper-plugin' ), 'biweekly'=> __( 'Every 15 Days', 'deal-scraper-plugin' ),]; ?><select name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_frequency]"><?php foreach ( $frequencies as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_frequency, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'Select how often the automatic email digest should be sent (checked daily).', 'deal-scraper-plugin' ); ?></p><?php }
function dsp_render_email_recipients_field() { $options = get_option( DSP_OPTION_NAME ); $recipients_array = isset( $options['email_recipients'] ) && is_array($options['email_recipients']) ? $options['email_recipients'] : []; $value = implode( "\n", $recipients_array ); ?><textarea name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_recipients]" class="large-text" rows="5" placeholder="<?php esc_attr_e( 'Enter email addresses, one per line.', 'deal-scraper-plugin' ); ?>"><?php echo esc_textarea( $value ); ?></textarea><p class="description"><?php esc_html_e( 'Enter email addresses (one per line) to send notifications to.', 'deal-scraper-plugin' ); ?></p><?php }
function dsp_render_manual_email_send_button_field() { $options = get_option( DSP_OPTION_NAME ); $recipients = isset( $options['email_recipients'] ) && is_array( $options['email_recipients'] ) ? $options['email_recipients'] : []; $can_send = ! empty( $recipients ); $button_text = __( 'Send Now', 'deal-scraper-plugin' ); ?><button type="button" id="dsp-send-manual-email-button" class="button" <?php disabled( ! $can_send ); ?>><?php echo esc_html( $button_text ); ?></button><span id="dsp-manual-email-spinner" class="spinner" style="float: none; visibility: hidden; margin-left: 5px; vertical-align: middle;"></span><p class="description"><?php if ( $can_send ) { printf( esc_html( _n( 'Manually trigger sending the email digest (containing the 10 most recently seen deals) to the %d configured recipient.', 'Manually trigger sending the email digest (containing the 10 most recently seen deals) to the %d configured recipients.', count( $recipients ), 'deal-scraper-plugin' ) ), count( $recipients ) ); } else { esc_html_e( 'You must enter and save at least one recipient email address above before you can send.', 'deal-scraper-plugin' ); } ?><br><em><?php esc_html_e( 'Note: Ensure your WordPress site is configured to send emails correctly.', 'deal-scraper-plugin' ); ?></em></p><div id="dsp-manual-email-status" style="margin-top: 10px;"><!-- Status messages --></div><?php }
function dsp_frontend_settings_section_callback() { echo '<hr style="margin: 20px 0;"><p>' . esc_html__( 'Control the appearance and functionality of the shortcode display.', 'deal-scraper-plugin' ) . '</p>'; }
function dsp_render_show_debug_button_field() { $options = get_option( DSP_OPTION_NAME ); $value = isset( $options['show_debug_button'] ) ? (bool) $options['show_debug_button'] : true; ?><label><input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[show_debug_button]" value="1" <?php checked( $value, true ); ?> /> <?php esc_html_e( 'Show the "Show Debug Log" button on the frontend.', 'deal-scraper-plugin' ); ?></label><p class="description"><?php esc_html_e( 'Uncheck to hide the debug log button for all users.', 'deal-scraper-plugin' ); ?></p><?php }
function dsp_render_refresh_button_access_field() { $options = get_option( DSP_OPTION_NAME ); $access_options = ['all'=> __( 'Show for all users', 'deal-scraper-plugin' ), 'logged_in'=> __( 'Show only for logged-in users', 'deal-scraper-plugin' ), 'admins'=> __( 'Show only for Administrators', 'deal-scraper-plugin' ), 'disabled'=> __( 'Disable for everyone', 'deal-scraper-plugin' ),]; $current_value = isset( $options['refresh_button_access'] ) ? $options['refresh_button_access'] : 'all'; ?><fieldset><legend class="screen-reader-text"><span><?php esc_html_e( 'Refresh Button Access', 'deal-scraper-plugin' ); ?></span></legend><?php foreach ($access_options as $value => $label) : ?><label style="display: block; margin-bottom: 5px;"><input type="radio" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[refresh_button_access]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current_value, $value ); ?> /> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></fieldset><p class="description"><?php esc_html_e( 'Control who can see and use the "Refresh Now" button.', 'deal-scraper-plugin' ); ?></p><?php }
function dsp_render_dark_mode_default_field() { $options = get_option( DSP_OPTION_NAME ); $mode_options = ['light'=> __( 'Light Mode', 'deal-scraper-plugin' ), 'dark'=> __( 'Dark Mode', 'deal-scraper-plugin' ), 'auto'=> __( 'Auto (Day/Night based on time)', 'deal-scraper-plugin' ),]; $current_value = isset( $options['dark_mode_default'] ) ? $options['dark_mode_default'] : 'light'; ?><fieldset><legend class="screen-reader-text"><span><?php esc_html_e( 'Default Color Mode', 'deal-scraper-plugin' ); ?></span></legend><?php foreach ($mode_options as $value => $label) : ?><label style="display: block; margin-bottom: 5px;"><input type="radio" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[dark_mode_default]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current_value, $value ); ?> /> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></fieldset><p class="description"><?php esc_html_e( 'Select the default color scheme for the deal display.', 'deal-scraper-plugin' ); ?><br><em><?php esc_html_e( 'Note: "Auto" mode uses visitor\'s browser time (approx. 6 AM - 6 PM as day).', 'deal-scraper-plugin' ); ?></em></p><?php }
function dsp_data_management_section_callback() { echo '<hr style="margin: 20px 0;"><p>' . esc_html__( 'Manage stored deal data to keep the database optimized.', 'deal-scraper-plugin' ) . '</p>'; }
function dsp_render_purge_enabled_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $value = isset( $options['purge_enabled'] ) ? (bool) $options['purge_enabled'] : ($defaults['purge_enabled'] ?? false); ?><label><input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[purge_enabled]" value="1" <?php checked( $value, true ); ?> /> <?php esc_html_e( 'Automatically delete old deals from the database.', 'deal-scraper-plugin' ); ?></label><p class="description"><?php esc_html_e( 'When enabled, deals older than the specified age below will be deleted during the regular cron run.', 'deal-scraper-plugin' ); ?></p><?php }
function dsp_render_purge_max_age_days_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $value = isset( $options['purge_max_age_days'] ) ? intval( $options['purge_max_age_days'] ) : ($defaults['purge_max_age_days'] ?? 90); $value = max(1, $value); ?><input type="number" min="1" step="1" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[purge_max_age_days]" value="<?php echo esc_attr( $value ); ?>" class="small-text" /> <?php esc_html_e( 'days', 'deal-scraper-plugin' ); ?><p class="description"><?php esc_html_e( 'Enter the maximum age (in days) for deals to keep. Deals first seen before this many days ago will be deleted if auto-purge is enabled.', 'deal-scraper-plugin' ); ?></p><?php }

// --- Sanitization Function (MODIFIED - Preserve site status fields Robustly) ---
function dsp_sanitize_settings( $input ) {
    // Start with the existing saved options to preserve non-submitted data
    $output = get_option( DSP_OPTION_NAME, [] );
    if ( ! is_array( $output ) ) { $output = []; }
    $defaults = dsp_get_default_config();

    // Get existing sites data to merge status back in
    $current_sites = $output['sites'] ?? [];
    // Map current sites by index for easier lookup
    $current_sites_map = [];
    if (is_array($current_sites)) {
        foreach ($current_sites as $index => $site) {
            $current_sites_map[$index] = $site;
        }
    }

    // Sanitize Scraping Sources
    $sanitized_sites = [];
    if ( isset( $input['sites'] ) && is_array( $input['sites'] ) ) {
        // Scan available parsers again for validation
        $available_parsers = []; $parser_dir = DSP_PLUGIN_DIR . 'includes/parsers/'; if ( is_dir( $parser_dir ) && is_readable( $parser_dir ) ) { $parser_files = glob( $parser_dir . '*.php' ); if ( $parser_files ) { foreach ( $parser_files as $parser_file_path ) { $base_name = basename( $parser_file_path, '.php' ); if ( !empty($base_name) && strpos($base_name, '.') === false ) { $available_parsers[] = $base_name; } } } }

        // Loop through *submitted* data using index
        // Use array_values to ensure we are working with numerical indices from the submitted form
        $submitted_sites = array_values($input['sites']);
        foreach ( $submitted_sites as $index => $site_data ) {
            if ( ! is_array( $site_data ) ) continue;

            // Sanitize submitted fields
            $name = isset($site_data['name']) ? sanitize_text_field( wp_unslash( $site_data['name'] ) ) : '';
            $url = isset($site_data['url']) ? esc_url_raw( wp_unslash( $site_data['url'] ) ) : '';
            $parser_file = isset($site_data['parser_file']) ? sanitize_key( wp_unslash( $site_data['parser_file'] ) ) : '';
            $enabled = isset( $site_data['enabled'] ) && ( $site_data['enabled'] == '1' || $site_data['enabled'] === true );

            // Validate parser selection
            if ( ! empty($parser_file) && ! in_array( $parser_file, $available_parsers, true ) ) {
                 add_settings_error('dsp_sites', 'dsp_invalid_parser_selection', sprintf(__('Invalid parser file (`%s`) selected for source `%s`. Row skipped.', 'deal-scraper-plugin'), esc_html($parser_file), esc_html($name)), 'warning');
                 continue; // Skip this row
            }

            // Keep only valid, sanitized data
            if ( ! empty( $name ) && ! empty( $url ) && ! empty( $parser_file ) ) {
                 // *** Find existing data based on submitted index (assuming JS didn't reorder heavily) ***
                 $existing_site_data = $current_sites_map[$index] ?? null;

                 $sanitized_site = [
                    'name' => $name, 'url' => $url, 'parser_file' => $parser_file, 'enabled' => $enabled,
                    // Get status/time from existing data for this index, or default if not found
                    'last_status' => isset($existing_site_data['last_status']) ? $existing_site_data['last_status'] : '',
                    'last_run_time' => isset($existing_site_data['last_run_time']) ? (int)$existing_site_data['last_run_time'] : 0,
                ];
                 // Add to the new array, letting PHP handle numeric indexing
                 $sanitized_sites[] = $sanitized_site;
            }
        }
    } else {
         // If 'sites' wasn't submitted at all (e.g., maybe cleared via JS?), respect that.
         // If you wanted to *preserve* sites if the whole section wasn't submitted, you'd use $current_sites here.
         // Let's assume if 'sites' is missing from input, it means they were all deleted.
         $sanitized_sites = [];
    }
    $output['sites'] = $sanitized_sites; // Use the newly built array


    // --- Sanitize Other Settings (Existing - No changes needed here) ---
    $output['email_enabled'] = ( isset( $input['email_enabled'] ) && $input['email_enabled'] == '1' ); $allowed_frequencies = ['weekly', 'biweekly']; if ( isset( $input['email_frequency'] ) && in_array( $input['email_frequency'], $allowed_frequencies, true ) ) { $output['email_frequency'] = $input['email_frequency']; } else { $output['email_frequency'] = $defaults['email_frequency']; } $valid_emails = []; $invalid_entries_found = false; if ( isset( $input['email_recipients'] ) && is_string($input['email_recipients']) ) { $emails_raw = preg_split( '/\r\n|\r|\n/', $input['email_recipients'] ); foreach ( $emails_raw as $email_raw ) { $email_trimmed = trim( $email_raw ); if ( empty( $email_trimmed ) ) continue; $sanitized_email = sanitize_email( $email_trimmed ); if ( is_email( $sanitized_email ) ) { $exists = false; foreach($valid_emails as $existing) { if(strcasecmp($existing, $sanitized_email) === 0) {$exists = true; break;} } if (!$exists) { $valid_emails[] = $sanitized_email; } } elseif ( !is_email($sanitized_email) ) { $invalid_entries_found = true; } } } elseif ( isset( $input['email_recipients'] ) && is_array($input['email_recipients']) ) { foreach ($input['email_recipients'] as $email_item) { if (is_string($email_item)) { $email_trimmed = trim($email_item); if (empty($email_trimmed)) continue; $sanitized_email = sanitize_email($email_trimmed); if (is_email($sanitized_email)) { $exists = false; foreach($valid_emails as $existing) { if(strcasecmp($existing, $sanitized_email) === 0) {$exists = true; break;} } if (!$exists) { $valid_emails[] = $sanitized_email; } } elseif (!is_email($sanitized_email)) { $invalid_entries_found = true; } } else { $invalid_entries_found = true; } } $valid_emails = array_values(array_unique($valid_emails)); } else { $valid_emails = $output['email_recipients'] ?? []; } $output['email_recipients'] = $valid_emails; if ($invalid_entries_found) { add_settings_error('dsp_email_recipients', 'dsp_invalid_email_entries', __('One or more invalid email addresses were provided and ignored.', 'deal-scraper-plugin'), 'warning'); }
    $output['show_debug_button'] = ( isset( $input['show_debug_button'] ) && $input['show_debug_button'] == '1' ); $allowed_refresh_access = ['all', 'logged_in', 'admins', 'disabled']; if ( isset( $input['refresh_button_access'] ) && in_array( $input['refresh_button_access'], $allowed_refresh_access, true ) ) { $output['refresh_button_access'] = $input['refresh_button_access']; } else { $output['refresh_button_access'] = $defaults['refresh_button_access']; } $allowed_dark_modes = ['light', 'dark', 'auto']; if ( isset( $input['dark_mode_default'] ) && in_array( $input['dark_mode_default'], $allowed_dark_modes, true ) ) { $output['dark_mode_default'] = $input['dark_mode_default']; } else { $output['dark_mode_default'] = $defaults['dark_mode_default']; }
    $output['purge_enabled'] = ( isset( $input['purge_enabled'] ) && $input['purge_enabled'] == '1' ); $current_purge_age = $output['purge_max_age_days'] ?? $defaults['purge_max_age_days']; if ( isset( $input['purge_max_age_days'] ) ) { $age = intval( $input['purge_max_age_days'] ); $output['purge_max_age_days'] = ( $age >= 1 ) ? $age : $defaults['purge_max_age_days']; } else { $output['purge_max_age_days'] = $current_purge_age; }

    // Final Merge and Filter
    $final_output = wp_parse_args($output, $defaults);
    // Preserve existing salt
    if (isset($output['unsubscribe_salt'])) { $final_output['unsubscribe_salt'] = $output['unsubscribe_salt']; }
    elseif (!isset($final_output['unsubscribe_salt']) || empty($final_output['unsubscribe_salt'])) { $final_output['unsubscribe_salt'] = wp_generate_password(64, true, true); error_log("DSP Sanitize: Unsubscribe salt was missing, regenerated."); }

    return array_intersect_key($final_output, $defaults);
 }

// --- Render Page ---
function dsp_render_settings_page() { ?><div class="wrap"><h1><?php esc_html_e( 'Deal Scraper Settings', 'deal-scraper-plugin' ); ?></h1><?php settings_errors(); ?><form action="options.php" method="post" id="dsp-settings-form"><?php settings_fields( 'dsp_settings_group' ); do_settings_sections( 'deal_scraper_settings' ); submit_button( __( 'Save Settings', 'deal-scraper-plugin' ) ); ?></form></div><?php }

// --- Fallback ---
if (!function_exists('dsp_get_default_config')) { function dsp_get_default_config() { return [ 'sites' => [], 'email_enabled' => false, 'email_frequency' => 'weekly', 'email_recipients' => [], 'show_debug_button' => true, 'refresh_button_access' => 'all', 'dark_mode_default' => 'light', 'purge_enabled' => false, 'purge_max_age_days' => 90, 'unsubscribe_salt' => '', ]; } }

?>