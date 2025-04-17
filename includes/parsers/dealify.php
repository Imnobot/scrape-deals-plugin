<?php
// File: includes/parsers/dealify.php (v1.1.34 - Add image_url extraction)

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Parses Dealify deals from HTML content.
 * Assumes helper functions from parser-helpers.php are available.
 * **NOTE:** Assumes all deals on the target URL are Lifetime Deals.
 *
 * @param string $html The HTML content of the page.
 * @param string $base_url The base URL (from settings) for resolving relative links.
 * @param string $site_name The name of the site ('Dealify').
 * @return array Array of deals found.
 */
function parse_dealify_php( $html, $base_url, $site_name = 'Dealify' ) { // Added site_name
    $xpath = dsp_get_dom_xpath($html, $base_url);
    if (!$xpath) { error_log("DSP Parser {$site_name}: Failed to create DOMXPath object."); return []; }
    $deals = [];
    error_log("DSP Parser {$site_name}: Starting parser function (assuming all deals are LTD).");

    // --- SELECTORS (Verified based on inspector snippet) ---
    $deal_container_xpath = "//div[contains(@class, 'product-item--vertical')]";
    $title_link_xpath = ".//a[contains(@class,'product-item__title')]";
    $price_xpath = ".//div[contains(@class,'product-item__price-list')]//span[contains(@class,'price--highlight')]";
    $price_fallback_xpath = ".//div[contains(@class,'product-item__price-list')]";
    $desc_xpath = ".//div[contains(@class, 'product-description')]/p";
    // This XPath includes the class found in the inspector snippet
    $image_xpath = ".//div[contains(@class,'product-item__image-wrapper')]//img | .//img[contains(@class,'product-item__primary-image')]";

    // Query for deal containers
    $deal_cards = $xpath->query($deal_container_xpath);
    if ($deal_cards === false) { error_log("DSP Parser {$site_name}: XPath query failed. Query: " . $deal_container_xpath); return []; }
    if ($deal_cards->length === 0) { error_log("DSP Parser {$site_name}: No deal card elements found using query: " . $deal_container_xpath); return []; }
    error_log("DSP Parser {$site_name}: Found {$deal_cards->length} potential card elements using query: {$deal_container_xpath}");

    $count = 0;
    foreach ($deal_cards as $card) {
        $count++;
        $deal_data = [
            'title' => '', 'link' => '', 'price' => 'N/A', 'description' => '', 'source' => $site_name,
            'image_url' => '', // Initialize image_url
            'is_ltd' => true, // Assume true by default for this source
        ];

        // Get Title & Link
        $title_link_tag = $xpath->query($title_link_xpath, $card)->item(0);
        if ($title_link_tag) {
            $deal_data['title'] = dsp_get_node_text($title_link_tag);
            $link_raw = dsp_get_node_attribute($title_link_tag, 'href');
            $deal_data['link'] = dsp_make_absolute_url($link_raw, $base_url);
        } else { if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Could not find title/link using query: {$title_link_xpath}"); continue; } // Skip if no title/link

        // Get Price
        $price_tag = $xpath->query($price_xpath, $card)->item(0);
         if ($price_tag) {
             $price_clone = $price_tag->cloneNode(true);
             $hidden_span = $xpath->query(".//span[contains(@class,'visually-hidden')]", $price_clone)->item(0);
             if ($hidden_span) { $hidden_span->parentNode->removeChild($hidden_span); }
             $deal_data['price'] = dsp_get_node_text($price_clone);
         } else {
              if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Could not find primary price, trying fallback.");
              $price_fallback_tag = $xpath->query($price_fallback_xpath, $card)->item(0);
              if ($price_fallback_tag) { $deal_data['price'] = dsp_get_node_text($price_fallback_tag); }
         }

        // Get Description
        $desc_tag = $xpath->query($desc_xpath, $card)->item(0);
        $deal_data['description'] = $desc_tag ? dsp_get_node_text($desc_tag) : '';

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

        // Add deal if essential data is present
        $deal_data['title'] = trim($deal_data['title']);
        $deal_data['price'] = trim($deal_data['price']) ?: 'N/A';
        $deal_data['description'] = trim($deal_data['description']);
        $deals[] = $deal_data;

    } // End foreach loop

    error_log("DSP Parser {$site_name}: Finished processing. Extracted " . count($deals) . " valid deals (marked as LTD).");
    return $deals;
}