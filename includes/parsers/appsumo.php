<?php
// File: includes/parsers/appsumo.php (v1.1.34 - Updated Image XPath)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parses AppSumo deals from HTML content.
 * Assumes helper functions from parser-helpers.php are available.
 *
 * @param string $html The HTML content of the page.
 * @param string $base_url The base URL for resolving relative links.
 * @param string $site_name The configured name of the site ('AppSumo').
 * @return array Array of deals found.
 */
function parse_appsumo_php($html, $base_url, $site_name = 'AppSumo') {
    $xpath = dsp_get_dom_xpath($html, $base_url);
    if (!$xpath) return [];
    $deals = [];

    // --- SELECTORS ---
    // Container selector seems okay, combining primary and fallback
    $deal_container_xpath = "//div[contains(@class, 'relative') and contains(@class, 'h-full') and .//a[contains(@class, 'absolute')]] | //div[contains(@class,'product-card')]";
    // Title/Link selectors seem okay
    $title_link_xpath = ".//a[contains(@class, 'product-card__title')] | .//a[contains(@class, 'absolute') and contains(@class, 'h-full') and contains(@class, 'w-full')]";
    $title_xpath = ".//span[contains(@class, 'overflow-hidden') and contains(@class, 'font-bold')] | .//strong[contains(@class, 'chakra-text')]";
    // Price selector seems okay
    $price_xpath = ".//span[@id='deal-price'] | .//*[contains(@class,'price') or contains(@class,'text-sumo-contrast')][contains(text(),'$')]";
    // Description selector seems okay
    $description_xpath = ".//div[contains(@class, 'my-1') and contains(@class, 'line-clamp-3')] | .//p[contains(@class, 'product-card__description')]";
    // *** UPDATED IMAGE XPATH based on Inspector ***
    // Target the img tag with class 'aspect-sku-card' which seems to be inside the relative div
    $image_xpath = ".//div[contains(@class,'relative')]/img[contains(@class, 'aspect-sku-card')] | .//img[contains(@class, 'aspect-sku-card')]"; // Try relative first, then anywhere in card

    // Query for deal containers
    $deal_cards = $xpath->query($deal_container_xpath);

    if ($deal_cards === false || $deal_cards->length === 0) {
        error_log("DSP Parser {$site_name}: No deal containers found using XPath: {$deal_container_xpath}");
        return [];
    }
    error_log("DSP Parser {$site_name}: Found {$deal_cards->length} potential card elements.");

    $count = 0;
    foreach ($deal_cards as $card) {
        $count++;
        $deal_data = [
            'link' => '', 'title' => '', 'price' => '', 'description' => '', 'source' => $site_name,
            'image_url' => '', // Initialize image_url
            'is_ltd' => false
        ];

        // Get Title and Link
        $title_link_node = $xpath->query($title_link_xpath, $card)->item(0);
        if ($title_link_node) {
            $raw_link = dsp_get_node_attribute($title_link_node, 'href');
            $deal_data['link'] = dsp_make_absolute_url($raw_link, $base_url);
            // Try finding title within the link tag context first
            $title_node = $xpath->query($title_xpath, $title_link_node)->item(0) ?: $xpath->query($title_xpath, $card)->item(0);
            if ($title_node) { $deal_data['title'] = dsp_get_node_text($title_node); }
        } else {
             // Fallback: Look for link anywhere in card if specific one failed
             $fallback_link_node = $xpath->query(".//a", $card)->item(0);
             if ($fallback_link_node) {
                 $raw_link = dsp_get_node_attribute($fallback_link_node, 'href');
                 $deal_data['link'] = dsp_make_absolute_url($raw_link, $base_url);
             }
             // Try title within card directly
             $title_node = $xpath->query($title_xpath, $card)->item(0);
             if ($title_node) { $deal_data['title'] = dsp_get_node_text($title_node); }
        }

        // Skip if essential data missing
        if (empty($deal_data['link']) || $deal_data['link'] === '#' || empty($deal_data['title'])) {
            if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Skipping due to missing link/title.");
            continue;
        }

        // Get Price
        $price_node = $xpath->query($price_xpath, $card)->item(0);
        $deal_data['price'] = $price_node ? dsp_get_node_text($price_node) : 'N/A';

        // Get Description
        $description_node = $xpath->query($description_xpath, $card)->item(0);
        $deal_data['description'] = $description_node ? dsp_get_node_text($description_node) : '';

        // LTD Check
        $title_check = is_string($deal_data['title']) && stripos($deal_data['title'], 'lifetime') !== false;
        $price_check = is_string($deal_data['price']) && stripos($deal_data['price'], 'lifetime') !== false;
        $desc_check = is_string($deal_data['description']) && stripos($deal_data['description'], 'lifetime') !== false;
        $deal_data['is_ltd'] = $title_check || $price_check || $desc_check;

        // --- Get Image URL ---
        $image_node = $xpath->query($image_xpath, $card)->item(0);
        if ($image_node) {
            $img_src = '';
            // AppSumo seems to use 'src' directly based on snippet
            if ($image_node->hasAttribute('src')) {
                $img_src = $image_node->getAttribute('src');
            }
            // Add fallbacks just in case structure varies or uses lazy loading elsewhere
            elseif ($image_node->hasAttribute('data-src')) { $img_src = $image_node->getAttribute('data-src'); }
            elseif ($image_node->hasAttribute('data-lazy-src')) { $img_src = $image_node->getAttribute('data-lazy-src'); }
            elseif ($image_node->hasAttribute('srcset')) {
                 $srcset = $image_node->getAttribute('srcset');
                 $srcs = explode(',', $srcset);
                 if (!empty($srcs)) {
                     $first_src = trim(explode(' ', trim($srcs[0]))[0]); // Get url part of first entry
                     if (!empty($first_src)) $img_src = $first_src;
                 }
            }

            if (!empty($img_src)) {
                // Make URL absolute using helper function (important if src was relative)
                $deal_data['image_url'] = function_exists('dsp_make_absolute_url')
                    ? dsp_make_absolute_url($img_src, $base_url)
                    : $img_src; // Fallback if helper missing

                // AppSumo URLs sometimes have query params, strip them if desired
                // $deal_data['image_url'] = strtok($deal_data['image_url'], '?');

            } else {
                if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Image tag found but no src/data-src/srcset for '{$deal_data['title']}'.");
            }
        } else {
             if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Image not found using XPath '{$image_xpath}' for '{$deal_data['title']}'.");
        }
        // --- End Image URL ---

        // Add the processed deal data to the results array
        $deal_data['title'] = trim($deal_data['title']);
        $deal_data['price'] = trim($deal_data['price']) ?: 'N/A';
        $deal_data['description'] = trim($deal_data['description']);
        $deals[] = $deal_data;

    } // End foreach loop

    error_log("DSP Parser {$site_name}: Extracted " . count($deals) . " deals.");
    return $deals;
}