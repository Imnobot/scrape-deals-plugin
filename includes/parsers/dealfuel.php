<?php
// File: includes/parsers/dealfuel.php (v1.1.34 - Add image_url extraction)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parses DealFuel deals from HTML content.
 * Assumes helper functions from parser-helpers.php are available.
 *
 * @param string $html The HTML content of the page.
 * @param string $base_url The base URL for resolving relative links.
 * @param string $site_name The configured name of the site ('DealFuel').
 * @return array Array of deals found.
 */
function parse_dealfuel_php($html, $base_url, $site_name = 'DealFuel') { // Added site_name
    $xpath = dsp_get_dom_xpath($html, $base_url);
    if (!$xpath) return [];
    $deals = [];

    // --- SELECTORS (Verified - Should work based on inspector snippet) ---
    $deal_container_xpath = "//li[contains(@class, 'product')]";
    $product_link_xpath = ".//a[contains(@class, 'woocommerce-LoopProduct-link')]";
    $title_in_link_xpath = ".//h2[contains(@class, 'woocommerce-loop-product__title')]";
    $title_direct_xpath = ".//h2[contains(@class, 'woocommerce-loop-product__title')]/a"; // Title as link text
    $title_fallback_xpath = ".//h2[contains(@class, 'woocommerce-loop-product__title')]"; // Title tag direct
    $price_container_xpath = ".//span[contains(@class, 'price')]";
    $sale_price_xpath = ".//ins//span[contains(@class, 'woocommerce-Price-amount')]";
    $regular_price_xpath = ".//span[contains(@class, 'woocommerce-Price-amount')][not(ancestor::del) and not(ancestor::ins)]";
    $full_price_xpath = ".//span[contains(@class, 'woocommerce-Price-amount')]"; // Last fallback within price span
    // This XPath should match the inspector snippet
    $image_xpath = ".//img[contains(@class, 'attachment-woocommerce_thumbnail')] | .//img[contains(@class, 'wp-post-image')]";

    // Query for deal containers
    $deal_cards = $xpath->query($deal_container_xpath);
    if ($deal_cards === false || $deal_cards->length === 0) {
        error_log("DSP Parser {$site_name}: Could not find deal card elements using XPath: {$deal_container_xpath}");
        return [];
    }
    error_log("DSP Parser {$site_name}: Found {$deal_cards->length} potential card elements.");

    $count = 0;
    foreach ($deal_cards as $card) {
        $count++;
        $deal_data = [
            'link' => '', 'title' => '', 'price' => 'N/A', 'description' => '', 'source' => $site_name,
            'image_url' => '', // Initialize image_url
            'is_ltd' => false
        ];

        // Get Link and Title
        $product_link_node = $xpath->query($product_link_xpath, $card)->item(0);
        if ($product_link_node) {
            $link_raw = dsp_get_node_attribute($product_link_node, 'href');
            $deal_data['link'] = dsp_make_absolute_url($link_raw, $base_url);
            $title_node = $xpath->query($title_in_link_xpath, $product_link_node)->item(0);
            if ($title_node) { $deal_data['title'] = dsp_get_node_text($title_node); }
        }
        // Fallback if link/title not found via main wrapper link
        if (empty($deal_data['link']) || $deal_data['link'] === '#' || empty($deal_data['title'])) {
             $title_direct_node = $xpath->query($title_direct_xpath, $card)->item(0);
             if ($title_direct_node) {
                 $link_raw = dsp_get_node_attribute($title_direct_node, 'href');
                 $deal_data['link'] = dsp_make_absolute_url($link_raw, $base_url);
                 $deal_data['title'] = dsp_get_node_text($title_direct_node);
             } else {
                  $title_fallback_node = $xpath->query($title_fallback_xpath, $card)->item(0);
                  if ($title_fallback_node) $deal_data['title'] = dsp_get_node_text($title_fallback_node);
                  if (empty($deal_data['link']) || $deal_data['link'] === '#') {
                       $any_link_node = $xpath->query('.//a', $card)->item(0);
                       if($any_link_node){
                            $link_raw = dsp_get_node_attribute($any_link_node, 'href');
                            $deal_data['link'] = dsp_make_absolute_url($link_raw, $base_url);
                       }
                  }
             }
        }

        // Skip if essential data missing
        if (empty($deal_data['link']) || $deal_data['link'] === '#' || empty($deal_data['title'])) {
            if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Skipping due to missing link/title.");
            continue;
        }

        // Get Price
        $price_container = $xpath->query($price_container_xpath, $card)->item(0);
        if ($price_container) {
            $sale_price_node = $xpath->query($sale_price_xpath, $price_container)->item(0);
            if ($sale_price_node) { $deal_data['price'] = dsp_get_node_text($sale_price_node); }
            else {
                $regular_price_node = $xpath->query($regular_price_xpath, $price_container)->item(0);
                 if ($regular_price_node) { $deal_data['price'] = dsp_get_node_text($regular_price_node); }
                 else { $full_price_node = $xpath->query($full_price_xpath, $price_container)->item(0); if($full_price_node) $deal_data['price'] = dsp_get_node_text($full_price_node); }
            }
        }

        // LTD Check
        $title_check = is_string($deal_data['title']) && stripos($deal_data['title'], 'lifetime') !== false;
        $price_check = is_string($deal_data['price']) && stripos($deal_data['price'], 'lifetime') !== false;
        $deal_data['is_ltd'] = $title_check || $price_check;

        // --- Get Image URL ---
        $image_node = $xpath->query($image_xpath, $card)->item(0);
        if ($image_node) {
            $img_src = '';
            // Check attributes in order: data-src, src, srcset
            if ($image_node->hasAttribute('data-src')) { $img_src = $image_node->getAttribute('data-src'); }
            elseif ($image_node->hasAttribute('src')) { $img_src = $image_node->getAttribute('src'); }
            elseif ($image_node->hasAttribute('srcset')) { $srcset = $image_node->getAttribute('srcset'); $srcs = explode(',', $srcset); if (!empty($srcs)) { $first_src = trim(explode(' ', trim($srcs[0]))[0]); if (!empty($first_src)) $img_src = $first_src; } }

            if (!empty($img_src)) {
                $deal_data['image_url'] = function_exists('dsp_make_absolute_url') ? dsp_make_absolute_url($img_src, $base_url) : $img_src;
            } else { if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Image tag found but no src/data-src/srcset for '{$deal_data['title']}'."); }
        } else { if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Image not found using XPath '{$image_xpath}' for '{$deal_data['title']}'."); }
        // --- End Image URL ---

        // Add the processed deal data to the results array
        $deal_data['title'] = trim($deal_data['title']);
        $deal_data['price'] = trim($deal_data['price']) ?: 'N/A';
        $deal_data['description'] = ''; // Typically no description
        $deals[] = $deal_data;

    } // End foreach loop

    error_log("DSP Parser {$site_name}: Extracted " . count($deals) . " deals.");
    return $deals;
}