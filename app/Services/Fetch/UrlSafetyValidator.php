<?php

namespace App\Services\Fetch;

use App\Exceptions\UnsafeUrlException;
use Psr\Http\Message\UriInterface;

/**
 * Validates user-supplied URLs before Spark fetches them server-side.
 *
 * Blocks private/loopback/link-local/reserved IP ranges (after DNS
 * resolution, checking every resolved address) and re-validates each
 * redirect hop to defend against DNS-rebinding. Only ever applied to
 * user-supplied URLs — system-issued calls (Playwright worker, etc.)
 * must not be routed through this validator.
 */
class UrlSafetyValidator
{
    /**
     * Hosts blocked outright regardless of DNS resolution.
     */
    private const BLOCKED_HOSTS = [
        'localhost',
        'metadata.google.internal',
    ];

    /**
     * Throw if the URL is not safe to fetch.
     *
     * @throws UnsafeUrlException
     */
    public function validate(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new UnsafeUrlException($url, 'malformed URL');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrlException($url, 'unsupported scheme');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException($url, 'credentials in URL');
        }

        $this->assertHostSafe($parts['host'], $url);
    }

    public function isSafe(string $url): bool
    {
        try {
            $this->validate($url);

            return true;
        } catch (UnsafeUrlException) {
            return false;
        }
    }

    /**
     * Re-validate a redirect target (used as a Guzzle on_redirect callback).
     *
     * @throws UnsafeUrlException
     */
    public function assertSafeUri(UriInterface $uri): void
    {
        $this->validate((string) $uri);
    }

    /**
     * Guzzle allow_redirects config that re-validates every hop.
     *
     * @return array<string, mixed>
     */
    public function guzzleRedirectConfig(): array
    {
        return [
            'max' => (int) config('fetch.url_safety.max_redirects', 10),
            'strict' => false,
            'referer' => true,
            'track_redirects' => true,
            'on_redirect' => function ($request, $response, UriInterface $uri): void {
                $this->assertSafeUri($uri);
            },
        ];
    }

    /**
     * @throws UnsafeUrlException
     */
    private function assertHostSafe(string $host, string $url): void
    {
        $host = strtolower(trim($host, '[]'));

        if (in_array($host, $this->allowedHosts(), true)) {
            return;
        }

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new UnsafeUrlException($url, 'blocked host');
        }

        $ips = $this->resolveHost($host);

        if ($ips === []) {
            throw new UnsafeUrlException($url, 'host did not resolve');
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new UnsafeUrlException($url, "non-public address ({$ip})");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (! empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($public === false) {
            return false;
        }

        // Belt-and-braces explicit blocks (cloud metadata / unspecified).
        $blocked = ['169.254.169.254', '0.0.0.0', '::', '::1'];

        return ! in_array($ip, $blocked, true);
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        return array_map('strtolower', (array) config('fetch.url_safety.allowed_hosts', []));
    }
}
