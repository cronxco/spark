<?php

namespace App\Integrations\Fetch;

use App\Models\EventObject;
use App\Models\IntegrationGroup;
use Exception;
use Illuminate\Support\Facades\Log;

class FetchEngineManager
{
    protected bool $playwrightEnabled;
    protected bool $autoEscalate;
    protected array $jsRequiredDomains;

    public function __construct()
    {
        $this->playwrightEnabled = config('services.playwright.enabled', false);
        $this->autoEscalate = config('services.playwright.auto_escalate', true);

        // Parse comma-separated domains
        $domainsString = config('services.playwright.js_required_domains', '');
        $this->jsRequiredDomains = array_filter(
            array_map('trim', explode(',', $domainsString))
        );
    }

    /**
     * Fetch a URL using the appropriate engine (Playwright or HTTP)
     *
     * @return array ['html' => string, 'status_code' => int, 'screenshot' => ?string, 'method' => string, 'error' => ?string]
     */
    public function fetch(string $url, IntegrationGroup $group, ?EventObject $webpage = null): array
    {
        $method = $this->determineMethod($url, $webpage);

        Log::debug('Fetch: Engine selected', [
            'url' => $url,
            'method' => $method,
            'playwright_enabled' => $this->playwrightEnabled,
        ]);

        if ($method === 'playwright') {
            return $this->fetchWithPlaywright($url, $group, $webpage);
        }

        return $this->fetchWithHttp($url, $group);
    }

    /**
     * Reset Playwright learning for a webpage (useful if you want to retry HTTP)
     */
    public function resetPlaywrightLearning(EventObject $webpage): void
    {
        $metadata = $webpage->metadata ?? [];
        unset($metadata['requires_playwright']);
        unset($metadata['playwright_learned_at']);

        $webpage->update(['metadata' => $metadata]);

        Log::info('Fetch: Reset Playwright learning', [
            'webpage_id' => $webpage->id,
            'url' => $webpage->url,
        ]);
    }

    /**
     * Log a fetch decision to the webpage's history
     */
    public function logFetchDecision(?EventObject $webpage, string $decision, string $reason, array $meta = []): void
    {
        if (! $webpage) {
            return;
        }

        $metadata = $webpage->metadata ?? [];
        $history = $metadata['playwright_history'] ?? [];

        // Create new history entry
        $entry = [
            'timestamp' => now()->toIso8601String(),
            'decision' => $decision,
            'reason' => $reason,
            'outcome' => null,
            'stealth_enabled' => config('services.playwright.stealth_enabled', true),
            'context_cached' => false,
            'duration_ms' => null,
            'status_code' => null,
        ];

        // Merge any additional metadata
        $entry = array_merge($entry, $meta);

        // Add to history
        $history[] = $entry;

        // Keep only the last 20 entries
        if (count($history) > 20) {
            $history = array_slice($history, -20);
        }

        $metadata['playwright_history'] = $history;
        $webpage->update(['metadata' => $metadata]);

        Log::debug('Fetch: Logged decision to history', [
            'webpage_id' => $webpage->id,
            'decision' => $decision,
            'reason' => $reason,
        ]);
    }

    /**
     * Update the last history entry with fetch outcome
     */
    public function updateLastHistoryEntry(EventObject $webpage, array $updates): void
    {
        $metadata = $webpage->metadata ?? [];
        $history = $metadata['playwright_history'] ?? [];

        if (empty($history)) {
            return;
        }

        // Update the last entry
        $lastIndex = count($history) - 1;
        $history[$lastIndex] = array_merge($history[$lastIndex], $updates);

        $metadata['playwright_history'] = $history;
        $webpage->update(['metadata' => $metadata]);

        Log::debug('Fetch: Updated history entry', [
            'webpage_id' => $webpage->id,
            'updates' => $updates,
        ]);
    }

    /**
     * Check if Playwright is available
     */
    public function isPlaywrightAvailable(): bool
    {
        if (! $this->playwrightEnabled) {
            return false;
        }

        try {
            $client = new PlaywrightFetchClient;

            return $client->isAvailable();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get statistics about fetch methods used
     */
    public function getMethodStats(IntegrationGroup $group): array
    {
        // Get all integration IDs for this group
        $integrationIds = $group->integrations()->pluck('id')->toArray();

        $webpages = EventObject::where('user_id', $group->user_id)
            ->where('type', 'fetch_webpage')
            ->when(! empty($integrationIds), function ($query) use ($integrationIds) {
                $query->whereIn('metadata->fetch_integration_id', $integrationIds);
            })
            ->get();

        $stats = [
            'total' => $webpages->count(),
            'requires_playwright' => 0,
            'prefers_http' => 0,
            'auto' => 0,
            'playwright_available' => $this->isPlaywrightAvailable(),
        ];

        foreach ($webpages as $webpage) {
            if (! empty($webpage->metadata['requires_playwright'])) {
                $stats['requires_playwright']++;
            } elseif (($webpage->metadata['playwright_preference'] ?? 'auto') === 'http') {
                $stats['prefers_http']++;
            } else {
                $stats['auto']++;
            }
        }

        return $stats;
    }

    /**
     * Determine which fetch method to use
     */
    protected function determineMethod(string $url, ?EventObject $webpage): string
    {
        $reason = 'default';

        // If Playwright is disabled, always use HTTP
        if (! $this->playwrightEnabled) {
            $this->logFetchDecision($webpage, 'http', 'playwright_disabled');

            return 'http';
        }

        // Check if the webpage has an explicit preference
        if ($webpage) {
            $preference = $webpage->metadata['playwright_preference'] ?? 'auto';

            if ($preference === 'playwright') {
                $this->logFetchDecision($webpage, 'playwright', 'user_preference');

                return 'playwright';
            }

            if ($preference === 'http') {
                $this->logFetchDecision($webpage, 'http', 'user_preference');

                return 'http';
            }

            // Check if this webpage has been learned to require Playwright
            if (! empty($webpage->metadata['requires_playwright'])) {
                Log::debug('Fetch: Webpage learned to require Playwright', ['url' => $url]);
                $this->logFetchDecision($webpage, 'playwright', 'learned');

                return 'playwright';
            }
        }

        // Check if domain is in JS-required list
        $domain = FetchHttpClient::getDomainFromUrl($url);
        if ($this->domainRequiresJavaScript($domain)) {
            Log::debug('Fetch: Domain requires JavaScript', ['domain' => $domain]);
            $this->logFetchDecision($webpage, 'playwright', 'js_domain');

            return 'playwright';
        }

        // Check for recent failures that suggest Playwright is needed
        if ($webpage && $this->hasPlaywrightIndicatingErrors($webpage)) {
            $lastError = $webpage->metadata['last_error'] ?? null;
            $errorMessage = strtolower($lastError['message'] ?? '');

            // Determine specific reason
            if (str_contains($errorMessage, 'robot') || str_contains($errorMessage, 'captcha')) {
                $reason = 'robot_detected';
            } elseif (str_contains($errorMessage, 'paywall')) {
                $reason = 'paywall_detected';
            } else {
                $consecutiveFailures = $lastError['consecutive_failures'] ?? 0;
                $reason = $consecutiveFailures >= 2 ? 'escalated' : 'error_detected';
            }

            Log::debug('Fetch: Recent errors suggest Playwright needed', ['url' => $url, 'reason' => $reason]);
            $this->logFetchDecision($webpage, 'playwright', $reason);

            return 'playwright';
        }

        // Default to HTTP
        $this->logFetchDecision($webpage, 'http', 'default');

        return 'http';
    }

    /**
     * Fetch using Playwright browser automation
     */
    protected function fetchWithPlaywright(string $url, IntegrationGroup $group, ?EventObject $webpage): array
    {
        try {
            $client = new PlaywrightFetchClient;
            $result = $client->fetch($url, $group);

            if (! $result['success']) {
                throw new Exception($result['error'] ?? 'Playwright fetch failed');
            }

            // Learn that this URL works with Playwright
            if ($webpage && ! isset($webpage->metadata['requires_playwright'])) {
                $this->learnPlaywrightSuccess($webpage);
            }

            return [
                'html' => $result['html'],
                'status_code' => 200,
                'screenshot' => $result['screenshot'] ?? null,
                'method' => 'playwright',
                'error' => null,
            ];

        } catch (Exception $e) {
            Log::warning('Fetch: Playwright failed, attempting HTTP fallback', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            // Fall back to HTTP
            return $this->fetchWithHttp($url, $group, true);
        }
    }

    /**
     * Fetch using standard HTTP client
     */
    protected function fetchWithHttp(string $url, IntegrationGroup $group, bool $isFallback = false): array
    {
        try {
            $response = FetchHttpClient::fetchWithCookies($url, $group);

            return [
                'html' => (string) $response->getBody(),
                'status_code' => $response->getStatusCode(),
                'screenshot' => null,
                'method' => $isFallback ? 'http (fallback)' : 'http',
                'error' => null,
            ];

        } catch (Exception $e) {
            return [
                'html' => '',
                'status_code' => 0,
                'screenshot' => null,
                'method' => $isFallback ? 'http (fallback)' : 'http',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if a domain requires JavaScript execution
     */
    protected function domainRequiresJavaScript(string $domain): bool
    {
        foreach ($this->jsRequiredDomains as $requiredDomain) {
            if ($domain === $requiredDomain || str_ends_with($domain, '.' . $requiredDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if recent errors suggest Playwright is needed
     */
    protected function hasPlaywrightIndicatingErrors(EventObject $webpage): bool
    {
        if (! $this->autoEscalate) {
            return false;
        }

        $lastError = $webpage->metadata['last_error'] ?? null;

        if (! $lastError || ! isset($lastError['message'])) {
            return false;
        }

        $errorMessage = strtolower($lastError['message']);

        // Indicators that Playwright might help
        $indicators = [
            'robot check detected',
            'captcha',
            'cloudflare',
            'access denied',
            '403',
            'forbidden',
            'paywall detected',
            'javascript',
            'js required',
        ];

        foreach ($indicators as $indicator) {
            if (str_contains($errorMessage, $indicator)) {
                return true;
            }
        }

        // If there are multiple consecutive failures, try Playwright
        $consecutiveFailures = $lastError['consecutive_failures'] ?? 0;
        if ($consecutiveFailures >= 2) {
            Log::debug('Fetch: Multiple consecutive failures, escalating to Playwright', [
                'consecutive_failures' => $consecutiveFailures,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Mark that this webpage successfully worked with Playwright
     */
    protected function learnPlaywrightSuccess(EventObject $webpage): void
    {
        $metadata = $webpage->metadata ?? [];
        $metadata['requires_playwright'] = true;
        $metadata['playwright_learned_at'] = now()->toIso8601String();

        $webpage->update(['metadata' => $metadata]);

        Log::info('Fetch: Learned Playwright requirement', [
            'webpage_id' => $webpage->id,
            'url' => $webpage->url,
        ]);
    }
}
