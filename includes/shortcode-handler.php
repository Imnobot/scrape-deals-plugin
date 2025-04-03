<?php
// File: includes/shortcode-handler.php (MODIFIED for Show Debug Button Option)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the deal display shortcode.
 * Fetches existing deals directly from the DB and renders them,
 * then relies on JS for subsequent background checks/refreshes.
 */
function dsp_render_shortcode( $atts ) {
    // Fetch deals and last fetch time immediately
    $deals = DSP_DB_Handler::get_deals(); // Using default sort (first_seen DESC)
    $last_fetch_time = get_option('dsp_last_fetch_time', 0);
    // Use the localized 'Never' text if possible, provide a fallback
    $never_text = function_exists('wp_localize_script') ? esc_html__( 'Never', 'deal-scraper-plugin' ) : 'Never'; // Text for 'Never'
    $last_fetch_display = $last_fetch_time ? date('Y-m-d H:i:s', $last_fetch_time) : $never_text;

    // Process deals for immediate display
    $processed_deals = [];
    if ($deals) {
        foreach ($deals as $deal) {
            // Basic validation of the deal object/data
            if (is_object($deal) && !empty($deal->link) && !empty($deal->title) && isset($deal->first_seen)) {
                 $first_seen_ts = strtotime($deal->first_seen);
                 // Determine if the deal is "new" based on the last fetch time
                 $deal->is_new = ($first_seen_ts && $last_fetch_time && $first_seen_ts >= $last_fetch_time);
                 // Format the date for display
                 $deal->first_seen_formatted = $first_seen_ts ? date('Y-m-d H:i', $first_seen_ts) : 'N/A';
                 // Check if it's a lifetime deal (helper function should be available)
                 $deal->is_lifetime = function_exists('dsp_is_lifetime_deal_php') ? dsp_is_lifetime_deal_php($deal) : false;
                 $processed_deals[] = $deal; // Add the processed deal to our array
            }
        }
    }

    // Start output buffering
    ob_start();
    ?>
    <div id="dsp-deal-display-container" class="dsp-container">

        
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
            <div class="dsp-filter-item">
                 <button id="dsp-refresh-button" class="dsp-button"><?php esc_html_e('Refresh Now', 'deal-scraper-plugin'); ?></button>
                 <span id="dsp-refresh-spinner" class="dsp-spinner" style="visibility: hidden;"></span>
                 <span id="dsp-refresh-message" class="dsp-refresh-status"></span>
            </div>
        </div>

        
        <div class="dsp-debug-controls">
             <?php
             // --- Get the show debug button setting ---
             $options = get_option( DSP_OPTION_NAME );
             // Default to true (show button) if the setting isn't saved yet
             $show_debug_button = isset( $options['show_debug_button'] ) ? (bool) $options['show_debug_button'] : true;

             // --- Conditionally render the debug button ---
             if ( $show_debug_button ) :
             ?>
                <button id="dsp-toggle-debug-log" class="dsp-button dsp-button-secondary"><?php esc_html_e('Show Debug Log', 'deal-scraper-plugin'); ?></button>
             <?php
             endif; // End conditional rendering for debug button

             // Donate button is always shown (currently)
             ?>
             <button id="dsp-donate-button" class="dsp-button dsp-button-secondary"><?php esc_html_e('Donate', 'deal-scraper-plugin'); ?></button>
        </div>
        



        <div id="dsp-debug-log-container" style="display: none;">
            <h4><?php esc_html_e('Refresh Debug Log:', 'deal-scraper-plugin'); ?></h4>
            <pre id="dsp-debug-log"></pre>
        </div>

        
        <div class="dsp-status-bar">
            <span id="dsp-status-message"><?php esc_html_e('Loading initial deals...', 'deal-scraper-plugin'); // JS will update this ?></span>
            <span id="dsp-last-updated" style="float: right; <?php echo $last_fetch_time === 0 ? 'visibility: hidden;' : ''; ?>">
                <?php esc_html_e('Last fetched:', 'deal-scraper-plugin'); ?>
                <span id="dsp-last-updated-time"><?php echo esc_html($last_fetch_display); ?></span>
            </span>
        </div>

        
        <table id="dsp-deals-table" class="dsp-table">
            <thead>
                <tr>
                    <th data-sort-key="is_new"><?php esc_html_e('New', 'deal-scraper-plugin'); ?><span class="dsp-sort-indicator"></span></th>
                    <th data-sort-key="title"><?php esc_html_e('Title / Description', 'deal-scraper-plugin'); ?><span class="dsp-sort-indicator"></span></th>
                    <th data-sort-key="price"><?php esc_html_e('Price', 'deal-scraper-plugin'); ?><span class="dsp-sort-indicator"></span></th>
                    <th data-sort-key="source"><?php esc_html_e('Source', 'deal-scraper-plugin'); ?><span class="dsp-sort-indicator"></span></th>
                    <th data-sort-key="first_seen"><?php esc_html_e('Date Seen', 'deal-scraper-plugin'); ?><span class="dsp-sort-indicator"></span></th>
                </tr>
            </thead>
            <tbody>
                <?php // Loop through processed deals and render rows
                if ( ! empty( $processed_deals ) ) : ?>
                    <?php foreach ( $processed_deals as $deal ) : ?>
                        <?php
                           // Prepare row classes
                           $row_class = 'dsp-deal-row';
                           if ( $deal->is_new ) $row_class .= ' dsp-new-deal';
                           if ( $deal->is_lifetime ) $row_class .= ' dsp-lifetime-deal';

                           // Prepare numeric price for data attribute
                           $numeric_price = PHP_FLOAT_MAX;
                           if (isset($deal->price)) {
                               if (stripos($deal->price, 'free') !== false) {
                                   $numeric_price = 0;
                               } else {
                                   $cleaned_price = preg_replace('/[^0-9.]/', '', $deal->price);
                                   if (is_numeric($cleaned_price)) {
                                       $numeric_price = floatval($cleaned_price);
                                   }
                               }
                           }
                           $first_seen_timestamp = strtotime($deal->first_seen) ?: 0;
                        ?>
                        <tr
                            class="<?php echo esc_attr($row_class); ?>"
                            data-source="<?php echo esc_attr( $deal->source ?? '' ); ?>"
                            data-title="<?php echo esc_attr( $deal->title ?? '' ); ?>"
                            data-description="<?php echo esc_attr( $deal->description ?? '' ); ?>"
                            data-is-new="<?php echo esc_attr( $deal->is_new ? '1' : '0' ); ?>"
                            data-first-seen="<?php echo esc_attr( $first_seen_timestamp ); ?>"
                            data-price="<?php echo esc_attr($numeric_price); ?>"
                        >
                            <td class="dsp-cell-new"><?php echo $deal->is_new ? '<span class="dsp-new-badge" title="' . esc_attr__('Added in last fetch', 'deal-scraper-plugin') . '">★</span>' : ''; ?></td>
                            <td class="dsp-cell-title">
                                <a href="<?php echo esc_url( $deal->url ?? '#' ); ?>" target="_blank" rel="noopener noreferrer">
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
                                <?php echo esc_html( $deal->first_seen_formatted ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    
                    <tr class="dsp-no-deals-initial">
                        <td colspan="5"><?php esc_html_e('No deals found in database yet. Checking for new deals now...', 'deal-scraper-plugin'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        


        
        <div id="dsp-donate-modal" class="dsp-modal" style="display: none;">
            <div class="dsp-modal-content">
                <span class="dsp-modal-close">×</span>
                <h2><?php esc_html_e('Support This Plugin', 'deal-scraper-plugin'); ?></h2>
                <p><?php esc_html_e('If you find this plugin useful, please consider supporting its development via crypto.', 'deal-scraper-plugin'); ?></p>
                <div class="dsp-donate-images">
                    <div class="dsp-donate-item">
                        <p><strong><?php esc_html_e('USDT (ERC20)', 'deal-scraper-plugin'); ?></strong></p>
                        <img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/usdt_qr.jpg'); ?>" alt="<?php esc_attr_e('USDT ERC20 QR Code', 'deal-scraper-plugin'); ?>">
                        <code>ethereum:0x3969A4fA61d66e078D268758c3a64408e1B16688?req-asset=0xdac17f958d2ee523a2206206994597c13d831ec7</code>
                    </div>
                    <div class="dsp-donate-item">
                         <p><strong><?php esc_html_e('BNB (BEP2 / Native)', 'deal-scraper-plugin'); ?></strong></p>
                        <img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/bnb_qr.jpg'); ?>" alt="<?php esc_attr_e('BNB QR Code', 'deal-scraper-plugin'); ?>">
                         <code>bnb:bnb1wcxj5tr7srfpyqagjxf6e92qyc9n83xfsp3y58</code>
                    </div>
                    <div class="dsp-donate-item">
                         <p><strong><?php esc_html_e('Bitcoin (BTC)', 'deal-scraper-plugin'); ?></strong></p>
                        <img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/btc_qr.jpg'); ?>" alt="<?php esc_attr_e('Bitcoin QR Code', 'deal-scraper-plugin'); ?>">
                        <code>bitcoin:bc1q3k0h6uf3yx9th02vt5ek5hyl6dlhv8sdnygyt9</code>
                    </div>
                </div>
                 <p style="margin-top: 20px; font-style: italic; text-align: center;"><?php esc_html_e('Thank you for your support!', 'deal-scraper-plugin'); ?></p>
            </div>
        </div>
        

    </div> <?php // End dsp-container ?>
    <?php
    // Return buffered content
    return ob_get_clean();
}

?>