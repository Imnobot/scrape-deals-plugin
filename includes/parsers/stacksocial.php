<?php
// File: includes/parsers/stacksocial.php

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
        if (!$link_tag) {
            // Fallback: find any link containing a heading or strong tag
            $link_tag = $xpath->query(".//a[.//h2 or .//h3 or .//h4 or .//strong]", $card)->item(0);
        }
        if (!$link_tag) continue; // Skip if no link found

        // Title - look for specific classes first, then generic strong/heading within link
        $title_tag = $xpath->query(".//strong[contains(@class, 'chakra-text') and contains(@class, 'css-pexdy8')]", $link_tag)->item(0);
        if (!$title_tag) {
             $title_tag = $xpath->query(".//strong | .//h2 | .//h3 | .//h4", $link_tag)->item(0);
        }

        // Price - look for specific classes first, then generic span with currency
        $price_tag = $xpath->query(".//span[contains(@class, 'chakra-text') and contains(@class, 'css-15mmxo2')]", $link_tag)->item(0);
        if (!$price_tag) {
             $price_tag = $xpath->query(".//span[contains(text(), '$') or contains(text(), '€') or contains(text(), '£')]", $link_tag)->item(0);
        }

        $link_raw = dsp_get_node_attribute($link_tag, 'href');
        $link = dsp_make_absolute_url($link_raw, $base_url);
        $title = dsp_get_node_text($title_tag);
        $price = dsp_get_node_text($price_tag);

        if ($link && $link !== '#' && $title) {
            $deals[] = [
                'title' => $title,
                'price' => $price ?: 'N/A',
                'link' => $link,
                'source' => 'StackSocial',
                'description' => ''
            ];
        } else {
             error_log("DSP Parser StackSocial: Skipping card due to missing link/title. Link: " . ($link ?? 'null') . " Title: " . ($title ?? 'null'));
        }
    }
    error_log("DSP Parser StackSocial: Extracted " . count($deals) . " deals.");
    return $deals;
}
?>