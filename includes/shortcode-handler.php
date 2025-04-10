<?php
// File: includes/shortcode-handler.php (MODIFIED for Instant Load + Responsive Table Wrapper)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the deal display shortcode.
 * Fetches existing deals directly and renders them,
 * then relies on JS for subsequent background checks/refreshes.
 */
function dsp_render_shortcode( $atts ) {
    // --- Get Plugin Settings ---
    $options = get_option( DSP_OPTION_NAME );
    $defaults = dsp_get_default_config(); // Get defaults
    $merged_options = wp_parse_args($options, $defaults); // Ensure all keys exist

    $refresh_access = $merged_options['refresh_button_access'];
    $show_debug_button = (bool) $merged_options['show_debug_button'];
    $email_enabled = (bool) $merged_options['email_enabled'];

    // --- Determine if the current user should see the Refresh button ---
    $show_refresh_button = false;
    switch ( $refresh_access ) {
        case 'all': $show_refresh_button = true; break;
        case 'logged_in': if ( is_user_logged_in() ) { $show_refresh_button = true; } break;
        case 'admins': if ( current_user_can( 'manage_options' ) ) { $show_refresh_button = true; } break;
        case 'disabled': default: $show_refresh_button = false; break;
    }

    // --- Fetch Initial Data ---
    // Get current deals directly from the database (use default sort: newest first)
    $deals = DSP_DB_Handler::get_deals(['orderby' => 'first_seen', 'order' => 'DESC']);
    $last_fetch_time = get_option('dsp_last_fetch_time', 0);
    $last_fetch_display = $last_fetch_time
                          ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_fetch_time )
                          : __('Never', 'deal-scraper-plugin');

    // Process deals for immediate display (calculate is_new, is_lifetime, etc.)
    $processed_deals = [];
    if ($deals) {
        foreach ($deals as $deal) {
            // Ensure deal is an object with expected properties
            if (is_object($deal) && isset($deal->first_seen) && isset($deal->title) && isset($deal->link)) {
                 $first_seen_ts = strtotime($deal->first_seen); // Use for is_new calculation
                 $deal->is_new = ($first_seen_ts && $last_fetch_time && $first_seen_ts >= $last_fetch_time);
                 // Use WP date/time format for display
                 $deal->first_seen_formatted = $first_seen_ts
                                               ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $first_seen_ts )
                                               : 'N/A';
                 $deal->is_lifetime = dsp_is_lifetime_deal_php($deal); // Use helper from main plugin file
                 $processed_deals[] = $deal;
            }
        }
    }


    // Start output buffering
    ob_start();
    ?>
    <div id="dsp-deal-display-container" class="dsp-container">

        <?php // --- Filters (remain the same) --- ?>
        <div class="dsp-filters">
            <div class="dsp-filter-item">
                <label for="dsp-search-input"><?php esc_html_e('Search:', 'deal-scraper-plugin'); ?></label>
                <input type="text" id="dsp-search-input" placeholder="<?php esc_attr_e('Search title, description, source...', 'deal-scraper-plugin'); ?>">
            </div>
            <div class="dsp-filter-item dsp-filter-checkboxes">
                <span><?php esc_html_e('Sources:', 'deal-scraper-plugin'); ?></span>
                <div id="dsp-source-checkboxes"><!-- Populated by JS --></div>
            </div>
            <div class="dsp-filter-item dsp-filter-checkboxes">
                <label>
                    <input type="checkbox" id="dsp-new-only-checkbox">
                    <?php esc_html_e('Show New Only', 'deal-scraper-plugin'); ?>
                </label>
            </div>
            <?php if ( $show_refresh_button ) : ?>
            <div class="dsp-filter-item">
                 <button id="dsp-refresh-button" class="dsp-button"><?php esc_html_e('Refresh Now', 'deal-scraper-plugin'); ?></button>
                 <span id="dsp-refresh-spinner" class="dsp-spinner" style="visibility: hidden;"></span>
                 <span id="dsp-refresh-message" class="dsp-refresh-status"></span>
            </div>
            <?php endif; ?>
        </div>

        <?php // --- Controls (Donate, Subscribe, Debug) --- ?>
        <div class="dsp-debug-controls">
             <?php if ( $show_debug_button ) : ?>
                <button id="dsp-toggle-debug-log" class="dsp-button dsp-button-secondary"><?php esc_html_e('Show Debug Log', 'deal-scraper-plugin'); ?></button>
             <?php endif; ?>
             <button id="dsp-donate-button" class="dsp-button dsp-button-secondary"><?php esc_html_e('Donate', 'deal-scraper-plugin'); ?></button>
             <?php if ($email_enabled) : ?>
                <button id="dsp-subscribe-button" class="dsp-button dsp-button-secondary"><?php esc_html_e('Subscribe', 'deal-scraper-plugin'); ?></button>
             <?php endif; ?>
        </div>

        <?php // --- Debug Log Container (remains hidden initially) --- ?>
        <?php if ( $show_debug_button ) : // Only render container if button might be shown ?>
        <div id="dsp-debug-log-container" style="display: none;">
            <h4><?php esc_html_e('Refresh Debug Log:', 'deal-scraper-plugin'); ?></h4>
            <pre id="dsp-debug-log"></pre>
        </div>
        <?php endif; ?>

        <?php // --- Status Bar (Set initial messages) --- ?>
        <div class="dsp-status-bar">
            <?php // Initial status message - JS will update after background check ?>
            <span id="dsp-status-message"><?php esc_html_e('Displaying stored deals. Checking for updates...', 'deal-scraper-plugin'); // Updated initial message ?></span>
            <?php // Display last fetch time immediately ?>
            <span id="dsp-last-updated" style="float: right; visibility: <?php echo $last_fetch_time ? 'visible' : 'hidden'; ?>;">
                 <?php esc_html_e('Last fetched:', 'deal-scraper-plugin'); ?> <span id="dsp-last-updated-time"><?php echo esc_html($last_fetch_display); ?></span>
            </span>
        </div>

        <?php // *** ADD TABLE WRAPPER DIV *** ?>
        <div class="dsp-table-wrapper">
            <table id="dsp-deals-table" class="dsp-table">
                <thead>
                    <tr>
                        <?php // Table headers remain the same ?>
                        <th data-sort-key="is_new"><?php esc_html_e('New', 'deal-scraper-plugin'); ?><span class="dsp-sort-indicator"></span></th>
                        <th data-sort-key="title"><?php esc_html_e('Title / Description', 'deal-scraper-plugin'); ?><span class="dsp-sort-indicator"></span></th>
                        <th data-sort-key="price"><?php esc_html_e('Price', 'deal-scraper-plugin'); ?><span class="dsp-sort-indicator"></span></th>
                        <th data-sort-key="source"><?php esc_html_e('Source', 'deal-scraper-plugin'); ?><span class="dsp-sort-indicator"></span></th>
                        <th data-sort-key="first_seen"><?php esc_html_e('Date Seen', 'deal-scraper-plugin'); ?><span class="dsp-sort-indicator"></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php // --- Populate Table Body Directly --- ?>
                    <?php if ( ! empty( $processed_deals ) ) : ?>
                        <?php foreach ( $processed_deals as $index => $deal ) : ?>
                            <?php
                               // Determine CSS classes
                               $row_classes = ['dsp-deal-row'];
                               if ($deal->is_new) { $row_classes[] = 'dsp-new-item'; }
                               if ($deal->is_lifetime) { $row_classes[] = 'dsp-lifetime-item'; }
                               $row_classes[] = ($index % 2 === 0) ? 'dsp-even-row' : 'dsp-odd-row';

                               // Attempt to parse price for sorting attribute
                                $sortable_price = 'Infinity'; // Default if not parsable
                                if(isset($deal->price)) {
                                    $price_str = strtolower(trim(strval($deal->price)));
                                    if (strpos($price_str, 'free') !== false || $price_str === '0' || $price_str === '0.00') {
                                        $sortable_price = '0';
                                    } else {
                                         if (preg_match('/(\d+(\.\d+)?)/', str_replace(',', '', $price_str), $matches)) {
                                             $sortable_price = $matches[1];
                                         }
                                    }
                                }
                                // Get timestamp for sorting attribute
                                $first_seen_timestamp = isset($deal->first_seen) ? strtotime($deal->first_seen) : 0;
                                if ($first_seen_timestamp === false) $first_seen_timestamp = 0; // Handle parse failure

                            ?>
                            <tr
                                class="<?php echo esc_attr( implode(' ', $row_classes) ); ?>"
                                data-source="<?php echo esc_attr( $deal->source ?? '' ); ?>"
                                data-title="<?php echo esc_attr( $deal->title ?? '' ); ?>"
                                data-description="<?php echo esc_attr( $deal->description ?? '' ); ?>"
                                data-is-new="<?php echo esc_attr( $deal->is_new ? '1' : '0' ); ?>"
                                data-first-seen="<?php echo esc_attr( $first_seen_timestamp ); ?>"
                                data-price="<?php echo esc_attr( $sortable_price ); ?>"
                                <?php // Add link as a data attribute for potential JS use ?>
                                data-link="<?php echo esc_url( $deal->link ?? '#' ); ?>"
                            >
                                <td class="dsp-cell-new"><?php echo $deal->is_new ? esc_html__('Yes', 'deal-scraper-plugin') : esc_html__('No', 'deal-scraper-plugin'); ?></td>
                                <td class="dsp-cell-title">
                                    <a href="<?php echo esc_url( $deal->link ?? '#' ); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo esc_html( $deal->title ?? 'N/A' ); ?>
                                    </a>
                                    <?php if ($deal->is_lifetime): ?>
                                        <span class="dsp-lifetime-badge" title="<?php esc_attr_e('Lifetime Deal', 'deal-scraper-plugin'); ?>">LTD</span>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $deal->description ) ) : ?>
                                        <p class="dsp-description"><?php echo esc_html( $deal->description ); ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="dsp-cell-price"><?php echo esc_html( $deal->price ?? 'N/A' ); ?></td>
                                <td class="dsp-cell-source"><?php echo esc_html( $deal->source ?? 'N/A' ); ?></td>
                                <td class="dsp-cell-date" data-timestamp="<?php echo esc_attr( $first_seen_timestamp ); ?>">
                                    <?php echo esc_html( $deal->first_seen_formatted ); // Use pre-formatted date ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <?php // Show message if no deals were found in the DB initially ?>
                        <tr class="dsp-no-deals-row">
                            <td colspan="5"><?php esc_html_e('No deals found yet. Checking for updates...', 'deal-scraper-plugin'); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php // REMOVED the explicit "Loading deals..." row ?>
                </tbody>
            </table>
        </div> <?php // *** END TABLE WRAPPER DIV *** ?>

        <?php // --- Modals (Donate, Subscribe) --- ?>
        <?php // Donate Modal HTML (use v1.0.4 structure with copy code) ?>
        <div id="dsp-donate-modal" class="dsp-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="dsp-donate-title">
             <div class="dsp-modal-content">
                 <button class="dsp-modal-close" aria-label="<?php esc_attr_e('Close donation dialog', 'deal-scraper-plugin'); ?>">×</button>
                 <h2 id="dsp-donate-title"><?php esc_html_e('Support This Plugin', 'deal-scraper-plugin'); ?></h2>
                 <p><?php esc_html_e('If you find this plugin useful, please consider supporting its development via crypto.', 'deal-scraper-plugin'); ?></p>
                 <div class="dsp-donate-images">
                     <div class="dsp-donate-item">
                         <p><strong><?php esc_html_e('USDT (ERC20)', 'deal-scraper-plugin'); ?></strong></p>
                         <img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/usdt_qr.jpg'); ?>" alt="<?php esc_attr_e('USDT ERC20 QR Code', 'deal-scraper-plugin'); ?>">
                         <code class="dsp-copy-code" title="<?php esc_attr_e('Click to copy address', 'deal-scraper-plugin'); ?>">0x3969A4fA61d66e078D268758c3a64408e1B16688</code>
                     </div>
                     <div class="dsp-donate-item">
                         <p><strong><?php esc_html_e('BNB (BEP2 / Native)', 'deal-scraper-plugin'); ?></strong></p>
                         <img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/bnb_qr.jpg'); ?>" alt="<?php esc_attr_e('BNB QR Code', 'deal-scraper-plugin'); ?>">
                          <code class="dsp-copy-code" title="<?php esc_attr_e('Click to copy address', 'deal-scraper-plugin'); ?>">bnb1wcxj5tr7srfpyqagjxf6e92qyc9n83xfsp3y58</code>
                     </div>
                     <div class="dsp-donate-item">
                         <p><strong><?php esc_html_e('Bitcoin (BTC)', 'deal-scraper-plugin'); ?></strong></p>
                         <img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/btc_qr.jpg'); ?>" alt="<?php esc_attr_e('Bitcoin QR Code', 'deal-scraper-plugin'); ?>">
                         <code class="dsp-copy-code" title="<?php esc_attr_e('Click to copy address', 'deal-scraper-plugin'); ?>">bc1q3k0h6uf3yx9th02vt5ek5hyl6dlhv8sdnygyt9</code>
                     </div>
                 </div>
                  <p class="dsp-copy-feedback" aria-live="polite"></p>
                  <p class="dsp-thank-you"><?php esc_html_e('Thank you for your support!', 'deal-scraper-plugin'); ?></p>
             </div>
         </div>


        <?php // Subscribe Modal HTML (use v1.0.4 structure) ?>
        <?php if ($email_enabled) : ?>
        <div id="dsp-subscribe-modal" class="dsp-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="dsp-subscribe-title">
            <div class="dsp-modal-content dsp-subscribe-modal-content">
                 <button class="dsp-modal-close dsp-subscribe-modal-close" aria-label="<?php esc_attr_e('Close subscription dialog', 'deal-scraper-plugin'); ?>">×</button>
                <h2 id="dsp-subscribe-title"><?php esc_html_e('Subscribe to New Deals', 'deal-scraper-plugin'); ?></h2>
                <p><?php esc_html_e('Enter your email address to receive notifications about new deals.', 'deal-scraper-plugin'); ?></p>
                <div class="dsp-subscribe-form">
                     <label for="dsp-subscribe-email-input" class="screen-reader-text"><?php esc_html_e('Email Address', 'deal-scraper-plugin'); ?></label>
                     <input type="email" id="dsp-subscribe-email-input" placeholder="<?php esc_attr_e('Your email address', 'deal-scraper-plugin'); ?>" required>
                     <button id="dsp-subscribe-submit-button" class="dsp-button"><?php esc_html_e('Subscribe', 'deal-scraper-plugin'); ?></button>
                     <span id="dsp-subscribe-spinner" class="dsp-spinner" style="visibility: hidden;"></span>
                </div>
                <div id="dsp-subscribe-message" class="dsp-subscribe-status" aria-live="polite"></div>
            </div>
        </div>
        <?php endif; ?>


    </div> <?php // End dsp-container ?>
    <?php
    // Return buffered content
    return ob_get_clean();
}

// Ensure helper function dsp_is_lifetime_deal_php is available (defined in main plugin file)
?>