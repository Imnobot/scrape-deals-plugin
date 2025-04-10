<?php
// File: includes/parser-helpers.php
// Contains common helper functions used by individual parsers.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'dsp_get_dom_xpath' ) ) {
    /**
     * Helper function to create DOMDocument and DOMXPath from HTML.
     * Returns false on failure.
     * @param string $html The HTML content.
     * @param string $url The URL for logging errors.
     * @return DOMXPath|false DOMXPath object or false on failure.
     */
    function dsp_get_dom_xpath($html, $url = '') {
        if ( empty($html) ) {
            error_log("DSP Parser Helper: Received empty HTML string for URL: " . $url);
            return false;
        }

        $dom = new DOMDocument();
        // Suppress errors during loading as HTML might be imperfect
        libxml_use_internal_errors(true);
        // Recommended flags for potentially malformed HTML5
        if (!$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED)) {
             $load_errors = libxml_get_errors();
             libxml_clear_errors();
             error_log("DSP Parser Helper: Failed basic HTML load for URL: " . $url . ". Errors: " . print_r($load_errors, true));
             return false;
        }
        libxml_clear_errors(); // Clear any logged errors
        return new DOMXPath($dom);
    }
}

if ( ! function_exists( 'dsp_get_node_text' ) ) {
    /**
     * Helper to get text content cleanly from a DOMNode or DOMNodeList item.
     * @param DOMNode|null $node The node to extract text from.
     * @return string The trimmed text content.
     */
    function dsp_get_node_text($node) {
        // Ensure $node is a valid DOMNode before accessing textContent
        return ($node instanceof DOMNode) ? trim(preg_replace('/\s+/', ' ', $node->textContent)) : '';
    }
}

if ( ! function_exists( 'dsp_get_node_attribute' ) ) {
    /**
     * Helper to get attribute value from a DOMElement.
     * @param DOMElement|null $node The node to extract attribute from.
     * @param string $attribute The attribute name.
     * @return string|null The attribute value or null if not found/invalid node.
     */
    function dsp_get_node_attribute($node, $attribute) {
        // Ensure it's a DOMElement that can have attributes
        return ($node instanceof DOMElement && $node->hasAttribute($attribute)) ? trim($node->getAttribute($attribute)) : null;
    }
}

if ( ! function_exists( 'dsp_make_absolute_url' ) ) {
    /**
     * Helper to build absolute URL from a relative URL and a base URL.
     * @param string $relative_url The relative URL found.
     * @param string $base_url The base URL of the page scraped.
     * @return string The absolute URL.
     */
     function dsp_make_absolute_url($relative_url, $base_url) {
         // If already absolute, empty, or not a string, return as is
         if (empty($relative_url) || !is_string($relative_url) || preg_match('/^https?:\/\//i', $relative_url)) {
            return $relative_url;
        }
         // If base URL is invalid, return relative
         if (empty($base_url) || !($base_parts = parse_url($base_url))) {
             return $relative_url;
         }
         // Must have scheme and host in base
         $scheme = $base_parts['scheme'] ?? null;
         $host = $base_parts['host'] ?? null;
         if (!$scheme || !$host) {
             return $relative_url; // Cannot build absolute without base scheme/host
         }

         $port = isset($base_parts['port']) ? ':' . $base_parts['port'] : '';
         $user = $base_parts['user'] ?? '';
         $pass = isset($base_parts['pass']) ? ':' . $base_parts['pass']  : '';
         $pass = ($user || $pass) ? "$pass@" : '';
         $base_path = $base_parts['path'] ?? '/';

         // Handle scheme-relative URLs (//...)
         if (strpos($relative_url, '//') === 0) {
            return $scheme . ':' . $relative_url;
         }
         // Handle root-relative URLs (/...)
         if (strpos($relative_url, '/') === 0) {
            return $scheme . '://' . $user . $pass . $host . $port . $relative_url;
         }

         // Handle relative paths (path/to/file or ../path)
         // Get directory path of the base URL
         $path = dirname($base_path);
         // Ensure path ends with / if it's not just /
         if ($path !== '/' && substr($path, -1) !== '/') {
            $path .= '/';
         }
         // Combine directory path with relative URL
         $full_relative_path = $path . $relative_url;

         // Resolve ../ and ./ segments
         $path_parts = explode('/', $full_relative_path);
         $absolutes = array();
         foreach ($path_parts as $part) {
            if ('.' == $part || '' == $part) continue; // Ignore . and empty segments
            if ('..' == $part) {
                array_pop($absolutes); // Go up one level
            } else {
                $absolutes[] = $part; // Add path segment
            }
         }
         $absolute_path = '/' . implode('/', $absolutes); // Ensure leading slash

         return $scheme . '://' . $user . $pass . $host . $port . $absolute_path;
    }
}
?>