<?php
// File: includes/parsers/appsumo.php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parses AppSumo deals from HTML content.
 * Assumes helper functions from parser-helpers.php are available.
 *
 * @param string $html The HTML content of the page.
 * @param string $base_url The base URL for resolving relative links.
 * @return array Array of deals found.
 */
function parse_appsumo_php($html, $base_url) {
    $xpath = dsp_get_dom_xpath($html, $base_url);
    if (!$xpath) return [];
    $deals = [];

    $deal_cards = $xpath->query("//div[contains(@class, 'relative') and contains(@class, 'h-full') and .//a[contains(@class, 'absolute')]]");

    if ($deal_cards === false || $deal_cards->length === 0) {
        error_log("DSP Parser AppSumo: Primary selector failed. Trying fallback //div[contains(@class,'product-card')]");
        $deal_cards = $xpath->query("//div[contains(@class,'product-card')]");
         if ($deal_cards === false || $deal_cards->length === 0) {
             error_log("DSP Parser AppSumo: Fallback selector also failed.");
             return [];
         }
    }
    error_log("DSP Parser AppSumo: Found {$deal_cards->length} potential card elements.");

    foreach ($deal_cards as $card) {
        $link_tag = $xpath->query(".//a[contains(@class, 'absolute') and contains(@class, 'h-full') and contains(@class, 'w-full')]", $card)->item(0);
        if (!$link_tag) {
            $link_tag = $xpath->query(".//a[contains(@class,'product-card') or contains(@class,'deal-card-details')]", $card)->item(0);
        }

        $title_tag = $xpath->query(".//span[contains(@class, 'overflow-hidden') and contains(@class, 'font-bold')]", $card)->item(0);
        $price_tag = $xpath->query(".//span[@id='deal-price']", $card)->item(0);
         if (!$price_tag) {
             $price_tag = $xpath->query(".//*[contains(@class,'price') or contains(@class,'text-sumo-contrast')][contains(text(),'$')]", $card)->item(0); // Broader price fallback
         }
        $desc_tag = $xpath->query(".//div[contains(@class, 'my-1') and contains(@class, 'line-clamp-3')]", $card)->item(0);

        $link_raw = dsp_get_node_attribute($link_tag, 'href');
        $link = dsp_make_absolute_url($link_raw, $base_url);
        $title = dsp_get_node_text($title_tag);
        $price = dsp_get_node_text($price_tag);
        $description = dsp_get_node_text($desc_tag);

        if ($link && $link !== '#' && $title) {
            $deals[] = [
                'title' => $title,
                'price' => $price ?: 'N/A',
                'link' => $link,
                'source' => 'AppSumo',
                'description' => $description ?: ''
            ];
        } else {
             error_log("DSP Parser AppSumo: Skipping card due to missing link/title. Link: " . ($link ?? 'null') . " Title: " . ($title ?? 'null'));
        }
    }
    error_log("DSP Parser AppSumo: Extracted " . count($deals) . " deals.");
    return $deals;
}
?>