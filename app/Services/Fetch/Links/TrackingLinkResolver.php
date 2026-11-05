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

    public function __construct(private UrlSafetyValidator $urlSafety, private NewsletterLinkFilter $filter) {}

    /**
     * The URL the link finally lands on, or the original URL when a request
     * fails. Unsafe or housekeeping destinations return null and are omitted.
     */
    public function resolve(string $url, array $listUnsubscribeUrls = []): ?string
    {
        if ($this->filter->isDeniedUrl($url, $listUnsubscribeUrls) || ! $this->urlSafety->isSafe($url)) {
            return null;
        }

        $key = 'fetch-tracking-link:' . sha1($url . json_encode($listUnsubscribeUrls));

        return Cache::remember($key, self::CACHE_TTL_SECONDS, fn (): ?string => $this->follow($url, $listUnsubscribeUrls));
    }

    private function follow(string $url, array $listUnsubscribeUrls): ?string
    {
        $current = $url;
        $method = 'head';
        $maxRedirects = max(0, (int) config('fetch.url_safety.max_redirects', 10));
        $redirects = 0;
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while (true) {
            // Revalidate before sending anything, including the initial request
            // and GET fallback. A redirect must never visit a housekeeping URL.
            if ($this->filter->isDeniedUrl($current, $listUnsubscribeUrls) || ! $this->urlSafety->isSafe($current)) {
                return null;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return $url;
            }

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'stream' => true,
                    'cookies' => false,
                ])
                    ->connectTimeout(min(2.0, $remaining))
                    ->timeout($remaining)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; SparkFetch/1.0)'])
                    ->{$method}($current);
            } catch (Throwable $e) {
                Log::info('Fetch: Could not resolve tracking link', ['url' => $current, 'method' => $method, 'error' => $e->getMessage()]);

                return $url;
            }

            $response->toPsrResponse()->getBody()->close();

            if ($response->status() === 405 && $method === 'head') {
                $method = 'get';

                continue;
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $destination = UrlResolver::resolve($response->header('Location'), $current);
                if ($destination === null || $redirects++ >= $maxRedirects) {
                    return null;
                }
                $current = $destination;

                continue;
            }

            return $response->successful() ? $current : $url;
        }
    }
}
