<?php
// File: includes/shortcode-handler.php (MODIFIED for Subscribe Button/Modal)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function dsp_render_shortcode( $atts ) {
    // --- Get Plugin Settings ---
    $options = get_option( DSP_OPTION_NAME );
    $refresh_access = isset( $options['refresh_button_access'] ) ? $options['refresh_button_access'] : 'all';
    $show_debug_button = isset( $options['show_debug_button'] ) ? (bool) $options['show_debug_button'] : true;
    // Check if email notifications are generally enabled (to decide if subscribe makes sense)
    $email_enabled = isset( $options['email_enabled'] ) ? (bool) $options['email_enabled'] : false;


    // --- Determine if the current user should see the Refresh button ---
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
            <?php if ( $show_refresh_button ) : ?>
            <div class="dsp-filter-item">
                 <button id="dsp-refresh-button" class="dsp-button"><?php esc_html_e('Refresh Now', 'deal-scraper-plugin'); ?></button>
                 <span id="dsp-refresh-spinner" class="dsp-spinner" style="visibility: hidden;"></span>
                 <span id="dsp-refresh-message" class="dsp-refresh-status"></span>
            </div>
            <?php endif; ?>
        </div>


        <div class="dsp-debug-controls">
             <?php if ( $show_debug_button ) : ?>
                <button id="dsp-toggle-debug-log" class="dsp-button dsp-button-secondary"><?php esc_html_e('Show Debug Log', 'deal-scraper-plugin'); ?></button>
             <?php endif; ?>

             <button id="dsp-donate-button" class="dsp-button dsp-button-secondary"><?php esc_html_e('Donate', 'deal-scraper-plugin'); ?></button>

             <?php // *** NEW: Conditionally show Subscribe button if emails are enabled in settings ***
             if ($email_enabled) : ?>
                <button id="dsp-subscribe-button" class="dsp-button dsp-button-secondary"><?php esc_html_e('Subscribe', 'deal-scraper-plugin'); ?></button>
             <?php endif; ?>
             <?php // *** END NEW *** ?>
        </div>


        <div id="dsp-debug-log-container" style="display: none;">
            <h4><?php esc_html_e('Refresh Debug Log:', 'deal-scraper-plugin'); ?></h4>
            <pre id="dsp-debug-log"></pre>
        </div>


        <div class="dsp-status-bar">
            <span id="dsp-status-message"><?php /* Status set by JS */ ?></span>
            <span id="dsp-last-updated" style="float: right; visibility: hidden;">
                 <?php esc_html_e('Last fetched:', 'deal-scraper-plugin'); ?> <span id="dsp-last-updated-time"></span>
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
                
                <tr class="dsp-loading-row">
                    <td colspan="5"><?php esc_html_e('Loading deals...', 'deal-scraper-plugin'); ?></td>
                </tr>
                
            </tbody>
        </table>


        <div id="dsp-donate-modal" class="dsp-modal" style="display: none;">
             <div class="dsp-modal-content">
                <span class="dsp-modal-close">×</span>
                <h2><?php esc_html_e('Support This Plugin', 'deal-scraper-plugin'); ?></h2>
                <p><?php esc_html_e('If you find this plugin useful, please consider supporting its development via crypto.', 'deal-scraper-plugin'); ?></p>
                <div class="dsp-donate-images">
                    <!-- Donate QR Codes -->
                    <div class="dsp-donate-item"><p><strong><?php esc_html_e('USDT (ERC20)', 'deal-scraper-plugin'); ?></strong></p><img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/usdt_qr.jpg'); ?>" alt="<?php esc_attr_e('USDT ERC20 QR Code', 'deal-scraper-plugin'); ?>"><code>ethereum:0x3969A4fA61d66e078D268758c3a64408e1B16688?req-asset=0xdac17f958d2ee523a2206206994597c13d831ec7</code></div>
                    <div class="dsp-donate-item"><p><strong><?php esc_html_e('BNB (BEP2 / Native)', 'deal-scraper-plugin'); ?></strong></p><img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/bnb_qr.jpg'); ?>" alt="<?php esc_attr_e('BNB QR Code', 'deal-scraper-plugin'); ?>"><code>bnb:bnb1wcxj5tr7srfpyqagjxf6e92qyc9n83xfsp3y58</code></div>
                    <div class="dsp-donate-item"><p><strong><?php esc_html_e('Bitcoin (BTC)', 'deal-scraper-plugin'); ?></strong></p><img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/btc_qr.jpg'); ?>" alt="<?php esc_attr_e('Bitcoin QR Code', 'deal-scraper-plugin'); ?>"><code>bitcoin:bc1q3k0h6uf3yx9th02vt5ek5hyl6dlhv8sdnygyt9</code></div>
                </div>
                 <p style="margin-top: 20px; font-style: italic; text-align: center;"><?php esc_html_e('Thank you for your support!', 'deal-scraper-plugin'); ?></p>
            </div>
        </div>

        <?php // *** NEW: Subscribe Modal HTML ***
        if ($email_enabled) : // Only output modal if emails are enabled globally
        ?>
        <div id="dsp-subscribe-modal" class="dsp-modal" style="display: none;">
            <div class="dsp-modal-content dsp-subscribe-modal-content">
                <span class="dsp-modal-close dsp-subscribe-modal-close">×</span>
                <h2><?php esc_html_e('Subscribe to New Deals', 'deal-scraper-plugin'); ?></h2>
                <p><?php esc_html_e('Enter your email address to receive notifications about new deals.', 'deal-scraper-plugin'); ?></p>
                <div class="dsp-subscribe-form">
                     <label for="dsp-subscribe-email-input" class="screen-reader-text"><?php esc_html_e('Email Address', 'deal-scraper-plugin'); ?></label>
                     <input type="email" id="dsp-subscribe-email-input" placeholder="<?php esc_attr_e('Your email address', 'deal-scraper-plugin'); ?>" required>
                     <button id="dsp-subscribe-submit-button" class="dsp-button"><?php esc_html_e('Subscribe', 'deal-scraper-plugin'); ?></button>
                     <span id="dsp-subscribe-spinner" class="dsp-spinner" style="visibility: hidden;"></span>
                </div>
                <div id="dsp-subscribe-message" class="dsp-subscribe-status"></div>
            </div>
        </div>
        <?php endif; ?>
        <?php // *** END NEW *** ?>


    </div> <?php // End dsp-container ?>
    <?php
    // Return buffered content
    return ob_get_clean();
}
?>