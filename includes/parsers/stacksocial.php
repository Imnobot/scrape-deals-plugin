<?php
// File: includes/parsers/stacksocial.php (v1.1.36 - Fix Price Extraction)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parses StackSocial deals from HTML content.
 * Assumes helper functions from parser-helpers.php are available.
 *
 * @param string $html The HTML content of the page.
 * @param string $base_url The base URL for resolving relative links.
 * @param string $site_name The configured name of the site ('StackSocial').
 * @return array Array of deals found.
 */
function parse_stacksocial_php($html, $base_url, $site_name = 'StackSocial') { // Added site_name
    $xpath = dsp_get_dom_xpath($html, $base_url);
    if (!$xpath) return [];
    $deals = [];

    // --- SELECTORS (Verified based on inspector snippet & fixed Price) ---
    // Container might be article or div
    $deal_container_xpath = "//article[contains(@class, 'chakra-card')] | //div[contains(@class, 'border') and contains(@class, 'shadow-md')]";
    // Link often wraps title or image
    $link_xpath = ".//a[contains(@class, 'showcase-item-link')] | .//a[.//strong or .//h3]";
    // Title is usually strong or h3 inside the link or card
    $title_xpath = ".//strong[contains(@class, 'chakra-text')] | .//h3[contains(@class, 'text-lg')]";
    // *** UPDATED Price XPath ***
    // Try specific class from old version, then span with currency, then div with currency
    $price_xpath = ".//span[contains(@class, 'chakra-text') and contains(@class, 'css-15mmxo2')] | .//span[contains(text(), '$') or contains(text(), '€') or contains(text(), '£')] | .//div[contains(@class, 'font-bold') and (contains(text(), '$') or contains(text(), '€') or contains(text(), '£'))]";
    // Description
    $desc_xpath = ".//p[contains(@class, 'chakra-text')]";
    // Image selector
    $image_xpath = ".//img[contains(@class, 'chakra-image')] | .//div[contains(@class,'h-40')]//img";

    // Query for deal containers
    $deal_cards = $xpath->query($deal_container_xpath);
    if ($deal_cards === false || $deal_cards->length === 0) { error_log("DSP Parser {$site_name}: Could not find deal card elements using XPath: {$deal_container_xpath}"); return []; }
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
        $link_node = $xpath->query($link_xpath, $card)->item(0);
        if ($link_node) {
             $link_raw = dsp_get_node_attribute($link_node, 'href');
             $deal_data['link'] = dsp_make_absolute_url($link_raw, $base_url);
             // Try finding title within the link first, then within the card as fallback
             $title_node = $xpath->query($title_xpath, $link_node)->item(0) ?: $xpath->query($title_xpath, $card)->item(0);
             if ($title_node) { $deal_data['title'] = dsp_get_node_text($title_node); }
        } else {
            // Fallback if specific link structure not found, try any link and title within card
            $any_link_node = $xpath->query('.//a', $card)->item(0);
             if ($any_link_node) { $link_raw = dsp_get_node_attribute($any_link_node, 'href'); $deal_data['link'] = dsp_make_absolute_url($link_raw, $base_url); }
             $title_node_card = $xpath->query($title_xpath, $card)->item(0);
             if ($title_node_card) $deal_data['title'] = dsp_get_node_text($title_node_card);
        }

        // Skip if essential data missing
        if (empty($deal_data['link']) || $deal_data['link'] === '#' || empty($deal_data['title'])) {
            if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Skipping due to missing link/title.");
            continue;
        }

        // Get Price (using the updated XPath)
        $price_node = $xpath->query($price_xpath, $card)->item(0);
        $deal_data['price'] = $price_node ? dsp_get_node_text($price_node) : 'N/A'; // Assign text or 'N/A'

        // Get Description
        $desc_node = $xpath->query($desc_xpath, $card)->item(0);
        $deal_data['description'] = $desc_node ? dsp_get_node_text($desc_node) : '';

        // LTD Check
        $title_check = is_string($deal_data['title']) && stripos($deal_data['title'], 'lifetime') !== false;
        // Check price *before* potentially overwriting with 'N/A'
        $price_text_for_check = $price_node ? dsp_get_node_text($price_node) : '';
        $price_check = is_string($price_text_for_check) && stripos($price_text_for_check, 'lifetime') !== false;
        $desc_check = is_string($deal_data['description']) && stripos($deal_data['description'], 'lifetime') !== false;
        $deal_data['is_ltd'] = $title_check || $price_check || $desc_check;

        // Get Image URL
        $image_node = $xpath->query($image_xpath, $card)->item(0);
        if ($image_node) {
            $img_src = '';
            // Check attributes in order: data-src, src, srcset (on img tag)
            if ($image_node->hasAttribute('data-src')) { $img_src = $image_node->getAttribute('data-src'); }
            elseif ($image_node->hasAttribute('src')) { $img_src = $image_node->getAttribute('src'); }
            elseif ($image_node->hasAttribute('srcset')) { $srcset = $image_node->getAttribute('srcset'); $srcs = explode(',', $srcset); if (!empty($srcs)) { $first_src = trim(explode(' ', trim($srcs[0]))[0]); if (!empty($first_src)) $img_src = $first_src; } }

            if (!empty($img_src)) {
                $deal_data['image_url'] = function_exists('dsp_make_absolute_url') ? dsp_make_absolute_url($img_src, $base_url) : $img_src;
            } else { if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Image tag found but no src/data-src/srcset for '{$deal_data['title']}'."); }
        } else { if ($count <= 5 || $count % 50 == 0) error_log("DSP Parser {$site_name}: Card {$count} - Image not found using XPath '{$image_xpath}' for '{$deal_data['title']}'."); }

        // Clean up and Add deal
        $deal_data['title'] = trim($deal_data['title']);
        $deal_data['price'] = trim($deal_data['price']) ?: 'N/A'; // Ensure 'N/A' if trim results in empty string
        $deal_data['description'] = trim($deal_data['description']);
        $deals[] = $deal_data;

    } // End foreach
    error_log("DSP Parser {$site_name}: Extracted " . count($deals) . " deals.");
    return $deals;
}