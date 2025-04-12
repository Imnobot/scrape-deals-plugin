<?php
// File: includes/parsers/dealify.php (v1.1.24+ - Hardcode is_ltd=true)

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
function parse_dealify_php( $html, $base_url, $site_name ) {
    $xpath = dsp_get_dom_xpath($html, $base_url);
    if (!$xpath) {
        error_log("DSP Parser {$site_name}: Failed to create DOMXPath object.");
        return [];
    }
    $deals = [];
    error_log("DSP Parser {$site_name}: Starting parser function (assuming all deals are LTD).");

    // --- XPath query for the repeating deal item ---
    $deal_card_query = "//div[contains(@class, 'product-item--vertical')]";
    $deal_cards = $xpath->query($deal_card_query);
    // --- End Query ---

    if ($deal_cards === false) {
         error_log("DSP Parser {$site_name}: XPath query for deal cards failed. Query: " . $deal_card_query);
         return [];
    }
    if ($deal_cards->length === 0) {
        error_log("DSP Parser {$site_name}: No deal card elements found using query: " . $deal_card_query);
        return [];
    }
    error_log("DSP Parser {$site_name}: Found {$deal_cards->length} potential card elements using query: {$deal_card_query}");

    $count = 0;
    foreach ($deal_cards as $card) {
        $count++;
        $deal_data = [
            'title' => null,
            'link' => null,
            'price' => 'N/A',
            'description' => '',
            'source' => $site_name,
            'is_ltd' => true, // *** MODIFIED: Assume true by default for this source ***
        ];

        // --- Extract Data ---
        // Title & Link
        $title_link_query = ".//a[contains(@class,'product-item__title')]";
        $title_link_tag = $xpath->query($title_link_query, $card)->item(0);
        if ($title_link_tag) {
            $deal_data['title'] = dsp_get_node_text($title_link_tag);
            $link_raw = dsp_get_node_attribute($title_link_tag, 'href');
            $deal_data['link'] = dsp_make_absolute_url($link_raw, $base_url);
        } else {
             error_log("DSP Parser {$site_name}: Card {$count} - Could not find title/link using query: {$title_link_query}");
        }

        // Price
        $price_query = ".//div[contains(@class,'product-item__price-list')]//span[contains(@class,'price--highlight')]";
        $price_tag = $xpath->query($price_query, $card)->item(0);
         if ($price_tag) {
             $price_clone = $price_tag->cloneNode(true);
             $hidden_span = $xpath->query(".//span[contains(@class,'visually-hidden')]", $price_clone)->item(0);
             if ($hidden_span) { $hidden_span->parentNode->removeChild($hidden_span); }
             $deal_data['price'] = dsp_get_node_text($price_clone);
         } else {
              error_log("DSP Parser {$site_name}: Card {$count} - Could not find price using query: {$price_query}");
              $price_fallback_query = ".//div[contains(@class,'product-item__price-list')]";
              $price_fallback_tag = $xpath->query($price_fallback_query, $card)->item(0);
              if ($price_fallback_tag) { $deal_data['price'] = dsp_get_node_text($price_fallback_tag); }
         }

        // Description
        $desc_query = ".//div[contains(@class, 'product-description')]/p";
        $desc_tag = $xpath->query($desc_query, $card)->item(0);
        $deal_data['description'] = $desc_tag ? dsp_get_node_text($desc_tag) : '';
        // --- End Data Extraction ---


        // --- LTD Check REMOVED - Assumed true by default ---
        // $title_check = ...
        // $price_check = ...
        // $desc_check = ...
        // $desc_otp_check = ...
        // $deal_data['is_ltd'] = $title_check || $price_check || $desc_check || $desc_otp_check;
        // --- End LTD Check Removal ---


        // Add deal if essential data is present
        if ( !empty($deal_data['link']) && $deal_data['link'] !== '#' && !empty($deal_data['title']) ) {
            // Trim whitespace
            $deal_data['title'] = trim($deal_data['title']);
            $deal_data['price'] = trim($deal_data['price']) ?: 'N/A';
            $deal_data['description'] = trim($deal_data['description']);
            $deals[] = $deal_data;
        } else {
            if ($count <= 5) { error_log("DSP Parser {$site_name}: Skipping Card {$count} due to missing link/title. Link='".($deal_data['link'] ?? 'NULL')."', Title='".($deal_data['title'] ?? 'NULL')."'"); }
            elseif ($count == 6) { error_log("DSP Parser {$site_name}: (Skipping further individual card skip logs)"); }
        }
    } // End foreach loop

    error_log("DSP Parser {$site_name}: Finished processing. Extracted " . count($deals) . " valid deals (marked as LTD).");
    return $deals;
}
?>