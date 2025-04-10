<?php
// File: includes/email-handler.php

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
                    // Calculate 'is_new' based on the last fetch time for context
                    $last_fetch_time = get_option('dsp_last_fetch_time', 0);
                    $helper_function_exists = function_exists('dsp_is_lifetime_deal_php'); // Check if helper exists

                    foreach ( $deals as $deal ) :
                        if ( !is_object($deal) || empty($deal->link) || empty($deal->title) ) continue;

                        $first_seen_ts = $deal->first_seen ? strtotime($deal->first_seen) : false;
                        $is_new = ($first_seen_ts && $last_fetch_time && $first_seen_ts >= $last_fetch_time);
                        $is_lifetime = $helper_function_exists ? dsp_is_lifetime_deal_php($deal) : false; // Use helper if available
                        ?>
                        <div class="deal-item">
                            <h3 class="deal-title">
                                <a href="<?php echo esc_url( $deal->link ); ?>" target="_blank">
                                    <?php echo esc_html( $deal->title ); ?>
                                </a>
                                <?php if ($is_new): ?>
                                    <span class="new-badge"><?php esc_html_e('[NEW]', 'deal-scraper-plugin'); ?></span>
                                <?php endif; ?>
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
                                     <span><strong><?php esc_html_e( 'Seen:', 'deal-scraper-plugin' ); ?></strong> <?php echo esc_html( date_i18n( get_option('date_format'), $first_seen_ts ) ); // Use WP date format ?></span>
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

                <div class="footer">
                    <p>
                        <?php
                        printf(
                            /* translators: %1$s: Website URL, %2$s: Website Name */
                            wp_kses_post( __( 'Deals provided by <a href="%1$s">%2$s</a>.', 'deal-scraper-plugin' ) ),
                            esc_url( $site_url ),
                            esc_html( $site_title )
                        );
                        ?>
                    </p>
                    <?php
                    // Optional: Add unsubscribe link logic later
                    ?>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    // Return buffered content
    return ob_get_clean();
}