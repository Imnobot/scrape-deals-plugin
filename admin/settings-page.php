<?php
// File: admin/settings-page.php (v1.0.9 - Updated frequency description)

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Add settings page */
function dsp_add_admin_menu() { add_options_page( __( 'Deal Scraper Settings', 'deal-scraper-plugin' ), __( 'Deal Scraper', 'deal-scraper-plugin' ), 'manage_options', 'deal_scraper_settings', 'dsp_render_settings_page' ); }
add_action( 'admin_menu', 'dsp_add_admin_menu' );

/** Register settings */
function dsp_register_settings() {
    register_setting( 'dsp_settings_group', DSP_OPTION_NAME, 'dsp_sanitize_settings' );
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
function dsp_email_settings_section_callback() { echo '<p>' . esc_html__( 'Configure settings for the new deals email notification.', 'deal-scraper-plugin' ) . '</p>'; }
function dsp_render_email_enabled_field() { $options = get_option( DSP_OPTION_NAME ); $value = isset( $options['email_enabled'] ) ? (bool) $options['email_enabled'] : false; ?><label><input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_enabled]" value="1" <?php checked( $value, true ); ?> /> <?php esc_html_e( 'Enable sending email digests of new deals.', 'deal-scraper-plugin' ); ?></label><?php }

// MODIFIED Callback: Updated description
function dsp_render_email_frequency_field() {
    $options = get_option( DSP_OPTION_NAME ); $current_frequency = isset( $options['email_frequency'] ) ? $options['email_frequency'] : 'weekly';
    $frequencies = ['weekly'=> __( 'Weekly', 'deal-scraper-plugin' ), 'biweekly'=> __( 'Every 15 Days', 'deal-scraper-plugin' ),];
    ?><select name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_frequency]"><?php foreach ( $frequencies as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_frequency, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
     <p class="description"><?php esc_html_e( 'Select how often the automatic email digest should be sent (checked daily).', 'deal-scraper-plugin' ); ?></p><?php // Updated description
}

function dsp_render_email_recipients_field() { $options = get_option( DSP_OPTION_NAME ); $recipients_array = isset( $options['email_recipients'] ) && is_array($options['email_recipients']) ? $options['email_recipients'] : []; $value = implode( "\n", $recipients_array ); ?><textarea name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_recipients]" class="large-text" rows="5" placeholder="<?php esc_attr_e( 'Enter email addresses, one per line.', 'deal-scraper-plugin' ); ?>"><?php echo esc_textarea( $value ); ?></textarea><p class="description"><?php esc_html_e( 'Enter email addresses (one per line) to send notifications to.', 'deal-scraper-plugin' ); ?></p><?php }
function dsp_render_manual_email_send_button_field() { $options = get_option( DSP_OPTION_NAME ); $recipients = isset( $options['email_recipients'] ) && is_array( $options['email_recipients'] ) ? $options['email_recipients'] : []; $can_send = ! empty( $recipients ); $button_text = __( 'Send Now', 'deal-scraper-plugin' ); ?><button type="button" id="dsp-send-manual-email-button" class="button" <?php disabled( ! $can_send ); ?>><?php echo esc_html( $button_text ); ?></button><span id="dsp-manual-email-spinner" class="spinner" style="float: none; visibility: hidden; margin-left: 5px; vertical-align: middle;"></span><p class="description"><?php if ( $can_send ) { printf( esc_html( _n( 'Manually trigger sending the email digest (containing the 10 most recently seen deals) to the %d configured recipient.', 'Manually trigger sending the email digest (containing the 10 most recently seen deals) to the %d configured recipients.', count( $recipients ), 'deal-scraper-plugin' ) ), count( $recipients ) ); } else { esc_html_e( 'You must enter and save at least one recipient email address above before you can send.', 'deal-scraper-plugin' ); } ?><br><em><?php esc_html_e( 'Note: Ensure your WordPress site is configured to send emails correctly.', 'deal-scraper-plugin' ); ?></em></p><div id="dsp-manual-email-status" style="margin-top: 10px;"><!-- Status messages --></div><?php }
function dsp_frontend_settings_section_callback() { echo '<hr style="margin: 20px 0;"><p>' . esc_html__( 'Control the appearance and functionality of the shortcode display.', 'deal-scraper-plugin' ) . '</p>'; }
function dsp_render_show_debug_button_field() { $options = get_option( DSP_OPTION_NAME ); $value = isset( $options['show_debug_button'] ) ? (bool) $options['show_debug_button'] : true; ?><label><input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[show_debug_button]" value="1" <?php checked( $value, true ); ?> /> <?php esc_html_e( 'Show the "Show Debug Log" button on the frontend.', 'deal-scraper-plugin' ); ?></label><p class="description"><?php esc_html_e( 'Uncheck to hide the debug log button for all users.', 'deal-scraper-plugin' ); ?></p><?php }
function dsp_render_refresh_button_access_field() { $options = get_option( DSP_OPTION_NAME ); $access_options = ['all'=> __( 'Show for all users', 'deal-scraper-plugin' ), 'logged_in'=> __( 'Show only for logged-in users', 'deal-scraper-plugin' ), 'admins'=> __( 'Show only for Administrators', 'deal-scraper-plugin' ), 'disabled'=> __( 'Disable for everyone', 'deal-scraper-plugin' ),]; $current_value = isset( $options['refresh_button_access'] ) ? $options['refresh_button_access'] : 'all'; ?><fieldset><legend class="screen-reader-text"><span><?php esc_html_e( 'Refresh Button Access', 'deal-scraper-plugin' ); ?></span></legend><?php foreach ($access_options as $value => $label) : ?><label style="display: block; margin-bottom: 5px;"><input type="radio" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[refresh_button_access]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current_value, $value ); ?> /> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></fieldset><p class="description"><?php esc_html_e( 'Control who can see and use the "Refresh Now" button.', 'deal-scraper-plugin' ); ?></p><?php }
function dsp_render_dark_mode_default_field() { $options = get_option( DSP_OPTION_NAME ); $mode_options = ['light'=> __( 'Light Mode', 'deal-scraper-plugin' ), 'dark'=> __( 'Dark Mode', 'deal-scraper-plugin' ), 'auto'=> __( 'Auto (Day/Night based on time)', 'deal-scraper-plugin' ),]; $current_value = isset( $options['dark_mode_default'] ) ? $options['dark_mode_default'] : 'light'; ?><fieldset><legend class="screen-reader-text"><span><?php esc_html_e( 'Default Color Mode', 'deal-scraper-plugin' ); ?></span></legend><?php foreach ($mode_options as $value => $label) : ?><label style="display: block; margin-bottom: 5px;"><input type="radio" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[dark_mode_default]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current_value, $value ); ?> /> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></fieldset><p class="description"><?php esc_html_e( 'Select the default color scheme for the deal display.', 'deal-scraper-plugin' ); ?><br><em><?php esc_html_e( 'Note: "Auto" mode uses visitor\'s browser time (approx. 6 AM - 6 PM as day).', 'deal-scraper-plugin' ); ?></em></p><?php }
function dsp_data_management_section_callback() { echo '<hr style="margin: 20px 0;"><p>' . esc_html__( 'Manage stored deal data to keep the database optimized.', 'deal-scraper-plugin' ) . '</p>'; }
function dsp_render_purge_enabled_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $value = isset( $options['purge_enabled'] ) ? (bool) $options['purge_enabled'] : $defaults['purge_enabled']; ?><label><input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[purge_enabled]" value="1" <?php checked( $value, true ); ?> /> <?php esc_html_e( 'Automatically delete old deals from the database.', 'deal-scraper-plugin' ); ?></label><p class="description"><?php esc_html_e( 'When enabled, deals older than the specified age below will be deleted during the regular cron run.', 'deal-scraper-plugin' ); ?></p><?php }
function dsp_render_purge_max_age_days_field() { $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $value = isset( $options['purge_max_age_days'] ) ? intval( $options['purge_max_age_days'] ) : $defaults['purge_max_age_days']; $value = max(1, $value); ?><input type="number" min="1" step="1" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[purge_max_age_days]" value="<?php echo esc_attr( $value ); ?>" class="small-text" /> <?php esc_html_e( 'days', 'deal-scraper-plugin' ); ?><p class="description"><?php esc_html_e( 'Enter the maximum age (in days) for deals to keep. Deals first seen before this many days ago will be deleted if auto-purge is enabled.', 'deal-scraper-plugin' ); ?></p><?php }

// --- Sanitization Function ---
function dsp_sanitize_settings( $input ) {
    $output = get_option( DSP_OPTION_NAME, [] ); if ( ! is_array( $output ) ) { $output = []; }
    $defaults = dsp_get_default_config();
    // Email
    $output['email_enabled'] = ( isset( $input['email_enabled'] ) && $input['email_enabled'] == '1' );
    $allowed_frequencies = ['weekly', 'biweekly'];
    if ( isset( $input['email_frequency'] ) && in_array( $input['email_frequency'], $allowed_frequencies, true ) ) { $output['email_frequency'] = $input['email_frequency']; } else { $output['email_frequency'] = $defaults['email_frequency']; }
    $valid_emails = []; $invalid_entries_found = false;
    if ( isset( $input['email_recipients'] ) && is_string($input['email_recipients']) ) {
        $emails_raw = preg_split( '/\r\n|\r|\n/', $input['email_recipients'] );
        foreach ( $emails_raw as $email_raw ) { $email_trimmed = trim( $email_raw ); if ( empty( $email_trimmed ) ) continue; $sanitized_email = sanitize_email( $email_trimmed ); if ( is_email( $sanitized_email ) ) { $exists = false; foreach($valid_emails as $existing) { if(strcasecmp($existing, $sanitized_email) === 0) {$exists = true; break;} } if (!$exists) { $valid_emails[] = $sanitized_email; } } elseif ( !is_email($sanitized_email) ) { $invalid_entries_found = true; } }
    } elseif ( isset( $input['email_recipients'] ) && is_array($input['email_recipients']) ) { foreach ($input['email_recipients'] as $email_item) { if (is_string($email_item)) { $email_trimmed = trim($email_item); if (empty($email_trimmed)) continue; $sanitized_email = sanitize_email($email_trimmed); if (is_email($sanitized_email)) { $exists = false; foreach($valid_emails as $existing) { if(strcasecmp($existing, $sanitized_email) === 0) {$exists = true; break;} } if (!$exists) { $valid_emails[] = $sanitized_email; } } elseif (!is_email($sanitized_email)) { $invalid_entries_found = true; } } else { $invalid_entries_found = true; } } $valid_emails = array_values(array_unique($valid_emails)); }
    else { $valid_emails = []; }
    $output['email_recipients'] = $valid_emails; if ($invalid_entries_found) { add_settings_error('dsp_email_recipients', 'dsp_invalid_email_entries', __('One or more invalid email addresses were provided and have been ignored.', 'deal-scraper-plugin'), 'warning'); }
    // Frontend
    $output['show_debug_button'] = ( isset( $input['show_debug_button'] ) && $input['show_debug_button'] == '1' );
    $allowed_refresh_access = ['all', 'logged_in', 'admins', 'disabled'];
    if ( isset( $input['refresh_button_access'] ) && in_array( $input['refresh_button_access'], $allowed_refresh_access, true ) ) { $output['refresh_button_access'] = $input['refresh_button_access']; } else { $output['refresh_button_access'] = $defaults['refresh_button_access']; }
    $allowed_dark_modes = ['light', 'dark', 'auto'];
    if ( isset( $input['dark_mode_default'] ) && in_array( $input['dark_mode_default'], $allowed_dark_modes, true ) ) { $output['dark_mode_default'] = $input['dark_mode_default']; } else { $output['dark_mode_default'] = $defaults['dark_mode_default']; }
    // Purge
    $output['purge_enabled'] = ( isset( $input['purge_enabled'] ) && $input['purge_enabled'] == '1' );
    if ( isset( $input['purge_max_age_days'] ) ) { $age = intval( $input['purge_max_age_days'] ); $output['purge_max_age_days'] = ( $age >= 1 ) ? $age : $defaults['purge_max_age_days']; } else { $output['purge_max_age_days'] = $output['purge_max_age_days'] ?? $defaults['purge_max_age_days']; }
    // Final merge and filter
    $final_output = wp_parse_args($output, $defaults);
    return array_intersect_key($final_output, $defaults);
 }

// --- Render Page ---
function dsp_render_settings_page() {
    ?><div class="wrap"><h1><?php esc_html_e( 'Deal Scraper Settings', 'deal-scraper-plugin' ); ?></h1><?php settings_errors(); ?><form action="options.php" method="post" id="dsp-settings-form"><?php settings_fields( 'dsp_settings_group' ); do_settings_sections( 'deal_scraper_settings' ); submit_button( __( 'Save Settings', 'deal-scraper-plugin' ) ); ?></form></div><?php
}

// --- Fallback ---
if (!function_exists('dsp_get_default_config')) { function dsp_get_default_config() { return []; } }

?>