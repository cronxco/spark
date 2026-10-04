<?php

namespace App\Services\Fetch\Links;

use App\Services\Fetch\UrlSafetyValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Follows a newsletter's click-tracking redirect to the article it points at.
 *
 * Only ever called for links already judged to be articles: housekeeping
 * links (unsubscribe in particular) are removed before this point, because
 * requesting them can have side effects. Every redirect hop is re-checked by
 * the SSRF guard, no cookies are sent and no body is read.
 */
class TrackingLinkResolver
{
    private const TIMEOUT_SECONDS = 5;

    private const CACHE_TTL_SECONDS = 604800;

    public function __construct(private UrlSafetyValidator $urlSafety) {}

    /**
     * The URL the link finally lands on, or the original URL when it cannot
     * be resolved (the fetch will follow the redirect itself).
     */
    public function resolve(string $url): string
    {
        if (! $this->urlSafety->isSafe($url)) {
            return $url;
        }

        return Cache::remember('fetch-tracking-link:' . sha1($url), self::CACHE_TTL_SECONDS, fn (): string => $this->follow($url));
    }

    private function follow(string $url): string
    {
        foreach (['head', 'get'] as $method) {
            try {
                $response = Http::withOptions([
                    'allow_redirects' => $this->urlSafety->guzzleRedirectConfig(),
                    'stream' => true,
                    'cookies' => false,
                ])
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; SparkFetch/1.0)'])
                    ->{$method}($url);
            } catch (Throwable $e) {
                Log::info('Fetch: Could not resolve tracking link', ['url' => $url, 'method' => $method, 'error' => $e->getMessage()]);

                continue;
            }

            $final = $this->finalUrl($response->header('X-Guzzle-Redirect-History'), $response->effectiveUri()?->__toString());
            $response->toPsrResponse()->getBody()->close();

            if ($response->status() === 405 && $method === 'head') {
                continue;
            }

            if ($final !== null && $this->urlSafety->isSafe($final)) {
                return $final;
            }

            return $url;
        }

        return $url;
    }

    private function finalUrl(string $redirectHistory, ?string $effectiveUri): ?string
    {
        $hops = array_values(array_filter(array_map('trim', explode(',', $redirectHistory))));

        $final = $hops === [] ? $effectiveUri : end($hops);

        return is_string($final) && preg_match('/^https?:\/\//i', $final) ? $final : null;
    }
}
