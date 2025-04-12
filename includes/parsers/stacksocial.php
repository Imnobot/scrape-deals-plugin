<?php
// File: includes/parsers/stacksocial.php (v1.1.22 - Add is_ltd detection)

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parses StackSocial deals from HTML content.
 * Assumes helper functions from parser-helpers.php are available.
 *
 * @param string $html The HTML content of the page.
 * @param string $base_url The base URL for resolving relative links.
 * @return array Array of deals found.
 */
function parse_stacksocial_php($html, $base_url) {
    $xpath = dsp_get_dom_xpath($html, $base_url);
    if (!$xpath) return [];
    $deals = [];

    // Main article card selector
    $deal_cards = $xpath->query("//article[contains(@class, 'chakra-card')]");

     if ($deal_cards === false || $deal_cards->length === 0) {
        error_log("DSP Parser StackSocial: Could not find deal card elements using //article[contains(@class, 'chakra-card')]");
        return [];
    }
    error_log("DSP Parser StackSocial: Found {$deal_cards->length} potential card elements.");

    foreach ($deal_cards as $card) {
        $link_tag = $xpath->query(".//a[contains(@class, 'showcase-item-link')]", $card)->item(0);
        if (!$link_tag) { $link_tag = $xpath->query(".//a[.//h2 or .//h3 or .//h4 or .//strong]", $card)->item(0); }
        if (!$link_tag) continue; // Skip if no link found

        // Title
        $title_tag = $xpath->query(".//strong[contains(@class, 'chakra-text') and contains(@class, 'css-pexdy8')]", $link_tag)->item(0);
        if (!$title_tag) { $title_tag = $xpath->query(".//strong | .//h2 | .//h3 | .//h4", $link_tag)->item(0); }

        // Price
        $price_tag = $xpath->query(".//span[contains(@class, 'chakra-text') and contains(@class, 'css-15mmxo2')]", $link_tag)->item(0);
        if (!$price_tag) { $price_tag = $xpath->query(".//span[contains(text(), '$') or contains(text(), '€') or contains(text(), '£')]", $link_tag)->item(0); }

        // Description (often not present on list view)
        $desc_tag = $xpath->query(".//p[contains(@class, 'chakra-text') and contains(@class, 'css-1s6hx55')]", $link_tag)->item(0); // Example, adjust if needed


        $link_raw = dsp_get_node_attribute($link_tag, 'href');
        $link = dsp_make_absolute_url($link_raw, $base_url);
        $title = dsp_get_node_text($title_tag);
        $price = dsp_get_node_text($price_tag);
        $description = dsp_get_node_text($desc_tag);


        if ($link && $link !== '#' && $title) {
            // *** NEW: LTD Check ***
            $is_lifetime = false;
            $title_check = is_string($title) && stripos($title,'lifetime') !== false;
            $price_check = is_string($price) && stripos($price,'lifetime') !== false;
            $desc_check = is_string($description) && stripos($description, 'lifetime') !== false;
            $is_lifetime = $title_check || $price_check || $desc_check;
            // *** END LTD Check ***

            $deals[] = [
                'title' => $title,
                'price' => $price ?: 'N/A',
                'link' => $link,
                'source' => 'StackSocial',
                'description' => $description ?: '',
                'is_ltd' => $is_lifetime, // Add the flag
            ];
        } else {
             error_log("DSP Parser StackSocial: Skipping card due to missing link/title. Link: " . ($link ?? 'null') . " Title: " . ($title ?? 'null'));
        }
    }
    error_log("DSP Parser StackSocial: Extracted " . count($deals) . " deals.");
    return $deals;
}
?>