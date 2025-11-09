<?php

namespace App\Integrations\Fetch;

use App\Models\IntegrationGroup;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

class FetchHttpClient
{
    /**
     * Fetch a URL with cookies and custom headers from the integration group
     *
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function fetchWithCookies(string $url, IntegrationGroup $group): ResponseInterface
    {
        $domain = self::getDomainFromUrl($url);
        $domainConfig = self::getCookiesForDomain($domain, $group);

        // Create cookie jar
        $cookieJar = self::createCookieJar($domain, $domainConfig['cookies'] ?? []);

        // Get headers
        $headers = $domainConfig['headers'] ?? [];
        $headers['User-Agent'] = $headers['User-Agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        // Create Guzzle client
        $client = new Client([
            'timeout' => 30,
            'allow_redirects' => [
                'max' => 10,
                'strict' => false,
                'referer' => true,
                'track_redirects' => true,
            ],
            'verify' => true,
            'cookies' => $cookieJar,
            'headers' => $headers,
        ]);

        // Log the request
        Log::debug('Fetch: HTTP request', [
            'url' => $url,
            'domain' => $domain,
            'has_cookies' => ! empty($domainConfig['cookies']),
            'cookie_count' => count($domainConfig['cookies'] ?? []),
        ]);

        // Make request
        $response = $client->get($url);

        // Update last_used_at
        self::updateLastUsed($domain, $group);

        // Log the response
        Log::debug('Fetch: HTTP response', [
            'url' => $url,
            'status_code' => $response->getStatusCode(),
            'content_length' => strlen($response->getBody()->getContents()),
        ]);

        // Reset body pointer for reading again
        $response->getBody()->rewind();

        return $response;
    }

    /**
     * Extract domain from URL
     */
    public static function getDomainFromUrl(string $url): string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        // Remove 'www.' prefix if present
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * Get cookie configuration for a specific domain
     */
    public static function getCookiesForDomain(string $domain, IntegrationGroup $group): array
    {
        $authMetadata = $group->auth_metadata ?? [];
        $domains = $authMetadata['domains'] ?? [];

        // Try exact match first
        if (isset($domains[$domain])) {
            return $domains[$domain];
        }

        // Try with www. prefix
        $wwwDomain = 'www.' . $domain;
        if (isset($domains[$wwwDomain])) {
            return $domains[$wwwDomain];
        }

        // Try without www. prefix if domain starts with www.
        if (str_starts_with($domain, 'www.')) {
            $nonWwwDomain = substr($domain, 4);
            if (isset($domains[$nonWwwDomain])) {
                return $domains[$nonWwwDomain];
            }
        }

        return [];
    }

    /**
     * Create a Guzzle CookieJar from cookie array
     */
    public static function createCookieJar(string $domain, array $cookies): CookieJar
    {
        $cookieJar = new CookieJar;

        foreach ($cookies as $name => $value) {
            $cookieJar->setCookie(new SetCookie([
                'Domain' => '.' . $domain, // Leading dot makes it work for subdomains
                'Name' => $name,
                'Value' => $value,
                'Discard' => false,
                'Secure' => true,
                'HttpOnly' => true,
            ]));
        }

        return $cookieJar;
    }

    /**
     * Update last_used_at timestamp for a domain
     */
    public static function updateLastUsed(string $domain, IntegrationGroup $group): void
    {
        $authMetadata = $group->auth_metadata ?? [];
        $domains = $authMetadata['domains'] ?? [];

        if (isset($domains[$domain])) {
            $domains[$domain]['last_used_at'] = now()->toIso8601String();
            $authMetadata['domains'] = $domains;
            $group->update(['auth_metadata' => $authMetadata]);
        }
    }

    /**
     * Test if a URL is accessible with the current cookies
     *
     * @return array ['success' => bool, 'status_code' => int, 'message' => string]
     */
    public static function testUrl(string $url, IntegrationGroup $group): array
    {
        try {
            $response = self::fetchWithCookies($url, $group);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'success' => true,
                    'status_code' => $statusCode,
                    'message' => 'Successfully fetched URL',
                ];
            } else {
                return [
                    'success' => false,
                    'status_code' => $statusCode,
                    'message' => "HTTP {$statusCode}",
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'status_code' => 0,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }
}
