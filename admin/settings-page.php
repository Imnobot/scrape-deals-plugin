<?php
// File: admin/settings-page.php (MODIFIED for Show Debug Button Option)

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
        'deal_scraper_settings',                          // Menu Slug (unique identifier)
        'dsp_render_settings_page'                        // Function to render the page content
    );
}
add_action( 'admin_menu', 'dsp_add_admin_menu' );

/**
 * Register plugin settings using the Settings API.
 */
function dsp_register_settings() {
    register_setting(
        'dsp_settings_group',           // Option group
        DSP_OPTION_NAME,                // Option name in wp_options table
        'dsp_sanitize_settings'         // Sanitization callback
    );

    // --- Email Settings Section ---
    add_settings_section(
        'dsp_email_settings_section',                // Section ID
        __( 'Email Notifications', 'deal-scraper-plugin' ), // Section Title
        'dsp_email_settings_section_callback',       // Callback for description
        'deal_scraper_settings'                      // Page slug
    );

    // Field: Enable/Disable Email
    add_settings_field(
        'dsp_email_enabled',                           // Field ID
        __( 'Enable Email', 'deal-scraper-plugin' ), // Field Title
        'dsp_render_email_enabled_field',              // Render callback
        'deal_scraper_settings',                       // Page slug
        'dsp_email_settings_section'                   // Section ID
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


    // --- NEW: Frontend Display Settings Section ---
    add_settings_section(
        'dsp_frontend_settings_section',             // Section ID
        __( 'Frontend Display Options', 'deal-scraper-plugin' ), // Section Title
        'dsp_frontend_settings_section_callback',    // Callback for description (NEW)
        'deal_scraper_settings'                      // Page slug
    );

    // --- NEW: Field: Show Debug Log Button ---
    add_settings_field(
        'dsp_show_debug_button',                     // Field ID
        __( 'Debug Button', 'deal-scraper-plugin' ),  // Field Title
        'dsp_render_show_debug_button_field',        // Render callback (NEW)
        'deal_scraper_settings',                     // Page slug
        'dsp_frontend_settings_section'              // Section ID for this field (NEW)
    );

    // --- Add future frontend options fields here (e.g., Guest Refresh, Dark Mode) ---
    // Example placeholder:
    // add_settings_field(
    //     'dsp_allow_guest_refresh',
    //     __( 'Allow Guest Refresh', 'deal-scraper-plugin' ),
    //     'dsp_render_allow_guest_refresh_field', // Need to create this function later
    //     'deal_scraper_settings',
    //     'dsp_frontend_settings_section'
    // );
     // Example placeholder:
     // add_settings_field(
     //    'dsp_default_dark_mode',
     //    __( 'Enable Dark Mode', 'deal-scraper-plugin' ),
     //    'dsp_render_default_dark_mode_field', // Need to create this function later
     //    'deal_scraper_settings',
     //    'dsp_frontend_settings_section'
     // );


}
add_action( 'admin_init', 'dsp_register_settings' );


/**
 * Callback function for the email settings section description.
 */
function dsp_email_settings_section_callback() {
    // (Remains the same as previous version)
    echo '<p>' . esc_html__( 'Configure settings for the new deals email notification.', 'deal-scraper-plugin' ) . '</p>';
    $options = get_option(DSP_OPTION_NAME);
    $recipients = isset($options['email_recipients']) && is_array($options['email_recipients']) ? $options['email_recipients'] : [];
    $enabled = isset($options['email_enabled']) ? (bool)$options['email_enabled'] : false;

    if ($enabled && !empty($recipients)) {
       echo '<p><em>' . esc_html__( 'Email notifications are enabled and will be sent to the configured addresses. Each email will contain an unsubscribe link.', 'deal-scraper-plugin') . '</em></p>';
    } elseif ($enabled && empty($recipients)) {
       echo '<p><em>' . esc_html__( 'Please enter at least one valid recipient email address below to activate notifications.', 'deal-scraper-plugin') . '</em></p>';
    }
}

/**
 * Render the checkbox field for enabling/disabling emails.
 */
function dsp_render_email_enabled_field() {
    // (Remains the same)
    $options = get_option( DSP_OPTION_NAME );
    $value = isset( $options['email_enabled'] ) ? (bool) $options['email_enabled'] : false;
    ?>
    <label>
        <input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_enabled]" value="1" <?php checked( $value, true ); ?> />
        <?php esc_html_e( 'Send an email digest of newly found deals.', 'deal-scraper-plugin' ); ?>
    </label>
    <?php
}

/**
 * Render the dropdown select field for email frequency.
 */
function dsp_render_email_frequency_field() {
    // (Remains the same)
    $options = get_option( DSP_OPTION_NAME );
    $current_frequency = isset( $options['email_frequency'] ) ? $options['email_frequency'] : 'weekly';
    $frequencies = [
        'weekly'    => __( 'Weekly', 'deal-scraper-plugin' ),
        'biweekly'  => __( 'Every 15 Days (Bi-Weekly)', 'deal-scraper-plugin' ),
    ];
    ?>
    <select name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_frequency]">
        <?php foreach ( $frequencies as $value => $label ) : ?>
            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_frequency, $value ); ?>>
                <?php echo esc_html( $label ); ?>
            </option>
        <?php endforeach; ?>
    </select>
     <p class="description">
        <?php esc_html_e( 'How often should the email notification be sent?', 'deal-scraper-plugin' ); ?>
    </p>
    <?php
}

/**
 * Render the TEXTAREA field for the recipient email addresses.
 */
function dsp_render_email_recipients_field() {
    // (Remains the same)
     $options = get_option( DSP_OPTION_NAME );
     $recipients_array = isset( $options['email_recipients'] ) && is_array($options['email_recipients']) ? $options['email_recipients'] : [];
     $value = implode( "\n", $recipients_array );
     ?>
     <textarea name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[email_recipients]" class="large-text" rows="5" placeholder="<?php esc_attr_e( 'Enter email addresses, one per line.', 'deal-scraper-plugin' ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
     <p class="description">
         <?php esc_html_e( 'Enter the email addresses (one per line) to send the notification to.', 'deal-scraper-plugin' ); ?>
     </p>
     <?php
}

/**
 * NEW Callback function for the frontend display section description.
 */
function dsp_frontend_settings_section_callback() {
    echo '<hr>'; // Add a separator
    echo '<p>' . esc_html__( 'Control the appearance and functionality of the shortcode display for users.', 'deal-scraper-plugin' ) . '</p>';
}

/**
 * NEW Render the checkbox field for showing/hiding the debug button.
 */
function dsp_render_show_debug_button_field() {
    $options = get_option( DSP_OPTION_NAME );
    // Default to true (show button) if not set
    $value = isset( $options['show_debug_button'] ) ? (bool) $options['show_debug_button'] : true;
    ?>
    <label>
        <input type="checkbox" name="<?php echo esc_attr( DSP_OPTION_NAME ); ?>[show_debug_button]" value="1" <?php checked( $value, true ); ?> />
        <?php esc_html_e( 'Show the "Show Debug Log" button on the frontend.', 'deal-scraper-plugin' ); ?>
    </label>
     <p class="description">
        <?php esc_html_e( 'Uncheck this to hide the debug log button for all users.', 'deal-scraper-plugin' ); ?>
    </p>
    <?php
}


/**
 * Sanitize the settings array before saving.
 * UPDATED to handle show_debug_button setting.
 */
function dsp_sanitize_settings( $input ) {
    $sanitized_input = [];
    $current_options = get_option( DSP_OPTION_NAME ); // Get existing options

    // Sanitize Email Enabled Checkbox
    $sanitized_input['email_enabled'] = isset( $input['email_enabled'] ) && $input['email_enabled'] == '1';

    // Sanitize Email Frequency
    $allowed_frequencies = ['weekly', 'biweekly'];
    if ( isset( $input['email_frequency'] ) && in_array( $input['email_frequency'], $allowed_frequencies, true ) ) {
        $sanitized_input['email_frequency'] = $input['email_frequency'];
    } else {
        $sanitized_input['email_frequency'] = 'weekly'; // Default
    }

    // Sanitize Recipient Emails Textarea
    $valid_emails = [];
    $invalid_entries_found = false;
    if ( isset( $input['email_recipients'] ) ) {
        $emails_raw = preg_split( '/\r\n|\r|\n/', $input['email_recipients'] );
        foreach ( $emails_raw as $email_raw ) {
            $email_trimmed = trim( $email_raw );
            if ( empty( $email_trimmed ) ) continue;
            $sanitized_email = sanitize_email( $email_trimmed );
            if ( is_email( $sanitized_email ) ) {
                 if (!in_array($sanitized_email, $valid_emails)) {
                    $valid_emails[] = $sanitized_email;
                 }
            } else {
                $invalid_entries_found = true;
            }
        }
    }
    $sanitized_input['email_recipients'] = $valid_emails;
    if ($invalid_entries_found) {
        add_settings_error('dsp_email_recipients', 'dsp_invalid_email_entries', __('One or more invalid email addresses were provided and have been ignored. Please check the format (one valid email per line).', 'deal-scraper-plugin'), 'warning');
    }

    // --- NEW: Sanitize Show Debug Button Checkbox ---
    // If the checkbox is checked, 'show_debug_button' will be '1'. If unchecked, it won't be present in $input.
    $sanitized_input['show_debug_button'] = isset( $input['show_debug_button'] ) && $input['show_debug_button'] == '1';
    // --- END NEW ---


    // Merge with existing settings to preserve any potential future options
    if (is_array($current_options)) {
       $sanitized_input = array_merge($current_options, $sanitized_input);
    }

    return $sanitized_input;
}

/**
 * Render the main settings page content.
 */
function dsp_render_settings_page() {
     // (Remains the same)
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Deal Scraper Settings', 'deal-scraper-plugin' ); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'dsp_settings_group' );
            do_settings_sections( 'deal_scraper_settings' ); // This automatically renders all sections added to this page slug
            submit_button( __( 'Save Settings', 'deal-scraper-plugin' ) );
            ?>
        </form>
    </div>
    <?php
}