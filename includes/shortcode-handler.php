<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function dsp_render_shortcode( $atts ) {
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
                 <span id="dsp-refresh-spinner" class="dsp-spinner" style="display: none;"></span>
                 <span id="dsp-refresh-message" class="dsp-refresh-status"></span>
            </div>
        </div>

        <div class="dsp-debug-controls">
             <button id="dsp-toggle-debug-log" class="dsp-button dsp-button-secondary"><?php esc_html_e('Show Debug Log', 'deal-scraper-plugin'); ?></button>
             <?php // **** START: Add Donate Button **** ?>
             <button id="dsp-donate-button" class="dsp-button dsp-button-secondary"><?php esc_html_e('Donate', 'deal-scraper-plugin'); ?></button>
             <?php // **** END: Add Donate Button **** ?>
        </div>

        <div id="dsp-debug-log-container" style="display: none;">
            <h4><?php esc_html_e('Refresh Debug Log:', 'deal-scraper-plugin'); ?></h4>
            <pre id="dsp-debug-log"></pre>
        </div>

        <div class="dsp-status-bar">
            <span id="dsp-status-message"></span>
            <span id="dsp-last-updated" style="float: right;"></span>
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
                <tr>
                    <td colspan="5" class="dsp-loading"><?php esc_html_e('Loading deals...', 'deal-scraper-plugin'); ?></td>
                </tr>
            </tbody>
        </table>

        <?php // **** START: Add Donate Modal HTML Structure **** ?>
        <div id="dsp-donate-modal" class="dsp-modal" style="display: none;">
            <div class="dsp-modal-content">
                <span class="dsp-modal-close">×</span>
                <h2><?php esc_html_e('Support This Plugin', 'deal-scraper-plugin'); ?></h2>
                <p><?php esc_html_e('If you find this plugin useful, please consider supporting its development via crypto.', 'deal-scraper-plugin'); ?></p>
                <div class="dsp-donate-images">
                    <div class="dsp-donate-item">
                        <p><strong><?php esc_html_e('USDT (ERC20)', 'deal-scraper-plugin'); ?></strong></p>
                        <?php // IMPORTANT: Replace 'usdt_qr.png' if your filename is different ?>
                        <img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/usdt_qr.jpg'); ?>" alt="<?php esc_attr_e('USDT ERC20 QR Code', 'deal-scraper-plugin'); ?>">
                        <code>ethereum:0x3969A4fA61d66e078D268758c3a64408e1B16688?req-asset=0xdac17f958d2ee523a2206206994597c13d831ec7</code>
                    </div>
                    <div class="dsp-donate-item">
                         <p><strong><?php esc_html_e('BNB (BEP2 / Native)', 'deal-scraper-plugin'); ?></strong></p> <?php // Updated label based on address format ?>
                         <?php // IMPORTANT: Replace 'bnb_qr.png' if your filename is different ?>
                        <img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/bnb_qr.jpg'); ?>" alt="<?php esc_attr_e('BNB QR Code', 'deal-scraper-plugin'); ?>">
                         <code>bnb:bnb1wcxj5tr7srfpyqagjxf6e92qyc9n83xfsp3y58</code>
                    </div>
                    <div class="dsp-donate-item">
                         <p><strong><?php esc_html_e('Bitcoin (BTC)', 'deal-scraper-plugin'); ?></strong></p>
                         <?php // IMPORTANT: Replace 'btc_qr.png' if your filename is different ?>
                        <img src="<?php echo esc_url(DSP_PLUGIN_URL . 'assets/images/btc_qr.jpg'); ?>" alt="<?php esc_attr_e('Bitcoin QR Code', 'deal-scraper-plugin'); ?>">
                        <code>bitcoin:bc1q3k0h6uf3yx9th02vt5ek5hyl6dlhv8sdnygyt9</code>
                    </div>
                </div>
                 <p style="margin-top: 20px; font-style: italic; text-align: center;"><?php esc_html_e('Thank you for your support!', 'deal-scraper-plugin'); ?></p>
            </div>
        </div>
        <?php // **** END: Add Donate Modal HTML Structure **** ?>

    </div> <?php // End dsp-container ?>
    <?php
    return ob_get_clean(); // Return buffered content
}