<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helper function to create DOMDocument and DOMXPath from HTML.
 * Returns false on failure.
 */
function dsp_get_dom_xpath($html, $url = '') {
    // ... (dsp_get_dom_xpath function remains the same as previous version) ...
    if ( empty($html) ) {
        error_log("DSP Parser: Received empty HTML string for URL: " . $url);
        return false;
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED)) {
         $load_errors = libxml_get_errors();
         libxml_clear_errors();
         error_log("DSP Parser: Failed basic HTML load for URL: " . $url . ". Errors: " . print_r($load_errors, true));
         return false;
    }
    libxml_clear_errors();
    return new DOMXPath($dom);
}

/**
 * Helper to get text content cleanly.
 */
function dsp_get_node_text($node) {
    // ... (dsp_get_node_text function remains the same) ...
    return $node ? trim(preg_replace('/\s+/', ' ', $node->textContent)) : '';
}

/**
 * Helper to get attribute value.
 */
function dsp_get_node_attribute($node, $attribute) {
    // ... (dsp_get_node_attribute function remains the same) ...
    return $node && $node->hasAttribute($attribute) ? trim($node->getAttribute($attribute)) : null;
}

/**
 * Helper to build absolute URL.
 */
 function dsp_make_absolute_url($relative_url, $base_url) {
    // ... (dsp_make_absolute_url function remains the same) ...
     if (empty($relative_url) || empty($base_url) || preg_match('/^https?:\/\//i', $relative_url)) {
        return $relative_url;
    }
    $base_parts = parse_url($base_url);
    if (!$base_parts) return $relative_url;
    $scheme = $base_parts['scheme'] ?? 'http';
    $host = $base_parts['host'] ?? '';
    if (!$host) return $relative_url;
    $port = isset($base_parts['port']) ? ':' . $base_parts['port'] : '';
    $user = $base_parts['user'] ?? '';
    $pass = isset($base_parts['pass']) ? ':' . $base_parts['pass']  : '';
    $pass = ($user || $pass) ? "$pass@" : '';
    $base_path = $base_parts['path'] ?? '/';
    if (strpos($relative_url, '//') === 0) {
        return $scheme . ':' . $relative_url;
    }
    if (strpos($relative_url, '/') === 0) {
        return $scheme . '://' . $user . $pass . $host . $port . $relative_url;
    }
    $path = dirname($base_path);
    $path_parts = explode('/', $path . '/' . $relative_url);
    $absolutes = array();
    foreach ($path_parts as $part) {
        if ('.' == $part) continue;
        if ('..' == $part) {
            array_pop($absolutes);
        } else {
            $absolutes[] = $part;
        }
    }
    $absolute_path = implode('/', $absolutes);
     if (strlen($absolute_path) > 0 && $absolute_path[0] !== '/') {
        $absolute_path = '/' . $absolute_path;
     } elseif (empty($absolute_path)) {
        $absolute_path = '/';
     }
    return $scheme . '://' . $user . $pass . $host . $port . $absolute_path;
}


// --- Specific Parsers ---

function parse_appsumo_php($html, $base_url) {
    $xpath = dsp_get_dom_xpath($html, $base_url);
    if (!$xpath) return [];
    $deals = [];

    // Based on Python: Select the main card div first
    $deal_cards = $xpath->query("//div[contains(@class, 'relative') and contains(@class, 'h-full') and .//a[contains(@class, 'absolute')]]"); // Find divs that are relative, full height, and contain the specific link type

    if ($deal_cards === false || $deal_cards->length === 0) {
        error_log("DSP Parser AppSumo: Could not find deal card elements using Python-based selector. Verification needed.");
        // Add fallbacks if necessary based on inspection
        $deal_cards = $xpath->query("//div[contains(@class,'product-card')]"); // A common pattern as fallback
         if ($deal_cards === false || $deal_cards->length === 0) {
             error_log("DSP Parser AppSumo: Fallback selector '//div[contains(@class,'product-card')]' also failed.");
             return [];
         }
    }
    error_log("DSP Parser AppSumo: Found {$deal_cards->length} potential card elements.");

    foreach ($deal_cards as $card) {
        // Now query relative to the found card div, using translated Python selectors
        $link_tag = $xpath->query(".//a[contains(@class, 'absolute') and contains(@class, 'h-full') and contains(@class, 'w-full')]", $card)->item(0);
        // Fallback link selector if the primary one fails inside the card
        if (!$link_tag) {
            $link_tag = $xpath->query(".//a[contains(@class,'product-card') or contains(@class,'deal-card-details')]", $card)->item(0);
        }

        $title_tag = $xpath->query(".//span[contains(@class, 'overflow-hidden') and contains(@class, 'font-bold')]", $card)->item(0);
        $price_tag = $xpath->query(".//span[@id='deal-price']", $card)->item(0);
         // Fallback price selector
         if (!$price_tag) {
             $price_tag = $xpath->query(".//*[contains(@class,'price')]", $card)->item(0);
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


function parse_stacksocial_php($html, $base_url) {
    $xpath = dsp_get_dom_xpath($html, $base_url);
    if (!$xpath) return [];
    $deals = [];

    // Based on Python: Select the main article card
    $deal_cards = $xpath->query("//article[contains(@class, 'chakra-card')]");

     if ($deal_cards === false || $deal_cards->length === 0) {
        error_log("DSP Parser StackSocial: Could not find deal card elements using //article[contains(@class, 'chakra-card')]");
        return [];
    }
    error_log("DSP Parser StackSocial: Found {$deal_cards->length} potential card elements.");

    foreach ($deal_cards as $card) {
        // Find elements relative to the card using translated Python selectors
        $link_tag = $xpath->query(".//a[contains(@class, 'showcase-item-link')]", $card)->item(0);
        // Fallback if specific link class not found, try any link within the card header/body area
        if (!$link_tag) {
            $link_tag = $xpath->query(".//a[.//strong]", $card)->item(0); // Find link containing a strong tag (likely title)
        }
        if (!$link_tag) continue; // Skip card if no usable link found

        // Title and Price - search relative to the found link_tag
        $title_tag = $xpath->query(".//strong[contains(@class, 'chakra-text') and contains(@class, 'css-pexdy8')]", $link_tag)->item(0);
        // Fallback title within link
        if (!$title_tag) {
             $title_tag = $xpath->query(".//strong", $link_tag)->item(0);
        }

        $price_tag = $xpath->query(".//span[contains(@class, 'chakra-text') and contains(@class, 'css-15mmxo2')]", $link_tag)->item(0);
        // Fallback price within link
         if (!$price_tag) {
             $price_tag = $xpath->query(".//span[contains(text(), '$') or contains(text(), '€') or contains(text(), '£')]", $link_tag)->item(0); // Find span containing currency
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


function parse_dealfuel_php($html, $base_url) {
    $xpath = dsp_get_dom_xpath($html, $base_url);
    if (!$xpath) return [];
    $deals = [];

    // Python selectors were already WooCommerce-like and working
    $deal_cards = $xpath->query("//li[contains(@class, 'product') and contains(@class, 'type-product')] | //li[contains(@class, 'product')]");

    if ($deal_cards === false || $deal_cards->length === 0) {
        error_log("DSP Parser DealFuel: Could not find deal card elements using //li[contains(@class, 'product')]");
        return [];
    }
    error_log("DSP Parser DealFuel: Found {$deal_cards->length} potential card elements.");

    foreach ($deal_cards as $card) {
        // Logic based on Python's refined approach
        $link = '#';
        $title = 'N/A';
        $price = 'N/A';

        $product_link_tag = $xpath->query(".//a[contains(@class, 'woocommerce-LoopProduct-link')]", $card)->item(0);
        if ($product_link_tag) {
            $link_raw = dsp_get_node_attribute($product_link_tag, 'href');
            $link = dsp_make_absolute_url($link_raw, $base_url);
            $title_tag = $xpath->query(".//h2[contains(@class, 'woocommerce-loop-product__title')]", $product_link_tag)->item(0);
             if($title_tag) {
                 $title = dsp_get_node_text($title_tag);
             } else { // Fallback title inside card if not in link
                $title_tag_fallback = $xpath->query(".//h2[contains(@class, 'woocommerce-loop-product__title')]", $card)->item(0);
                $title = dsp_get_node_text($title_tag_fallback);
             }
        } else {
             // Fallback if no main product link found
             $title_tag_direct_link = $xpath->query(".//h2[contains(@class, 'woocommerce-loop-product__title')]/a", $card)->item(0);
             if ($title_tag_direct_link) {
                  $link_raw = dsp_get_node_attribute($title_tag_direct_link, 'href');
                  $link = dsp_make_absolute_url($link_raw, $base_url);
                  $title = dsp_get_node_text($title_tag_direct_link);
             } else { // Absolute fallback for title
                 $title_tag_fallback = $xpath->query(".//h2[contains(@class, 'woocommerce-loop-product__title')]", $card)->item(0);
                 $title = dsp_get_node_text($title_tag_fallback);
             }
        }

        // Price Logic (matches Python logic)
        $price_container = $xpath->query(".//span[contains(@class, 'price')]", $card)->item(0);
        if ($price_container) {
            $sale_price_tag = $xpath->query(".//ins//span[contains(@class, 'woocommerce-Price-amount')]", $price_container)->item(0);
            if ($sale_price_tag) {
                $price = dsp_get_node_text($sale_price_tag);
            } else {
                $regular_price_tag = $xpath->query(".//span[contains(@class, 'woocommerce-Price-amount')][not(ancestor::del) and not(ancestor::ins)]", $price_container)->item(0);
                 if ($regular_price_tag) {
                    $price = dsp_get_node_text($regular_price_tag);
                 } else {
                     // Fallback: full text minus deleted price (less reliable but matches Python)
                     $original_price_node = $xpath->query(".//del", $price_container)->item(0);
                     $full_price_text = dsp_get_node_text($price_container);
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


function parse_dealmirror_php($html, $base_url) {
    $xpath = dsp_get_dom_xpath($html, $base_url);
    if (!$xpath) return [];
    $deals = [];
    error_log("DSP Parser DealMirror: Starting parser function."); // Added start log

    // Based on Python: Select the main product div/li - Keep it somewhat broad initially
    $deal_cards = $xpath->query("//div[contains(@class, 'product')] | //li[contains(@class, 'product')]");

    if ($deal_cards === false || $deal_cards->length === 0) {
        error_log("DSP Parser DealMirror: [ERROR] Could not find ANY deal card elements using '//div[contains(@class, 'product')] | //li[contains(@class, 'product')]'. Verify site structure.");
        return [];
     }
     error_log("DSP Parser DealMirror: Found {$deal_cards->length} potential card elements.");

    $count = 0; // Counter for logging first few items
    foreach ($deal_cards as $card) {
        $count++;
        if ($count <= 3) error_log("DSP Parser DealMirror: --- Processing Card {$count} ---"); // Log start of card processing

        // Try finding elements based on Python selectors and common fallbacks
        $title_link_tag = $xpath->query(".//h4[contains(@class, 'ht-product-title')]/a | .//h2[contains(@class, 'woocommerce-loop-product__title')]/a", $card)->item(0);
        $price_tag_sale = $xpath->query(".//span[contains(@class, 'price')]//ins//span[contains(@class, 'woocommerce-Price-amount') and contains(@class, 'amount')]", $card)->item(0);
        $price_tag_regular = null;
        if (!$price_tag_sale) {
            $price_tag_regular = $xpath->query(".//span[contains(@class, 'price')]//span[contains(@class, 'woocommerce-Price-amount') and contains(@class, 'amount') and not(ancestor::del) and not(ancestor::ins)]", $card)->item(0);
        }
        // Absolute fallback for price if others fail
        $price_tag_fallback = null;
         if (!$price_tag_sale && !$price_tag_regular) {
             $price_tag_fallback = $xpath->query(".//span[contains(@class, 'price')]//span[contains(@class, 'woocommerce-Price-amount')]", $card)->item(0);
         }


        $link_raw = dsp_get_node_attribute($title_link_tag, 'href');
        $link = dsp_make_absolute_url($link_raw, $base_url);

        $title_from_link_text = $title_link_tag ? dsp_get_node_text($title_link_tag) : null;
        $title = $title_from_link_text; // Start with title from link text

         if (!$title && $title_link_tag) { // Fallback to parent heading text if link text empty
             $parent_heading = $xpath->query("ancestor::h4 | ancestor::h2", $title_link_tag)->item(0);
             if($parent_heading) $title = dsp_get_node_text($parent_heading);
         }
         // If still no title, try finding any H tag directly under card
          if (!$title) {
              $title_tag_fallback = $xpath->query(".//h2 | .//h3 | .//h4", $card)->item(0);
              $title = dsp_get_node_text($title_tag_fallback);
          }

        $price = 'N/A';
        $price_source = 'N/A'; // To log which price selector worked
        if ($price_tag_sale) {
            $price = dsp_get_node_text($price_tag_sale);
            $price_source = 'Sale';
        } elseif ($price_tag_regular) {
            $price = dsp_get_node_text($price_tag_regular);
            $price_source = 'Regular';
        } elseif ($price_tag_fallback) {
             $price = dsp_get_node_text($price_tag_fallback);
             $price_source = 'Fallback';
        }

        // Log findings for the first few cards BEFORE the filter
        if ($count <= 3) {
            error_log("DSP Parser DealMirror: Card {$count}: Title Link Tag Found? " . ($title_link_tag ? 'Yes' : 'No'));
            error_log("DSP Parser DealMirror: Card {$count}: Link Raw: " . ($link_raw ?? 'N/A'));
            error_log("DSP Parser DealMirror: Card {$count}: Link Final: " . ($link ?? 'N/A'));
            error_log("DSP Parser DealMirror: Card {$count}: Title From Link Text: " . ($title_from_link_text ?: 'N/A'));
            error_log("DSP Parser DealMirror: Card {$count}: Title Final: " . ($title ?: 'N/A'));
            error_log("DSP Parser DealMirror: Card {$count}: Price Source: " . $price_source);
            error_log("DSP Parser DealMirror: Card {$count}: Price Final: " . ($price ?: 'N/A'));
        }

        // Filter: Check if essential data was found
        if ($link && $link !== '#' && $title && $title !== 'N/A') {
            $deals[] = [
                'title' => $title,
                'price' => $price,
                'link' => $link,
                'source' => 'DealMirror',
                'description' => '' // DealMirror description usually not on listing page
            ];
        } else {
              if ($count <= 5) { // Log skips for first few cards only
                error_log("DSP Parser DealMirror: Skipping Card {$count} due to missing essential data. Link='".($link ?? 'NULL')."', Title='".($title ?? 'NULL')."'");
              } else if ($count == 6) {
                 error_log("DSP Parser DealMirror: (Skipping further individual skip logs for brevity)");
              }
        }
    }
     error_log("DSP Parser DealMirror: Finished processing cards. Extracted " . count($deals) . " valid deals.");
    return $deals;
}