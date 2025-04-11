<?php
// File: includes/shortcode-handler.php (v1.1.16 - Adjust initial empty message)

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Renders the deal display shortcode.
 * Fetches only the FIRST PAGE of existing deals directly and renders them,
 * then relies on JS for pagination and manual refreshes.
 */
function dsp_render_shortcode( $atts ) {
    // --- Get Plugin Settings ---
    $options = get_option( DSP_OPTION_NAME ); $defaults = dsp_get_default_config(); $merged_options = wp_parse_args($options, $defaults);
    $refresh_access = $merged_options['refresh_button_access']; $show_debug_button = (bool) $merged_options['show_debug_button']; $email_enabled = (bool) $merged_options['email_enabled'];
    $show_refresh_button = false; switch ( $refresh_access ) { case 'all': $show_refresh_button = true; break; case 'logged_in': if ( is_user_logged_in() ) { $show_refresh_button = true; } break; case 'admins': if ( current_user_can( 'manage_options' ) ) { $show_refresh_button = true; } break; }

    // --- Fetch Initial Data (Page 1 ONLY, default sort) ---
    $items_per_page = defined('DSP_ITEMS_PER_PAGE') ? DSP_ITEMS_PER_PAGE : 25;
    // Fetch page 1 sorted by first_seen desc by default for initial render
    $initial_db_data = DSP_DB_Handler::get_deals([
        'orderby' => 'first_seen',
        'order' => 'DESC',
        'items_per_page' => $items_per_page,
        'page' => 1
    ]);
    $deals_page_1 = $initial_db_data['deals'] ?? [];

    $last_fetch_time = get_option('dsp_last_fetch_time', 0);
    $last_fetch_display = $last_fetch_time ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), $last_fetch_time ) : __('Never', 'deal-scraper-plugin');

    // --- Process deals for immediate display ---
    $processed_deals_page_1 = [];
    if ($deals_page_1) {
        // Use the common processing function
        $processed_deals_page_1 = dsp_process_deals_for_ajax($deals_page_1, $last_fetch_time);
    }

    // --- Start output buffering ---
    ob_start();
    ?>
    <div id="dsp-deal-display-container" class="dsp-container">
        <?php // Filters ?>
        <div class="dsp-filters">
            <div class="dsp-filter-item">
                <label for="dsp-search-input"><?php esc_html_e('Search:', 'deal-scraper-plugin'); ?></label>
                <input type="text" id="dsp-search-input" placeholder="<?php esc_attr_e('Search...', 'deal-scraper-plugin'); ?>">
            </div>
            <div class="dsp-filter-item dsp-filter-checkboxes">
                <span><?php esc_html_e('Sources:', 'deal-scraper-plugin'); ?></span>
                <div id="dsp-source-checkboxes"></div>
            </div>
            <div class="dsp-filter-item dsp-filter-checkboxes">
                <label><input type="checkbox" id="dsp-new-only-checkbox"> <?php esc_html_e('New Only', 'deal-scraper-plugin'); ?></label>
                 <label style="margin-left: 15px;"><input type="checkbox" id="dsp-ltd-only-checkbox"> <?php esc_html_e('LTD Only', 'deal-scraper-plugin'); ?></label>
            </div>
            <div class="dsp-filter-item dsp-filter-price-range">
                 <label><?php esc_html_e('Price:', 'deal-scraper-plugin'); ?></label>
                 <input type="number" id="dsp-min-price-input" min="0" step="any" placeholder="<?php esc_attr_e('Min', 'deal-scraper-plugin'); ?>" class="dsp-price-input">
                 <span>-</span>
                 <input type="number" id="dsp-max-price-input" min="0" step="any" placeholder="<?php esc_attr_e('Max', 'deal-scraper-plugin'); ?>" class="dsp-price-input">
            </div>
            <?php if ( $show_refresh_button ) : ?>
            <div class="dsp-filter-item">
                <button id="dsp-refresh-button" class="dsp-button"><?php esc_html_e('Refresh Now', 'deal-scraper-plugin'); ?></button>
                <span id="dsp-refresh-spinner" class="dsp-spinner" style="visibility: hidden;"></span>
                <span id="dsp-refresh-message" class="dsp-refresh-status"></span>
            </div>
            <?php endif; ?>
        </div>
        <?php // Controls ?>
        <div class="dsp-debug-controls"><?php if ( $show_debug_button ) : ?><button id="dsp-toggle-debug-log" class="dsp-button dsp-button-secondary"><?php esc_html_e('Show Debug Log', 'deal-scraper-plugin'); ?></button><?php endif; ?><button id="dsp-donate-button" class="dsp-button dsp-button-secondary"><?php esc_html_e('Donate', 'deal-scraper-plugin'); ?></button><?php if ($email_enabled) : ?><button id="dsp-subscribe-button" class="dsp-button dsp-button-secondary"><?php esc_html_e('Subscribe', 'deal-scraper-plugin'); ?></button><?php endif; ?></div>
        <?php // Debug Log Container ?>
        <?php if ( $show_debug_button ) : ?><div id="dsp-debug-log-container" style="display: none;"><h4><?php esc_html_e('Refresh Debug Log:', 'deal-scraper-plugin'); ?></h4><pre id="dsp-debug-log"></pre></div><?php endif; ?>
        <?php // Background Update Notice Placeholder ?>
        <div id="dsp-background-update-notice" class="dsp-update-notice" style="display: none;" aria-live="polite"></div>
        <?php // Status Bar ?>
        <div class="dsp-status-bar">
             <?php // Initial Status Text (JS will update this) ?>
             <span id="dsp-status-message"><?php esc_html_e('Loading deal info...', 'deal-scraper-plugin'); ?></span>
             <span id="dsp-last-updated" style="float: right; visibility: <?php echo $last_fetch_time ? 'visible' : 'hidden'; ?>;"><?php esc_html_e('Last fetched:', 'deal-scraper-plugin'); ?> <span id="dsp-last-updated-time"><?php echo esc_html($last_fetch_display); ?></span></span>
        </div>
        <?php // Table Wrapper ?>
        <div class="dsp-table-wrapper">
            <table id="dsp-deals-table" class="dsp-table">
                <thead><tr><th data-sort-key="is_new"><?php esc_html_e('New', 'deal-scraper-plugin'); ?><span class="dsp-sort-indicator"></span></th><th data-sort-key="title"><?php esc_html_e('Title / Description', 'deal-scraper-plugin'); ?><span class="dsp-sort-indicator"></span></th><th data-sort-key="price"><?php esc_html_e('Price', 'deal-scraper-plugin'); ?><span class="dsp-sort-indicator"></span></th><th data-sort-key="source"><?php esc_html_e('Source', 'deal-scraper-plugin'); ?><span class="dsp-sort-indicator"></span></th><th data-sort-key="first_seen"><?php esc_html_e('Date Seen', 'deal-scraper-plugin'); ?><span class="dsp-sort-indicator"></span></th></tr></thead>
                <tbody>
                    <?php if ( ! empty( $processed_deals_page_1 ) ) : ?>
                        <?php foreach ( $processed_deals_page_1 as $index => $deal ) : ?>
                            <?php // Render the deal row (same as before)
                               $row_classes = ['dsp-deal-row']; if ($deal->is_new) { $row_classes[] = 'dsp-new-item'; } if ($deal->is_lifetime) { $row_classes[] = 'dsp-lifetime-item'; } $row_classes[] = ($index % 2 === 0) ? 'dsp-even-row' : 'dsp-odd-row';
                               $sortable_price = 'Infinity'; if(isset($deal->price)) { $price_str = strtolower(trim(strval($deal->price))); if (strpos($price_str, 'free') !== false || $price_str === '0' || $price_str === '0.00') { $sortable_price = '0'; } else { if (preg_match('/(\d+(\.\d+)?)/', str_replace(',', '', $price_str), $matches)) { $sortable_price = $matches[1]; } } }
                               $first_seen_timestamp = $deal->first_seen_ts ?? 0;
                            ?>
                            <tr class="<?php echo esc_attr( implode(' ', $row_classes) ); ?>" data-source="<?php echo esc_attr( $deal->source ?? '' ); ?>" data-title="<?php echo esc_attr( $deal->title ?? '' ); ?>" data-description="<?php echo esc_attr( $deal->description ?? '' ); ?>" data-is-new="<?php echo esc_attr( $deal->is_new ? '1' : '0' ); ?>" data-is-ltd="<?php echo esc_attr( $deal->is_lifetime ? '1' : '0' ); ?>" data-first-seen="<?php echo esc_attr( $first_seen_timestamp ); ?>" data-price="<?php echo esc_attr( $sortable_price ); ?>" data-link="<?php echo esc_url( $deal->link ?? '#' ); ?>">
                                <td class="dsp-cell-new"><?php echo $deal->is_new ? esc_html__('Yes', 'deal-scraper-plugin') : esc_html__('No', 'deal-scraper-plugin'); ?></td>
                                <td class="dsp-cell-title"><a href="<?php echo esc_url( $deal->link ?? '#' ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $deal->title ?? 'N/A' ); ?></a><?php if ($deal->is_lifetime): ?><span class="dsp-lifetime-badge" title="<?php esc_attr_e('Lifetime Deal', 'deal-scraper-plugin'); ?>">LTD</span><?php endif; ?><?php if ( ! empty( $deal->description ) ) : ?><p class="dsp-description"><?php echo esc_html( $deal->description ); ?></p><?php endif; ?></td>
                                <td class="dsp-cell-price"><?php echo esc_html( $deal->price ?? 'N/A' ); ?></td>
                                <td class="dsp-cell-source"><?php echo esc_html( $deal->source ?? 'N/A' ); ?></td>
                                <td class="dsp-cell-date" data-timestamp="<?php echo esc_attr( $first_seen_timestamp ); ?>"><?php echo esc_html( $deal->first_seen_formatted ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <?php // *** MODIFIED: Show "Loading..." initially if no deals on page 1 *** ?>
                        <tr class="dsp-loading-row">
                            <td colspan="5"><?php esc_html_e('Loading deals...', 'deal-scraper-plugin'); ?></td>
                        </tr>
                         <?php // --- END Modification --- ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div> <?php // End Table Wrapper ?>
        <?php // Pagination Placeholder ?>
        <div id="dsp-pagination-controls" class="dsp-pagination"></div>
        <?php // Modals (Donate, Subscribe) ?>
        <div id="dsp-donate-modal" class="dsp-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="dsp-donate-title"> <div class="dsp-modal-content"> <button class="dsp-modal-close" aria-label="<?php esc_attr_e('Close donation dialog', 'deal-scraper-plugin'); ?>">×</button> <h2 id="dsp-donate-title"><?php esc_html_e('Support This Plugin', 'deal-scraper-plugin'); ?></h2> <p><?php esc_html_e('If you find this plugin useful, please consider supporting its development via crypto.', 'deal-scraper-plugin'); ?></p> <div class="dsp-donate-images"> <div class="dsp-donate-item"> <p><strong><?php esc_html_e('USDT (ERC20)', 'deal-scraper-plugin'); ?></strong></p> <img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/usdt_qr.jpg'); ?>" alt="<?php esc_attr_e('USDT ERC20 QR Code', 'deal-scraper-plugin'); ?>"> <code class="dsp-copy-code" title="<?php esc_attr_e('Click to copy address', 'deal-scraper-plugin'); ?>">0x3969A4fA61d66e078D268758c3a64408e1B16688</code> </div> <div class="dsp-donate-item"> <p><strong><?php esc_html_e('BNB (BEP2 / Native)', 'deal-scraper-plugin'); ?></strong></p> <img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/bnb_qr.jpg'); ?>" alt="<?php esc_attr_e('BNB QR Code', 'deal-scraper-plugin'); ?>"> <code class="dsp-copy-code" title="<?php esc_attr_e('Click to copy address', 'deal-scraper-plugin'); ?>">bnb1wcxj5tr7srfpyqagjxf6e92qyc9n83xfsp3y58</code> </div> <div class="dsp-donate-item"> <p><strong><?php esc_html_e('Bitcoin (BTC)', 'deal-scraper-plugin'); ?></strong></p> <img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/btc_qr.jpg'); ?>" alt="<?php esc_attr_e('Bitcoin QR Code', 'deal-scraper-plugin'); ?>"> <code class="dsp-copy-code" title="<?php esc_attr_e('Click to copy address', 'deal-scraper-plugin'); ?>">bc1q3k0h6uf3yx9th02vt5ek5hyl6dlhv8sdnygyt9</code> </div> </div> <p class="dsp-copy-feedback" aria-live="polite"></p> <p class="dsp-thank-you"><?php esc_html_e('Thank you for your support!', 'deal-scraper-plugin'); ?></p> </div> </div>
        <?php if ($email_enabled) : ?><div id="dsp-subscribe-modal" class="dsp-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="dsp-subscribe-title"> <div class="dsp-modal-content dsp-subscribe-modal-content"> <button class="dsp-modal-close dsp-subscribe-modal-close" aria-label="<?php esc_attr_e('Close subscription dialog', 'deal-scraper-plugin'); ?>">×</button> <h2 id="dsp-subscribe-title"><?php esc_html_e('Subscribe to New Deals', 'deal-scraper-plugin'); ?></h2> <p><?php esc_html_e('Enter your email address to receive notifications about new deals.', 'deal-scraper-plugin'); ?></p> <div class="dsp-subscribe-form"> <label for="dsp-subscribe-email-input" class="screen-reader-text"><?php esc_html_e('Email Address', 'deal-scraper-plugin'); ?></label> <input type="email" id="dsp-subscribe-email-input" placeholder="<?php esc_attr_e('Your email address', 'deal-scraper-plugin'); ?>" required> <button id="dsp-subscribe-submit-button" class="dsp-button"><?php esc_html_e('Subscribe', 'deal-scraper-plugin'); ?></button> <span id="dsp-subscribe-spinner" class="dsp-spinner" style="visibility: hidden;"></span> </div> <div id="dsp-subscribe-message" class="dsp-subscribe-status" aria-live="polite"></div> </div> </div><?php endif; ?>
    </div> <?php // End dsp-container ?>
    <?php
    return ob_get_clean();
}
?>