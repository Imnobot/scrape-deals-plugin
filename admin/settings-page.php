<?php
// File: admin/settings-page.php (v1.3.4 - Correct Fix for Redeclare Error)

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/** Add settings page */
function dsp_add_admin_menu() { add_options_page( __( 'Deal Scraper Settings', 'deal-scraper-plugin' ), __( 'Deal Scraper', 'deal-scraper-plugin' ), 'manage_options', 'deal_scraper_settings', 'dsp_render_settings_page' ); }
add_action( 'admin_menu', 'dsp_add_admin_menu' );

/** Register settings */
function dsp_register_settings() {
    register_setting( 'dsp_settings_group', DSP_OPTION_NAME, 'dsp_sanitize_settings' );

    // --- Sections ---
    add_settings_section( 'dsp_managed_sites_section', '', 'dsp_managed_sites_section_callback', 'deal_scraper_settings' );
    add_settings_section( 'dsp_email_settings_section', __( 'Email Notifications', 'deal-scraper-plugin' ), 'dsp_email_settings_section_callback', 'deal_scraper_settings' );
    add_settings_section( 'dsp_frontend_settings_section', __( 'Frontend Display Options', 'deal-scraper-plugin' ), 'dsp_frontend_settings_section_callback', 'deal_scraper_settings' );
    add_settings_section( 'dsp_data_management_section', __( 'Data Management', 'deal-scraper-plugin' ), 'dsp_data_management_section_callback', 'deal_scraper_settings' );

    // --- Fields ---
    // Register only fields rendered via Settings API (do_settings_fields)
    add_settings_field( 'dsp_email_enabled', __( 'Enable Email', 'deal-scraper-plugin' ), 'dsp_render_email_enabled_field', 'deal_scraper_settings', 'dsp_email_settings_section' );
    add_settings_field( 'dsp_email_frequency', __( 'Email Frequency', 'deal-scraper-plugin' ), 'dsp_render_email_frequency_field', 'deal_scraper_settings', 'dsp_email_settings_section' );
    add_settings_field( 'dsp_email_recipients', __( 'Recipient Emails', 'deal-scraper-plugin' ), 'dsp_render_email_recipients_field', 'deal_scraper_settings', 'dsp_email_settings_section' );
    add_settings_field( 'dsp_show_debug_button', __( 'Debug Button', 'deal-scraper-plugin' ), 'dsp_render_show_debug_button_field', 'deal_scraper_settings', 'dsp_frontend_settings_section' );
    add_settings_field( 'dsp_refresh_button_access', __( 'Refresh Button Access', 'deal-scraper-plugin' ), 'dsp_render_refresh_button_access_field', 'deal_scraper_settings', 'dsp_frontend_settings_section' );
    add_settings_field( 'dsp_dark_mode_default', __( 'Default Color Mode', 'deal-scraper-plugin' ), 'dsp_render_dark_mode_default_field', 'deal_scraper_settings', 'dsp_frontend_settings_section' );
    add_settings_field( 'dsp_purge_enabled', __( 'Auto-Purge Old Deals', 'deal-scraper-plugin' ), 'dsp_render_purge_enabled_field', 'deal_scraper_settings', 'dsp_data_management_section' );
    add_settings_field( 'dsp_purge_max_age_days', __( 'Purge Deals Older Than', 'deal-scraper-plugin' ), 'dsp_render_purge_max_age_days_field', 'deal_scraper_settings', 'dsp_data_management_section' );

    // *** DO NOT register dsp_clear_all_deals_action field here ***

}
add_action( 'admin_init', 'dsp_register_settings' );

// --- Enqueue Admin Assets ---
function dsp_enqueue_admin_assets($hook) {
    if ('settings_page_deal_scraper_settings' !== $hook) { return; }
    $plugin_version = '1.3.1'; // Use version where JS/CSS were created
    wp_enqueue_script( 'dsp-admin-script', DSP_PLUGIN_URL . 'admin/js/admin-settings.js', ['jquery'], $plugin_version, true );
    wp_localize_script('dsp-admin-script', 'dsp_admin_vars', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('dsp_admin_ajax_nonce'),
        'option_base_name' => DSP_OPTION_NAME . '[managed_sites]',
        'text' => [ /* ... text strings ... */
            'test' => __('Test', 'deal-scraper-plugin'),
            'save_first' => __('(Save first)', 'deal-scraper-plugin'),
            'remove_mark_title' => __('Mark for deletion on save', 'deal-scraper-plugin'),
            'remove_undo_title' => __('Undo deletion mark', 'deal-scraper-plugin'),
            'error_url_parser_missing' => __('Error: URL or Parser missing.', 'deal-scraper-plugin'),
            'error_unknown' => __('Unknown error occurred.', 'deal-scraper-plugin'),
            'error_ajax' => __('AJAX Error: Request failed.', 'deal-scraper-plugin'),
            'error_prefix' => __('Error:', 'deal-scraper-plugin'),
            'placeholder_name' => __('e.g., New Site', 'deal-scraper-plugin'),
            'placeholder_url' => __('https://...', 'deal-scraper-plugin'),
            'placeholder_parser' => __('e.g., parse_newsite_php', 'deal-scraper-plugin'),
            'clear_confirm' => __('Are you absolutely sure you want to delete ALL stored deals? This cannot be undone.', 'deal-scraper-plugin'),
            'clearing' => __('Clearing...', 'deal-scraper-plugin'),
            'clear_success' => __('All deals cleared successfully.', 'deal-scraper-plugin'),
            'clear_error' => __('Error clearing deals.', 'deal-scraper-plugin'),
        ]
    ]);
    wp_enqueue_style( 'dsp-admin-style', DSP_PLUGIN_URL . 'admin/css/admin-style.css', [], $plugin_version );
}
add_action( 'admin_enqueue_scripts', 'dsp_enqueue_admin_assets' );

// --- Callback Functions ---

// -- Managed Sites Section Callback --
function dsp_managed_sites_section_callback() { echo '<p>' . esc_html__( 'Add, edit, or remove the website sources the plugin will attempt to scrape.', 'deal-scraper-plugin' ) . '</p>'; echo '<p>' . esc_html__( 'The "Parser Function" must correspond to a valid PHP function name (usually starting with `parse_`). Changes require saving before testing.', 'deal-scraper-plugin' ) . '</p>';}

// -- Other Setting Callbacks --
function dsp_email_settings_section_callback() { echo '<p>' . esc_html__( 'Configure settings for the new deals email notification.', 'deal-scraper-plugin' ) . '</p>'; }
function dsp_render_email_enabled_field() { $options = get_option( DSP_OPTION_NAME ); $value = isset( $options['email_enabled'] ) ? (bool) $options['email_enabled'] : false; ?><label><input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_enabled]" value="1" <?php checked( $value, true ); ?> /> <?php esc_html_e( 'Send an email digest of new deals.', 'deal-scraper-plugin' ); ?></label><?php }
function dsp_render_email_frequency_field() { $options = get_option( DSP_OPTION_NAME ); $current_frequency = isset( $options['email_frequency'] ) ? $options['email_frequency'] : 'weekly'; $frequencies = ['weekly'=> __( 'Weekly', 'deal-scraper-plugin' ), 'biweekly'=> __( 'Every 15 Days', 'deal-scraper-plugin' ),]; ?><select name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_frequency]"><?php foreach ( $frequencies as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_frequency, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><p class="description"><?php esc_html_e( 'How often should the notification be sent?', 'deal-scraper-plugin' ); ?></p><?php }
function dsp_render_email_recipients_field() { $options = get_option( DSP_OPTION_NAME ); $recipients_array = isset( $options['email_recipients'] ) && is_array($options['email_recipients']) ? $options['email_recipients'] : []; $value = implode( "\n", $recipients_array ); ?><textarea name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_recipients]" class="large-text code" rows="5" placeholder="<?php esc_attr_e( 'Enter email addresses, one per line.', 'deal-scraper-plugin' ); ?>"><?php echo esc_textarea( $value ); ?></textarea><p class="description"><?php esc_html_e( 'Enter email addresses (one per line) to send notifications to.', 'deal-scraper-plugin' ); ?></p><?php }
function dsp_frontend_settings_section_callback() { echo '<hr style="margin: 20px 0;">'; echo '<p>' . esc_html__( 'Control the appearance and functionality of the shortcode display.', 'deal-scraper-plugin' ) . '</p>'; }
function dsp_render_show_debug_button_field() { $options = get_option( DSP_OPTION_NAME ); $value = isset( $options['show_debug_button'] ) ? (bool) $options['show_debug_button'] : true; ?><label><input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[show_debug_button]" value="1" <?php checked( $value, true ); ?> /> <?php esc_html_e( 'Show the "Show Debug Log" button on the frontend.', 'deal-scraper-plugin' ); ?></label><p class="description"><?php esc_html_e( 'Uncheck to hide the debug log button for all users.', 'deal-scraper-plugin' ); ?></p><?php }
function dsp_render_refresh_button_access_field() { $options = get_option( DSP_OPTION_NAME ); $access_options = ['all'=> __( 'Show for all users', 'deal-scraper-plugin' ), 'logged_in'=> __( 'Show only for logged-in users', 'deal-scraper-plugin' ), 'admins'=> __( 'Show only for Administrators', 'deal-scraper-plugin' ), 'disabled'=> __( 'Disable for everyone', 'deal-scraper-plugin' ),]; $current_value = isset( $options['refresh_button_access'] ) ? $options['refresh_button_access'] : 'all'; ?><fieldset><legend class="screen-reader-text"><span><?php esc_html_e( 'Refresh Button Access', 'deal-scraper-plugin' ); ?></span></legend><?php foreach ($access_options as $value => $label) : ?><label style="display: block; margin-bottom: 5px;"><input type="radio" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[refresh_button_access]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current_value, $value ); ?> /> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></fieldset><p class="description"><?php esc_html_e( 'Control who can see and use the "Refresh Now" button.', 'deal-scraper-plugin' ); ?></p><?php }
function dsp_render_dark_mode_default_field() { $options = get_option( DSP_OPTION_NAME ); $mode_options = ['light'=> __( 'Light Mode', 'deal-scraper-plugin' ), 'dark'=> __( 'Dark Mode', 'deal-scraper-plugin' ), 'auto'=> __( 'Auto (Day/Night based on time)', 'deal-scraper-plugin' ),]; $current_value = isset( $options['dark_mode_default'] ) ? $options['dark_mode_default'] : 'light'; ?><fieldset><legend class="screen-reader-text"><span><?php esc_html_e( 'Default Color Mode', 'deal-scraper-plugin' ); ?></span></legend><?php foreach ($mode_options as $value => $label) : ?><label style="display: block; margin-bottom: 5px;"><input type="radio" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[dark_mode_default]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current_value, $value ); ?> /> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></fieldset><p class="description"><?php esc_html_e( 'Select the default color scheme for the deal display.', 'deal-scraper-plugin' ); ?><br><em><?php esc_html_e( 'Note: "Auto" mode uses visitor\'s browser time (approx. 6 AM - 6 PM as day).', 'deal-scraper-plugin' ); ?></em></p><?php }
function dsp_data_management_section_callback() { echo '<hr style="margin: 20px 0;">'; echo '<p>' . esc_html__( 'Manage stored deal data to keep the database optimized.', 'deal-scraper-plugin' ) . '</p>'; }
function dsp_render_purge_enabled_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $value = isset( $options['purge_enabled'] ) ? (bool) $options['purge_enabled'] : ($defaults['purge_enabled'] ?? false); ?><label><input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[purge_enabled]" value="1" <?php checked( $value, true ); ?> /><?php esc_html_e( 'Automatically delete old deals from the database.', 'deal-scraper-plugin' ); ?></label><p class="description"><?php esc_html_e( 'When enabled, deals older than the specified age below will be deleted during the regular cron run.', 'deal-scraper-plugin' ); ?></p><?php }
function dsp_render_purge_max_age_days_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $value = isset( $options['purge_max_age_days'] ) ? intval( $options['purge_max_age_days'] ) : ($defaults['purge_max_age_days'] ?? 90); $value = max(1, $value); ?><input type="number" min="1" step="1" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[purge_max_age_days]" value="<?php echo esc_attr( $value ); ?>" class="small-text" /><?php esc_html_e( 'days', 'deal-scraper-plugin' ); ?><p class="description"><?php esc_html_e( 'Enter the maximum age (in days) for deals to keep. Deals first seen before this many days ago will be deleted if auto-purge is enabled.', 'deal-scraper-plugin' ); ?></p><?php }

/** Sanitize the settings array before saving. */
function dsp_sanitize_settings( $input ) { /* ... Same as before ... */ $existing_options = get_option( DSP_OPTION_NAME ); if ( !is_array($existing_options) ) { $existing_options = []; } $sanitized_input = []; $defaults = function_exists('dsp_get_default_config') ? dsp_get_default_config() : []; $sanitized_sites = []; if ( isset( $input['managed_sites'] ) && is_array( $input['managed_sites'] ) ) { foreach ( $input['managed_sites'] as $site_key => $site_data ) { if ( isset( $site_data['delete'] ) && $site_data['delete'] === '1' ) { continue; } $name = isset($site_data['name']) ? trim($site_data['name']) : ''; $url = isset($site_data['url']) ? trim($site_data['url']) : ''; $parser = isset($site_data['parser']) ? trim($site_data['parser']) : ''; if ( empty($name) || empty($url) || empty($parser) ) { add_settings_error('dsp_managed_sites', 'dsp_missing_site_data', __('Skipped saving a source due to missing Name, URL, or Parser Function.', 'deal-scraper-plugin'), 'warning'); continue; } $sanitized_site = []; $sanitized_site['name'] = sanitize_text_field($name); $sanitized_site['url'] = esc_url_raw( $url ); $sanitized_site['parser'] = preg_replace('/[^a-zA-Z0-9_]/', '', $parser); $sanitized_site['enabled'] = isset( $site_data['enabled'] ) && $site_data['enabled'] == '1'; if (filter_var($sanitized_site['url'], FILTER_VALIDATE_URL) === false) { add_settings_error('dsp_managed_sites', 'dsp_invalid_url', sprintf(__('Skipped saving source "%s" due to an invalid URL format.', 'deal-scraper-plugin'), esc_html($sanitized_site['name'])), 'warning'); continue; } if (strpos($sanitized_site['parser'], 'parse_') !== 0 || !ctype_alnum(str_replace('_', '', $sanitized_site['parser']))) { add_settings_error('dsp_managed_sites', 'dsp_invalid_parser', sprintf(__('Parser function name "%s" for source "%s" might be invalid (should typically start with "parse_" and contain letters/numbers/underscores). Please verify.', 'deal-scraper-plugin'), esc_html($sanitized_site['parser']), esc_html($sanitized_site['name'])), 'warning'); } $sanitized_sites[$site_key] = $sanitized_site; } } $sanitized_input['managed_sites'] = $sanitized_sites; $sanitized_input = array_merge($existing_options, $sanitized_input); if (isset($input['email_enabled'])) { $sanitized_input['email_enabled'] = ($input['email_enabled'] == '1'); } if (isset($input['email_frequency'])) { $allowed_frequencies = ['weekly', 'biweekly']; if (in_array($input['email_frequency'], $allowed_frequencies, true)) { $sanitized_input['email_frequency'] = $input['email_frequency']; } else { $sanitized_input['email_frequency'] = $defaults['email_frequency'] ?? 'weekly'; } } if (isset($input['email_recipients'])) { $valid_emails = []; $invalid_entries_found = false; if (is_string($input['email_recipients'])) { $emails_raw = preg_split('/\r\n|\r|\n/', $input['email_recipients']); foreach ($emails_raw as $email_raw) { $email_trimmed = trim($email_raw); if (empty($email_trimmed)) continue; $sanitized_email = sanitize_email($email_trimmed); if (is_email($sanitized_email)) { $exists = false; foreach($valid_emails as $existing) { if(strcasecmp($existing, $sanitized_email) === 0) {$exists = true; break;} } if (!$exists) { $valid_emails[] = $sanitized_email; } } elseif (!is_email($sanitized_email)) { $invalid_entries_found = true; } } } $sanitized_input['email_recipients'] = $valid_emails; if ($invalid_entries_found) { add_settings_error('dsp_email_recipients', 'dsp_invalid_email_entries', __('One or more invalid email addresses were provided and have been ignored.', 'deal-scraper-plugin'), 'warning'); } } if (isset($input['show_debug_button'])) { $sanitized_input['show_debug_button'] = ($input['show_debug_button'] == '1'); } if (isset($input['refresh_button_access'])) { $allowed_refresh_access = ['all', 'logged_in', 'admins', 'disabled']; if (in_array($input['refresh_button_access'], $allowed_refresh_access, true)) { $sanitized_input['refresh_button_access'] = $input['refresh_button_access']; } else { $sanitized_input['refresh_button_access'] = $defaults['refresh_button_access'] ?? 'all'; } } if (isset($input['dark_mode_default'])) { $allowed_dark_modes = ['light', 'dark', 'auto']; if (in_array($input['dark_mode_default'], $allowed_dark_modes, true)) { $sanitized_input['dark_mode_default'] = $input['dark_mode_default']; } else { $sanitized_input['dark_mode_default'] = $defaults['dark_mode_default'] ?? 'light'; } } if (isset($input['purge_enabled'])) { $sanitized_input['purge_enabled'] = ($input['purge_enabled'] == '1'); } if (isset($input['purge_max_age_days'])) { $age = intval($input['purge_max_age_days']); $sanitized_input['purge_max_age_days'] = ($age >= 1) ? $age : ($defaults['purge_max_age_days'] ?? 90); } $final_sanitized_input = array_merge($defaults, $sanitized_input); return array_intersect_key($final_sanitized_input, $defaults); }


/**
 * Render the main settings page content.
 */
function dsp_render_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Deal Scraper Settings', 'deal-scraper-plugin' ); ?></h1>
        <?php settings_errors(); ?>
        <form action="options.php" method="post" id="dsp-settings-form">
            <?php settings_fields( 'dsp_settings_group' ); ?>

            <?php // --- Managed Sites Section --- ?>
            <div id="dsp-managed-sites-container">
                <h2><?php esc_html_e( 'Managed Scraper Sources', 'deal-scraper-plugin' ); ?></h2>
                <?php dsp_managed_sites_section_callback(); // Description ?>
                <table class="wp-list-table widefat fixed striped table-view-list" id="dsp-managed-sites-table">
                    <thead><tr><th scope="col" class="manage-column column-cb check-column"><label class="screen-reader-text" for="dsp_enable_all"><?php esc_html_e( 'Select All' ); ?></label><input id="dsp_enable_all" type="checkbox"/></th><th scope="col" class="manage-column column-primary"><?php esc_html_e('Source Name', 'deal-scraper-plugin'); ?></th><th scope="col" class="manage-column"><?php esc_html_e('Website URL', 'deal-scraper-plugin'); ?></th><th scope="col" class="manage-column"><?php esc_html_e('Parser Function', 'deal-scraper-plugin'); ?></th><th scope="col" class="manage-column column-test" style="width: 10%;"><?php esc_html_e('Test', 'deal-scraper-plugin'); ?></th><th scope="col" class="manage-column column-actions" style="width: 5%;"><?php esc_html_e('Actions', 'deal-scraper-plugin'); ?></th></tr></thead>
                    <tbody id="dsp-managed-sites-body"><?php /* Table rows rendered here */
                         $managed_sites = dsp_get_managed_sites_config();
                         if (!is_array($managed_sites)) { $managed_sites = []; }
                         if (!empty($managed_sites)) :
                             foreach ($managed_sites as $site_key => $site) :
                                 if (!is_array($site)) continue;
                                 $site_name = isset($site['name']) ? $site['name'] : ''; $site_url = isset($site['url']) ? $site['url'] : ''; $site_parser = isset($site['parser']) ? $site['parser'] : ''; $site_enabled = isset($site['enabled']) ? (bool)$site['enabled'] : false; $base_name = DSP_OPTION_NAME . '[managed_sites][' . esc_attr($site_key) . ']'; $is_new_row = strpos($site_key, 'new_') === 0;
                                 ?> <tr class="dsp-site-row" data-key="<?php echo esc_attr($site_key); ?>"> <th scope="row" class="check-column"><input type="hidden" name="<?php echo $base_name; ?>[enabled]" value="0" /><input type="checkbox" name="<?php echo $base_name; ?>[enabled]" value="1" <?php checked($site_enabled, true); ?> title="<?php esc_attr_e('Enable/disable this source', 'deal-scraper-plugin'); ?>" /></th> <td class="column-primary" data-colname="<?php esc_attr_e('Source Name', 'deal-scraper-plugin'); ?>"><input type="text" class="regular-text" name="<?php echo $base_name; ?>[name]" value="<?php echo esc_attr($site_name); ?>" placeholder="<?php esc_attr_e('e.g., AppSumo', 'deal-scraper-plugin'); ?>" required /><button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details' ); ?></span></button></td> <td data-colname="<?php esc_attr_e('Website URL', 'deal-scraper-plugin'); ?>"><input type="url" class="regular-text code" name="<?php echo $base_name; ?>[url]" value="<?php echo esc_url($site_url); ?>" placeholder="<?php esc_attr_e('https://...', 'deal-scraper-plugin'); ?>" required /></td> <td data-colname="<?php esc_attr_e('Parser Function', 'deal-scraper-plugin'); ?>"><input type="text" class="regular-text code" name="<?php echo $base_name; ?>[parser]" value="<?php echo esc_attr($site_parser); ?>" placeholder="<?php esc_attr_e('e.g., parse_appsumo_php', 'deal-scraper-plugin'); ?>" required /><input type="hidden" name="<?php echo $base_name; ?>[original_key]" value="<?php echo esc_attr($site_key); ?>" /><input type="hidden" class="dsp-delete-flag" name="<?php echo $base_name; ?>[delete]" value="0" /></td> <td class="column-test dsp-site-test" data-colname="<?php esc_attr_e('Test', 'deal-scraper-plugin'); ?>"><button type="button" class="button button-secondary dsp-test-parser-button" data-key="<?php echo esc_attr($site_key); ?>" <?php disabled( $is_new_row ); ?>><?php esc_html_e('Test', 'deal-scraper-plugin'); ?></button> <span class="spinner"></span> <span class="dsp-test-result"></span></td> <td class="column-actions dsp-site-actions" data-colname="<?php esc_attr_e('Actions', 'deal-scraper-plugin'); ?>"><button type="button" class="button button-link delete dsp-remove-site-button" title="<?php esc_attr_e('Mark for deletion on save', 'deal-scraper-plugin'); ?>"><span class="dashicons dashicons-trash"></span><span class="screen-reader-text"><?php esc_html_e('Remove', 'deal-scraper-plugin'); ?></span></button></td> </tr> <?php
                             endforeach;
                         else :
                             ?> <tr id="dsp-no-sites-row"><td colspan="6"><?php esc_html_e('No sources configured yet. Click "Add Source" to begin.', 'deal-scraper-plugin'); ?></td></tr> <?php
                         endif;
                         ?></tbody>
                     <tfoot><tr> <th scope="col" class="manage-column column-cb check-column"><label class="screen-reader-text" for="dsp_enable_all_foot"><?php esc_html_e( 'Select All' ); ?></label><input id="dsp_enable_all_foot" type="checkbox"/></th> <th scope="col" class="manage-column column-primary"><?php esc_html_e('Source Name', 'deal-scraper-plugin'); ?></th> <th scope="col" class="manage-column"><?php esc_html_e('Website URL', 'deal-scraper-plugin'); ?></th> <th scope="col" class="manage-column"><?php esc_html_e('Parser Function', 'deal-scraper-plugin'); ?></th> <th scope="col" class="manage-column column-test"><?php esc_html_e('Test', 'deal-scraper-plugin'); ?></th> <th scope="col" class="manage-column column-actions"><?php esc_html_e('Actions', 'deal-scraper-plugin'); ?></th> </tr></tfoot>
                </table>
                <p><button type="button" id="dsp-add-site-button" class="button button-secondary"><?php esc_html_e('Add New Source Row', 'deal-scraper-plugin'); ?></button></p>
            </div> <?php // End dsp-managed-sites-container ?>

            <?php // --- Template Row (Hidden) --- ?>
            <table style="display:none;"><tr id="dsp-site-row-template" class="dsp-site-row" data-key="__INDEX__"><th scope="row" class="check-column"><input type="hidden" name="<?php echo DSP_OPTION_NAME; ?>[managed_sites][__INDEX__][enabled]" value="0" /><input type="checkbox" name="<?php echo DSP_OPTION_NAME; ?>[managed_sites][__INDEX__][enabled]" value="1" checked title="<?php esc_attr_e('Enable/disable this source', 'deal-scraper-plugin'); ?>" /></th><td class="column-primary" data-colname="<?php esc_attr_e('Source Name', 'deal-scraper-plugin'); ?>"><input type="text" class="regular-text" name="<?php echo DSP_OPTION_NAME; ?>[managed_sites][__INDEX__][name]" value="" placeholder="<?php esc_attr_e('e.g., New Site', 'deal-scraper-plugin'); ?>" required /><button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details' ); ?></span></button></td><td data-colname="<?php esc_attr_e('Website URL', 'deal-scraper-plugin'); ?>"><input type="url" class="regular-text code" name="<?php echo DSP_OPTION_NAME; ?>[managed_sites][__INDEX__][url]" value="" placeholder="<?php esc_attr_e('https://...', 'deal-scraper-plugin'); ?>" required /></td><td data-colname="<?php esc_attr_e('Parser Function', 'deal-scraper-plugin'); ?>"><input type="text" class="regular-text code" name="<?php echo DSP_OPTION_NAME; ?>[managed_sites][__INDEX__][parser]" value="" placeholder="<?php esc_attr_e('e.g., parse_newsite_php', 'deal-scraper-plugin'); ?>" required /><input type="hidden" name="<?php echo DSP_OPTION_NAME; ?>[managed_sites][__INDEX__][original_key]" value="__INDEX__" /><input type="hidden" class="dsp-delete-flag" name="<?php echo DSP_OPTION_NAME; ?>[managed_sites][__INDEX__][delete]" value="0" /></td><td class="column-test dsp-site-test" data-colname="<?php esc_attr_e('Test', 'deal-scraper-plugin'); ?>"><button type="button" class="button button-secondary dsp-test-parser-button" data-key="__INDEX__" disabled><?php esc_html_e('Test', 'deal-scraper-plugin'); ?></button> <span class="spinner"></span> <span class="dsp-test-result"><?php esc_html_e('(Save first)', 'deal-scraper-plugin'); ?></span></td><td class="column-actions dsp-site-actions" data-colname="<?php esc_attr_e('Actions', 'deal-scraper-plugin'); ?>"><button type="button" class="button button-link delete dsp-remove-site-button" title="<?php esc_attr_e('Mark for deletion on save', 'deal-scraper-plugin'); ?>"><span class="dashicons dashicons-trash"></span><span class="screen-reader-text"><?php esc_html_e('Remove', 'deal-scraper-plugin'); ?></span></button></td></tr></table>

            <?php
            // --- Render Other Sections ---
            echo '<hr/>';
            global $wp_settings_sections, $wp_settings_fields;
            if (isset($wp_settings_sections['deal_scraper_settings'])) {
                 echo '<h2 class="title">' . __('General Options', 'deal-scraper-plugin') . '</h2>';
                foreach ((array) $wp_settings_sections['deal_scraper_settings'] as $section) {
                    if ($section['id'] == 'dsp_managed_sites_section') { continue; } // Skip managed sites

                    if ($section['title']) { echo "<h3>" . esc_html($section['title']) . "</h3>\n"; }
                    if ($section['callback']) { call_user_func($section['callback'], $section); }

                    if (!empty($wp_settings_fields['deal_scraper_settings'][$section['id']])) {
                        echo '<table class="form-table" role="presentation">';
                        // Render standard fields for the section
                        do_settings_fields('deal_scraper_settings', $section['id']);

                        // *** Manually render the clear button within its section ***
                        if ($section['id'] === 'dsp_data_management_section') {
                             // Check if the render function exists before calling
                             if (function_exists('dsp_render_clear_all_deals_field')) {
                                // Output the field manually wrapped in table row/cells
                                echo '<tr><th scope="row">' . esc_html__('Clear All Stored Deals', 'deal-scraper-plugin') . '</th><td>';
                                dsp_render_clear_all_deals_field(); // Call the render function directly
                                echo '</td></tr>';
                             }
                        }
                        echo '</table>';
                    }
                }
            }

            // --- Final Save Button ---
            submit_button( __( 'Save Settings', 'deal-scraper-plugin' ) );
            ?>
        </form>
    </div> <?php // End wrap ?>
    <?php
} // End dsp_render_settings_page

// Define fallback functions if needed
if (!function_exists('dsp_get_default_config')) { function dsp_get_default_config(){ return []; } }
if (!function_exists('dsp_get_managed_sites_config')) { function dsp_get_managed_sites_config() { $options = get_option(DSP_OPTION_NAME); $defaults = function_exists('dsp_get_default_config') ? dsp_get_default_config() : []; $merged_options = wp_parse_args($options, $defaults); return isset($merged_options['managed_sites']) && is_array($merged_options['managed_sites']) ? $merged_options['managed_sites'] : []; } }

// Define render function for clear deals button (WITH function_exists check for safety)
if ( ! function_exists( 'dsp_render_clear_all_deals_field' ) ) {
    /**
     * Render the "Clear All Deals" button and status area.
     */
    function dsp_render_clear_all_deals_field() {
        ?>
        <div style="padding-top: 10px; border-top: 1px dashed #ccc; margin-top: 15px;">
             <button type="button" id="dsp-clear-deals-button" class="button button-danger">
                 <?php esc_html_e('Clear All Deals Now', 'deal-scraper-plugin'); ?>
             </button>
             <span class="spinner" id="dsp-clear-deals-spinner" style="float: none; vertical-align: middle; margin: 0 0 0 5px;"></span>
             <p class="description" style="color: #dc3232;"><?php esc_html_e('Permanently deletes all stored deals from the database immediately. This action cannot be undone!', 'deal-scraper-plugin'); ?></p>
             <div id="dsp-clear-deals-status" style="margin-top: 5px; font-weight: bold;"></div>
        </div>
        <?php
    }
}
?>