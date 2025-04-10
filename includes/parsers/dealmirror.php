<?php
// File: includes/parsers/dealmirror.php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parses DealMirror deals from HTML content.
 * Assumes helper functions from parser-helpers.php are available.
 *
 * @param string $html The HTML content of the page.
 * @param string $base_url The base URL for resolving relative links.
 * @return array Array of deals found.
 */
function parse_dealmirror_php($html, $base_url) {
    $xpath = dsp_get_dom_xpath($html, $base_url);
    if (!$xpath) return [];
    $deals = [];
    error_log("DSP Parser DealMirror: Starting parser function.");

    // Broad selector for product containers
    $deal_cards = $xpath->query("//div[contains(@class, 'product')] | //li[contains(@class, 'product')]");

    if ($deal_cards === false || $deal_cards->length === 0) {
        error_log("DSP Parser DealMirror: [ERROR] Could not find ANY deal card elements. Verify site structure.");
        return [];
     }
     error_log("DSP Parser DealMirror: Found {$deal_cards->length} potential card elements.");

    $count = 0;
    foreach ($deal_cards as $card) {
        $count++;

        // Title & Link: Try specific heading links first
        $title_link_tag = $xpath->query(".//h4[contains(@class, 'ht-product-title')]/a | .//h2[contains(@class, 'woocommerce-loop-product__title')]/a", $card)->item(0);
        $link_raw = dsp_get_node_attribute($title_link_tag, 'href');
        $link = dsp_make_absolute_url($link_raw, $base_url);
        $title = $title_link_tag ? dsp_get_node_text($title_link_tag) : null;

        // Fallback title if link text was empty or tag not found
         if (!$title) {
             $title_tag_fallback = $xpath->query(".//h2 | .//h3 | .//h4", $card)->item(0); // Any heading inside card
             if ($title_tag_fallback) {
                 $title = dsp_get_node_text($title_tag_fallback);
                 // If link still missing, try finding a link within this fallback title tag
                 if (!$link || $link === '#') {
                    $link_in_title = $xpath->query(".//a", $title_tag_fallback)->item(0);
                    $link_raw = dsp_get_node_attribute($link_in_title, 'href');
                    $link = dsp_make_absolute_url($link_raw, $base_url);
                 }
             }
         }
         // Absolute link fallback: find first link in card
          if (!$link || $link === '#') {
              $link_tag_fallback = $xpath->query(".//a", $card)->item(0);
              $link_raw = dsp_get_node_attribute($link_tag_fallback, 'href');
              $link = dsp_make_absolute_url($link_raw, $base_url);
          }


        // Price: Prioritize sale price (<ins>), then regular (not <del>/<ins>), then any price amount
        $price = 'N/A';
        $price_tag_sale = $xpath->query(".//span[contains(@class, 'price')]//ins//span[contains(@class, 'woocommerce-Price-amount')]", $card)->item(0);
        if ($price_tag_sale) {
            $price = dsp_get_node_text($price_tag_sale);
        } else {
            $price_tag_regular = $xpath->query(".//span[contains(@class, 'price')]//span[contains(@class, 'woocommerce-Price-amount')][not(ancestor::del) and not(ancestor::ins)]", $card)->item(0);
            if ($price_tag_regular) {
                $price = dsp_get_node_text($price_tag_regular);
            } else {
                $price_tag_fallback = $xpath->query(".//span[contains(@class, 'price')]//span[contains(@class, 'woocommerce-Price-amount')]", $card)->item(0);
                if ($price_tag_fallback) {
                    $price = dsp_get_node_text($price_tag_fallback);
                }
            }
        }


        if ($link && $link !== '#' && $title && $title !== 'N/A') {
            $deals[] = [
                'title' => $title,
                'price' => $price,
                'link' => $link,
                'source' => 'DealMirror',
                'description' => ''
            ];
        } else {
              if ($count <= 5) {
                error_log("DSP Parser DealMirror: Skipping Card {$count} due to missing essential data. Link='".($link ?? 'NULL')."', Title='".($title ?? 'NULL')."'");
              } else if ($count == 6) {
                 error_log("DSP Parser DealMirror: (Skipping further individual skip logs)");
              }
        }
    }
     error_log("DSP Parser DealMirror: Finished processing cards. Extracted " . count($deals) . " valid deals.");
    return $deals;
}
?>