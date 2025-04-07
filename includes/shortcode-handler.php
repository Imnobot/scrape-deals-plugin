<?php
// File: includes/shortcode-handler.php (v1.3.3 - Add Overlay Placeholder)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function dsp_render_shortcode( $atts ) {
    // --- Process Shortcode Attributes ---
    $default_atts = [
        'layout' => 'table', // Default layout is 'table'
    ];
    $atts = shortcode_atts( $default_atts, $atts, 'deal_scraper_display' );
    $allowed_layouts = ['table', 'grid'];
    $layout = in_array( $atts['layout'], $allowed_layouts, true ) ? $atts['layout'] : 'table';

    // --- Get Plugin Settings ---
    $options = get_option( DSP_OPTION_NAME );
    $defaults = function_exists('dsp_get_default_config') ? dsp_get_default_config() : []; // Ensure helper exists
    $merged_options = wp_parse_args($options, $defaults);

    $refresh_access = $merged_options['refresh_button_access'] ?? 'all'; // Use default if not set
    $show_debug_button = isset($merged_options['show_debug_button']) ? (bool) $merged_options['show_debug_button'] : true; // Default true
    $email_enabled = isset($merged_options['email_enabled']) ? (bool) $merged_options['email_enabled'] : false;

    // --- Determine Refresh Button Visibility ---
    $show_refresh_button = false;
    switch ( $refresh_access ) {
        case 'all': $show_refresh_button = true; break;
        case 'logged_in': if ( is_user_logged_in() ) { $show_refresh_button = true; } break;
        case 'admins': if ( current_user_can( 'manage_options' ) ) { $show_refresh_button = true; } break;
        case 'disabled': default: $show_refresh_button = false; break;
    }

    // Start output buffering
    ob_start();
    ?>
    <div id="dsp-deal-display-container" class="dsp-container dsp-layout-<?php echo esc_attr($layout); ?>">

        <?php // --- Filters --- ?>
        <div class="dsp-filters">
            <div class="dsp-filter-item">
                <label for="dsp-search-input"><?php esc_html_e('Search:', 'deal-scraper-plugin'); ?></label>
                <input type="text" id="dsp-search-input" placeholder="<?php esc_attr_e('Search title, description...', 'deal-scraper-plugin'); ?>">
            </div>
             <div class="dsp-filter-item dsp-filter-sources">
                 <span><?php esc_html_e('Sources:', 'deal-scraper-plugin'); ?></span>
                 <div id="dsp-source-checkboxes"><!-- Populated by JS --></div>
             </div>
             <div class="dsp-filter-item dsp-filter-checkboxes">
                 <label>
                     <input type="checkbox" id="dsp-new-only-checkbox">
                     <?php esc_html_e('New Only', 'deal-scraper-plugin'); ?>
                 </label>
                 <label>
                     <input type="checkbox" id="dsp-ltd-only-checkbox">
                     <?php esc_html_e('Lifetime Only', 'deal-scraper-plugin'); ?>
                 </label>
             </div>
             <div class="dsp-filter-item dsp-filter-price">
                 <label for="dsp-min-price-input"><?php esc_html_e('Price:', 'deal-scraper-plugin'); ?></label>
                 <input type="number" id="dsp-min-price-input" min="0" step="any" placeholder="<?php esc_attr_e('Min', 'deal-scraper-plugin'); ?>" class="dsp-price-input">
                 <span>-</span>
                 <input type="number" id="dsp-max-price-input" min="0" step="any" placeholder="<?php esc_attr_e('Max', 'deal-scraper-plugin'); ?>" class="dsp-price-input">
             </div>
            <?php if ( $show_refresh_button ) : ?>
            <div class="dsp-filter-item dsp-filter-actions">
                 <button id="dsp-refresh-button" class="dsp-button"><?php esc_html_e('Refresh Now', 'deal-scraper-plugin'); ?></button>
                 <span id="dsp-refresh-spinner" class="dsp-spinner" style="visibility: hidden;"></span>
                 <span id="dsp-refresh-message" class="dsp-refresh-status"></span>
            </div>
            <?php endif; ?>
        </div>

        <?php // --- Debug/Action Buttons --- ?>
        <div class="dsp-debug-controls">
             <?php if ( $show_debug_button ) : ?>
                <button id="dsp-toggle-debug-log" class="dsp-button dsp-button-secondary"><?php esc_html_e('Show Debug Log', 'deal-scraper-plugin'); ?></button>
             <?php endif; ?>
             <button id="dsp-donate-button" class="dsp-button dsp-button-secondary"><?php esc_html_e('Donate', 'deal-scraper-plugin'); ?></button>
             <?php if ($email_enabled) : ?>
                <button id="dsp-subscribe-button" class="dsp-button dsp-button-secondary"><?php esc_html_e('Subscribe', 'deal-scraper-plugin'); ?></button>
             <?php endif; ?>
        </div>

        <?php // --- Debug Log Container --- ?>
        <div id="dsp-debug-log-container" style="display: none;">
            <h4><?php esc_html_e('Refresh Debug Log:', 'deal-scraper-plugin'); ?></h4>
            <pre id="dsp-debug-log"></pre>
        </div>

        <?php // --- Status Bar --- ?>
        <div class="dsp-status-bar">
            <span id="dsp-status-message"><?php /* Status set by JS */ ?></span>
            <span id="dsp-last-updated" style="float: right; visibility: hidden;">
                 <?php esc_html_e('Last fetched:', 'deal-scraper-plugin'); ?> <span id="dsp-last-updated-time"></span>
            </span>
        </div>

        <?php // --- Grid Sort Dropdown --- ?>
        <?php if ($layout === 'grid') : ?>
            <div class="dsp-grid-controls">
                 <label for="dsp-grid-sort-select"><?php esc_html_e('Sort by:', 'deal-scraper-plugin'); ?></label>
                 <select id="dsp-grid-sort-select">
                     <option value="first_seen|desc"><?php esc_html_e('Date Seen (Newest First)', 'deal-scraper-plugin'); ?></option>
                     <option value="first_seen|asc"><?php esc_html_e('Date Seen (Oldest First)', 'deal-scraper-plugin'); ?></option>
                     <option value="price|asc"><?php esc_html_e('Price (Low to High)', 'deal-scraper-plugin'); ?></option>
                     <option value="price|desc"><?php esc_html_e('Price (High to Low)', 'deal-scraper-plugin'); ?></option>
                     <option value="title|asc"><?php esc_html_e('Title (A-Z)', 'deal-scraper-plugin'); ?></option>
                     <option value="title|desc"><?php esc_html_e('Title (Z-A)', 'deal-scraper-plugin'); ?></option>
                     <option value="source|asc"><?php esc_html_e('Source (A-Z)', 'deal-scraper-plugin'); ?></option>
                 </select>
            </div>
        <?php endif; ?>

        <?php // --- Deals Container Wrapper --- ?>
        <div class="dsp-deals-wrapper">
            <?php // --- The actual container for deals --- ?>
            <?php if ($layout === 'grid') : ?>
                <div id="dsp-deals-grid-container" class="dsp-grid">
                    <?php // Start empty - JS/Overlay handles loading state ?>
                </div>
            <?php else : ?>
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
                         <?php // Start empty - JS/Overlay handles loading state ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php // --- Loading Overlay (Initially Hidden) --- ?>
            <div class="dsp-loading-overlay">
                <span class="dsp-loading-spinner spinner is-active"></span>
            </div>

        </div> <?php // End dsp-deals-wrapper ?>

        <?php // --- Pagination Controls Placeholder --- ?>
        <div id="dsp-pagination-controls" class="dsp-pagination-controls" style="display: none;">
             <button id="dsp-prev-page" class="dsp-button dsp-button-secondary" disabled>« <?php esc_html_e('Previous', 'deal-scraper-plugin'); ?></button>
             <span id="dsp-page-indicator" class="dsp-page-indicator"></span>
             <button id="dsp-next-page" class="dsp-button dsp-button-secondary" disabled><?php esc_html_e('Next', 'deal-scraper-plugin'); ?> »</button>
        </div>

        <?php // --- Modals --- ?>
        <?php /* ... Donate Modal HTML ... */ ?>
        <div id="dsp-donate-modal" class="dsp-modal" style="display: none;"> <div class="dsp-modal-content"> <span class="dsp-modal-close">×</span> <h2><?php esc_html_e('Support This Plugin', 'deal-scraper-plugin'); ?></h2> <p><?php esc_html_e('If you find this plugin useful, please consider supporting its development via crypto.', 'deal-scraper-plugin'); ?></p> <div class="dsp-donate-images"> <div class="dsp-donate-item"><p><strong><?php esc_html_e('USDT (ERC20)', 'deal-scraper-plugin'); ?></strong></p><img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/usdt_qr.jpg'); ?>" alt="<?php esc_attr_e('USDT ERC20 QR Code', 'deal-scraper-plugin'); ?>"><code>ethereum:0x3969A4fA61d66e078D268758c3a64408e1B16688?req-asset=0xdac17f958d2ee523a2206206994597c13d831ec7</code></div> <div class="dsp-donate-item"><p><strong><?php esc_html_e('BNB (BEP2 / Native)', 'deal-scraper-plugin'); ?></strong></p><img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/bnb_qr.jpg'); ?>" alt="<?php esc_attr_e('BNB QR Code', 'deal-scraper-plugin'); ?>"><code>bnb:bnb1wcxj5tr7srfpyqagjxf6e92qyc9n83xfsp3y58</code></div> <div class="dsp-donate-item"><p><strong><?php esc_html_e('Bitcoin (BTC)', 'deal-scraper-plugin'); ?></strong></p><img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/btc_qr.jpg'); ?>" alt="<?php esc_attr_e('Bitcoin QR Code', 'deal-scraper-plugin'); ?>"><code>bitcoin:bc1q3k0h6uf3yx9th02vt5ek5hyl6dlhv8sdnygyt9</code></div> </div> <p style="margin-top: 20px; font-style: italic; text-align: center;"><?php esc_html_e('Thank you for your support!', 'deal-scraper-plugin'); ?></p> </div> </div>
        <?php /* ... Subscribe Modal HTML ... */ ?>
        <?php if ($email_enabled) : ?> <div id="dsp-subscribe-modal" class="dsp-modal" style="display: none;"> <div class="dsp-modal-content dsp-subscribe-modal-content"> <span class="dsp-modal-close dsp-subscribe-modal-close">×</span> <h2><?php esc_html_e('Subscribe to New Deals', 'deal-scraper-plugin'); ?></h2> <p><?php esc_html_e('Enter your email address to receive notifications about new deals.', 'deal-scraper-plugin'); ?></p> <div class="dsp-subscribe-form"> <label for="dsp-subscribe-email-input" class="screen-reader-text"><?php esc_html_e('Email Address', 'deal-scraper-plugin'); ?></label> <input type="email" id="dsp-subscribe-email-input" placeholder="<?php esc_attr_e('Your email address', 'deal-scraper-plugin'); ?>" required> <button id="dsp-subscribe-submit-button" class="dsp-button"><?php esc_html_e('Subscribe', 'deal-scraper-plugin'); ?></button> <span id="dsp-subscribe-spinner" class="dsp-spinner" style="visibility: hidden;"></span> </div> <div id="dsp-subscribe-message" class="dsp-subscribe-status"></div> </div> </div> <?php endif; ?>

    </div> <?php // End dsp-container ?>
    <?php
    return ob_get_clean();
}

// Fallback function
if (!function_exists('dsp_get_default_config')) { function dsp_get_default_config() { return []; } }
?>