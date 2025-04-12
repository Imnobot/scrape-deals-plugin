<?php
// File: includes/email-handler.php (v1.1.20 - Restore missing functions)

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Formats an array of deal objects into an HTML email body.
 *
 * @param array $deals Array of deal objects from DSP_DB_Handler::get_deals().
 * @return string HTML formatted email body, or empty string if no deals.
 */
function dsp_format_deals_email( $deals ) {
    if ( empty( $deals ) || ! is_array( $deals ) ) {
        return '';
    }

    $site_title = get_bloginfo( 'name' );
    $site_url = home_url();

    // Start buffering output
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="<?php echo esc_attr( get_locale() ); ?>">
    <head>
        <meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php printf( esc_html__( '%s Deal Digest', 'deal-scraper-plugin' ), esc_html( $site_title ) ); ?></title>
        <style>
            /* Basic Responsive Email Styles */
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; line-height: 1.6; color: #333; background-color: #f0f0f0; margin: 0; padding: 0; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
            .email-wrapper { width: 100%; background-color: #f0f0f0; padding: 20px 0; }
            .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #cccccc; border-radius: 5px; overflow: hidden; }
            .header { text-align: center; background-color: #f8f8f8; border-bottom: 1px solid #dddddd; padding: 20px; }
            .header h1 { margin: 0; color: #444; font-size: 24px; font-weight: 600; }
            .content { padding: 20px 30px; }
            .content p { margin: 0 0 15px 0; }
            .deal-item { border-bottom: 1px solid #eeeeee; padding: 15px 0; }
            .deal-item:last-child { border-bottom: none; }
            .deal-title { font-size: 18px; font-weight: bold; margin: 0 0 8px 0; line-height: 1.3; }
            .deal-title a { color: #006699; text-decoration: none; }
            .deal-meta { font-size: 13px; color: #666666; margin-bottom: 10px; }
            .deal-meta span { margin-right: 12px; white-space: nowrap; }
            .deal-meta strong { font-weight: 600; color: #444;}
            .deal-description { font-size: 14px; color: #555555; margin: 10px 0; }
            .deal-link a { display: inline-block; margin-top: 8px; padding: 8px 15px; background-color: #0077aa; color: #ffffff !important; text-decoration: none !important; border-radius: 4px; font-size: 14px; font-weight: 500;}
            .footer { text-align: center; margin-top: 20px; padding: 20px 30px; border-top: 1px solid #eeeeee; font-size: 12px; color: #888888; background-color: #f8f8f8; }
            .footer a { color: #006699; text-decoration: none; }
            .new-badge { font-weight: bold; color: #d54e21; margin-left: 5px; font-size: 0.9em; }
            .lifetime-badge { background-color: #e0e0e0; color: #555; font-size: 0.8em; padding: 2px 6px; border-radius: 3px; margin-left: 5px; vertical-align: middle; white-space: nowrap;}
            /* Responsive adjustments */
            @media screen and (max-width: 600px) {
                .content { padding: 15px 20px; }
                .header h1 { font-size: 20px; }
                .deal-title { font-size: 16px; }
                .deal-meta span { display: block; margin-right: 0; margin-bottom: 5px; }
                .footer { padding: 15px 20px; }
            }
        </style>
    </head>
    <body>
        <div class="email-wrapper">
            <div class="email-container">
                <div class="header">
                    <h1><?php printf( esc_html__( '%s Deal Digest', 'deal-scraper-plugin' ), esc_html( $site_title ) ); ?></h1>
                </div>

                <div class="content">
                    <p><?php esc_html_e( 'Here are the latest deals found:', 'deal-scraper-plugin' ); ?></p>

                    <?php
                    // Calculate 'is_new' based on the legacy last fetch time for context within the email
                    // Note: This might not be perfectly accurate if cron runs very frequently.
                    $last_fetch_time = get_option('dsp_last_fetch_time', 0);
                    $helper_function_exists = function_exists('dsp_is_lifetime_deal_php'); // Check if helper exists

                    foreach ( $deals as $deal ) :
                        if ( !is_object($deal) || empty($deal->link) || empty($deal->title) ) continue;

                        $first_seen_ts = $deal->first_seen ? strtotime($deal->first_seen) : false;
                        $is_new = ($first_seen_ts && $last_fetch_time && $first_seen_ts >= $last_fetch_time);
                        $is_lifetime = $helper_function_exists ? dsp_is_lifetime_deal_php($deal) : false;
                        ?>
                        <div class="deal-item">
                            <h3 class="deal-title">
                                <a href="<?php echo esc_url( $deal->link ); ?>" target="_blank">
                                    <?php echo esc_html( $deal->title ); ?>
                                </a>
                                <?php /* Adding NEW badge based on global last fetch is less reliable now
                                <?php if ($is_new): ?>
                                    <span class="new-badge"><?php esc_html_e('[NEW]', 'deal-scraper-plugin'); ?></span>
                                <?php endif; ?>
                                */ ?>
                                <?php if ($is_lifetime): ?>
                                    <span class="lifetime-badge"><?php esc_html_e('LTD', 'deal-scraper-plugin'); ?></span>
                                <?php endif; ?>
                            </h3>
                            <div class="deal-meta">
                                <?php if ( ! empty( $deal->source ) ) : ?>
                                    <span><strong><?php esc_html_e( 'Source:', 'deal-scraper-plugin' ); ?></strong> <?php echo esc_html( $deal->source ); ?></span>
                                <?php endif; ?>
                                <?php if ( ! empty( $deal->price ) ) : ?>
                                    <span><strong><?php esc_html_e( 'Price:', 'deal-scraper-plugin' ); ?></strong> <?php echo esc_html( $deal->price ); ?></span>
                                <?php endif; ?>
                                <?php if ( $first_seen_ts ) : ?>
                                     <span><strong><?php esc_html_e( 'Seen:', 'deal-scraper-plugin' ); ?></strong> <?php echo esc_html( date_i18n( get_option('date_format'), $first_seen_ts ) ); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ( ! empty( $deal->description ) ) : ?>
                                <p class="deal-description"><?php echo nl2br( esc_html( wp_strip_all_tags( $deal->description ) ) ); // Strip tags from desc before nl2br ?></p>
                            <?php endif; ?>
                            <div class="deal-link">
                                <a href="<?php echo esc_url( $deal->link ); ?>" target="_blank">
                                    <?php esc_html_e( 'View Deal', 'deal-scraper-plugin' ); ?> →
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="footer" id="dsp-email-footer"> <?php // Add ID for potential unsubscribe link placement ?>
                    <p>
                        <?php printf( /* translators: %1$s: Website URL, %2$s: Website Name */ wp_kses_post( __( 'Deals provided by <a href="%1$s">%2$s</a>.', 'deal-scraper-plugin' ) ), esc_url( $site_url ), esc_html( $site_title ) ); ?>
                    </p>
                    <?php // Unsubscribe link will be appended dynamically before sending ?>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    // Return buffered content
    return ob_get_clean();
}

// *** START RESTORED FUNCTIONS ***

/**
 * Checks if it's time to send the scheduled email digest and sends it.
 * Hooked to the DSP_EMAIL_CRON_HOOK action.
 */
function dsp_check_and_send_scheduled_email() {
    error_log("DSP Log: Running scheduled email check (" . DSP_EMAIL_CRON_HOOK . ")...");

    // Get current settings merged with defaults
    $options = get_option( DSP_OPTION_NAME );
    $defaults = function_exists('dsp_get_default_config') ? dsp_get_default_config() : []; // Ensure defaults function exists
    $merged_options = wp_parse_args( $options, $defaults );

    // Check if email sending is enabled
    if ( empty( $merged_options['email_enabled'] ) ) {
        error_log("DSP Log: Scheduled email skipped - Email notifications disabled in settings.");
        return;
    }

    // Determine the sending frequency and calculate next threshold
    $frequency = $merged_options['email_frequency'] ?? 'weekly';
    $last_send_timestamp = (int) get_option( DSP_LAST_EMAIL_OPTION, 0 );
    $current_timestamp = time();
    $threshold = 0;

    if ( $frequency === 'weekly' ) {
        $threshold = strtotime( '+7 days', $last_send_timestamp );
    } elseif ( $frequency === 'biweekly' ) {
        $threshold = strtotime( '+15 days', $last_send_timestamp );
    } else {
        // If frequency is invalid, log error and don't proceed
        error_log("DSP Log: Scheduled email skipped - Invalid frequency configured: " . $frequency);
        return;
    }

    // Check if it's time to send
    if ( $current_timestamp < $threshold ) {
        error_log("DSP Log: Scheduled email skipped - Not time yet. Last Sent: " . ($last_send_timestamp > 0 ? date('Y-m-d H:i:s', $last_send_timestamp) : 'Never') . ", Threshold: " . date('Y-m-d H:i:s', $threshold));
        return; // Not time yet
    }

    error_log("DSP Log: Time to send scheduled email (Frequency: {$frequency}).");

    // Get recipients and validate
    $recipients = $merged_options['email_recipients'] ?? [];
    if ( ! is_array( $recipients ) || empty( $recipients ) ) {
        error_log("DSP Log: Scheduled email aborted - No recipients configured.");
        // Update last send time even if no recipients, to prevent immediate re-run
        update_option( DSP_LAST_EMAIL_OPTION, $current_timestamp, 'no' );
        error_log("DSP Log: Updated last email send time despite no recipients.");
        return;
    }
    $valid_recipients = array_filter( $recipients, 'is_email' );
    if ( empty( $valid_recipients ) ) {
        error_log("DSP Log: Scheduled email aborted - No *valid* recipients found.");
        update_option( DSP_LAST_EMAIL_OPTION, $current_timestamp, 'no' );
        error_log("DSP Log: Updated last email send time despite no valid recipients.");
        return;
    }

    // Get new deals since the last email was sent
    // Ensure the DB handler and method exist
    if (!class_exists('DSP_DB_Handler') || !method_exists('DSP_DB_Handler', 'get_deals_since')) {
        error_log("DSP Log: Scheduled email aborted - DSP_DB_Handler::get_deals_since method not found.");
        return;
    }
    $last_send_datetime_gmt = $last_send_timestamp > 0 ? gmdate('Y-m-d H:i:s', $last_send_timestamp) : '0000-00-00 00:00:00';
    $new_deals = DSP_DB_Handler::get_deals_since($last_send_datetime_gmt);

    if ( empty( $new_deals ) ) {
        error_log("DSP Log: Scheduled email aborted - No new deals found since " . $last_send_datetime_gmt);
        // Update the last send time so we don't keep checking immediately
        update_option( DSP_LAST_EMAIL_OPTION, $current_timestamp, 'no' );
        error_log("DSP Log: Updated last email send time even though no new deals were found.");
        return;
    }

    error_log("DSP Log: Found " . count($new_deals) . " new deals to email.");

    // Format the email content
    // Ensure the format function exists (it's in this file, but good practice)
    if ( ! function_exists('dsp_format_deals_email') ) {
        error_log("DSP Log: Scheduled email aborted - dsp_format_deals_email function not found.");
        return;
    }
    $email_subject = sprintf( __( '%s Deal Digest', 'deal-scraper-plugin' ), get_bloginfo( 'name' ) );
    $email_body_html = dsp_format_deals_email($new_deals);
    if (empty($email_body_html)) {
        error_log("DSP Log: Scheduled email aborted - Failed to generate email content.");
        return;
    }

    // Prepare headers
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $site_name = get_bloginfo('name');
    $site_domain = wp_parse_url(home_url(), PHP_URL_HOST);
    if (!$site_domain) { $site_domain = $_SERVER['SERVER_NAME'] ?? 'localhost'; } // Fallback domain
    $from_email = 'wordpress@' . $site_domain;
    $headers[] = "From: {$site_name} <{$from_email}>";

    // Send email to each recipient
    $total_sent = 0;
    $total_failed = 0;
    foreach ( $valid_recipients as $recipient_email ) {
        // Ensure unsubscribe functions exist before calling
        if (!function_exists('dsp_generate_unsubscribe_link') || !function_exists('dsp_get_unsubscribe_footer_html')) {
            error_log("DSP Log: Cannot generate unsubscribe link - helper functions missing.");
            $final_email_body = $email_body_html; // Send without footer if helpers missing
        } else {
            $unsubscribe_link = dsp_generate_unsubscribe_link($recipient_email);
            $final_email_body = $email_body_html . dsp_get_unsubscribe_footer_html($unsubscribe_link);
        }

        $sent = wp_mail( $recipient_email, $email_subject, $final_email_body, $headers );
        if ($sent) {
            $total_sent++;
        } else {
            $total_failed++;
             error_log("DSP Log: Failed to send email to {$recipient_email}. Check wp_mail configuration/logs.");
             global $phpmailer;
             if ( isset( $phpmailer ) && $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) {
                  error_log( "PHPMailer error: " . $phpmailer->ErrorInfo );
             }
        }
    }

    error_log("DSP Log: Scheduled email send complete. Sent: {$total_sent}, Failed: {$total_failed}.");

    // Update last send time if emails were attempted
    if ($total_sent > 0 || $total_failed > 0) {
        update_option( DSP_LAST_EMAIL_OPTION, $current_timestamp, 'no' );
        error_log("DSP Log: Updated last email send time to " . date('Y-m-d H:i:s', $current_timestamp));
    }
}

/**
 * Generates a security token for unsubscribing.
 *
 * @param string $email The email address to generate token for.
 * @return string The generated HMAC token.
 */
function dsp_generate_unsubscribe_token( $email ) {
    $email = strtolower( trim( $email ) );
    $options = get_option( DSP_OPTION_NAME );
    // Retrieve the salt, ensuring it's a non-empty string
    $saved_salt = isset( $options['unsubscribe_salt'] ) && is_string( $options['unsubscribe_salt'] ) && ! empty( $options['unsubscribe_salt'] ) ? $options['unsubscribe_salt'] : '';
    // Use saved salt, or fallback to WP auth salt, or finally a hardcoded emergency fallback
    $secret = ! empty( $saved_salt ) ? $saved_salt : wp_salt('auth');
    if ( empty( $secret ) ) {
        $secret = 'emergency_fallback_salt_value_deal_scraper_123!'; // Use a *unique* fallback
        error_log("DSP Warning: Unsubscribe salt and WP auth salt were both empty! Using emergency fallback.");
    }
    return hash_hmac( 'sha256', $email, $secret );
}

/**
 * Generates the full unsubscribe URL.
 *
 * @param string $email The email address.
 * @return string The unsubscribe URL.
 */
function dsp_generate_unsubscribe_link( $email ) {
    $token = dsp_generate_unsubscribe_token( $email );
    $args = [
        'dsp_unsubscribe' => 1,
        'email' => rawurlencode($email), // URL encode email
        'token' => $token,
    ];
    return add_query_arg( $args, home_url( '/' ) ); // Add args to home URL
}

/**
 * Generates the HTML for the unsubscribe footer in emails.
 *
 * @param string $unsubscribe_link The generated unsubscribe URL.
 * @return string HTML footer content.
 */
function dsp_get_unsubscribe_footer_html( $unsubscribe_link ) {
    $style='text-align:center;margin-top:20px;padding-top:15px;border-top:1px solid #eee;font-size:12px;color:#888;';
    $link_style='color:#069;text-decoration:underline;';
    $text = sprintf(
        __( 'Don\'t want these emails? %s.', 'deal-scraper-plugin'),
        '<a href="'.esc_url($unsubscribe_link).'" style="'.$link_style.'">'.esc_html__('Unsubscribe here', 'deal-scraper-plugin').'</a>'
    );
    return '<div style="'.$style.'"><p>'.$text.'</p></div>';
}

// *** END RESTORED FUNCTIONS ***

?>