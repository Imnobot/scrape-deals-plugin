<?php
// File: includes/parsers/dealmirror.php (v1.1.49 - Prevent Duplicate Links)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parses DealMirror deals from HTML content.
 * Assumes helper functions from parser-helpers.php are available.
 * Prevents adding duplicate links within the same run.
 *
 * @param string $html The HTML content of the page.
 * @param string $base_url The base URL for resolving relative links.
 * @param string $site_name The configured name of the site ('DealMirror').
 * @return array Array of deals found.
 */
function parse_dealmirror_php($html, $base_url, $site_name = 'DealMirror') {
    $xpath = dsp_get_dom_xpath($html, $base_url);
    if (!$xpath) return [];
    $deals = [];
    $processed_links = []; // *** NEW: Array to track processed links ***
    error_log("DSP Parser {$site_name}: Starting parser function.");

    // --- SELECTORS ---
    $deal_container_xpath = "//div[contains(@class, 'ht-product')]";
    $title_link_xpath = ".//h4[contains(@class, 'ht-product-title')]/a";
    $title_fallback_xpath = ".//h4[contains(@class, 'ht-product-title')]";
    $link_fallback_xpath = ".//div[contains(@class,'ht-product-image')]/a | .//h4[contains(@class, 'ht-product-title')]/a | .//a";
    $price_sale_xpath = ".//span[contains(@class, 'price')]//ins//span[contains(@class, 'woocommerce-Price-amount')]";
    $price_regular_xpath = ".//span[contains(@class, 'price')]/span[contains(@class, 'woocommerce-Price-amount')][not(ancestor::del) and not(ancestor::ins)]";
    $price_full_xpath = ".//span[contains(@class, 'price')]//span[contains(@class, 'woocommerce-Price-amount')]";
    $desc_xpath = ".//div[contains(@class,'woocommerce-product-details__short-description')] | .//div[contains(@class,'ht-product-excerpt')]";
    $image_xpath = ".//div[contains(@class,'ht-product-image')]//img";

    // Query for deal containers
    $deal_cards = $xpath->query($deal_container_xpath);
    if ($deal_cards === false || $deal_cards->length === 0) {
        error_log("DSP Parser {$site_name}: [ERROR] Could not find ANY deal card elements using XPath: {$deal_container_xpath}");
        return [];
    }
    error_log("DSP Parser {$site_name}: Found {$deal_cards->length} potential card elements using XPath: {$deal_container_xpath}");

    $count = 0;
    foreach ($deal_cards as $card) {
        $count++;
        $deal_data = [
            'link' => '', 'title' => '', 'price' => 'N/A', 'description' => '', 'source' => $site_name,
            'image_url' => '', 'is_ltd' => false
        ];

        // Get Title & Link
        $title_link_node = $xpath->query($title_link_xpath, $card)->item(0);
        if ($title_link_node) {
            $link_raw = dsp_get_node_attribute($title_link_node, 'href');
            $deal_data['link'] = dsp_make_absolute_url($link_raw, $base_url);
            $deal_data['title'] = dsp_get_node_text($title_link_node);
        } else {
             $title_fallback_node = $xpath->query($title_fallback_xpath, $card)->item(0);
             if ($title_fallback_node) { $deal_data['title'] = dsp_get_node_text($title_fallback_node); }
             $link_fallback_node = $xpath->query($link_fallback_xpath, $card)->item(0);
             if ($link_fallback_node) { $link_raw = dsp_get_node_attribute($link_fallback_node, 'href'); $deal_data['link'] = dsp_make_absolute_url($link_raw, $base_url); }
        }

        // Skip if essential data missing or link already processed
        if (empty($deal_data['link']) || $deal_data['link'] === '#' || empty($deal_data['title']) || $deal_data['title'] === 'N/A') {
             if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Skipping due to missing essential data. Link='".($deal_data['link'] ?? 'NULL')."', Title='".($deal_data['title'] ?? 'NULL')."'");
             continue;
        }
        // *** NEW: Check if link was already added in this run ***
        if (in_array($deal_data['link'], $processed_links)) {
             if ($count <= 10 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Skipping duplicate link: {$deal_data['link']}");
            continue;
        }
        // *** END NEW ***

        // Get Price
        $price_node = $xpath->query($price_sale_xpath, $card)->item(0);
        if ($price_node) { $deal_data['price'] = dsp_get_node_text($price_node); }
        else { $price_node = $xpath->query($price_regular_xpath, $card)->item(0); if ($price_node) { $deal_data['price'] = dsp_get_node_text($price_node); }
        else { $price_node = $xpath->query($price_full_xpath, $card)->item(0); if ($price_node) { $deal_data['price'] = dsp_get_node_text($price_node); } } }

        // Get Description
        $desc_tag = $xpath->query($desc_xpath, $card)->item(0);
        $deal_data['description'] = $desc_tag ? dsp_get_node_text($desc_tag) : '';

        // LTD Check
        $title_check = is_string($deal_data['title']) && stripos($deal_data['title'], 'lifetime') !== false;
        $price_check = is_string($deal_data['price']) && stripos($deal_data['price'], 'lifetime') !== false;
        $desc_check = is_string($deal_data['description']) && stripos($deal_data['description'], 'lifetime') !== false;
        $deal_data['is_ltd'] = $title_check || $price_check || $desc_check;

        // Get Image URL
        $image_node = $xpath->query($image_xpath, $card)->item(0);
        if ($image_node) {
            $img_src = '';
            if ($image_node->hasAttribute('data-src')) { $img_src = $image_node->getAttribute('data-src'); }
            elseif ($image_node->hasAttribute('src')) { $img_src = $image_node->getAttribute('src'); }
            elseif ($image_node->hasAttribute('srcset')) { $srcset = $image_node->getAttribute('srcset'); $srcs = explode(',', $srcset); if (!empty($srcs)) { $first_src = trim(explode(' ', trim($srcs[0]))[0]); if (!empty($first_src)) $img_src = $first_src; } }
            if (!empty($img_src)) { $deal_data['image_url'] = function_exists('dsp_make_absolute_url') ? dsp_make_absolute_url($img_src, $base_url) : $img_src; if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Found image URL: {$deal_data['image_url']}"); }
            else { if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Image tag found but no src/data-src/srcset for '{$deal_data['title']}'."); }
        } else { if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Image not found using XPath '{$image_xpath}' for '{$deal_data['title']}'."); }

        // Add deal and track link
        $deal_data['title'] = trim($deal_data['title']);
        $deal_data['price'] = trim($deal_data['price']) ?: 'N/A';
        $deal_data['description'] = trim($deal_data['description']);
        $deals[] = $deal_data;
        $processed_links[] = $deal_data['link']; // *** NEW: Add link to tracked array ***

    } // End foreach
     error_log("DSP Parser {$site_name}: Finished processing cards. Extracted " . count($deals) . " unique valid deals.");
    return $deals;
}