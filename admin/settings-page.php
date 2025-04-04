<?php
// File: admin/settings-page.php (v1.0.4 level - Includes Refresh Access, Dark Mode, and Sanitization Fix)

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Add settings page to the WP Admin menu.
 */
function dsp_add_admin_menu() {
    add_options_page(
        __( 'Deal Scraper Settings', 'deal-scraper-plugin' ), // Page Title
        __( 'Deal Scraper', 'deal-scraper-plugin' ),       // Menu Title
        'manage_options',                                 // Capability required
        'deal_scraper_settings',                          // Menu Slug
        'dsp_render_settings_page'                        // Render callback
    );
}
add_action( 'admin_menu', 'dsp_add_admin_menu' );

/**
 * Register plugin settings using the Settings API.
 */
function dsp_register_settings() {
    register_setting(
        'dsp_settings_group',           // Option group Name. Should match the settings_fields() call.
        DSP_OPTION_NAME,                // Option name in wp_options table
        'dsp_sanitize_settings'         // Sanitization callback function
    );

    // --- Email Settings Section ---
    add_settings_section(
        'dsp_email_settings_section',                // Section ID
        __( 'Email Notifications', 'deal-scraper-plugin' ), // Section Title
        'dsp_email_settings_section_callback',       // Callback for description
        'deal_scraper_settings'                      // Page slug where this section appears
    );

    // Field: Enable/Disable Email
    add_settings_field(
        'dsp_email_enabled',                           // Field ID
        __( 'Enable Email', 'deal-scraper-plugin' ), // Field Title
        'dsp_render_email_enabled_field',              // Render callback
        'deal_scraper_settings',                       // Page slug
        'dsp_email_settings_section'                   // Section ID where this field appears
    );

    // Field: Email Frequency
    add_settings_field(
        'dsp_email_frequency',                         // Field ID
        __( 'Email Frequency', 'deal-scraper-plugin' ), // Field Title
        'dsp_render_email_frequency_field',            // Render callback
        'deal_scraper_settings',                       // Page slug
        'dsp_email_settings_section'                   // Section ID
    );

    // Field: Recipient Email Addresses
    add_settings_field(
        'dsp_email_recipients',                         // Field ID
        __( 'Recipient Emails', 'deal-scraper-plugin' ),// Field Title
        'dsp_render_email_recipients_field',            // Render callback
        'deal_scraper_settings',                       // Page slug
        'dsp_email_settings_section'                   // Section ID
    );


    // --- Frontend Display Settings Section ---
    add_settings_section(
        'dsp_frontend_settings_section',             // Section ID
        __( 'Frontend Display Options', 'deal-scraper-plugin' ), // Section Title
        'dsp_frontend_settings_section_callback',    // Callback for description
        'deal_scraper_settings'                      // Page slug
    );

    // Field: Show Debug Log Button
    add_settings_field(
        'dsp_show_debug_button',                     // Field ID
        __( 'Debug Button', 'deal-scraper-plugin' ),  // Field Title
        'dsp_render_show_debug_button_field',        // Render callback
        'deal_scraper_settings',                     // Page slug
        'dsp_frontend_settings_section'              // Section ID
    );

    // Field: Refresh Button Access
    add_settings_field(
        'dsp_refresh_button_access',                  // Field ID
        __( 'Refresh Button Access', 'deal-scraper-plugin' ), // Field Title
        'dsp_render_refresh_button_access_field',     // Render callback
        'deal_scraper_settings',                      // Page slug
        'dsp_frontend_settings_section'               // Section ID
    );

    // Field: Dark Mode Default
    add_settings_field(
        'dsp_dark_mode_default',                      // Field ID
        __( 'Default Color Mode', 'deal-scraper-plugin' ), // Field Title
        'dsp_render_dark_mode_default_field',         // Render callback
        'deal_scraper_settings',                      // Page slug
        'dsp_frontend_settings_section'               // Section ID
    );

}
add_action( 'admin_init', 'dsp_register_settings' );


// --- Callback Functions for Rendering Fields ---

/** Callback function for the email settings section description. */
function dsp_email_settings_section_callback() {
    echo '<p>' . esc_html__( 'Configure settings for the new deals email notification.', 'deal-scraper-plugin' ) . '</p>';
    $options = get_option(DSP_OPTION_NAME);
    $recipients = isset($options['email_recipients']) && is_array($options['email_recipients']) ? $options['email_recipients'] : [];
    $enabled = isset($options['email_enabled']) ? (bool)$options['email_enabled'] : false;
    if ($enabled && !empty($recipients)) { echo '<p><em>' . esc_html__( 'Email notifications are enabled.', 'deal-scraper-plugin') . '</em></p>'; }
    elseif ($enabled && empty($recipients)) { echo '<p><em>' . esc_html__( 'Please enter recipient email addresses below.', 'deal-scraper-plugin') . '</em></p>'; }
}

/** Render the checkbox field for enabling/disabling emails. */
function dsp_render_email_enabled_field() {
    $options = get_option( DSP_OPTION_NAME ); $value = isset( $options['email_enabled'] ) ? (bool) $options['email_enabled'] : false;
    ?><label><input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_enabled]" value="1" <?php checked( $value, true ); ?> /> <?php esc_html_e( 'Send an email digest of new deals.', 'deal-scraper-plugin' ); ?></label><?php
}

/** Render the dropdown select field for email frequency. */
function dsp_render_email_frequency_field() {
    $options = get_option( DSP_OPTION_NAME ); $current_frequency = isset( $options['email_frequency'] ) ? $options['email_frequency'] : 'weekly';
    $frequencies = ['weekly'=> __( 'Weekly', 'deal-scraper-plugin' ), 'biweekly'=> __( 'Every 15 Days', 'deal-scraper-plugin' ),];
    ?><select name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_frequency]"><?php foreach ( $frequencies as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_frequency, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
     <p class="description"><?php esc_html_e( 'How often should the notification be sent?', 'deal-scraper-plugin' ); ?></p><?php
}

/** Render the TEXTAREA field for the recipient email addresses. */
function dsp_render_email_recipients_field() {
     $options = get_option( DSP_OPTION_NAME ); $recipients_array = isset( $options['email_recipients'] ) && is_array($options['email_recipients']) ? $options['email_recipients'] : []; $value = implode( "\n", $recipients_array );
     ?><textarea name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_recipients]" class="large-text" rows="5" placeholder="<?php esc_attr_e( 'Enter email addresses, one per line.', 'deal-scraper-plugin' ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
     <p class="description"><?php esc_html_e( 'Enter email addresses (one per line) to send notifications to.', 'deal-scraper-plugin' ); ?></p><?php
}

/** Callback function for the frontend display section description. */
function dsp_frontend_settings_section_callback() {
    echo '<hr style="margin: 20px 0;">'; echo '<p>' . esc_html__( 'Control the appearance and functionality of the shortcode display.', 'deal-scraper-plugin' ) . '</p>';
}

/** Render the checkbox field for showing/hiding the debug button. */
function dsp_render_show_debug_button_field() {
    $options = get_option( DSP_OPTION_NAME ); $value = isset( $options['show_debug_button'] ) ? (bool) $options['show_debug_button'] : true; // Default true
    ?><label><input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[show_debug_button]" value="1" <?php checked( $value, true ); ?> /> <?php esc_html_e( 'Show the "Show Debug Log" button on the frontend.', 'deal-scraper-plugin' ); ?></label>
     <p class="description"><?php esc_html_e( 'Uncheck to hide the debug log button for all users.', 'deal-scraper-plugin' ); ?></p><?php
}

/** Render the radio buttons for controlling refresh button access. */
function dsp_render_refresh_button_access_field() {
    $options = get_option( DSP_OPTION_NAME );
    $access_options = ['all'=> __( 'Show for all users', 'deal-scraper-plugin' ), 'logged_in'=> __( 'Show only for logged-in users', 'deal-scraper-plugin' ), 'admins'=> __( 'Show only for Administrators', 'deal-scraper-plugin' ), 'disabled'=> __( 'Disable for everyone', 'deal-scraper-plugin' ),];
    $current_value = isset( $options['refresh_button_access'] ) ? $options['refresh_button_access'] : 'all'; // Default 'all'
    ?><fieldset><legend class="screen-reader-text"><span><?php esc_html_e( 'Refresh Button Access', 'deal-scraper-plugin' ); ?></span></legend><?php foreach ($access_options as $value => $label) : ?>
    <label style="display: block; margin-bottom: 5px;"><input type="radio" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[refresh_button_access]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current_value, $value ); ?> /> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></fieldset>
    <p class="description"><?php esc_html_e( 'Control who can see and use the "Refresh Now" button.', 'deal-scraper-plugin' ); ?></p><?php
}

/** Render the radio buttons for selecting the default dark mode. */
function dsp_render_dark_mode_default_field() {
    $options = get_option( DSP_OPTION_NAME );
    $mode_options = ['light'=> __( 'Light Mode', 'deal-scraper-plugin' ), 'dark'=> __( 'Dark Mode', 'deal-scraper-plugin' ), 'auto'=> __( 'Auto (Day/Night based on time)', 'deal-scraper-plugin' ),];
    $current_value = isset( $options['dark_mode_default'] ) ? $options['dark_mode_default'] : 'light'; // Default 'light'
    ?><fieldset><legend class="screen-reader-text"><span><?php esc_html_e( 'Default Color Mode', 'deal-scraper-plugin' ); ?></span></legend><?php foreach ($mode_options as $value => $label) : ?>
    <label style="display: block; margin-bottom: 5px;"><input type="radio" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[dark_mode_default]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current_value, $value ); ?> /> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></fieldset>
    <p class="description"><?php esc_html_e( 'Select the default color scheme for the deal display.', 'deal-scraper-plugin' ); ?><br><em><?php esc_html_e( 'Note: "Auto" mode uses visitor\'s browser time (approx. 6 AM - 6 PM as day).', 'deal-scraper-plugin' ); ?></em></p><?php
}


/**
 * Sanitize the settings array before saving.
 * CORRECTED to handle potential array input for email_recipients.
 */
function dsp_sanitize_settings( $input ) {
    $sanitized_input = [];
    // Fetch defaults to ensure all keys exist and have fallbacks.
    // Use function_exists for safety if this file could be loaded independently.
    $defaults = function_exists('dsp_get_default_config') ? dsp_get_default_config() : [];

    // --- Sanitize Email Settings ---
    $sanitized_input['email_enabled'] = isset( $input['email_enabled'] ) && $input['email_enabled'] == '1';

    $allowed_frequencies = ['weekly', 'biweekly'];
    $sanitized_input['email_frequency'] = $defaults['email_frequency'] ?? 'weekly';
    if ( isset( $input['email_frequency'] ) && in_array( $input['email_frequency'], $allowed_frequencies, true ) ) {
        $sanitized_input['email_frequency'] = $input['email_frequency'];
    }

    // --- CORRECTED Email Recipients Sanitization ---
    $valid_emails = [];
    $invalid_entries_found = false;
    if ( isset( $input['email_recipients'] ) ) {
        // Check if the input is a string (from textarea) or already an array (from update_option)
        if ( is_string($input['email_recipients']) ) {
            // Input is a string, process it line by line
            $emails_raw = preg_split( '/\r\n|\r|\n/', $input['email_recipients'] );
            foreach ( $emails_raw as $email_raw ) {
                $email_trimmed = trim( $email_raw );
                if ( empty( $email_trimmed ) ) continue;
                $sanitized_email = sanitize_email( $email_trimmed );
                // Add if valid and not already present (case-insensitive check during add isn't strictly needed due to array_unique later, but good practice)
                if ( is_email( $sanitized_email ) ) {
                     // Check existence case-insensitively before adding
                     $exists = false;
                     foreach($valid_emails as $existing) { if(strcasecmp($existing, $sanitized_email) === 0) {$exists = true; break;} }
                     if (!$exists) { $valid_emails[] = $sanitized_email; }
                } elseif ( !is_email($sanitized_email) ) {
                     $invalid_entries_found = true; // Flag invalid only if it wasn't empty
                }
            }
        } elseif ( is_array($input['email_recipients']) ) {
             // Input is already an array (likely from AJAX update_option), sanitize each item
             foreach ($input['email_recipients'] as $email_item) {
                 if (is_string($email_item)) {
                     $email_trimmed = trim($email_item);
                     if (empty($email_trimmed)) continue;
                     $sanitized_email = sanitize_email($email_trimmed);
                     // Add if valid and not already present (case-insensitive check)
                     if (is_email($sanitized_email)) {
                          $exists = false;
                          foreach($valid_emails as $existing) { if(strcasecmp($existing, $sanitized_email) === 0) {$exists = true; break;} }
                          if (!$exists) { $valid_emails[] = $sanitized_email; }
                     } elseif (!is_email($sanitized_email)) {
                         $invalid_entries_found = true;
                     }
                 } else {
                      $invalid_entries_found = true; // Non-string item in array
                 }
             }
             // Ensure uniqueness again after processing the array
             $valid_emails = array_values(array_unique($valid_emails)); // Re-index array after unique
        }
    }
    // Always store as an array
    $sanitized_input['email_recipients'] = $valid_emails;
    if ($invalid_entries_found) {
        add_settings_error('dsp_email_recipients', 'dsp_invalid_email_entries', __('One or more invalid email addresses were provided and have been ignored.', 'deal-scraper-plugin'), 'warning');
    }
    // --- END CORRECTED ---


    // --- Sanitize Frontend Settings ---
    $sanitized_input['show_debug_button'] = isset( $input['show_debug_button'] ) && $input['show_debug_button'] == '1';

    $allowed_refresh_access = ['all', 'logged_in', 'admins', 'disabled'];
    $sanitized_input['refresh_button_access'] = $defaults['refresh_button_access'] ?? 'all';
    if ( isset( $input['refresh_button_access'] ) && in_array( $input['refresh_button_access'], $allowed_refresh_access, true ) ) {
        $sanitized_input['refresh_button_access'] = $input['refresh_button_access'];
    }

    $allowed_dark_modes = ['light', 'dark', 'auto'];
    $sanitized_input['dark_mode_default'] = $defaults['dark_mode_default'] ?? 'light';
    if ( isset( $input['dark_mode_default'] ) && in_array( $input['dark_mode_default'], $allowed_dark_modes, true ) ) {
        $sanitized_input['dark_mode_default'] = $input['dark_mode_default'];
    }

    // Ensure all default keys are present before returning
    $final_sanitized_input = array_merge($defaults, $sanitized_input);

    // Return only the keys that are defined in the defaults to prevent saving unknown/malicious data
    // This protects against unexpected data being added to the options array
    return array_intersect_key($final_sanitized_input, $defaults);
}


/**
 * Render the main settings page content.
 */
function dsp_render_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Deal Scraper Settings', 'deal-scraper-plugin' ); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'dsp_settings_group' ); // Security fields for group
            do_settings_sections( 'deal_scraper_settings' ); // Renders sections and fields for this page
            submit_button( __( 'Save Settings', 'deal-scraper-plugin' ) ); // Save button
            ?>
        </form>
    </div>
    <?php
}

// Define dsp_get_default_config only if it doesn't exist (safety for potential standalone includes)
if (!function_exists('dsp_get_default_config')) {
    function dsp_get_default_config() {
         // MUST match the function in the main plugin file
         return [
             'sites' => [ /* Default sites... */ ],
             'email_enabled' => false,
             'email_frequency' => 'weekly',
             'email_recipients' => [],
             'show_debug_button' => true,
             'refresh_button_access' => 'all',
             'dark_mode_default' => 'light',
         ];
    }
}

?>