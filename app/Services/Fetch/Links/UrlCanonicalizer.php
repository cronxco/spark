<?php

namespace App\Services\Fetch\Links;

/**
 * Produces a dedupe identity for a URL.
 *
 * The canonical form is only ever compared, never fetched: sorting the query,
 * dropping a trailing slash or stripping a parameter can occasionally change
 * what a server returns (or break a signed URL), so bookmarks keep the URL as
 * observed in `url` and store this identity in `metadata.canonical_url`.
 */
class UrlCanonicalizer
{
    /**
     * Query parameters that only carry attribution/tracking data.
     *
     * @var list<string>
     */
    public const DEFAULT_TRACKING_PARAMS = [
        'utm_*',
        'fbclid',
        'gclid',
        'dclid',
        'msclkid',
        'mc_cid',
        'mc_eid',
        '_hsenc',
        '_hsmi',
        'mkt_tok',
        'ref_src',
        'igshid',
        'vero_id',
        'oly_enc_id',
        'oly_anon_id',
        '__s',
    ];

    public static function canonicalize(string $url): string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['host'])) {
            return trim($url);
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;

        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $path = $parts['path'] ?? '';
        if ($path === '' || $path === '/') {
            $path = '/';
        } else {
            $path = rtrim($path, '/');
        }

        $query = self::canonicalQuery($parts['query'] ?? '');

        return $scheme . '://' . $host
            . ($port !== null ? ':' . $port : '')
            . $path
            . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Whether two URLs share the same canonical identity.
     */
    public static function same(string $a, string $b): bool
    {
        return self::canonicalize($a) === self::canonicalize($b);
    }

    private static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $patterns = self::trackingParams();
        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $name = strtolower(urldecode(explode('=', $pair, 2)[0]));

            if (self::isTrackingParam($name, $patterns)) {
                continue;
            }

            $pairs[] = $pair;
        }

        sort($pairs, SORT_STRING);

        return implode('&', $pairs);
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function isTrackingParam(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function trackingParams(): array
    {
        $configured = config('fetch.list_expansion.tracking_params');

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULT_TRACKING_PARAMS;
        }

        return array_values(array_map(fn ($param): string => strtolower((string) $param), $configured));
    }
}
