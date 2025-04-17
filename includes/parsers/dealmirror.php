<?php
// File: includes/parsers/dealmirror.php (v1.1.34 - Add image_url extraction)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parses DealMirror deals from HTML content.
 * Assumes helper functions from parser-helpers.php are available.
 *
 * @param string $html The HTML content of the page.
 * @param string $base_url The base URL for resolving relative links.
 * @param string $site_name The configured name of the site ('DealMirror').
 * @return array Array of deals found.
 */
function parse_dealmirror_php($html, $base_url, $site_name = 'DealMirror') { // Added site_name
    $xpath = dsp_get_dom_xpath($html, $base_url);
    if (!$xpath) return [];
    $deals = [];
    error_log("DSP Parser {$site_name}: Starting parser function.");

    // --- SELECTORS (Verified based on inspector snippet) ---
    $deal_container_xpath = "//div[contains(@class, 'product-inner') and contains(@class,'clearfix')] | //li[contains(@class, 'product')]"; // Combined selectors
    $title_link_xpath = ".//h2[contains(@class, 'woo-loop-product__title')]/a | .//h4[contains(@class, 'ht-product-title')]/a"; // Primary title link
    $title_fallback_xpath = ".//h2 | .//h3 | .//h4"; // Fallback title text
    $link_fallback_xpath = ".//a[contains(@class,'woocommerce-LoopProduct-link')] | .//a"; // Fallback link
    $price_sale_xpath = ".//span[contains(@class, 'price')]//ins//span[contains(@class, 'woocommerce-Price-amount')]";
    $price_regular_xpath = ".//span[contains(@class, 'price')]//span[contains(@class, 'woocommerce-Price-amount')][not(ancestor::del) and not(ancestor::ins)]";
    $price_full_xpath = ".//span[contains(@class, 'price')]//span[contains(@class, 'woocommerce-Price-amount')]"; // Last price fallback
    $desc_xpath = ".//div[contains(@class,'woocommerce-product-details__short-description')] | .//div[contains(@class,'ht-product-excerpt')]";
    // This XPath covers the structure found in the inspector snippet
    $image_xpath = ".//img[contains(@class, 'attachment-woocommerce_thumbnail')] | .//img[contains(@class, 'wp-post-image')] | .//div[contains(@class,'ht-product-image')]//img";

    // Query for deal containers
    $deal_cards = $xpath->query($deal_container_xpath);
    if ($deal_cards === false || $deal_cards->length === 0) { error_log("DSP Parser {$site_name}: [ERROR] Could not find ANY deal card elements using: {$deal_container_xpath}"); return []; }
    error_log("DSP Parser {$site_name}: Found {$deal_cards->length} potential card elements.");

    $count = 0;
    foreach ($deal_cards as $card) {
        $count++;
        $deal_data = [
            'link' => '', 'title' => '', 'price' => 'N/A', 'description' => '', 'source' => $site_name,
            'image_url' => '', // Initialize image_url
            'is_ltd' => false
        ];

        // Get Title & Link
        $title_link_node = $xpath->query($title_link_xpath, $card)->item(0);
        if ($title_link_node) {
            $link_raw = dsp_get_node_attribute($title_link_node, 'href');
            $deal_data['link'] = dsp_make_absolute_url($link_raw, $base_url);
            $deal_data['title'] = dsp_get_node_text($title_link_node);
        } else {
             $title_fallback_node = $xpath->query($title_fallback_xpath, $card)->item(0);
             if ($title_fallback_node) $deal_data['title'] = dsp_get_node_text($title_fallback_node);
             $link_fallback_node = $xpath->query($link_fallback_xpath, $card)->item(0);
             if ($link_fallback_node) {
                 $link_raw = dsp_get_node_attribute($link_fallback_node, 'href');
                 $deal_data['link'] = dsp_make_absolute_url($link_raw, $base_url);
             }
        }

        // Skip if essential data missing
        if (empty($deal_data['link']) || $deal_data['link'] === '#' || empty($deal_data['title']) || $deal_data['title'] === 'N/A') {
             if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Skipping due to missing essential data. Link='".($deal_data['link'] ?? 'NULL')."', Title='".($deal_data['title'] ?? 'NULL')."'");
             continue;
        }

        // Get Price
        $price_tag_sale = $xpath->query($price_sale_xpath, $card)->item(0);
        if ($price_tag_sale) { $deal_data['price'] = dsp_get_node_text($price_tag_sale); }
        else {
            $price_tag_regular = $xpath->query($price_regular_xpath, $card)->item(0);
            if ($price_tag_regular) { $deal_data['price'] = dsp_get_node_text($price_tag_regular); }
            else { $price_tag_fallback = $xpath->query($price_full_xpath, $card)->item(0); if ($price_tag_fallback) { $deal_data['price'] = dsp_get_node_text($price_tag_fallback); } }
        }

        // Get Description
        $desc_tag = $xpath->query($desc_xpath, $card)->item(0);
        $deal_data['description'] = $desc_tag ? dsp_get_node_text($desc_tag) : '';

        // LTD Check
        $title_check = is_string($deal_data['title']) && stripos($deal_data['title'], 'lifetime') !== false;
        $price_check = is_string($deal_data['price']) && stripos($deal_data['price'], 'lifetime') !== false;
        $desc_check = is_string($deal_data['description']) && stripos($deal_data['description'], 'lifetime') !== false;
        $deal_data['is_ltd'] = $title_check || $price_check || $desc_check;

        // --- Get Image URL ---
        $image_node = $xpath->query($image_xpath, $card)->item(0);
        if ($image_node) {
            $img_src = '';
            // Check attributes in order: data-src, src, srcset
            if ($image_node->hasAttribute('data-src')) { $img_src = $image_node->getAttribute('data-src'); }
            elseif ($image_node->hasAttribute('src')) { $img_src = $image_node->getAttribute('src'); } // Will match the snippet
            elseif ($image_node->hasAttribute('srcset')) { $srcset = $image_node->getAttribute('srcset'); $srcs = explode(',', $srcset); if (!empty($srcs)) { $first_src = trim(explode(' ', trim($srcs[0]))[0]); if (!empty($first_src)) $img_src = $first_src; } }

            if (!empty($img_src)) {
                $deal_data['image_url'] = function_exists('dsp_make_absolute_url') ? dsp_make_absolute_url($img_src, $base_url) : $img_src;
            } else { if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Image tag found but no src/data-src/srcset for '{$deal_data['title']}'."); }
        } else { if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Image not found using XPath '{$image_xpath}' for '{$deal_data['title']}'."); }
        // --- End Image URL ---

        // Add deal
        $deal_data['title'] = trim($deal_data['title']);
        $deal_data['price'] = trim($deal_data['price']) ?: 'N/A';
        $deal_data['description'] = trim($deal_data['description']);
        $deals[] = $deal_data;

    } // End foreach
     error_log("DSP Parser {$site_name}: Finished processing cards. Extracted " . count($deals) . " valid deals.");
    return $deals;
}