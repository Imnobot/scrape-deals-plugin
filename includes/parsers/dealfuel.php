<?php
// File: includes/parsers/dealfuel.php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parses DealFuel deals from HTML content.
 * Assumes helper functions from parser-helpers.php are available.
 *
 * @param string $html The HTML content of the page.
 * @param string $base_url The base URL for resolving relative links.
 * @return array Array of deals found.
 */
function parse_dealfuel_php($html, $base_url) {
    $xpath = dsp_get_dom_xpath($html, $base_url);
    if (!$xpath) return [];
    $deals = [];

    // WooCommerce-like selector
    $deal_cards = $xpath->query("//li[contains(@class, 'product')]");

    if ($deal_cards === false || $deal_cards->length === 0) {
        error_log("DSP Parser DealFuel: Could not find deal card elements using //li[contains(@class, 'product')]");
        return [];
    }
    error_log("DSP Parser DealFuel: Found {$deal_cards->length} potential card elements.");

    foreach ($deal_cards as $card) {
        $link = '#'; $title = 'N/A'; $price = 'N/A';

        // Primary link/title source
        $product_link_tag = $xpath->query(".//a[contains(@class, 'woocommerce-LoopProduct-link')]", $card)->item(0);
        if ($product_link_tag) {
            $link_raw = dsp_get_node_attribute($product_link_tag, 'href');
            $link = dsp_make_absolute_url($link_raw, $base_url);
            $title_tag = $xpath->query(".//h2[contains(@class, 'woocommerce-loop-product__title')]", $product_link_tag)->item(0);
             if($title_tag) $title = dsp_get_node_text($title_tag);
        }

        // Fallback link/title source if primary failed
        if ($link === '#' || $title === 'N/A') {
            $title_tag_direct_link = $xpath->query(".//h2[contains(@class, 'woocommerce-loop-product__title')]/a", $card)->item(0);
             if ($title_tag_direct_link) {
                  $link_raw = dsp_get_node_attribute($title_tag_direct_link, 'href');
                  $link = dsp_make_absolute_url($link_raw, $base_url);
                  $title = dsp_get_node_text($title_tag_direct_link);
             } else { // Absolute fallback for title if link text was empty
                 $title_tag_fallback = $xpath->query(".//h2[contains(@class, 'woocommerce-loop-product__title')]", $card)->item(0);
                 if($title_tag_fallback) $title = dsp_get_node_text($title_tag_fallback);
             }
        }

        // Price Logic
        $price_container = $xpath->query(".//span[contains(@class, 'price')]", $card)->item(0);
        if ($price_container) {
            $sale_price_tag = $xpath->query(".//ins//span[contains(@class, 'woocommerce-Price-amount')]", $price_container)->item(0);
            if ($sale_price_tag) { $price = dsp_get_node_text($sale_price_tag); }
            else {
                // Try regular price (not inside <del> or <ins>)
                $regular_price_tag = $xpath->query(".//span[contains(@class, 'woocommerce-Price-amount')][not(ancestor::del) and not(ancestor::ins)]", $price_container)->item(0);
                 if ($regular_price_tag) { $price = dsp_get_node_text($regular_price_tag); }
                 else { // Final fallback: grab anything, trying to exclude <del> text
                     $full_price_text = dsp_get_node_text($price_container);
                     $original_price_node = $xpath->query(".//del", $price_container)->item(0);
                     if($original_price_node) {
                         $del_text = dsp_get_node_text($original_price_node);
                         $full_price_text = trim(str_replace($del_text, '', $full_price_text));
                     }
                     $price = $full_price_text ?: 'N/A';
                 }
            }
        }

        if ($link && $link !== '#' && $title && $title !== 'N/A') {
            $deals[] = [
                'title' => $title,
                'price' => $price,
                'link' => $link,
                'source' => 'DealFuel',
                'description' => ''
            ];
        } else {
             error_log("DSP Parser DealFuel: Skipping card due to missing link/title. Link: " . ($link ?? 'null') . " Title: " . ($title ?? 'null'));
        }
    }
    error_log("DSP Parser DealFuel: Extracted " . count($deals) . " deals.");
    return $deals;
}
?>