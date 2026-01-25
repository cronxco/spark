<?php

namespace App\Integrations\Fetch;

use App\Models\IntegrationGroup;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlaywrightFetchClient
{
    protected string $workerUrl;

    protected int $timeout;

    protected bool $screenshotEnabled;

    public function __construct()
    {
        $this->workerUrl = config('services.playwright.worker_url');
        $this->timeout = config('services.playwright.timeout', 30000);
        $this->screenshotEnabled = config('services.playwright.screenshot_enabled', true);
    }

    /**
     * Fetch a URL using Playwright browser automation
     *
     * @return array ['success' => bool, 'html' => string, 'title' => string, 'url' => string, 'screenshot' => ?string, 'cookies' => array, 'error' => ?string]
     *
     * @throws Exception
     */
    public function fetch(string $url, IntegrationGroup $group): array
    {
        $this->ensureWorkerAvailable();

        $domain = FetchHttpClient::getDomainFromUrl($url);
        $domainConfig = FetchHttpClient::getCookiesForDomain($domain, $group);

        // Convert cookies to Playwright format
        $playwrightCookies = $this->convertCookiesToPlaywrightFormat($domain, $domainConfig['cookies'] ?? []);

        // Get user agent from headers
        $userAgent = $domainConfig['headers']['User-Agent'] ?? null;

        Log::debug('Fetch: Playwright request', [
            'url' => $url,
            'domain' => $domain,
            'has_cookies' => ! empty($playwrightCookies),
            'cookie_count' => count($playwrightCookies),
            'worker_url' => $this->workerUrl,
        ]);

        try {
            // Check if context persistence is enabled
            $usePersistence = config('services.playwright.context_persistence_enabled', true);

            // Use default context (with extensions) by default, or create isolated contexts
            $useDefaultContext = config('services.playwright.use_default_context', true);

            $response = Http::timeout(($this->timeout / 1000) + 10) // Add buffer to HTTP timeout
                ->post("{$this->workerUrl}/fetch", [
                    'url' => $url,
                    'cookies' => $playwrightCookies,
                    'waitFor' => 'networkidle',
                    'timeout' => $this->timeout,
                    'screenshot' => $this->screenshotEnabled,
                    'userAgent' => $userAgent,
                    'usePersistence' => $usePersistence,
                    'useDefaultContext' => $useDefaultContext,
                ]);

            if (! $response->successful()) {
                $errorData = $response->json();
                throw new Exception($errorData['error'] ?? 'Unknown Playwright error', $response->status());
            }

            $data = $response->json();

            Log::debug('Fetch: Playwright response', [
                'url' => $url,
                'final_url' => $data['url'] ?? $url,
                'html_length' => strlen($data['html'] ?? ''),
                'screenshot_size' => $data['screenshot'] ? strlen($data['screenshot']) : 0,
            ]);

            // Write most recent response to debug file
            $this->writeDebugResponse($url, $data);

            // Update last_used_at
            FetchHttpClient::updateLastUsed($domain, $group);

            // Auto-update cookies if they changed (enabled by default)
            if (! empty($data['cookies']) && config('services.playwright.auto_update_cookies', true)) {
                $this->updateCookiesIfChanged($domain, $group, $data['cookies']);
            }

            return [
                'success' => true,
                'html' => $data['html'] ?? '',
                'title' => $data['title'] ?? '',
                'url' => $data['url'] ?? $url,
                'screenshot' => $data['screenshot'] ?? null,
                'cookies' => $data['cookies'] ?? [],
                'error' => null,
                'meta' => $data['meta'] ?? [],
            ];

        } catch (Exception $e) {
            Log::error('Fetch: Playwright request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
                'worker_url' => $this->workerUrl,
            ]);

            return [
                'success' => false,
                'html' => '',
                'title' => '',
                'url' => $url,
                'screenshot' => null,
                'cookies' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract cookies from the browser session for a specific domain
     *
     * @return array ['success' => bool, 'cookies' => array, 'error' => ?string]
     */
    public function extractCookies(string $domain): array
    {
        try {
            $this->ensureWorkerAvailable();

            Log::debug('Fetch: Extracting cookies from browser', ['domain' => $domain]);

            $response = Http::timeout(10)
                ->get("{$this->workerUrl}/cookies/{$domain}");

            if (! $response->successful()) {
                $errorData = $response->json();
                throw new Exception($errorData['error'] ?? 'Failed to extract cookies');
            }

            $data = $response->json();

            Log::info('Fetch: Cookies extracted successfully', [
                'domain' => $domain,
                'cookie_count' => $data['count'] ?? 0,
            ]);

            return [
                'success' => true,
                'cookies' => $data['cookies'] ?? [],
                'count' => $data['count'] ?? 0,
                'error' => null,
            ];

        } catch (Exception $e) {
            Log::error('Fetch: Cookie extraction failed', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'cookies' => [],
                'count' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if Playwright worker is available and healthy
     */
    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->workerUrl}/health");

            if (! $response->successful()) {
                return false;
            }

            $health = $response->json();

            return ($health['status'] ?? '') === 'ok' && ($health['connected'] ?? false);

        } catch (Exception $e) {
            Log::debug('Fetch: Playwright worker not available', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Get browser session information
     */
    public function getBrowserInfo(): ?array
    {
        try {
            $response = Http::timeout(5)->get("{$this->workerUrl}/browser/info");

            if (! $response->successful()) {
                return null;
            }

            return $response->json();

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Clear cached browser contexts
     *
     * @param  string|null  $domain  Specific domain to clear, or null to clear all
     * @return array ['success' => bool, 'message' => string, 'domains' => ?array]
     */
    public function clearContexts(?string $domain = null): array
    {
        try {
            $this->ensureWorkerAvailable();

            $response = Http::timeout(10)
                ->post("{$this->workerUrl}/contexts/clear", [
                    'domain' => $domain,
                ]);

            if (! $response->successful()) {
                $errorData = $response->json();
                throw new Exception($errorData['error'] ?? 'Failed to clear contexts');
            }

            return $response->json();

        } catch (Exception $e) {
            Log::error('Fetch: Failed to clear contexts', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get worker statistics and information
     *
     * @return array|null ['contextCount', 'cachedContextCount', 'stealthEnabled', 'contextTTL', 'uptime', etc.]
     */
    public function getWorkerStats(): ?array
    {
        try {
            $response = Http::timeout(5)->get("{$this->workerUrl}/health");

            if (! $response->successful()) {
                return null;
            }

            $healthData = $response->json();

            // Parse health data to extract useful stats
            return [
                'status' => $healthData['status'] ?? 'unknown',
                'browser_connected' => $healthData['connected'] ?? false,
                'stealth_enabled' => config('services.playwright.stealth_enabled', true),
                'context_ttl' => config('services.playwright.context_ttl_minutes', 30),
                'context_persistence' => config('services.playwright.context_persistence_enabled', true),
                'screenshot_enabled' => $this->screenshotEnabled,
                'timeout' => $this->timeout,
                'worker_url' => $this->workerUrl,
            ];

        } catch (Exception $e) {
            Log::error('Fetch: Failed to get worker stats', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Convert simple key-value cookies to Playwright cookie format
     */
    protected function convertCookiesToPlaywrightFormat(string $domain, array $cookies): array
    {
        $playwrightCookies = [];

        foreach ($cookies as $name => $value) {
            $playwrightCookies[] = [
                'name' => $name,
                'value' => $value,
                'domain' => '.' . $domain, // Leading dot for subdomain support
                'path' => '/',
                'secure' => true,
                'httpOnly' => true,
                'sameSite' => 'Lax',
            ];
        }

        return $playwrightCookies;
    }

    /**
     * Update stored cookies if they changed during the fetch
     */
    protected function updateCookiesIfChanged(string $domain, IntegrationGroup $group, array $newCookies): void
    {
        // Convert Playwright cookies back to simple format
        $simpleCookies = [];
        foreach ($newCookies as $cookie) {
            $cookieDomain = ltrim($cookie['domain'] ?? '', '.');
            if ($cookieDomain === $domain || str_ends_with($cookieDomain, '.' . $domain)) {
                $simpleCookies[$cookie['name']] = $cookie['value'];
            }
        }

        if (empty($simpleCookies)) {
            return;
        }

        // Get current cookies
        $currentConfig = FetchHttpClient::getCookiesForDomain($domain, $group);
        $currentCookies = $currentConfig['cookies'] ?? [];

        // Check if cookies actually changed
        if ($simpleCookies !== $currentCookies) {
            $authMetadata = $group->auth_metadata ?? [];
            $domains = $authMetadata['domains'] ?? [];

            if (! isset($domains[$domain])) {
                $domains[$domain] = [];
            }

            $domains[$domain]['cookies'] = $simpleCookies;
            $domains[$domain]['updated_at'] = now()->toIso8601String();

            $authMetadata['domains'] = $domains;
            $group->update(['auth_metadata' => $authMetadata]);

            Log::info('Fetch: Cookies auto-updated from Playwright session', [
                'domain' => $domain,
                'cookie_count' => count($simpleCookies),
            ]);
        }
    }

    /**
     * Ensure the Playwright worker is available, throw exception if not
     *
     * @throws Exception
     */
    protected function ensureWorkerAvailable(): void
    {
        if (! $this->isAvailable()) {
            throw new Exception('Playwright worker is not available. Please ensure the playwright-worker service is running.');
        }
    }

    /**
     * Write most recent Playwright response to debug file
     */
    protected function writeDebugResponse(string $url, array $data): void
    {
        try {
            $logPath = storage_path('logs/fetch_playwright_last.json');

            $debugData = [
                'timestamp' => now()->toIso8601String(),
                'url' => $url,
                'final_url' => $data['url'] ?? $url,
                'title' => $data['title'] ?? '',
                'html_length' => strlen($data['html'] ?? ''),
                'html' => $data['html'] ?? '',
                'screenshot' => $data['screenshot'] ?? null,
                'cookies_count' => count($data['cookies'] ?? []),
                'meta' => $data['meta'] ?? [],
            ];

            file_put_contents($logPath, json_encode($debugData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Exception $e) {
            // Silently fail - don't break the fetch process
            Log::debug('Failed to write Playwright debug file', ['error' => $e->getMessage()]);
        }
    }
}
