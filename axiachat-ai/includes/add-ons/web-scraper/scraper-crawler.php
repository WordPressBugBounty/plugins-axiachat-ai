<?php
/**
 * Web Scraper – Crawler / Spider
 *
 * BFS crawler that discovers URLs from a root page.
 * Stays within the same domain, respects depth and page limits.
 *
 * @package AxiaChat
 * @subpackage WebScraper
 * @since 3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Crawl a website starting from a root URL.
 *
 * Uses BFS (breadth-first search) to discover pages within the same domain.
 *
 * @param string $root_url   The starting URL.
 * @param int    $max_depth  Maximum link depth to follow (1 = only links on root page).
 * @param int    $max_pages  Maximum number of pages to discover.
 * @return array {
 *     @type array  $urls    Discovered URLs (array of strings).
 *     @type int    $total   Total URLs found.
 *     @type string $domain  The crawled domain.
 *     @type array  $errors  Any errors encountered (array of strings).
 * }
 */
function aichat_webscraper_crawl( $root_url, $max_depth = 2, $max_pages = 50 ) {
    $root_url = esc_url_raw( $root_url );
    if ( empty( $root_url ) ) {
        return [
            'urls'   => [],
            'total'  => 0,
            'domain' => '',
            'errors' => [ __( 'Invalid root URL.', 'axiachat-ai' ) ],
        ];
    }

    // Enforce sane limits
    $max_depth = max( 1, min( 5, (int) $max_depth ) );
    $max_pages = max( 1, min( 200, (int) $max_pages ) );

    $parsed_root = wp_parse_url( $root_url );
    $root_host   = strtolower( $parsed_root['host'] ?? '' );
    $root_scheme = $parsed_root['scheme'] ?? 'https';

    if ( empty( $root_host ) ) {
        return [
            'urls'   => [],
            'total'  => 0,
            'domain' => '',
            'errors' => [ __( 'Could not determine domain from URL.', 'axiachat-ai' ) ],
        ];
    }

    aichat_webscraper_log( "Crawl start: {$root_url} (depth={$max_depth}, max={$max_pages})" );

    $discovered = [];   // url => depth
    $queue      = [];   // [ url, depth ]
    $errors     = [];

    // Normalize root URL
    $root_url_normalized = aichat_webscraper_normalize_url( $root_url );
    $discovered[ $root_url_normalized ] = 0;
    $queue[] = [ $root_url_normalized, 0 ];

    // HTTP options (lightweight — we only need links, not full content)
    $http_options = [
        'timeout'     => 20,
        'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'sslverify'   => false,
        'redirection' => 3,
        'headers'     => [
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9,es;q=0.8',
            'Accept-Encoding' => 'identity',
            'Referer'         => $root_scheme . '://' . $root_host,
        ],
    ];

    // File extensions to skip
    $skip_extensions = [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico',
        'mp3', 'mp4', 'avi', 'mov', 'wmv', 'flv',
        'zip', 'rar', 'gz', 'tar', '7z',
        'css', 'js', 'json', 'xml', 'rss', 'atom',
        'woff', 'woff2', 'ttf', 'eot',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    ];

    $processed = 0;

    while ( ! empty( $queue ) && count( $discovered ) <= $max_pages ) {
        $item  = array_shift( $queue );
        $url   = $item[0];
        $depth = $item[1];

        // Don't crawl links beyond max_depth
        if ( $depth >= $max_depth ) {
            continue;
        }

        $processed++;

        // Safety: abort if we've processed too many pages (prevent runaway)
        if ( $processed > $max_pages * 3 ) {
            $errors[] = __( 'Crawl safety limit reached.', 'axiachat-ai' );
            break;
        }

        // Fetch the page
        $response = wp_remote_get( $url, $http_options );

        if ( is_wp_error( $response ) ) {
            $errors[] = sprintf( '%s: %s', $url, $response->get_error_message() );
            continue;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status ) {
            $errors[] = sprintf( '%s: HTTP %d', $url, $status );
            continue;
        }

        // Only process HTML responses
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        if ( $content_type && false === stripos( $content_type, 'text/html' ) ) {
            continue;
        }

        $html = wp_remote_retrieve_body( $response );
        if ( empty( $html ) ) {
            continue;
        }

        // Extract links from HTML
        $new_urls = aichat_webscraper_extract_links( $html, $url, $root_host, $root_scheme );

        foreach ( $new_urls as $new_url ) {
            if ( count( $discovered ) >= $max_pages ) {
                break;
            }

            // Skip known extensions
            $path_lower = strtolower( wp_parse_url( $new_url, PHP_URL_PATH ) ?? '' );
            $ext        = pathinfo( $path_lower, PATHINFO_EXTENSION );
            if ( in_array( $ext, $skip_extensions, true ) ) {
                continue;
            }

            // Skip common non-content paths
            if ( aichat_webscraper_is_skip_path( $path_lower ) ) {
                continue;
            }

            $normalized = aichat_webscraper_normalize_url( $new_url );
            if ( ! isset( $discovered[ $normalized ] ) ) {
                $discovered[ $normalized ] = $depth + 1;
                $queue[] = [ $normalized, $depth + 1 ];
            }
        }
    }

    $urls = array_keys( $discovered );

    aichat_webscraper_log( 'Crawl done: ' . count( $urls ) . ' URLs discovered, ' . count( $errors ) . ' errors' );

    return [
        'urls'   => $urls,
        'total'  => count( $urls ),
        'domain' => $root_host,
        'errors' => $errors,
    ];
}

/**
 * Extract all same-domain links from an HTML page.
 *
 * @param string $html        Raw HTML content.
 * @param string $page_url    The URL of the page (for resolving relative links).
 * @param string $root_host   The root domain to stay within.
 * @param string $root_scheme The root scheme (http/https).
 * @return array Array of absolute URLs.
 */
function aichat_webscraper_extract_links( $html, $page_url, $root_host, $root_scheme ) {
    $links = [];

    libxml_use_internal_errors( true );
    $doc = new DOMDocument();
    $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
    libxml_clear_errors();

    // Detect <base href="..."> which overrides the base URL for relative resolution.
    $base_url  = $page_url;
    $base_tags = $doc->getElementsByTagName( 'base' );
    if ( $base_tags->length > 0 ) {
        $base_href = $base_tags->item( 0 )->getAttribute( 'href' );
        if ( ! empty( $base_href ) ) {
            // If <base> is absolute, use it; if relative, resolve against page URL.
            if ( preg_match( '#^https?://#i', $base_href ) ) {
                $base_url = $base_href;
            } elseif ( 0 === strpos( $base_href, '//' ) ) {
                $base_url = $root_scheme . ':' . $base_href;
            } elseif ( 0 === strpos( $base_href, '/' ) ) {
                $parsed_page = wp_parse_url( $page_url );
                $base_url    = ( $parsed_page['scheme'] ?? $root_scheme ) . '://' . ( $parsed_page['host'] ?? $root_host ) . $base_href;
            }
        }
    }

    $parsed_base = wp_parse_url( $base_url );
    $base_scheme = $parsed_base['scheme'] ?? $root_scheme;
    $base_host   = $parsed_base['host']   ?? $root_host;
    $base_origin = $base_scheme . '://' . $base_host;
    $base_path   = $parsed_base['path']   ?? '/';

    // If the path has no file extension in its last segment, it is a directory.
    // The normalize_url function strips trailing slashes for deduplication, but
    // for relative-URL resolution we need the slash so the path is treated as a
    // directory, not a file.  e.g. /help  →  /help/  (so "ai-tools.html"
    // resolves to /help/ai-tools.html instead of /ai-tools.html).
    $last_segment = basename( $base_path );
    if ( '/' !== substr( $base_path, -1 ) && false === strpos( $last_segment, '.' ) ) {
        $base_path .= '/';
    }

    $anchors = $doc->getElementsByTagName( 'a' );

    foreach ( $anchors as $a ) {
        $href = trim( $a->getAttribute( 'href' ) );
        if ( empty( $href ) ) {
            continue;
        }

        // Skip anchors, javascript, mailto, tel
        if ( preg_match( '/^(#|javascript:|mailto:|tel:|data:)/i', $href ) ) {
            continue;
        }

        // Resolve to absolute URL (RFC 3986)
        $href = aichat_webscraper_resolve_url( $href, $base_origin, $base_path, $root_scheme );

        // Parse and validate
        $parsed_href = wp_parse_url( $href );
        $href_host   = strtolower( $parsed_href['host'] ?? '' );

        // Stay within the same domain (allow www and non-www variants)
        $root_base = preg_replace( '/^www\./', '', $root_host );
        $href_base = preg_replace( '/^www\./', '', $href_host );

        if ( $href_base !== $root_base ) {
            continue;
        }

        // Strip fragment
        $href = preg_replace( '/#.*$/', '', $href );

        if ( ! empty( $href ) ) {
            $links[] = $href;
        }
    }

    return array_unique( $links );
}

/**
 * Resolve a (possibly relative) URL against a base, following RFC 3986.
 *
 * Handles protocol-relative, root-relative, directory-relative, and
 * path segment navigation (../ and ./).
 *
 * @param string $href        The href value from the <a> tag.
 * @param string $base_origin Scheme + host, e.g. "https://example.com".
 * @param string $base_path   Path of the current page, e.g. "/shop/products/".
 * @param string $root_scheme Fallback scheme.
 * @return string Absolute URL.
 */
function aichat_webscraper_resolve_url( $href, $base_origin, $base_path, $root_scheme ) {
    // Already absolute
    if ( preg_match( '#^https?://#i', $href ) ) {
        return $href;
    }

    // Protocol-relative
    if ( 0 === strpos( $href, '//' ) ) {
        return $root_scheme . ':' . $href;
    }

    // Root-relative
    if ( 0 === strpos( $href, '/' ) ) {
        return $base_origin . $href;
    }

    // Directory-relative: determine base directory.
    // RFC 3986: if the base path ends with "/" the base directory IS that path;
    // otherwise the base directory is everything up to the last "/".
    if ( substr( $base_path, -1 ) === '/' ) {
        $dir = $base_path;
    } else {
        $dir = substr( $base_path, 0, (int) strrpos( $base_path, '/' ) + 1 );
    }

    $merged = $dir . $href;

    // Collapse "." and ".." segments
    $merged = aichat_webscraper_normalize_path( $merged );

    return $base_origin . $merged;
}

/**
 * Normalize a URL path by resolving "." and ".." segments.
 *
 * @param string $path URL path that may contain "./" or "../".
 * @return string Clean path.
 */
function aichat_webscraper_normalize_path( $path ) {
    // Separate path and query string
    $query = '';
    $qpos  = strpos( $path, '?' );
    if ( false !== $qpos ) {
        $query = substr( $path, $qpos );
        $path  = substr( $path, 0, $qpos );
    }

    $segments = explode( '/', $path );
    $resolved = [];

    foreach ( $segments as $seg ) {
        if ( '.' === $seg ) {
            continue;
        }
        if ( '..' === $seg ) {
            // Go up one level, but never above root
            array_pop( $resolved );
            continue;
        }
        $resolved[] = $seg;
    }

    $result = implode( '/', $resolved );

    // Ensure leading slash
    if ( empty( $result ) || '/' !== $result[0] ) {
        $result = '/' . $result;
    }

    return $result . $query;
}

/**
 * Normalize a URL for deduplication.
 *
 * @param string $url The URL to normalize.
 * @return string Normalized URL.
 */
function aichat_webscraper_normalize_url( $url ) {
    // Remove trailing slash for consistency (except root)
    $parsed = wp_parse_url( $url );
    $path   = $parsed['path'] ?? '/';

    // Don't remove trailing slash from root
    if ( '/' !== $path ) {
        $path = rtrim( $path, '/' );
    }

    $normalized = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? '' ) . $path;

    // Include query string but sort params for consistency
    if ( ! empty( $parsed['query'] ) ) {
        parse_str( $parsed['query'], $params );
        ksort( $params );
        // Remove common tracking params
        unset( $params['utm_source'], $params['utm_medium'], $params['utm_campaign'], $params['utm_term'], $params['utm_content'] );
        unset( $params['fbclid'], $params['gclid'], $params['ref'] );
        if ( ! empty( $params ) ) {
            $normalized .= '?' . http_build_query( $params );
        }
    }

    return strtolower( $normalized );
}

/**
 * Check if a URL path should be skipped (non-content pages).
 *
 * @param string $path URL path (lowercase).
 * @return bool True to skip.
 */
function aichat_webscraper_is_skip_path( $path ) {
    $skip_patterns = [
        '/wp-admin',
        '/wp-login',
        '/wp-json',
        '/feed',
        '/xmlrpc',
        '/wp-content/uploads',
        '/wp-includes',
        '/cart',
        '/checkout',
        '/my-account',
        '/login',
        '/register',
        '/wp-cron',
        '/trackback',
        '/embed',
        '/amp/',
        '/print/',
    ];

    foreach ( $skip_patterns as $pattern ) {
        if ( false !== strpos( $path, $pattern ) ) {
            return true;
        }
    }

    return false;
}
