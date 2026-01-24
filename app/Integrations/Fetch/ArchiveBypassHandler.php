<?php

namespace App\Integrations\Fetch;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class ArchiveBypassHandler
{
    protected const ARCHIVE_BASE_URL = 'https://archive.is';

    protected const ARCHIVE_TODAY_URL = 'https://archive.today';

    protected Client $httpClient;

    protected int $timeout;

    public function __construct()
    {
        $this->timeout = config('services.fetch.archive_bypass_timeout', 30);
        $this->httpClient = new Client([
            'timeout' => $this->timeout,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ],
            'verify' => true,
        ]);
    }

    /**
     * Check if archive bypass is enabled
     */
    public static function isEnabled(): bool
    {
        return config('services.fetch.archive_bypass_enabled', true);
    }

    /**
     * Check if a URL should be considered for archive bypass
     * Some domains don't work well with archive.is
     */
    public static function shouldAttemptBypass(string $url): bool
    {
        if (! self::isEnabled()) {
            return false;
        }

        $excludedDomains = config('services.fetch.archive_bypass_excluded_domains', []);

        if (is_string($excludedDomains)) {
            $excludedDomains = array_filter(array_map('trim', explode(',', $excludedDomains)));
        }

        $domain = FetchHttpClient::getDomainFromUrl($url);

        foreach ($excludedDomains as $excluded) {
            if ($domain === $excluded || str_ends_with($domain, '.'.$excluded)) {
                Log::debug('Fetch: Domain excluded from archive bypass', [
                    'url' => $url,
                    'domain' => $domain,
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * Attempt to fetch content from archive.is for a paywalled URL
     *
     * @return array ['success' => bool, 'html' => ?string, 'archive_url' => ?string, 'error' => ?string]
     */
    public function fetchFromArchive(string $originalUrl): array
    {
        if (! self::isEnabled()) {
            return [
                'success' => false,
                'html' => null,
                'archive_url' => null,
                'error' => 'Archive bypass is disabled',
            ];
        }

        Log::info('Fetch: Attempting archive.is bypass', ['url' => $originalUrl]);

        try {
            // Step 1: Check if archive exists for this URL
            $archiveUrl = $this->findArchivedVersion($originalUrl);

            if ($archiveUrl) {
                // Step 2: Fetch the archived content
                $html = $this->fetchArchiveContent($archiveUrl);

                if ($html) {
                    Log::info('Fetch: Archive bypass successful', [
                        'original_url' => $originalUrl,
                        'archive_url' => $archiveUrl,
                    ]);

                    return [
                        'success' => true,
                        'html' => $html,
                        'archive_url' => $archiveUrl,
                        'error' => null,
                    ];
                }
            }

            Log::debug('Fetch: No archived version found', ['url' => $originalUrl]);

            return [
                'success' => false,
                'html' => null,
                'archive_url' => null,
                'error' => 'No archived version available',
            ];

        } catch (Exception $e) {
            Log::warning('Fetch: Archive bypass failed', [
                'url' => $originalUrl,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'html' => null,
                'archive_url' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Find an archived version of the URL on archive.is
     *
     * Based on the archive.is mechanism:
     * 1. Fetch https://archive.is/{url}
     * 2. Parse HTML to find the actual archived page URL
     */
    protected function findArchivedVersion(string $url): ?string
    {
        // Build the archive.is search URL
        $searchUrl = self::ARCHIVE_BASE_URL.'/'.$url;

        try {
            $response = $this->httpClient->get($searchUrl, [
                'allow_redirects' => [
                    'max' => 5,
                    'track_redirects' => true,
                ],
            ]);

            $html = (string) $response->getBody();
            $statusCode = $response->getStatusCode();

            Log::debug('Fetch: Archive search response', [
                'url' => $searchUrl,
                'status_code' => $statusCode,
                'content_length' => strlen($html),
            ]);

            // Check if we were redirected directly to an archived page
            $finalUrl = $this->getFinalUrl($response);
            if ($this->isArchivedPageUrl($finalUrl)) {
                Log::debug('Fetch: Redirected directly to archived page', ['archive_url' => $finalUrl]);

                return $finalUrl;
            }

            // Parse the HTML to find archived version links
            // Based on the iOS Shortcut pattern: (?<=break-word" href=").*?(?=")
            // This targets links with word-break styling which archive.is uses for archive links
            $archiveUrl = $this->extractArchiveUrlFromHtml($html);

            if ($archiveUrl) {
                return $archiveUrl;
            }

            // Try alternative patterns
            return $this->extractArchiveUrlAlternative($html);

        } catch (GuzzleException $e) {
            Log::debug('Fetch: Archive search request failed', [
                'url' => $searchUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Extract archive URL from HTML using the pattern from the iOS Shortcut
     * Pattern: break-word" href="..." targets the archive links
     */
    protected function extractArchiveUrlFromHtml(string $html): ?string
    {
        // Pattern matching the iOS Shortcut: (?<=break-word" href=").*?(?=")
        // In PHP we use a capturing group approach
        if (preg_match('/break-word["\'][^>]*href=["\']([^"\']+)["\']/', $html, $matches)) {
            $archiveUrl = $matches[1];

            // Ensure it's a valid archive.is URL
            if ($this->isArchivedPageUrl($archiveUrl)) {
                return $archiveUrl;
            }

            // If it's a relative URL, make it absolute
            if (str_starts_with($archiveUrl, '/')) {
                return self::ARCHIVE_BASE_URL.$archiveUrl;
            }
        }

        // Alternative pattern: look for href containing archive.is/TIMESTAMP/
        // Archive URLs typically look like: https://archive.is/ABC123 or https://archive.is/2023.01.01-123456/url
        if (preg_match('/href=["\']((https?:\/\/archive\.(is|today|ph|md|vn)\/[a-zA-Z0-9]+))["\']/', $html, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Alternative extraction method looking for archive snapshot links
     */
    protected function extractArchiveUrlAlternative(string $html): ?string
    {
        // Look for links in the archive listing page
        // Archive.is listing pages contain links to snapshots in formats like:
        // - https://archive.is/ABC123
        // - /ABC123
        $patterns = [
            // Direct archive ID links (5-6 character alphanumeric codes)
            '/href=["\'](?:https?:\/\/archive\.(?:is|today|ph|md|vn))?\/([a-zA-Z0-9]{5,6})["\']/',
            // Links with timestamps
            '/href=["\'](?:https?:\/\/archive\.(?:is|today|ph|md|vn))?\/(\d{4}\.\d{2}\.\d{2}-\d+\/[^"\']+)["\']/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $path = $matches[1];

                // Skip if it looks like a navigation link rather than an archive
                if (in_array($path, ['search', 'submit', 'faq', 'about', 'recent'])) {
                    continue;
                }

                return self::ARCHIVE_BASE_URL.'/'.ltrim($path, '/');
            }
        }

        return null;
    }

    /**
     * Fetch the actual content from an archived page
     */
    protected function fetchArchiveContent(string $archiveUrl): ?string
    {
        try {
            $response = $this->httpClient->get($archiveUrl, [
                'allow_redirects' => true,
            ]);

            $html = (string) $response->getBody();

            if (strlen($html) < 100) {
                Log::debug('Fetch: Archive content too short', [
                    'archive_url' => $archiveUrl,
                    'length' => strlen($html),
                ]);

                return null;
            }

            // Clean up archive.is wrapper elements if present
            $html = $this->cleanArchiveHtml($html);

            return $html;

        } catch (GuzzleException $e) {
            Log::debug('Fetch: Failed to fetch archive content', [
                'archive_url' => $archiveUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Clean up archive.is HTML to remove their wrapper/toolbar elements
     */
    protected function cleanArchiveHtml(string $html): string
    {
        // Remove archive.is toolbar/banner elements
        // Archive.is adds a toolbar at the top with id="HEADER"
        $html = preg_replace('/<div[^>]*id=["\']?HEADER["\']?[^>]*>.*?<\/div>/si', '', $html);

        // Remove archive.is scripts
        $html = preg_replace('/<script[^>]*archive\.(is|today|ph|md|vn)[^>]*>.*?<\/script>/si', '', $html);

        // Remove any archive.is specific stylesheets
        $html = preg_replace('/<link[^>]*archive\.(is|today|ph|md|vn)[^>]*>/si', '', $html);

        return $html;
    }

    /**
     * Check if a URL is an archived page (not the search/listing page)
     */
    protected function isArchivedPageUrl(string $url): bool
    {
        // Archived pages have URLs like:
        // https://archive.is/ABC123 (short hash)
        // https://archive.today/ABC123
        return (bool) preg_match('/^https?:\/\/archive\.(is|today|ph|md|vn)\/[a-zA-Z0-9]{5,6}$/', $url);
    }

    /**
     * Get the final URL after redirects from a Guzzle response
     */
    protected function getFinalUrl($response): string
    {
        $redirects = $response->getHeader('X-Guzzle-Redirect-History');

        if (! empty($redirects)) {
            return end($redirects);
        }

        return '';
    }
}
