<?php

namespace App\Services\Fetch\Links;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Throwable;

/**
 * Resolves links found in a page against the page's final URL (RFC 3986).
 */
class UrlResolver
{
    /**
     * Resolve an href to an absolute http(s) URL, or null when it is not a
     * fetchable web link (javascript:, mailto:, data:, malformed, …).
     */
    public static function resolve(string $href, string $baseUrl): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));

        if ($href === '') {
            return null;
        }

        try {
            $resolved = (string) UriResolver::resolve(new Uri($baseUrl), new Uri($href));
        } catch (Throwable) {
            return null;
        }

        $scheme = strtolower((string) parse_url($resolved, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || ! parse_url($resolved, PHP_URL_HOST)) {
            return null;
        }

        return $resolved;
    }

    /**
     * The base URL links on a page resolve against: the page's `<base href>`
     * when present (itself resolved against the page URL), else the page URL.
     */
    public static function documentBase(?string $baseHref, string $pageUrl): string
    {
        if ($baseHref === null || trim($baseHref) === '') {
            return $pageUrl;
        }

        return self::resolve($baseHref, $pageUrl) ?? $pageUrl;
    }
}
