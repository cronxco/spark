<?php

namespace App\Integrations\Fetch;

use DOMDocument;
use DOMXPath;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Extracts the main article image from HTML content.
 *
 * Priority order:
 * 1. Open Graph image (og:image)
 * 2. Twitter Card image (twitter:image)
 * 3. Schema.org image (article/newsarticle/blogposting image)
 * 4. Largest image in content area
 */
class ArticleImageExtractor
{
    /**
     * Extract the primary article image URL from HTML.
     *
     * @return string|null The absolute URL of the article image, or null if not found
     */
    public static function extract(string $html, string $baseUrl): ?string
    {
        if (empty($html)) {
            return null;
        }

        try {
            // Suppress HTML parsing errors
            libxml_use_internal_errors(true);

            $dom = new DOMDocument;
            $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

            libxml_clear_errors();

            $xpath = new DOMXPath($dom);

            // Try extraction methods in priority order
            $imageUrl = self::extractOpenGraphImage($xpath)
                ?? self::extractTwitterImage($xpath)
                ?? self::extractSchemaOrgImage($html)
                ?? self::extractLargestContentImage($xpath, $baseUrl);

            if ($imageUrl) {
                // Make URL absolute if needed
                $absoluteUrl = self::makeAbsoluteUrl($imageUrl, $baseUrl);

                // Validate the URL
                if (self::isValidImageUrl($absoluteUrl)) {
                    Log::debug('ArticleImageExtractor: Image found', [
                        'url' => $baseUrl,
                        'image_url' => $absoluteUrl,
                    ]);

                    return $absoluteUrl;
                }
            }

            Log::debug('ArticleImageExtractor: No article image found', [
                'url' => $baseUrl,
            ]);

            return null;
        } catch (Exception $e) {
            Log::warning('ArticleImageExtractor: Failed to extract image', [
                'url' => $baseUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract Open Graph image (og:image meta tag).
     */
    protected static function extractOpenGraphImage(DOMXPath $xpath): ?string
    {
        // Try og:image
        $nodes = $xpath->query('//meta[@property="og:image"]/@content');
        if ($nodes && $nodes->length > 0) {
            $url = trim($nodes->item(0)->nodeValue);
            if (! empty($url)) {
                return $url;
            }
        }

        // Try og:image:url as fallback
        $nodes = $xpath->query('//meta[@property="og:image:url"]/@content');
        if ($nodes && $nodes->length > 0) {
            $url = trim($nodes->item(0)->nodeValue);
            if (! empty($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Extract Twitter Card image (twitter:image meta tag).
     */
    protected static function extractTwitterImage(DOMXPath $xpath): ?string
    {
        // Try twitter:image
        $nodes = $xpath->query('//meta[@name="twitter:image"]/@content');
        if ($nodes && $nodes->length > 0) {
            $url = trim($nodes->item(0)->nodeValue);
            if (! empty($url)) {
                return $url;
            }
        }

        // Try twitter:image:src as fallback
        $nodes = $xpath->query('//meta[@name="twitter:image:src"]/@content');
        if ($nodes && $nodes->length > 0) {
            $url = trim($nodes->item(0)->nodeValue);
            if (! empty($url)) {
                return $url;
            }
        }

        // Some sites use property instead of name
        $nodes = $xpath->query('//meta[@property="twitter:image"]/@content');
        if ($nodes && $nodes->length > 0) {
            $url = trim($nodes->item(0)->nodeValue);
            if (! empty($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Extract Schema.org image from JSON-LD structured data.
     */
    protected static function extractSchemaOrgImage(string $html): ?string
    {
        // Find all JSON-LD script blocks
        if (! preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $jsonLd) {
            try {
                $data = json_decode($jsonLd, true);
                if (! $data) {
                    continue;
                }

                // Handle @graph structure
                if (isset($data['@graph']) && is_array($data['@graph'])) {
                    foreach ($data['@graph'] as $item) {
                        $imageUrl = self::extractImageFromSchemaItem($item);
                        if ($imageUrl) {
                            return $imageUrl;
                        }
                    }
                } else {
                    $imageUrl = self::extractImageFromSchemaItem($data);
                    if ($imageUrl) {
                        return $imageUrl;
                    }
                }
            } catch (Exception $e) {
                // Continue to next JSON-LD block
                continue;
            }
        }

        return null;
    }

    /**
     * Extract image URL from a Schema.org item.
     */
    protected static function extractImageFromSchemaItem(array $item): ?string
    {
        // Check for Article, NewsArticle, BlogPosting types
        $articleTypes = ['Article', 'NewsArticle', 'BlogPosting', 'WebPage', 'WebSite'];
        $type = $item['@type'] ?? '';

        if (is_array($type)) {
            $isArticle = count(array_intersect($type, $articleTypes)) > 0;
        } else {
            $isArticle = in_array($type, $articleTypes);
        }

        if (! $isArticle) {
            return null;
        }

        // Check for image property
        if (isset($item['image'])) {
            $image = $item['image'];

            // Image can be a URL string
            if (is_string($image)) {
                return $image;
            }

            // Image can be an array of URLs
            if (is_array($image) && isset($image[0])) {
                if (is_string($image[0])) {
                    return $image[0];
                }
                if (is_array($image[0]) && isset($image[0]['url'])) {
                    return $image[0]['url'];
                }
            }

            // Image can be an ImageObject
            if (is_array($image) && isset($image['url'])) {
                return $image['url'];
            }

            if (is_array($image) && isset($image['@id'])) {
                return $image['@id'];
            }
        }

        // Check for thumbnailUrl
        if (isset($item['thumbnailUrl'])) {
            return is_string($item['thumbnailUrl']) ? $item['thumbnailUrl'] : null;
        }

        return null;
    }

    /**
     * Extract the largest image from the content area as a fallback.
     */
    protected static function extractLargestContentImage(DOMXPath $xpath, string $baseUrl): ?string
    {
        // Look for images in article, main, or content areas first
        $contentSelectors = [
            '//article//img',
            '//main//img',
            '//*[contains(@class, "content")]//img',
            '//*[contains(@class, "post")]//img',
            '//*[contains(@class, "article")]//img',
            '//*[contains(@class, "entry")]//img',
        ];

        $candidates = [];

        foreach ($contentSelectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes && $nodes->length > 0) {
                foreach ($nodes as $node) {
                    $src = $node->getAttribute('src') ?: $node->getAttribute('data-src');
                    if (! $src) {
                        continue;
                    }

                    // Skip common non-article images
                    if (self::shouldSkipImage($src, $node)) {
                        continue;
                    }

                    // Get dimensions if available
                    $width = (int) ($node->getAttribute('width') ?: 0);
                    $height = (int) ($node->getAttribute('height') ?: 0);

                    // Estimate size from URL patterns if dimensions not available
                    if ($width === 0 && $height === 0) {
                        $width = self::estimateSizeFromUrl($src);
                    }

                    $candidates[] = [
                        'src' => $src,
                        'width' => $width,
                        'height' => $height,
                        'area' => $width * $height,
                    ];
                }
            }

            // If we found candidates in a content area, don't search further
            if (! empty($candidates)) {
                break;
            }
        }

        // Sort by area (largest first) and return the best candidate
        if (! empty($candidates)) {
            usort($candidates, fn ($a, $b) => $b['area'] <=> $a['area']);

            return $candidates[0]['src'];
        }

        return null;
    }

    /**
     * Check if an image should be skipped (icons, logos, avatars, etc.).
     */
    protected static function shouldSkipImage(string $src, $node): bool
    {
        $lowerSrc = strtolower($src);

        // Skip common non-article image patterns
        $skipPatterns = [
            'logo',
            'icon',
            'avatar',
            'profile',
            'sprite',
            'placeholder',
            'loading',
            'spinner',
            'spacer',
            'pixel',
            'tracking',
            'badge',
            'button',
            'banner',
            'ad-',
            'advertisement',
            'sponsor',
            'social',
            'share',
            'facebook',
            'twitter',
            'linkedin',
            'pinterest',
            'instagram',
            'emoji',
            'smiley',
            '.svg',
            '.gif',
            'data:image',
            '1x1',
            '2x2',
        ];

        foreach ($skipPatterns as $pattern) {
            if (str_contains($lowerSrc, $pattern)) {
                return true;
            }
        }

        // Check class and alt attributes
        $class = strtolower($node->getAttribute('class') ?? '');
        $alt = strtolower($node->getAttribute('alt') ?? '');

        $skipClasses = ['logo', 'icon', 'avatar', 'profile', 'author'];
        foreach ($skipClasses as $skipClass) {
            if (str_contains($class, $skipClass) || str_contains($alt, $skipClass)) {
                return true;
            }
        }

        // Skip very small images
        $width = (int) ($node->getAttribute('width') ?: 0);
        $height = (int) ($node->getAttribute('height') ?: 0);

        if (($width > 0 && $width < 100) || ($height > 0 && $height < 100)) {
            return true;
        }

        return false;
    }

    /**
     * Estimate image size from URL patterns.
     */
    protected static function estimateSizeFromUrl(string $url): int
    {
        // Look for size indicators in URL
        $patterns = [
            '/(\d{3,4})x(\d{3,4})/' => 1, // 800x600
            '/w=(\d{3,4})/' => 1,          // w=800
            '/width[=_-](\d{3,4})/' => 1,  // width=800
            '/size[=_-](\d{3,4})/' => 1,   // size=800
            '/-(\d{3,4})(?:x\d+)?\./' => 1, // -800x600.jpg
        ];

        foreach ($patterns as $pattern => $group) {
            if (preg_match($pattern, $url, $matches)) {
                return (int) $matches[$group];
            }
        }

        // Default to a reasonable size for images without indicators
        return 300;
    }

    /**
     * Convert a relative URL to an absolute URL.
     */
    protected static function makeAbsoluteUrl(string $url, string $baseUrl): string
    {
        // Already absolute
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        // Protocol-relative URL
        if (str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?? 'https';

            return $scheme.':'.$url;
        }

        // Parse the base URL
        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? '';

        if (empty($host)) {
            return $url;
        }

        // Root-relative URL
        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$url}";
        }

        // Relative URL - append to base path
        $basePath = $parsedBase['path'] ?? '/';
        $basePath = dirname($basePath);
        if ($basePath === '.') {
            $basePath = '/';
        }

        return "{$scheme}://{$host}{$basePath}/{$url}";
    }

    /**
     * Validate that a URL looks like a valid image URL.
     */
    protected static function isValidImageUrl(string $url): bool
    {
        // Must be a valid URL
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Must start with http:// or https://
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return false;
        }

        // Exclude data URLs
        if (str_starts_with($url, 'data:')) {
            return false;
        }

        // Must have a reasonable length
        if (strlen($url) < 10 || strlen($url) > 2000) {
            return false;
        }

        return true;
    }
}
