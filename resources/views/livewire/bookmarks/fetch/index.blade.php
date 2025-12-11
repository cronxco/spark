<?php

use App\Integrations\Fetch\CookieParser;
use App\Integrations\Fetch\FetchEngineManager;
use App\Integrations\Fetch\FetchHttpClient;
use App\Integrations\Fetch\PlaywrightFetchClient;
use App\Integrations\PluginRegistry;
use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Services\PlaywrightHealthMetrics;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new class extends Component
{
    use Toast, WithPagination;

    public ?Integration $integration = null;
    public ?IntegrationGroup $group = null;
    public string $activeTab = 'urls';

    // URL subscription
    #[Validate('required|url|max:2048')]
    public string $newUrl = '';
    public array $urls = [];
    public string $urlSearch = '';
    public string $domainFilter = '';
    public string $statusFilter = 'all'; // all, active, disabled, error, pending
    public string $urlSortBy = 'last_changed'; // last_changed, last_checked, title, domain

    // Cookie management
    public string $cookieDomain = '';
    public string $cookieJson = '';
    public array $domains = [];

    // Discovery settings
    public array $availableIntegrations = [];
    public array $monitoredIntegrations = [];
    public array $excludedDomains = [];
    public string $newExcludedDomain = '';
    public string $discoverySearch = '';
    public string $discoveryStatusFilter = '';
    public int $discoveryPerPage = 10;
    public array $discoverySortBy = ['column' => 'discovered_at', 'direction' => 'desc'];

    // Stats
    public array $stats = [];

    // Playwright
    public bool $playwrightEnabled = false;
    public bool $playwrightAvailable = false;
    public array $playwrightStats = [];
    public array $healthMetrics = [];
    public array $workerStats = [];

    // API Tokens
    public array $apiTokens = [];
    public string $newTokenName = '';
    public ?string $newlyCreatedToken = null;
    public bool $showTokenCreateModal = false;
    public ?string $temporaryTokenValue = null;

    protected $listeners = ['$refresh' => 'loadData'];

    public function mount(): void
    {
        // Find or create Fetch integration for user
        $user = Auth::user();

        $this->group = IntegrationGroup::firstOrCreate(
            [
                'user_id' => $user->id,
                'service' => 'fetch',
            ],
            [
                'auth_metadata' => [
                    'domains' => [],
                ],
            ]
        );

        // Find or create the fetcher instance
        $this->integration = Integration::firstOrCreate(
            [
                'user_id' => $user->id,
                'service' => 'fetch',
                'instance_type' => 'fetcher',
            ],
            [
                'name' => 'Fetch',
                'integration_group_id' => $this->group->id,
                'configuration' => [
                    'update_frequency_minutes' => 180,
                    'use_schedule' => true,
                    'schedule_times' => ['00:00', '03:00', '06:00', '09:00', '12:00', '15:00', '18:00', '21:00'],
                    'schedule_timezone' => 'UTC',
                    'monitor_integrations' => [],
                ],
            ]
        );

        // Check for tab query parameter
        $tab = request()->query('tab');
        if ($tab && in_array($tab, ['urls', 'cookies', 'discovery', 'stats', 'playwright', 'api'])) {
            $this->activeTab = $tab;
        }

        // Check for temporary token in session (auto-clears after page refresh)
        $this->temporaryTokenValue = session('fetch_api_temp_token');

        $this->loadData();
    }

    public function loadData(): void
    {
        $this->loadUrls();
        $this->loadCookies();
        $this->loadDiscoverySettings();
        $this->loadStats();
        $this->loadPlaywrightStatus();
        $this->loadApiTokens();
    }

    public function loadApiTokens(): void
    {
        $this->apiTokens = Auth::user()
            ->tokens()
            ->where('name', 'like', 'Bookmark API%')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($token) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'created_at' => $token->created_at,
                    'last_used_at' => $token->last_used_at,
                ];
            })
            ->toArray();
    }

    public function latestTokenName(): ?string
    {
        return ! empty($this->apiTokens) ? $this->apiTokens[0]['name'] : null;
    }

    public function getTokenForExamples(): string
    {
        // If we have a temporary token (just created), show the actual value
        if ($this->temporaryTokenValue) {
            return $this->temporaryTokenValue;
        }

        // Otherwise show a placeholder with the token name
        if (! empty($this->apiTokens)) {
            return '[Your ' . str_replace('Bookmark API: ', '', $this->latestTokenName()) . ' token]';
        }

        return 'YOUR_TOKEN_HERE';
    }

    public function loadPlaywrightStatus(): void
    {
        $this->playwrightEnabled = config('services.playwright.enabled', false);

        if ($this->playwrightEnabled) {
            $engine = new FetchEngineManager;
            $this->playwrightAvailable = $engine->isPlaywrightAvailable();
            $this->playwrightStats = $engine->getMethodStats($this->group);

            // Load health metrics
            $metrics = new PlaywrightHealthMetrics;
            $this->healthMetrics = $metrics->getStats('24h');

            // Load worker stats
            if ($this->playwrightAvailable) {
                $client = new PlaywrightFetchClient;
                $this->workerStats = $client->getWorkerStats() ?? [];
            }
        } else {
            $this->playwrightAvailable = false;
            $this->playwrightStats = [];
            $this->healthMetrics = [];
            $this->workerStats = [];
        }
    }

    public function refreshMetrics(): void
    {
        $this->loadPlaywrightStatus();
    }

    public function loadUrls(): void
    {
        $query = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->where(function ($q) {
                // Only show subscribed URLs (exclude discovered URLs)
                $q->whereRaw("metadata->>'subscription_source' = 'subscribed'")
                    ->orWhereNull('metadata->subscription_source'); // Legacy URLs without source
            });

        // Apply search
        if (! empty($this->urlSearch)) {
            $query->where(function ($q) {
                $q->where('url', 'like', '%' . $this->urlSearch . '%')
                    ->orWhere('title', 'like', '%' . $this->urlSearch . '%');
            });
        }

        // Apply domain filter
        if (! empty($this->domainFilter)) {
            $query->whereRaw("metadata->>'domain' = ?", [$this->domainFilter]);
        }

        // Apply status filter
        if ($this->statusFilter !== 'all') {
            switch ($this->statusFilter) {
                case 'active':
                    $query->whereRaw("(metadata->>'enabled')::boolean = true")
                        ->whereNull('metadata->last_error');
                    break;
                case 'disabled':
                    $query->whereRaw("(metadata->>'enabled')::boolean = false");
                    break;
                case 'error':
                    $query->whereNotNull('metadata->last_error');
                    break;
                case 'pending':
                    $query->whereNull('metadata->last_checked_at');
                    break;
            }
        }

        // Apply sorting
        switch ($this->urlSortBy) {
            case 'last_changed':
                $query->orderByRaw("metadata->>'last_changed_at' DESC NULLS LAST");
                break;
            case 'last_checked':
                $query->orderByRaw("metadata->>'last_checked_at' DESC NULLS LAST");
                break;
            case 'title':
                $query->orderBy('title');
                break;
            case 'domain':
                $query->orderByRaw("metadata->>'domain'");
                break;
        }

        $this->urls = $query->get()->map(function ($obj) {
            $metadata = $obj->metadata ?? [];

            return [
                'id' => $obj->id,
                'url' => $obj->url,
                'title' => $obj->title,
                'domain' => $metadata['domain'] ?? parse_url($obj->url, PHP_URL_HOST),
                'enabled' => $metadata['enabled'] ?? true,
                'last_checked_at' => $metadata['last_checked_at'] ?? null,
                'last_changed_at' => $metadata['last_changed_at'] ?? null,
                'last_error' => $metadata['last_error'] ?? null,
                'subscription_source' => $metadata['subscription_source'] ?? 'manual',
                'fetch_count' => $metadata['fetch_count'] ?? 0,
                'last_fetch_method' => $metadata['last_fetch_method'] ?? null,
                'playwright_history' => array_slice($metadata['playwright_history'] ?? [], -10), // Last 10 entries
            ];
        })->toArray();
    }

    public function loadCookies(): void
    {
        $authMetadata = $this->group->auth_metadata ?? [];
        $domains = $authMetadata['domains'] ?? [];

        $this->domains = collect($domains)->map(function ($config, $domain) {
            $expiresAt = $config['expires_at'] ?? null;
            $status = $this->getCookieExpiryStatus($expiresAt);

            return [
                'domain' => $domain,
                'cookie_count' => count($config['cookies'] ?? []),
                'expires_at' => $expiresAt,
                'expiry_status' => $status,
                'last_used_at' => $config['last_used_at'] ?? null,
                'added_at' => $config['added_at'] ?? null,
                'updated_at' => $config['updated_at'] ?? null,
                'auto_refresh_enabled' => $config['auto_refresh_enabled'] ?? false,
                'last_refreshed_at' => $config['last_refreshed_at'] ?? null,
            ];
        })->sortBy('domain')->values()->toArray();
    }

    public function loadDiscoverySettings(): void
    {
        // Load available integrations
        $this->availableIntegrations = Integration::where('user_id', Auth::id())
            ->where('service', '!=', 'fetch')
            ->with('group')
            ->get()
            ->map(function ($integration) {
                $pluginClass = PluginRegistry::getPlugin($integration->service);

                return [
                    'id' => (string) $integration->id,
                    'name' => $integration->name ?: ($pluginClass ? $pluginClass::getDisplayName() : $integration->service),
                    'service' => $integration->service,
                    'service_name' => $pluginClass ? $pluginClass::getDisplayName() : ucfirst($integration->service),
                ];
            })
            ->toArray();

        // Load current monitored integrations
        $config = $this->integration->configuration ?? [];
        $this->monitoredIntegrations = $config['monitor_integrations'] ?? [];

        // Load excluded domains and set defaults if empty
        $user = Auth::user();
        $this->excludedDomains = $user->getFetchDiscoveryExcludedDomains();

        // Pre-populate common asset CDN domains if list is empty
        if (empty($this->excludedDomains)) {
            $defaultExcludedDomains = [
                't0.gstatic.com',
                't1.gstatic.com',
                't2.gstatic.com',
                't3.gstatic.com',
                'cdnjs.cloudflare.com',
                'unpkg.com',
                'cdn.jsdelivr.net',
            ];
            $user->setFetchDiscoveryExcludedDomains($defaultExcludedDomains);
            $this->excludedDomains = $defaultExcludedDomains;
        }
    }

    public function getDiscoveredUrls()
    {
        $query = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->whereRaw("metadata->>'subscription_source' = 'discovered'")
            ->whereRaw("(metadata->>'discovery_ignored')::boolean IS NOT TRUE"); // Exclude ignored URLs

        // Apply search filter
        if ($this->discoverySearch) {
            $query->where(function ($q) {
                $q->where('url', 'ilike', '%' . $this->discoverySearch . '%')
                    ->orWhere('title', 'ilike', '%' . $this->discoverySearch . '%');
            });
        }

        // Apply status filter
        if ($this->discoveryStatusFilter) {
            if ($this->discoveryStatusFilter === 'pending') {
                $query->whereRaw("(metadata->>'fetch_count')::int = 0")
                    ->whereRaw("metadata->>'last_checked_at' IS NULL");
            } elseif ($this->discoveryStatusFilter === 'fetched') {
                $query->where(function ($q) {
                    $q->whereRaw("(metadata->>'fetch_count')::int > 0")
                        ->orWhereRaw("metadata->>'last_checked_at' IS NOT NULL");
                });
            } elseif ($this->discoveryStatusFilter === 'error') {
                $query->whereRaw("metadata->>'last_error' IS NOT NULL");
            }
        }

        // Apply sorting
        $sortColumn = $this->discoverySortBy['column'] ?? 'discovered_at';
        $sortDirection = $this->discoverySortBy['direction'] ?? 'desc';

        if ($sortColumn === 'discovered_at') {
            $query->orderByRaw("metadata->>'discovered_at' {$sortDirection}");
        } elseif ($sortColumn === 'url') {
            $query->orderBy('url', $sortDirection);
        } elseif ($sortColumn === 'title') {
            $query->orderBy('title', $sortDirection);
        }

        return $query->paginate($this->discoveryPerPage);
    }

    public function discoveryHeaders(): array
    {
        return [
            ['key' => 'url', 'label' => 'URL', 'sortable' => true],
            ['key' => 'context', 'label' => 'Context', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'source', 'label' => 'Source', 'sortable' => false, 'class' => 'hidden lg:table-cell'],
            ['key' => 'discovered_at', 'label' => 'Discovered', 'sortable' => true, 'class' => 'hidden md:table-cell'],
            ['key' => 'status', 'label' => 'Status', 'sortable' => false],
            ['key' => 'enabled', 'label' => 'Fetch', 'sortable' => false],
            ['key' => 'actions', 'label' => 'Actions', 'sortable' => false],
        ];
    }

    public function updatedDiscoverySearch(): void
    {
        $this->resetPage();
    }

    public function updatedDiscoveryStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearDiscoveryFilters(): void
    {
        $this->reset(['discoverySearch', 'discoveryStatusFilter']);
        $this->resetPage();
    }

    public function determineDiscoveryStatus(array $metadata): string
    {
        // Check if ignored
        if ($metadata['discovery_ignored'] ?? false) {
            return 'ignored';
        }

        // Check if there's an error
        if (isset($metadata['last_error'])) {
            return 'error';
        }

        // Check if it's been fetched
        if (($metadata['fetch_count'] ?? 0) > 0 || isset($metadata['last_checked_at'])) {
            return 'fetched';
        }

        // Otherwise it's pending
        return 'pending';
    }

    public function getDiscoveryContext(EventObject $url): string
    {
        $metadata = $url->metadata ?? [];
        $foundIn = $metadata['found_in'] ?? 'unknown';

        // Get source integration name
        $sourceIntegrationId = $metadata['discovered_from_integration_id'] ?? null;
        $sourceIntegrationName = 'Unknown';

        if ($sourceIntegrationId) {
            $sourceIntegration = collect($this->availableIntegrations)
                ->firstWhere('id', $sourceIntegrationId);
            $sourceIntegrationName = $sourceIntegration['service_name'] ?? 'Unknown';
        }

        // Get source object or event info
        $sourceObjectId = $metadata['discovered_from_object_id'] ?? null;
        $sourceEventId = $metadata['discovered_from_event_id'] ?? null;

        $contextParts = [];

        // Format the found_in location
        $foundInMap = [
            'url_field' => 'Object URL',
            'metadata' => 'Object Metadata',
            'content' => 'Object Content',
            'event_url_field' => 'Event URL',
            'event_metadata' => 'Event Metadata',
        ];

        $contextParts[] = $foundInMap[$foundIn] ?? ucfirst(str_replace('_', ' ', $foundIn));

        // Add source info if available
        if ($sourceObjectId) {
            $sourceObject = EventObject::find($sourceObjectId);
            if ($sourceObject) {
                $contextParts[] = 'from "' . Str::limit($sourceObject->title, 30) . '"';
            }
        } elseif ($sourceEventId) {
            $sourceEvent = Event::find($sourceEventId);
            if ($sourceEvent && $sourceEvent->target) {
                $contextParts[] = 'from "' . Str::limit($sourceEvent->target->title, 30) . '"';
            }
        }

        return implode(' ', $contextParts);
    }

    public function getDiscoveryStats(): array
    {
        $query = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->whereRaw("metadata->>'subscription_source' = 'discovered'")
            ->whereRaw("(metadata->>'discovery_ignored')::boolean IS NOT TRUE");

        $total = $query->count();

        $pending = (clone $query)
            ->whereRaw("(metadata->>'fetch_count')::int = 0")
            ->whereRaw("metadata->>'last_checked_at' IS NULL")
            ->count();

        $fetched = (clone $query)
            ->where(function ($q) {
                $q->whereRaw("(metadata->>'fetch_count')::int > 0")
                    ->orWhereRaw("metadata->>'last_checked_at' IS NOT NULL");
            })
            ->count();

        $errors = (clone $query)
            ->whereRaw("metadata->>'last_error' IS NOT NULL")
            ->count();

        return [
            'total' => $total,
            'pending' => $pending,
            'fetched' => $fetched,
            'errors' => $errors,
        ];
    }

    public function getAutoFetchEnabled(): bool
    {
        return Auth::user()->getFetchDiscoveryAutoFetchEnabled();
    }

    public function loadStats(): void
    {
        // Subscribed URLs (URLs tab)
        $subscribedUrls = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->where(function ($q) {
                $q->whereRaw("metadata->>'subscription_source' = 'subscribed'")
                    ->orWhereNull('metadata->subscription_source'); // Legacy URLs
            })
            ->count();

        $activeSubscribedUrls = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->where(function ($q) {
                $q->whereRaw("metadata->>'subscription_source' = 'subscribed'")
                    ->orWhereNull('metadata->subscription_source'); // Legacy URLs
            })
            ->whereRaw("(metadata->>'enabled')::boolean = true")
            ->count();

        // Discovered URLs (Discovery tab)
        $discoveredUrls = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->whereRaw("metadata->>'subscription_source' = 'discovered'")
            ->whereRaw("(metadata->>'discovery_ignored')::boolean IS NOT TRUE")
            ->count();

        $activeDiscoveredUrls = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->whereRaw("metadata->>'subscription_source' = 'discovered'")
            ->whereRaw("(metadata->>'discovery_ignored')::boolean IS NOT TRUE")
            ->whereRaw("(metadata->>'enabled')::boolean = true")
            ->count();

        // Overall stats
        $totalUrls = $subscribedUrls + $discoveredUrls;
        $activeUrls = $activeSubscribedUrls + $activeDiscoveredUrls;

        $urlsWithErrors = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->whereNotNull('metadata->last_error')
            ->count();

        $domainsWithCookies = count($this->group->auth_metadata['domains'] ?? []);

        $nextRun = $this->integration->getNextScheduledRun();

        $this->stats = [
            'total_urls' => $totalUrls,
            'active_urls' => $activeUrls,
            'urls_with_errors' => $urlsWithErrors,
            'domains_with_cookies' => $domainsWithCookies,
            'next_run' => $nextRun?->toISOString(),
            // Breakdown
            'subscribed_urls' => $subscribedUrls,
            'subscribed_active' => $activeSubscribedUrls,
            'discovered_urls' => $discoveredUrls,
            'discovered_active' => $activeDiscoveredUrls,
        ];
    }

    public function subscribeToUrl(): void
    {
        $this->validate([
            'newUrl' => 'required|url|max:2048',
        ]);

        // Check if URL already exists as a subscription (allow if it's only discovered)
        $existing = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->where('url', $this->newUrl)
            ->where(function ($q) {
                $q->whereRaw("metadata->>'subscription_source' = 'subscribed'")
                    ->orWhereNull('metadata->subscription_source'); // Legacy URLs
            })
            ->exists();

        if ($existing) {
            $this->error('This URL is already subscribed.');

            return;
        }

        try {
            $domain = parse_url($this->newUrl, PHP_URL_HOST);

            EventObject::create([
                'user_id' => Auth::id(),
                'concept' => 'bookmark',
                'type' => 'fetch_webpage',
                'title' => $this->newUrl, // Will be updated on first fetch
                'url' => $this->newUrl,
                'time' => now(),
                'metadata' => [
                    'domain' => $domain,
                    'fetch_integration_id' => $this->integration->id,
                    'subscription_source' => 'subscribed',
                    'fetch_mode' => 'recurring', // Subscribed URLs are fetched repeatedly
                    'subscribed_at' => now()->toIso8601String(),
                    'enabled' => true,
                    'last_checked_at' => null,
                    'last_changed_at' => null,
                    'content_hash' => null,
                    'fetch_count' => 0,
                ],
            ]);

            $this->success('URL subscribed successfully!');
            $this->newUrl = '';
            $this->loadData();
        } catch (\Exception $e) {
            Log::error('Failed to subscribe to URL', [
                'url' => $this->newUrl,
                'error' => $e->getMessage(),
            ]);
            $this->error('Failed to subscribe to URL. Please try again.');
        }
    }

    public function toggleUrl(string $id): void
    {
        $eventObject = EventObject::find($id);

        if (! $eventObject || $eventObject->user_id !== Auth::id()) {
            $this->error('URL not found.');

            return;
        }

        $metadata = $eventObject->metadata ?? [];
        $metadata['enabled'] = ! ($metadata['enabled'] ?? true);

        $eventObject->update(['metadata' => $metadata]);

        $this->success($metadata['enabled'] ? 'URL enabled.' : 'URL disabled.');
        $this->loadData();
    }

    public function deleteUrl(string $id): void
    {
        $eventObject = EventObject::find($id);

        if (! $eventObject || $eventObject->user_id !== Auth::id()) {
            $this->error('URL not found.');

            return;
        }

        $eventObject->delete();
        $this->success('URL deleted successfully.');
        $this->loadData();
    }

    public function fetchNow(string $id, bool $forceRefresh = false): void
    {
        $eventObject = EventObject::find($id);

        if (! $eventObject || $eventObject->user_id !== Auth::id()) {
            $this->error('URL not found.');

            return;
        }

        try {
            FetchSingleUrl::dispatch($this->integration, $eventObject->id, $eventObject->url, $forceRefresh);
            $message = $forceRefresh ? 'Force refresh queued. Will regenerate AI summaries even if content unchanged.' : 'Fetch job queued. Check back shortly for results.';
            $this->success($message);
        } catch (\Exception $e) {
            Log::error('Failed to dispatch fetch job', [
                'object_id' => $id,
                'force_refresh' => $forceRefresh,
                'error' => $e->getMessage(),
            ]);
            $this->error('Failed to queue fetch job. Please try again.');
        }
    }

    public function addCookies(): void
    {
        $this->validate([
            'cookieDomain' => 'required|string|max:255',
            'cookieJson' => 'required|string',
        ]);

        try {
            $parsed = CookieParser::parse($this->cookieJson);

            if (! $parsed['success']) {
                $this->error('Cookie parsing failed: ' . ($parsed['error'] ?? 'Unknown error'));

                return;
            }

            $authMetadata = $this->group->auth_metadata ?? [];
            if (! isset($authMetadata['domains'])) {
                $authMetadata['domains'] = [];
            }

            // Use formatForStorage to get properly structured data
            $authMetadata['domains'][$this->cookieDomain] = CookieParser::formatForStorage($parsed, $this->cookieDomain);

            $this->group->update(['auth_metadata' => $authMetadata]);

            $this->success('Cookies added successfully!');
            $this->cookieDomain = '';
            $this->cookieJson = '';
            $this->loadData();
        } catch (\Exception $e) {
            Log::error('Failed to add cookies', [
                'domain' => $this->cookieDomain,
                'error' => $e->getMessage(),
            ]);
            $this->error('Failed to add cookies. Please check your JSON format.');
        }
    }

    public function deleteCookies(string $domain): void
    {
        $authMetadata = $this->group->auth_metadata ?? [];

        if (isset($authMetadata['domains'][$domain])) {
            unset($authMetadata['domains'][$domain]);
            $this->group->update(['auth_metadata' => $authMetadata]);
            $this->success('Cookies deleted successfully.');
            $this->loadData();
        } else {
            $this->error('Domain not found.');
        }
    }

    public function testDomain(string $domain): void
    {
        // Find a URL for this domain to test
        $url = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->whereRaw("metadata->>'domain' = ?", [$domain])
            ->value('url');

        if (! $url) {
            // Use a generic test URL for the domain
            $url = 'https://' . $domain;
        }

        try {
            $client = new FetchHttpClient;
            $result = $client->testUrl($url, $this->group);

            if ($result['success']) {
                $this->success('Test successful! Status: ' . $result['status_code']);
            } else {
                $this->error('Test failed: ' . $result['error']);
            }
        } catch (\Exception $e) {
            Log::error('Cookie test failed', [
                'domain' => $domain,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            $this->error('Test failed: ' . $e->getMessage());
        }
    }

    public function clearFilters(): void
    {
        $this->urlSearch = '';
        $this->domainFilter = '';
        $this->statusFilter = 'all';
    }

    public function getDomainOptions(): array
    {
        return EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->select('metadata->domain as domain')
            ->distinct()
            ->pluck('domain')
            ->filter()
            ->sort()
            ->values()
            ->toArray();
    }

    public function updateMonitoredIntegrations(): void
    {
        try {
            $config = $this->integration->configuration ?? [];
            $config['monitor_integrations'] = $this->monitoredIntegrations;

            $this->integration->update([
                'configuration' => $config,
            ]);

            $this->success('Discovery settings saved successfully!');
            $this->loadDiscoverySettings();
        } catch (\Exception $e) {
            $this->error('Failed to save discovery settings: ' . $e->getMessage());
            Log::error('Failed to update monitored integrations', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function addExcludedDomain(): void
    {
        $this->validate([
            'newExcludedDomain' => 'required|string|max:255',
        ]);

        try {
            $user = Auth::user();
            $user->addFetchDiscoveryExcludedDomain($this->newExcludedDomain);
            $this->success('Domain added to exclusion list.');
            $this->newExcludedDomain = '';
            $this->loadDiscoverySettings();
        } catch (\Exception $e) {
            $this->error('Failed to add domain: ' . $e->getMessage());
            Log::error('Failed to add excluded domain', [
                'error' => $e->getMessage(),
                'domain' => $this->newExcludedDomain,
            ]);
        }
    }

    public function removeExcludedDomain(string $domain): void
    {
        try {
            Auth::user()->removeFetchDiscoveryExcludedDomain($domain);
            $this->success('Domain removed from exclusion list.');
            $this->loadDiscoverySettings();
        } catch (\Exception $e) {
            $this->error('Failed to remove domain: ' . $e->getMessage());
            Log::error('Failed to remove excluded domain', [
                'error' => $e->getMessage(),
                'domain' => $domain,
            ]);
        }
    }

    public function clearAllDiscoveredUrls(): void
    {
        try {
            $count = EventObject::where('user_id', Auth::id())
                ->where('concept', 'bookmark')
                ->where('type', 'fetch_webpage')
                ->whereRaw("metadata->>'subscription_source' = 'discovered'")
                ->delete();

            $this->success("Cleared {$count} discovered URL(s).");
            $this->loadDiscoverySettings();

            Log::info('Cleared all discovered URLs', [
                'user_id' => Auth::id(),
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to clear discovered URLs: ' . $e->getMessage());
            Log::error('Failed to clear discovered URLs', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
        }
    }

    public function triggerDiscovery(): void
    {
        try {
            if (empty($this->monitoredIntegrations)) {
                $this->warning('Please select at least one integration to monitor.');

                return;
            }

            // Dispatch the discovery job
            App\Jobs\Fetch\DiscoverUrlsFromIntegrations::dispatch($this->integration);

            $this->success('URL discovery started! Check back in a few minutes.');
            Log::info('Manual URL discovery triggered', [
                'integration_id' => $this->integration->id,
                'monitored_integrations' => $this->monitoredIntegrations,
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to start discovery: ' . $e->getMessage());
            Log::error('Failed to trigger URL discovery', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function removeDiscoveredUrl(string $urlId): void
    {
        try {
            $url = EventObject::where('user_id', Auth::id())
                ->where('concept', 'bookmark')
                ->where('type', 'fetch_webpage')
                ->where('id', $urlId)
                ->first();

            if ($url) {
                $url->delete();
                $this->success('URL removed successfully.');
                $this->loadDiscoverySettings();
                $this->loadUrls();
            } else {
                $this->error('URL not found.');
            }
        } catch (\Exception $e) {
            $this->error('Failed to remove URL: ' . $e->getMessage());
        }
    }

    public function toggleAutoFetchMode(): void
    {
        try {
            $user = Auth::user();
            $currentValue = $user->getFetchDiscoveryAutoFetchEnabled();
            $newValue = ! $currentValue;

            $user->setFetchDiscoveryAutoFetchEnabled($newValue);

            $message = $newValue
                ? 'Auto-fetch enabled. New discovered URLs will be fetched automatically.'
                : 'Auto-fetch disabled. New discovered URLs will require manual approval.';

            $this->success($message);
            $this->loadDiscoverySettings();

            Log::info('Fetch discovery auto-fetch mode toggled', [
                'user_id' => $user->id,
                'new_value' => $newValue,
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to toggle auto-fetch mode: ' . $e->getMessage());
            Log::error('Failed to toggle auto-fetch mode', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function toggleUrlAutoFetch(string $urlId): void
    {
        try {
            $eventObject = EventObject::where('user_id', Auth::id())
                ->where('concept', 'bookmark')
                ->where('type', 'fetch_webpage')
                ->where('id', $urlId)
                ->first();

            if (! $eventObject) {
                $this->error('URL not found.');

                return;
            }

            $metadata = $eventObject->metadata ?? [];
            $currentValue = $metadata['enabled'] ?? false;
            $metadata['enabled'] = ! $currentValue;

            $eventObject->update(['metadata' => $metadata]);

            $message = $metadata['enabled']
                ? 'Auto-fetch enabled for this URL.'
                : 'Auto-fetch disabled for this URL.';

            $this->success($message);
            $this->loadDiscoverySettings();
            $this->loadUrls();
        } catch (\Exception $e) {
            $this->error('Failed to toggle auto-fetch: ' . $e->getMessage());
            Log::error('Failed to toggle URL auto-fetch', [
                'url_id' => $urlId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function ignoreDiscoveredUrl(string $urlId): void
    {
        try {
            $eventObject = EventObject::where('user_id', Auth::id())
                ->where('concept', 'bookmark')
                ->where('type', 'fetch_webpage')
                ->where('id', $urlId)
                ->first();

            if (! $eventObject) {
                $this->error('URL not found.');

                return;
            }

            $metadata = $eventObject->metadata ?? [];
            $metadata['discovery_ignored'] = true;
            $metadata['ignored_at'] = now()->toIso8601String();
            $metadata['enabled'] = false; // Also disable auto-fetch

            $eventObject->update(['metadata' => $metadata]);

            $this->success('URL ignored. It will no longer appear in the discovery list.');
            $this->loadDiscoverySettings();
            $this->loadUrls();

            Log::info('Discovered URL ignored', [
                'url_id' => $urlId,
                'url' => $eventObject->url,
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to ignore URL: ' . $e->getMessage());
            Log::error('Failed to ignore discovered URL', [
                'url_id' => $urlId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function unignoreDiscoveredUrl(string $urlId): void
    {
        try {
            $eventObject = EventObject::where('user_id', Auth::id())
                ->where('concept', 'bookmark')
                ->where('type', 'fetch_webpage')
                ->where('id', $urlId)
                ->first();

            if (! $eventObject) {
                $this->error('URL not found.');

                return;
            }

            $metadata = $eventObject->metadata ?? [];
            $metadata['discovery_ignored'] = false;
            unset($metadata['ignored_at']);

            $eventObject->update(['metadata' => $metadata]);

            $this->success('URL restored. It will now appear in the discovery list.');
            $this->loadDiscoverySettings();
            $this->loadUrls();

            Log::info('Discovered URL unignored', [
                'url_id' => $urlId,
                'url' => $eventObject->url,
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to restore URL: ' . $e->getMessage());
            Log::error('Failed to unignore discovered URL', [
                'url_id' => $urlId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function extractCookiesFromBrowser(): void
    {
        if (! $this->playwrightEnabled || ! $this->playwrightAvailable) {
            $this->error('Playwright is not available. Please ensure the playwright-worker service is running.');

            return;
        }

        if (empty($this->cookieDomain)) {
            $this->error('Please enter a domain first.');

            return;
        }

        try {
            $client = new PlaywrightFetchClient;
            $result = $client->extractCookies($this->cookieDomain);

            if (! $result['success']) {
                $this->error('Failed to extract cookies: ' . ($result['error'] ?? 'Unknown error'));

                return;
            }

            if (empty($result['cookies'])) {
                $this->warning('No cookies found for domain: ' . $this->cookieDomain);

                return;
            }

            // Convert Playwright cookies to storage format
            $simpleCookies = [];
            $earliestExpiry = null;

            foreach ($result['cookies'] as $cookie) {
                $simpleCookies[$cookie['name']] = $cookie['value'];

                // Track earliest expiry
                if (isset($cookie['expires']) && $cookie['expires'] > 0) {
                    $expiryDate = Carbon\Carbon::createFromTimestamp($cookie['expires']);
                    if (! $earliestExpiry || $expiryDate->lt($earliestExpiry)) {
                        $earliestExpiry = $expiryDate;
                    }
                }
            }

            // Store in auth_metadata
            $authMetadata = $this->group->auth_metadata ?? [];
            $domains = $authMetadata['domains'] ?? [];

            $domains[$this->cookieDomain] = [
                'cookies' => $simpleCookies,
                'headers' => $domains[$this->cookieDomain]['headers'] ?? [],
                'added_at' => now()->toIso8601String(),
                'expires_at' => $earliestExpiry ? $earliestExpiry->toIso8601String() : null,
                'last_used_at' => null,
                'extracted_via' => 'playwright',
            ];

            $authMetadata['domains'] = $domains;
            $this->group->update(['auth_metadata' => $authMetadata]);

            $this->success('Successfully extracted ' . count($simpleCookies) . ' cookies from browser session!');
            $this->cookieDomain = '';
            $this->loadCookies();

            Log::info('Fetch: Cookies extracted from Playwright browser', [
                'domain' => $this->cookieDomain,
                'cookie_count' => count($simpleCookies),
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to extract cookies: ' . $e->getMessage());
            Log::error('Failed to extract cookies from Playwright', [
                'domain' => $this->cookieDomain,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function toggleCookieAutoRefresh(string $domain): void
    {
        try {
            $authMetadata = $this->group->auth_metadata ?? [];
            $domains = $authMetadata['domains'] ?? [];

            if (! isset($domains[$domain])) {
                $this->error('Domain not found.');

                return;
            }

            // Toggle auto-refresh
            $domains[$domain]['auto_refresh_enabled'] = ! ($domains[$domain]['auto_refresh_enabled'] ?? false);

            $authMetadata['domains'] = $domains;
            $this->group->update(['auth_metadata' => $authMetadata]);

            $status = $domains[$domain]['auto_refresh_enabled'] ? 'enabled' : 'disabled';
            $this->success("Cookie auto-refresh {$status} for {$domain}.");
            $this->loadCookies();

            Log::info('Fetch: Cookie auto-refresh toggled', [
                'domain' => $domain,
                'enabled' => $domains[$domain]['auto_refresh_enabled'],
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to toggle auto-refresh: ' . $e->getMessage());
            Log::error('Failed to toggle cookie auto-refresh', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function createApiToken(): void
    {
        $this->validate([
            'newTokenName' => 'required|string|max:255',
        ]);

        try {
            $tokenName = 'Bookmark API: ' . $this->newTokenName;
            $token = Auth::user()->createToken($tokenName);

            $this->newlyCreatedToken = $token->plainTextToken;
            $this->temporaryTokenValue = $token->plainTextToken;
            $this->showTokenCreateModal = false;
            $this->newTokenName = '';

            // Store token in session temporarily (flash - will be gone after next request)
            session()->flash('fetch_api_temp_token', $token->plainTextToken);

            $this->success('API token created successfully! The examples below are auto-populated with your new token.');
            $this->loadApiTokens();

            Log::info('Fetch: API token created', [
                'token_name' => $tokenName,
                'user_id' => Auth::id(),
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to create API token: ' . $e->getMessage());
            Log::error('Failed to create Fetch API token', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function revokeApiToken(int $tokenId): void
    {
        try {
            $token = Auth::user()->tokens()->where('id', $tokenId)->first();

            if (! $token) {
                $this->error('Token not found.');

                return;
            }

            $token->delete();
            $this->success('API token revoked successfully.');
            $this->loadApiTokens();

            Log::info('Fetch: API token revoked', [
                'token_id' => $tokenId,
                'user_id' => Auth::id(),
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to revoke API token: ' . $e->getMessage());
            Log::error('Failed to revoke Fetch API token', [
                'token_id' => $tokenId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function closeTokenModal(): void
    {
        $this->newlyCreatedToken = null;
        // Keep temporaryTokenValue and session so examples stay populated
        // Session will auto-clear on page refresh
    }

    private function getReasonLabel(string $reason): string
    {
        return match ($reason) {
            'js_domain' => 'Domain requires JavaScript (configured)',
            'robot_detected' => 'Previous fetch detected bot/CAPTCHA',
            'paywall_detected' => 'Previous fetch encountered paywall',
            'learned' => 'Previously successful with Playwright',
            'user_preference' => 'Manual preference set',
            'escalated' => 'Auto-escalated after failures',
            'error_detected' => 'Previous errors detected',
            'default' => 'Default HTTP fetch',
            'playwright_disabled' => 'Playwright disabled',
            default => ucfirst(str_replace('_', ' ', $reason)),
        };
    }

    private function getCookieExpiryStatus(?string $expiresAt): string
    {
        if (! $expiresAt) {
            return 'gray'; // No expiry set
        }

        $expiryDate = Carbon\Carbon::parse($expiresAt);
        $now = now();
        $daysUntilExpiry = $now->diffInDays($expiryDate, false);

        if ($daysUntilExpiry < 0) {
            return 'red'; // Expired
        } elseif ($daysUntilExpiry <= 3) {
            return 'red'; // Expires soon
        } elseif ($daysUntilExpiry <= 7) {
            return 'yellow'; // Warning
        } else {
            return 'green'; // OK
        }
    }
}; ?>

<div>
    <x-header title="Fetch Bookmarks" subtitle="Monitor URLs, extract content, and get AI-powered summaries" separator>
        <x-slot:actions>
            <a href="{{ route('integrations.configure', $integration->id) }}" class="btn btn-outline btn-sm">
                <x-icon name="fas.gear" class="w-4 h-4" />
                Settings
            </a>
        </x-slot:actions>
    </x-header>

    <!-- Tabs -->
    <x-tabs wire:model="activeTab">
        <!-- Subscribed URLs Tab -->
        <x-tab name="urls" label="Subscribed URLs" icon="fas.bookmark">
            <!-- Add URL Section -->
            <div class="card bg-base-200 shadow mb-6">
                <div class="card-body">
                    <h3 class="text-lg font-semibold mb-4">Subscribe to URL</h3>
                    <form wire:submit="subscribeToUrl" class="flex flex-col sm:flex-row gap-4">
                        <div class="form-control flex-1">
                            <input
                                type="url"
                                wire:model="newUrl"
                                placeholder="https://example.com/article"
                                class="input input-bordered w-full"
                                required />
                            @error('newUrl')
                            <label class="label">
                                <span class="label-text-alt text-error">{{ $message }}</span>
                            </label>
                            @enderror
                        </div>
                        <x-button type="submit" class="btn-primary">
                            <x-icon name="fas.plus" class="w-4 h-4" />
                            Subscribe
                        </x-button>
                    </form>
                </div>
            </div>

            <!-- Filters -->
            <div class="card bg-base-200 shadow mb-6">
                <div class="card-body">
                    <div class="flex flex-col sm:flex-row gap-4">
                        <div class="form-control flex-1">
                            <input
                                type="text"
                                wire:model.live.debounce.300ms="urlSearch"
                                placeholder="Search URLs or titles..."
                                class="input input-bordered w-full" />
                        </div>
                        <div class="form-control sm:w-48">
                            <select wire:model.live="domainFilter" class="select select-bordered">
                                <option value="">All Domains</option>
                                @foreach ($this->getDomainOptions() as $domain)
                                <option value="{{ $domain }}">{{ $domain }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-control sm:w-48">
                            <select wire:model.live="statusFilter" class="select select-bordered">
                                <option value="all">All Status</option>
                                <option value="active">Active</option>
                                <option value="disabled">Disabled</option>
                                <option value="error">Error</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                        <div class="form-control sm:w-48">
                            <select wire:model.live="urlSortBy" class="select select-bordered">
                                <option value="last_changed">Last Changed</option>
                                <option value="last_checked">Last Checked</option>
                                <option value="title">Title</option>
                                <option value="domain">Domain</option>
                            </select>
                        </div>
                        @if (!empty($urlSearch) || !empty($domainFilter) || $statusFilter !== 'all')
                        <x-button wire:click="clearFilters" class="btn-outline">
                            <x-icon name="fas.xmark" class="w-4 h-4" />
                        </x-button>
                        @endif
                    </div>
                </div>
            </div>

            <!-- URLs List -->
            @if (count($urls) > 0)
            <div class="space-y-4">
                @foreach ($urls as $url)
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex flex-col sm:flex-row sm:items-start gap-4">
                            <!-- Favicon & URL -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-2">
                                    <img src="https://www.google.com/s2/favicons?domain={{ $url['domain'] }}"
                                        alt="Favicon"
                                        class="w-4 h-4" />
                                    <a href="{{ $url['url'] }}"
                                        target="_blank"
                                        class="text-sm font-mono text-primary hover:underline truncate"
                                        title="{{ $url['url'] }}">
                                        {{ $url['url'] }}
                                    </a>
                                </div>
                                <h3 class="font-semibold mb-1">
                                    {{ $url['title'] === $url['url'] ? 'Not yet fetched' : $url['title'] }}
                                </h3>
                                <div class="flex flex-wrap gap-2 items-center text-sm text-base-content/70">
                                    <span class="flex items-center gap-1">
                                        <x-icon name="fas.globe" class="w-3 h-3" />
                                        {{ $url['domain'] }}
                                    </span>
                                    @if ($url['last_checked_at'])
                                    <span class="flex items-center gap-1">
                                        <x-icon name="fas.clock" class="w-3 h-3" />
                                        Checked {{ \Carbon\Carbon::parse($url['last_checked_at'])->diffForHumans() }}
                                    </span>
                                    @endif
                                    @if ($url['last_changed_at'])
                                    <span class="flex items-center gap-1">
                                        <x-icon name="fas.pen" class="w-3 h-3" />
                                        Changed {{ \Carbon\Carbon::parse($url['last_changed_at'])->diffForHumans() }}
                                    </span>
                                    @endif
                                </div>
                            </div>

                            <!-- Status Badge -->
                            <div class="flex flex-col gap-2 items-end">
                                @if ($url['last_error'])
                                <x-badge value="Error" class="badge-error" />
                                @elseif (!$url['enabled'])
                                <x-badge value="Disabled" class="badge-neutral" />
                                @elseif (!$url['last_checked_at'])
                                <x-badge value="Pending" class="badge-warning" />
                                @else
                                <x-badge value="Active" class="badge-success" />
                                @endif

                                <!-- Fetch Method Badge -->
                                @if (isset($url['last_fetch_method']))
                                <x-badge value="{{ $url['last_fetch_method'] }}" class="badge-info badge-sm" />
                                @endif

                                <!-- Actions Dropdown -->
                                <x-dropdown position="dropdown-end">
                                    <x-slot:trigger>
                                        <x-button icon="fas.ellipsis-vertical" class="btn-ghost btn-sm" />
                                    </x-slot:trigger>
                                    <x-menu-item
                                        title="Fetch Now"
                                        icon="fas.rotate"
                                        wire:click="fetchNow('{{ $url['id'] }}')" />
                                    <x-menu-item
                                        title="Force Refresh"
                                        icon="fas.repeat"
                                        wire:click="fetchNow('{{ $url['id'] }}', true)" />
                                    <x-menu-separator />
                                    <x-menu-item
                                        title="{{ $url['enabled'] ? 'Disable' : 'Enable' }}"
                                        icon="fas.power-off"
                                        wire:click="toggleUrl('{{ $url['id'] }}')" />
                                    <x-menu-item
                                        title="Delete"
                                        icon="fas.trash"
                                        wire:click="deleteUrl('{{ $url['id'] }}')"
                                        class="text-error" />
                                </x-dropdown>
                            </div>
                        </div>

                        <!-- Error Message -->
                        @if ($url['last_error'])
                        <div class="mt-4 space-y-3">
                            <div class="alert alert-error">
                                <x-icon name="fas.triangle-exclamation" class="w-5 h-5" />
                                <span class="text-sm">
                                    {{ $url['last_error']['message'] ?? 'Unknown error' }}
                                    @if (isset($url['last_error']['timestamp']))
                                    <span class="text-xs opacity-70">
                                        ({{ \Carbon\Carbon::parse($url['last_error']['timestamp'])->diffForHumans() }})
                                    </span>
                                    @endif
                                </span>
                            </div>

                            @php
                                $eventObject = \App\Models\EventObject::find($url['id']);
                                $errorScreenshot = $eventObject?->getFirstMediaUrl('error_screenshots');
                            @endphp

                            @if ($errorScreenshot)
                            <div class="card bg-base-300">
                                <div class="card-body p-3">
                                    <div class="flex items-center gap-2 mb-2">
                                        <x-icon name="fas.camera" class="w-4 h-4 text-warning" />
                                        <span class="text-xs font-medium text-base-content/70">Screenshot of failed fetch</span>
                                    </div>
                                    <a href="{{ $errorScreenshot }}" target="_blank" class="block">
                                        <img src="{{ $errorScreenshot }}" alt="Error screenshot" class="w-full rounded-lg border border-base-content/10 hover:border-warning transition-colors" />
                                    </a>
                                    <p class="text-xs text-base-content/60 mt-2">
                                        This screenshot shows what Playwright saw when trying to fetch the page.
                                        <a href="{{ $errorScreenshot }}" target="_blank" class="link link-warning">Click to view full size</a>
                                    </p>
                                </div>
                            </div>
                            @endif
                        </div>
                        @endif

                        <!-- Fetch History -->
                        @if (!empty($url['playwright_history']) && count($url['playwright_history']) > 0)
                        <div class="mt-4">
                            <x-collapse>
                                <x-slot:heading>
                                    <div class="flex items-center gap-2">
                                        <x-icon name="fas.clock" class="w-4 h-4" />
                                        <span class="text-sm font-medium">Fetch History ({{ count($url['playwright_history']) }} entries)</span>
                                    </div>
                                </x-slot:heading>
                                <x-slot:content>
                                    <div class="overflow-x-auto">
                                        <table class="table table-xs">
                                            <thead>
                                                <tr>
                                                    <th>Time</th>
                                                    <th>Method</th>
                                                    <th>Reason</th>
                                                    <th>Outcome</th>
                                                    <th>Duration</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach (array_reverse($url['playwright_history']) as $entry)
                                                <tr>
                                                    <td>
                                                        <span class="text-xs" title="{{ $entry['timestamp'] }}">
                                                            {{ \Carbon\Carbon::parse($entry['timestamp'])->diffForHumans() }}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <x-badge value="{{ $entry['decision'] }}" class="badge-xs {{ $entry['decision'] === 'playwright' ? 'badge-info' : 'badge-neutral' }}" />
                                                    </td>
                                                    <td>
                                                        <span class="text-xs" title="{{ $this->getReasonLabel($entry['reason']) }}">
                                                            {{ Str::limit($this->getReasonLabel($entry['reason']), 30) }}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        @if (is_null($entry['outcome']))
                                                        <x-badge value="pending" class="badge-xs badge-ghost" />
                                                        @elseif ($entry['outcome'] === 'success')
                                                        <x-badge value="success" class="badge-xs badge-success" />
                                                        @else
                                                        <x-badge value="failed" class="badge-xs badge-error" />
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if ($entry['duration_ms'])
                                                        <span class="text-xs">{{ number_format($entry['duration_ms']) }}ms</span>
                                                        @else
                                                        <span class="text-xs text-base-content/50">-</span>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        @if ($entry['status_code'])
                                                        <span class="text-xs">{{ $entry['status_code'] }}</span>
                                                        @else
                                                        <span class="text-xs text-base-content/50">-</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </x-slot:content>
                            </x-collapse>
                        </div>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="card bg-base-200 shadow">
                <div class="card-body">
                    <div class="text-center py-12">
                        <x-icon name="fas.bookmark" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                        <h3 class="text-lg font-medium text-base-content mb-2">No URLs subscribed</h3>
                        <p class="text-base-content/70">
                            @if ($urlSearch || $domainFilter || $statusFilter !== 'all')
                            Try adjusting your filters.
                            @else
                            Add your first URL above to get started.
                            @endif
                        </p>
                    </div>
                </div>
            </div>
            @endif
        </x-tab>

        <!-- Cookie Management Tab -->
        <x-tab name="cookies" label="Cookies" icon="fas.lock">
            <!-- Add Cookies Section -->
            <div class="card bg-base-200 shadow mb-6">
                <div class="card-body">
                    <h3 class="text-lg font-semibold mb-4">Add Domain Cookies</h3>
                    <form wire:submit="addCookies" class="space-y-4">
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Domain</span>
                            </label>
                            <input
                                type="text"
                                wire:model="cookieDomain"
                                placeholder="example.com"
                                class="input input-bordered w-full"
                                required />
                            @error('cookieDomain')
                            <label class="label">
                                <span class="label-text-alt text-error">{{ $message }}</span>
                            </label>
                            @enderror
                        </div>
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Cookies (JSON)</span>
                            </label>
                            <br />
                            <textarea
                                wire:model="cookieJson"
                                placeholder='[{"name": "session_id", "value": "abc123", "expires": 1733155200}]'
                                class="textarea textarea-bordered h-32 font-mono text-sm"
                                required></textarea>
                            @error('cookieJson')
                            <label class="label">
                                <span class="label-text-alt text-error">{{ $message }}</span>
                            </label>
                            @enderror
                        </div>
                        <div class="flex gap-2">
                            <x-button type="submit" class="btn-primary">
                                <x-icon name="fas.plus" class="w-4 h-4" />
                                Add Cookies
                            </x-button>
                        </div>
                    </form>

                    @if ($playwrightEnabled && $playwrightAvailable)
                    <x-alert icon="fas.circle-info" class="alert-info alert-soft mt-4">
                        <div>
                            <p class="font-semibold">Need to access a logged-in site?</p>
                            <p class="text-sm"><a href="{{ config('services.playwright.chrome_vnc_url') }}" target="_blank" class="link link-primary text-sm">Open the browser</a>, log in to any site, then come back here to save your session cookies.</p>
                        </div>
                        <x-slot:actions>
                            <x-button type="button" wire:click="extractCookiesFromBrowser" class="btn-info btn-outline">
                                <x-icon name="fas.globe" class="w-4 h-4" />
                                Extract from Browser
                            </x-button>
                        </x-slot:actions>
                    </x-alert>
                    @endif

                    <!-- Format Help -->
                    <x-collapse class="mt-6">
                        <x-slot:heading>
                            <div class="flex items-center gap-2">
                                <x-icon name="o-question-mark-circle" class="w-5 h-5" />
                                Supported Formats
                            </div>
                        </x-slot:heading>
                        <x-slot:content>
                            <div class="prose prose-sm max-w-none">
                                <p class="text-sm text-base-content/70">Fetch supports multiple cookie formats:</p>
                                <pre class="text-xs"><code>// Standard format with expiry
[{"name": "session_id", "value": "abc123", "expires": 1733155200}]

// Simple key-value
{"session_id": "abc123", "auth_token": "xyz789"}

// Browser HAR format
[{"name": "session_id", "value": "abc123", "expirationDate": 1733155200}]</code></pre>
                            </div>
                        </x-slot:content>
                    </x-collapse>
                </div>
            </div>

            <!-- Domains List -->
            @if (count($domains) > 0)
            <div class="space-y-4">
                @foreach ($domains as $domain)
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex-1">
                                <h3 class="text-lg font-semibold mb-2">{{ $domain['domain'] }}</h3>
                                <div class="flex flex-wrap gap-2 items-center text-sm">
                                    <!-- Expiry Badge -->
                                    @if ($domain['expires_at'])
                                    @php
                                    $expiryDate = \Carbon\Carbon::parse($domain['expires_at']);
                                    $badgeClass = match($domain['expiry_status']) {
                                    'green' => 'badge-success',
                                    'yellow' => 'badge-warning',
                                    'red' => 'badge-error',
                                    default => 'badge-neutral',
                                    };
                                    @endphp
                                    <x-badge value="Expires {{ $expiryDate->format('M j') }}" class="{{ $badgeClass }} badge-outline" />
                                    @else
                                    <x-badge value="No expiry set" class="badge-neutral badge-outline" />
                                    @endif

                                    <span class="text-base-content/70">{{ $domain['cookie_count'] }} cookies</span>

                                    @if ($domain['last_used_at'])
                                    <span class="text-base-content/70">
                                        Used {{ \Carbon\Carbon::parse($domain['last_used_at'])->diffForHumans() }}
                                    </span>
                                    @else
                                    <span class="text-base-content/70">Never used</span>
                                    @endif
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex gap-2">
                                <x-button
                                    wire:click="testDomain('{{ $domain['domain'] }}')"
                                    class="btn-outline btn-sm">
                                    <x-icon name="fas.flask" class="w-4 h-4" />
                                    Test
                                </x-button>
                                <x-button
                                    wire:click="deleteCookies('{{ $domain['domain'] }}')"
                                    class="btn-error btn-outline btn-sm">
                                    <x-icon name="fas.trash" class="w-4 h-4" />
                                    Delete
                                </x-button>
                            </div>
                        </div>

                        <!-- Auto-Refresh Toggle Section -->
                        <div class="border-t border-base-300 pt-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            class="toggle toggle-primary toggle-sm"
                                            @if ($domain['auto_refresh_enabled']) checked @endif
                                            wire:click="toggleCookieAutoRefresh('{{ $domain['domain'] }}')" />
                                        <span class="text-sm font-medium">Auto-refresh cookies before expiry</span>
                                    </label>
                                    <div class="tooltip" data-tip="Automatically refresh cookies before they expire using Playwright">
                                        <x-icon name="fas.circle-info" class="w-4 h-4 text-base-content/50" />
                                    </div>
                                </div>
                            </div>

                            <!-- Status Info -->
                            @if ($domain['auto_refresh_enabled'] || $domain['updated_at'] || $domain['last_refreshed_at'])
                            <div class="mt-2 flex flex-wrap gap-3 text-xs text-base-content/70">
                                @if ($domain['updated_at'])
                                <span class="flex items-center gap-1">
                                    <x-icon name="fas.clock" class="w-3 h-3" />
                                    Auto-updated {{ \Carbon\Carbon::parse($domain['updated_at'])->diffForHumans() }}
                                </span>
                                @endif
                                @if ($domain['last_refreshed_at'])
                                <span class="flex items-center gap-1">
                                    <x-icon name="fas.rotate" class="w-3 h-3" />
                                    Last refreshed {{ \Carbon\Carbon::parse($domain['last_refreshed_at'])->diffForHumans() }}
                                </span>
                                @endif
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="card bg-base-200 shadow">
                <div class="card-body">
                    <div class="text-center py-12">
                        <x-icon name="fas.lock" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                        <h3 class="text-lg font-medium text-base-content mb-2">No saved sessions yet</h3>
                        <p class="text-base-content/70">
                            Add cookies to access sites that require login.
                        </p>
                    </div>
                </div>
            </div>
            @endif
        </x-tab>

        <!-- Discovery Settings Tab -->
        <x-tab name="discovery" label="Discovery" icon="fas.magnifying-glass">
            <!-- Filters -->
            <div class="card bg-base-200 shadow mb-6">
                <div class="card-body">
                    <div class="flex flex-col lg:flex-row gap-4">
                        <div class="form-control flex-1">
                            <label class="label"><span class="label-text">Search</span></label>
                            <input type="text" class="input input-bordered w-full" placeholder="Search URLs..." wire:model.live.debounce.300ms="discoverySearch" />
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Status</span></label>
                            <select class="select select-bordered" wire:model.live="discoveryStatusFilter">
                                <option value="">All Statuses</option>
                                <option value="pending">Pending</option>
                                <option value="fetched">Fetched</option>
                                <option value="error">Error</option>
                            </select>
                        </div>
                        @if ($discoverySearch || $discoveryStatusFilter)
                        <div class="form-control content-end">
                            <label class="label"><span class="label-text">&nbsp;</span></label>
                            <button class="btn btn-outline" wire:click="clearDiscoveryFilters">
                                <x-icon name="fas.xmark" class="w-4 h-4" />
                                Clear
                            </button>
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Discovered URLs Table -->
            <div class="card bg-base-200 shadow mb-6">
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold">Discovered URLs</h3>
                        <button
                            type="button"
                            class="btn btn-error btn-sm"
                            wire:click="clearAllDiscoveredUrls"
                            wire:confirm="Are you sure you want to clear all discovered URLs? This action cannot be undone.">
                            <x-icon name="fas.trash" class="w-4 h-4" />
                            Clear All
                        </button>
                    </div>

                    <x-table
                        :headers="$this->discoveryHeaders()"
                        :rows="$this->getDiscoveredUrls()"
                        :sort-by="$discoverySortBy"
                        with-pagination
                        per-page="discoveryPerPage"
                        :per-page-values="[10, 25, 50, 100]"
                        striped>
                        <x-slot:empty>
                            <div class="text-center py-12">
                                <x-icon name="fas.magnifying-glass" class="w-16 h-16 mx-auto mb-4 text-base-content/30" />
                                <h3 class="text-lg font-medium text-base-content mb-2">
                                    @if ($discoverySearch || $discoveryStatusFilter)
                                    No URLs match your filters
                                    @elseif (!empty($monitoredIntegrations))
                                    No URLs Discovered Yet
                                    @else
                                    Start Monitoring Integrations
                                    @endif
                                </h3>
                                <p class="text-base-content/70">
                                    @if ($discoverySearch || $discoveryStatusFilter)
                                    Try adjusting your filters or search terms
                                    @elseif (!empty($monitoredIntegrations))
                                    URLs will appear here when discovered from your connected integrations
                                    @else
                                    Enable integrations to monitor in the settings below
                                    @endif
                                </p>
                            </div>
                        </x-slot:empty>

                        @scope('cell_url', $url)
                        <div class="flex items-center gap-2 max-w-md">
                            <img
                                src="https://www.google.com/s2/favicons?domain={{ parse_url($url->url, PHP_URL_HOST) }}&sz=32"
                                alt="favicon"
                                class="w-4 h-4 flex-shrink-0" />
                            <div class="flex flex-col min-w-0">
                                @if ($url->title && $url->title !== $url->url)
                                <span class="text-sm font-medium truncate">
                                    {{ Str::limit($url->title, 60) }}
                                </span>
                                <span class="text-xs text-base-content/50 truncate">{{ Str::limit($url->url, 50) }}</span>
                                @else
                                <span class="text-sm truncate">
                                    {{ Str::limit($url->url, 60) }}
                                </span>
                                @endif
                            </div>
                        </div>
                        @endscope

                        @scope('cell_context', $url)
                        <span class="text-xs text-base-content/70">{{ $this->getDiscoveryContext($url) }}</span>
                        @endscope

                        @scope('cell_source', $url)
                        @php
                            $sourceIntegrationId = $url->metadata['discovered_from_integration_id'] ?? null;
                            $sourceIntegrationName = null;
                            if ($sourceIntegrationId) {
                                $sourceIntegration = collect($this->availableIntegrations)->firstWhere('id', $sourceIntegrationId);
                                $sourceIntegrationName = $sourceIntegration['service_name'] ?? 'Unknown';
                            }
                        @endphp
                        @if ($sourceIntegrationName)
                        <span class="badge badge-sm badge-neutral">{{ $sourceIntegrationName }}</span>
                        @else
                        <span class="badge badge-sm badge-ghost">Unknown</span>
                        @endif
                        @endscope

                        @scope('cell_discovered_at', $url)
                        @php
                            $discoveredAt = $url->metadata['discovered_at'] ?? null;
                        @endphp
                        <span class="text-xs text-base-content/70" title="{{ $discoveredAt }}">
                            {{ $discoveredAt ? \Carbon\Carbon::parse($discoveredAt)->diffForHumans() : '-' }}
                        </span>
                        @endscope

                        @scope('cell_status', $url)
                        @php
                            $status = $this->determineDiscoveryStatus($url->metadata ?? []);
                        @endphp
                        @if ($status === 'pending')
                        <span class="badge badge-sm badge-warning gap-1">
                            <x-icon name="fas.clock" class="w-3 h-3" />
                            Pending
                        </span>
                        @elseif ($status === 'fetched')
                        <span class="badge badge-sm badge-success gap-1">
                            <x-icon name="fas.circle-check" class="w-3 h-3" />
                            Fetched
                        </span>
                        @elseif ($status === 'error')
                        <span class="badge badge-sm badge-error gap-1">
                            <x-icon name="o-exclamation-circle" class="w-3 h-3" />
                            Error
                        </span>
                        @else
                        <span class="badge badge-sm badge-ghost">{{ ucfirst($status) }}</span>
                        @endif
                        @endscope

                        @scope('cell_enabled', $url)
                        @php
                            $enabled = $url->metadata['enabled'] ?? true;
                        @endphp
                        <input
                            type="checkbox"
                            class="toggle toggle-sm toggle-success"
                            wire:click="toggleUrlAutoFetch('{{ $url->id }}')"
                            @if ($enabled) checked @endif />
                        @endscope

                        @scope('cell_actions', $url)
                        <div class="dropdown dropdown-end">
                            <button tabindex="0" class="btn btn-ghost btn-xs">
                                <x-icon name="fas.ellipsis-vertical" class="w-4 h-4" />
                            </button>
                            <ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-52">
                                <li>
                                    <a wire:click="fetchNow('{{ $url->id }}')">
                                        <x-icon name="fas.rotate" class="w-4 h-4" />
                                        Fetch Now
                                    </a>
                                </li>
                                <li>
                                    <a wire:click="ignoreDiscoveredUrl('{{ $url->id }}')" class="text-warning">
                                        <x-icon name="fas.eye-slash" class="w-4 h-4" />
                                        Ignore
                                    </a>
                                </li>
                                <li>
                                    <a
                                        wire:click="removeDiscoveredUrl('{{ $url->id }}')"
                                        wire:confirm="Are you sure you want to remove this URL?"
                                        class="text-error">
                                        <x-icon name="fas.trash" class="w-4 h-4" />
                                        Delete
                                    </a>
                                </li>
                            </ul>
                        </div>
                        @endscope
                    </x-table>
                </div>
            </div>
            <!-- Auto-Fetch Mode Toggle -->
            <div class="card bg-base-200 shadow mb-6">
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex-1">
                            <h3 class="text-lg font-semibold mb-2">Auto-Fetch Mode</h3>
                            <p class="text-sm text-base-content/70">
                                @if ($this->getAutoFetchEnabled())
                                New discovered URLs will be automatically fetched and processed.
                                @else
                                New discovered URLs require manual approval before being fetched.
                                @endif
                            </p>
                        </div>
                        <input
                            type="checkbox"
                            class="toggle toggle-primary toggle-lg"
                            wire:click="toggleAutoFetchMode"
                            @if ($this->getAutoFetchEnabled()) checked @endif
                        />
                    </div>

                    <!-- Stats -->
                    @php
                        $discoveryStats = $this->getDiscoveryStats();
                    @endphp
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
                        <div class="stat bg-base-100 rounded-lg p-3">
                            <div class="stat-title text-xs">Discovered</div>
                            <div class="stat-value text-2xl">{{ $discoveryStats['total'] }}</div>
                        </div>
                        <div class="stat bg-base-100 rounded-lg p-3">
                            <div class="stat-title text-xs">Pending</div>
                            <div class="stat-value text-2xl text-warning">
                                {{ $discoveryStats['pending'] }}
                            </div>
                        </div>
                        <div class="stat bg-base-100 rounded-lg p-3">
                            <div class="stat-title text-xs">Fetched</div>
                            <div class="stat-value text-2xl text-success">
                                {{ $discoveryStats['fetched'] }}
                            </div>
                        </div>
                        <div class="stat bg-base-100 rounded-lg p-3">
                            <div class="stat-title text-xs">Errors</div>
                            <div class="stat-value text-2xl text-error">
                                {{ $discoveryStats['errors'] }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Monitor Integrations Section -->
            <div class="card bg-base-200 shadow mb-6">
                <div class="card-body">
                    <h3 class="text-lg font-semibold mb-4">Smart URL Discovery</h3>

                    @if (empty($this->availableIntegrations))
                    <div class="alert alert-info">
                        <x-icon name="fas.circle-info" class="w-6 h-6" />
                        <span>Add integrations to get started.</span>
                    </div>
                    @else
                    <div class="form-control mb-4">
                        <label class="label">
                            <span class="label-text">Select Integrations to Monitor</span>
                        </label>
                        <div class="space-y-2">
                            @foreach ($this->availableIntegrations as $integration)
                            <label class="flex items-center gap-3 p-3 rounded-lg border border-base-300 hover:bg-base-300 cursor-pointer transition">
                                <input
                                    type="checkbox"
                                    class="checkbox"
                                    value="{{ $integration['id'] }}"
                                    wire:model="monitoredIntegrations" />
                                <div class="flex-1">
                                    <div class="font-medium">{{ $integration['name'] }}</div>
                                    <div class="text-sm text-base-content/70">{{ $integration['service_name'] }}</div>
                                </div>
                                @if (isset($integration['object_count']))
                                <span class="badge badge-neutral">{{ $integration['object_count'] }} objects</span>
                                @endif
                            </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="btn btn-success"
                            wire:click="updateMonitoredIntegrations">
                            <x-icon name="fas.check" class="w-4 h-4" />
                            Save
                        </button>

                        <button
                            type="button"
                            class="btn btn-secondary btn-outline"
                            wire:click="triggerDiscovery"
                            @if (empty($monitoredIntegrations)) disabled @endif>
                            <x-icon name="fas.magnifying-glass" class="w-4 h-4" />
                            Scan Now
                        </button>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Excluded Domains Section -->
            <div class="card bg-base-200 shadow mb-6">
                <div class="card-body">
                    <h3 class="text-lg font-semibold mb-4">Excluded Domains</h3>
                    <p class="text-sm text-base-content/70 mb-4">
                        URLs from these domains will be automatically ignored during discovery.
                    </p>

                    <!-- Add Domain Form -->
                    <div class="flex gap-2 mb-4">
                        <input
                            type="text"
                            class="input input-bordered flex-1"
                            placeholder="example.com or cdn.example.com"
                            wire:model="newExcludedDomain"
                            wire:keydown.enter="addExcludedDomain" />
                        <button
                            type="button"
                            class="btn btn-primary"
                            wire:click="addExcludedDomain">
                            <x-icon name="fas.plus" class="w-4 h-4" />
                            Add Domain
                        </button>
                    </div>

                    <!-- Excluded Domains List -->
                    @if (empty($excludedDomains))
                    <div class="alert">
                        <x-icon name="fas.circle-info" class="w-5 h-5" />
                        <span>No domains excluded. Add domains above to filter them from discovery.</span>
                    </div>
                    @else
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                        @foreach ($excludedDomains as $domain)
                        <div class="flex items-center justify-between p-3 rounded-lg border border-base-300 bg-base-100">
                            <div class="flex items-center gap-2 flex-1 min-w-0">
                                <x-icon name="fas.ban" class="w-4 h-4 text-base-content/50 flex-shrink-0" />
                                <span class="font-mono text-sm truncate">{{ $domain }}</span>
                            </div>
                            <button
                                type="button"
                                class="btn btn-ghost btn-sm btn-circle flex-shrink-0"
                                wire:click="removeExcludedDomain('{{ $domain }}')"
                                title="Remove">
                                <x-icon name="fas.xmark" class="w-4 h-4" />
                            </button>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
            </div>
        </x-tab>

        <!-- Stats Tab -->
        <x-tab name="stats" label="Stats" icon="fas.chart-simple">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="stat">
                            <div class="stat-title">Total URLs</div>
                            <div class="stat-value text-primary">{{ $stats['total_urls'] ?? 0 }}</div>
                        </div>
                    </div>
                </div>
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="stat">
                            <div class="stat-title">Active URLs</div>
                            <div class="stat-value text-success">{{ $stats['active_urls'] ?? 0 }}</div>
                        </div>
                    </div>
                </div>
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="stat">
                            <div class="stat-title">URLs with Errors</div>
                            <div class="stat-value text-error">{{ $stats['urls_with_errors'] ?? 0 }}</div>
                        </div>
                    </div>
                </div>
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="stat">
                            <div class="stat-title">Domains with Cookies</div>
                            <div class="stat-value text-info">{{ $stats['domains_with_cookies'] ?? 0 }}</div>
                        </div>
                    </div>
                </div>
                <div class="card bg-base-200 shadow col-span-1 sm:col-span-2">
                    <div class="card-body">
                        <div class="stat">
                            <div class="stat-title">Next Scheduled Run</div>
                            <div class="stat-value text-2xl">
                                @if (isset($stats['next_run']))
                                {{ \Carbon\Carbon::parse($stats['next_run'])->diffForHumans() }}
                                @else
                                Not scheduled
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </x-tab>

        <!-- Playwright Settings Tab -->
        @if ($playwrightEnabled)
        <x-tab name="playwright" label="Playwright" icon="fas.desktop">
            <div class="space-y-6" wire:poll.30s="refreshMetrics">
                <!-- Status Card -->
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold">Playwright Status</h3>
                            <span class="text-xs text-base-content/50">Auto-refreshes every 30s</span>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="flex-1">
                                <p class="text-sm text-base-content/70">Service Status</p>
                                <p class="font-semibold">
                                    @if ($playwrightAvailable)
                                    <x-badge value="Available" class="badge-success" />
                                    @else
                                    <x-badge value="Unavailable" class="badge-error" />
                                    @endif
                                </p>
                            </div>
                            @if ($playwrightAvailable && !empty($workerStats))
                            <div class="stats stats-vertical sm:stats-horizontal shadow-sm">
                                <div class="stat py-2 px-4">
                                    <div class="stat-title text-xs">Stealth</div>
                                    <div class="stat-value text-sm">
                                        @if ($workerStats['stealth_enabled'])
                                        <x-badge value="Enabled" class="badge-success badge-sm" />
                                        @else
                                        <x-badge value="Disabled" class="badge-neutral badge-sm" />
                                        @endif
                                    </div>
                                </div>
                                <div class="stat py-2 px-4">
                                    <div class="stat-title text-xs">Context TTL</div>
                                    <div class="stat-value text-sm">{{ $workerStats['context_ttl'] }}m</div>
                                </div>
                            </div>
                            @endif
                            @if ($playwrightAvailable)
                            <a href="{{ config('services.playwright.chrome_vnc_url') }}" target="_blank" class="btn btn-primary btn-sm">
                                <x-icon name="fas.desktop" class="w-4 h-4" />
                                Open Browser (VNC)
                            </a>
                            @endif
                        </div>

                        @if (!$playwrightAvailable)
                        <div class="alert alert-warning mt-4">
                            <x-icon name="fas.triangle-exclamation" class="w-5 h-5" />
                            <div>
                                <p class="font-semibold">Browser automation unavailable</p>
                                <p class="text-sm">To enable, run: <code class="bg-base-300 px-2 py-1 rounded">sail up -d --profile playwright</code></p>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>

                <!-- Real-time Metrics Card -->
                @if ($playwrightAvailable && !empty($healthMetrics))
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">Real-time Metrics (Last 24h)</h3>

                        <!-- Success Rate Comparison -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                            <div class="card bg-base-100 shadow-sm">
                                <div class="card-body">
                                    <h4 class="font-medium mb-2">HTTP Success Rate</h4>
                                    <div class="flex items-end gap-2">
                                        <span class="text-3xl font-bold text-secondary">{{ $healthMetrics['http']['success_rate'] }}%</span>
                                        <span class="text-sm text-base-content/70 mb-1">
                                            ({{ $healthMetrics['http']['success'] }}/{{ $healthMetrics['http']['total'] }})
                                        </span>
                                    </div>
                                    <progress class="progress progress-secondary w-full mt-2" value="{{ $healthMetrics['http']['success_rate'] }}" max="100"></progress>
                                    <p class="text-xs text-base-content/70 mt-2">
                                        Avg: {{ $healthMetrics['http']['avg_duration_ms'] }}ms
                                    </p>
                                </div>
                            </div>

                            <div class="card bg-base-100 shadow-sm">
                                <div class="card-body">
                                    <h4 class="font-medium mb-2">Playwright Success Rate</h4>
                                    <div class="flex items-end gap-2">
                                        <span class="text-3xl font-bold text-primary">{{ $healthMetrics['playwright']['success_rate'] }}%</span>
                                        <span class="text-sm text-base-content/70 mb-1">
                                            ({{ $healthMetrics['playwright']['success'] }}/{{ $healthMetrics['playwright']['total'] }})
                                        </span>
                                    </div>
                                    <progress class="progress progress-primary w-full mt-2" value="{{ $healthMetrics['playwright']['success_rate'] }}" max="100"></progress>
                                    <p class="text-xs text-base-content/70 mt-2">
                                        Avg: {{ $healthMetrics['playwright']['avg_duration_ms'] }}ms
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Total Fetches Today -->
                        <div class="stats shadow w-full">
                            <div class="stat">
                                <div class="stat-title">Total Fetches Today</div>
                                <div class="stat-value text-primary">{{ $healthMetrics['total_fetches'] }}</div>
                                <div class="stat-desc">
                                    HTTP: {{ $healthMetrics['http']['total'] }} |
                                    Playwright: {{ $healthMetrics['playwright']['total'] }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stealth Effectiveness Card -->
                @if ($playwrightAvailable && !empty($healthMetrics['stealth']) && $healthMetrics['stealth']['total'] > 0)
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">Stealth Effectiveness</h3>
                        <div class="flex items-center gap-6">
                            @php
                            $effectiveness = $healthMetrics['stealth']['effectiveness'];
                            @endphp
                            <div class="radial-progress text-accent" style="--value: {{ $effectiveness }};" role="progressbar">
                                {{ $effectiveness }}%
                            </div>
                            <div class="flex-1">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-sm text-base-content/70">Bypassed</p>
                                        <p class="text-2xl font-bold text-success">{{ $healthMetrics['stealth']['bypassed'] }}</p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-base-content/70">Detected</p>
                                        <p class="text-2xl font-bold text-error">{{ $healthMetrics['stealth']['detected'] }}</p>
                                    </div>
                                </div>
                                <p class="text-xs text-base-content/50 mt-2">
                                    Bot detection attempts in last 24 hours
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                @endif
                @endif

                <!-- Statistics Card -->
                @if ($playwrightAvailable && !empty($playwrightStats))
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">Fetch Method Statistics</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="stat">
                                <div class="stat-title">Requires Playwright</div>
                                <div class="stat-value text-primary">{{ $playwrightStats['requires_playwright'] ?? 0 }}</div>
                                <div class="stat-desc">URLs that need JavaScript</div>
                            </div>
                            <div class="stat">
                                <div class="stat-title">Prefers HTTP</div>
                                <div class="stat-value text-secondary">{{ $playwrightStats['prefers_http'] ?? 0 }}</div>
                                <div class="stat-desc">Simple HTTP fetches</div>
                            </div>
                            <div class="stat">
                                <div class="stat-title">Auto-detect</div>
                                <div class="stat-value text-info">{{ $playwrightStats['auto'] ?? 0 }}</div>
                                <div class="stat-desc">Smart routing enabled</div>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                <!-- Cookie Auto-Refresh Card -->
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">Cookie Auto-Refresh</h3>
                        @php
                        $autoRefreshEnabled = collect($domains)->where('auto_refresh_enabled', true)->count();
                        $totalDomains = count($domains);
                        @endphp
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="stat">
                                <div class="stat-title">Domains with Auto-Refresh</div>
                                <div class="stat-value text-primary">{{ $autoRefreshEnabled }}</div>
                                <div class="stat-desc">Out of {{ $totalDomains }} total domains</div>
                            </div>
                            <div class="stat">
                                <div class="stat-title">Status</div>
                                <div class="stat-value text-sm">
                                    @if ($autoRefreshEnabled > 0)
                                    <x-badge value="Active" class="badge-success badge-lg" />
                                    @else
                                    <x-badge value="Inactive" class="badge-neutral badge-lg" />
                                    @endif
                                </div>
                                <div class="stat-desc">
                                    @if ($autoRefreshEnabled > 0)
                                    Cookies will refresh automatically
                                    @else
                                    No domains configured
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-info mt-4">
                            <x-icon name="fas.circle-info" class="w-5 h-5" />
                            <div class="text-sm">
                                <p>Cookie auto-refresh uses Playwright to automatically update cookies before they expire.</p>
                                <p class="mt-1">Enable it per-domain in the <strong>Cookies</strong> tab.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- JavaScript-Required Domains -->
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">Sites That Need Browser Automation</h3>
                        <p class="text-sm text-base-content/70 mb-4">
                            These sites are always fetched using the browser:
                        </p>
                        <div class="flex flex-wrap gap-2">
                            @php
                            $jsDomains = array_filter(array_map('trim', explode(',', config('services.playwright.js_required_domains', ''))));
                            @endphp
                            @forelse ($jsDomains as $domain)
                            <x-badge value="{{ $domain }}" class="badge-outline" />
                            @empty
                            <p class="text-sm text-base-content/50">No domains configured</p>
                            @endforelse
                        </div>
                        <p class="text-sm text-base-content/50 mt-4">
                            Configure via PLAYWRIGHT_JS_DOMAINS environment variable (comma-separated).
                        </p>
                    </div>
                </div>
            </div>
        </x-tab>
        @endif

        <!-- API Tab -->
        <x-tab name="api" label="API & Share" icon="fas.key">
            <!-- API Tokens Section -->
            <div class="card bg-base-200 shadow mb-6">
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold">API Access Tokens</h3>
                        <x-button wire:click="$set('showTokenCreateModal', true)" class="btn-primary btn-sm">
                            <x-icon name="fas.plus" class="w-4 h-4" />
                            Create Token
                        </x-button>
                    </div>

                    <div class="alert alert-warning mb-4">
                        <x-icon name="fas.triangle-exclamation" class="w-5 h-5" />
                        <div class="text-sm">
                            <p class="font-semibold">Keep your tokens secure!</p>
                            <p>Treat API tokens like passwords. Anyone with your token can save bookmarks to your account.</p>
                        </div>
                    </div>

                    <!-- Token List -->
                    @if (count($apiTokens) > 0)
                    <div class="space-y-3">
                        @foreach ($apiTokens as $token)
                        <div class="card bg-base-100 shadow-sm">
                            <div class="card-body p-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <h4 class="font-medium">{{ $token['name'] }}</h4>
                                        <div class="flex flex-wrap gap-3 text-sm text-base-content/70 mt-1">
                                            <span class="flex items-center gap-1">
                                                <x-icon name="fas.calendar" class="w-3 h-3" />
                                                Created {{ \Carbon\Carbon::parse($token['created_at'])->format('M j, Y') }}
                                            </span>
                                            @if ($token['last_used_at'])
                                            <span class="flex items-center gap-1">
                                                <x-icon name="fas.clock" class="w-3 h-3" />
                                                Last used {{ \Carbon\Carbon::parse($token['last_used_at'])->diffForHumans() }}
                                            </span>
                                            @else
                                            <span class="text-base-content/50">Never used</span>
                                            @endif
                                        </div>
                                    </div>
                                    <x-button
                                        wire:click="revokeApiToken({{ $token['id'] }})"
                                        wire:confirm="Are you sure you want to revoke this token? This cannot be undone."
                                        class="btn-error btn-outline btn-sm">
                                        <x-icon name="fas.trash" class="w-4 h-4" />
                                        Revoke
                                    </x-button>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <div class="text-center py-8 bg-base-100 rounded-lg">
                        <x-icon name="fas.key" class="w-12 h-12 mx-auto text-base-content/50 mb-3" />
                        <p class="text-base-content/70">No API tokens created yet.</p>
                        <p class="text-sm text-base-content/50 mt-1">Create one to start using the API.</p>
                    </div>
                    @endif
                </div>
            </div>

            <!-- API Endpoint Information -->
            <div class="card bg-base-200 shadow mb-6">
                <div class="card-body">
                    <h3 class="text-lg font-semibold mb-4">API Endpoint</h3>

                    <div class="form-control mb-4">
                        <label class="label">
                            <span class="label-text font-medium">Endpoint URL</span>
                        </label>
                        <div class="flex gap-2">
                            <input
                                type="text"
                                value="{{ url('/api/fetch/bookmarks') }}"
                                readonly
                                class="input input-bordered flex-1 font-mono text-sm"
                                id="api-endpoint-url" />
                            <x-button
                                onclick="navigator.clipboard.writeText(document.getElementById('api-endpoint-url').value); window.dispatchEvent(new CustomEvent('toast-success', { detail: { message: 'Copied to clipboard!' } }))"
                                class="btn-outline">
                                <x-icon name="o-clipboard" class="w-4 h-4" />
                                Copy
                            </x-button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Parameter</th>
                                    <th>Type</th>
                                    <th>Required</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code class="text-xs">url</code></td>
                                    <td><span class="badge badge-sm badge-info">string</span></td>
                                    <td><x-badge value="Yes" class="badge-success badge-sm" /></td>
                                    <td>The URL to bookmark</td>
                                </tr>
                                <tr>
                                    <td><code class="text-xs">fetch_immediately</code></td>
                                    <td><span class="badge badge-sm badge-info">boolean</span></td>
                                    <td><x-badge value="No" class="badge-neutral badge-sm" /></td>
                                    <td>Fetch content right away (default: true)</td>
                                </tr>
                                <tr>
                                    <td><code class="text-xs">force_refresh</code></td>
                                    <td><span class="badge badge-sm badge-info">boolean</span></td>
                                    <td><x-badge value="No" class="badge-neutral badge-sm" /></td>
                                    <td>Force re-fetch if exists (default: false)</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Integration Instructions -->
            <div class="space-y-6">
                <!-- Apple Shortcuts -->
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center">
                                <x-icon name="fas.mobile-screen" class="w-5 h-5 text-primary" />
                            </div>
                            <h3 class="text-lg font-semibold">Apple Shortcuts</h3>
                        </div>

                        <x-collapse>
                            <x-slot:heading>
                                <div class="flex items-center gap-2">
                                    <x-icon name="fas.circle-info" class="w-5 h-5" />
                                    Setup Instructions
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <div class="prose prose-sm max-w-none">
                                    @if ($temporaryTokenValue)
                                    <div class="alert alert-success mb-4">
                                        <x-icon name="fas.wand-magic-sparkles" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>✨ Auto-populated!</strong> Your new token is ready to use below.</p>
                                            <p class="text-xs opacity-70 mt-1">The actual token value is showing in all examples (will reset on page refresh)</p>
                                        </div>
                                    </div>
                                    @elseif (!empty($apiTokens))
                                    <div class="alert alert-info mb-4">
                                        <x-icon name="fas.circle-info" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>Using your token:</strong> {{ $this->latestTokenName() }}</p>
                                            <p class="text-xs opacity-70 mt-1">Paste your actual token value in place of the placeholder</p>
                                        </div>
                                    </div>
                                    @endif

                                    <ol class="text-sm space-y-3">
                                        <li>Open the <strong>Shortcuts</strong> app on your iPhone or iPad</li>
                                        <li>Tap the <strong>+</strong> button to create a new shortcut</li>
                                        <li>Add a <strong>"Get URLs from Input"</strong> action</li>
                                        <li>Add a <strong>"Get Contents of URL"</strong> action with these settings:
                                            <ul class="mt-2">
                                                <li>URL: <code class="bg-base-300 px-2 py-1 rounded text-xs">{{ url('/api/fetch/bookmarks') }}</code></li>
                                                <li>Method: <strong>POST</strong></li>
                                                <li>Headers:
                                                    <ul class="mt-1">
                                                        <li><code class="bg-base-300 px-1 rounded text-xs">Authorization: Bearer {{ $this->getTokenForExamples() }}</code></li>
                                                        <li><code class="bg-base-300 px-1 rounded text-xs">Content-Type: application/json</code></li>
                                                        <li><code class="bg-base-300 px-1 rounded text-xs">Accept: application/json</code></li>
                                                    </ul>
                                                </li>
                                                <li>Request Body: <strong>JSON</strong></li>
                                                <li>Body content: <code class="bg-base-300 px-2 py-1 rounded text-xs">{"url": "URL from Input"}</code></li>
                                            </ul>
                                        </li>
                                        <li>Add a <strong>"Show Notification"</strong> action to confirm success</li>
                                        <li>Name your shortcut (e.g., "Save to Fetch")</li>
                                        <li>Enable <strong>"Show in Share Sheet"</strong> in settings</li>
                                    </ol>
                                    <div class="alert alert-info mt-4">
                                        <x-icon name="fas.lightbulb" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>Pro tip:</strong> You can now use the Share Sheet from Safari or any app to save URLs directly to Fetch!</p>
                                        </div>
                                    </div>
                                </div>
                            </x-slot:content>
                        </x-collapse>
                    </div>
                </div>

                <!-- Chrome Bookmarklet -->
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-lg bg-secondary/10 flex items-center justify-center">
                                <x-icon name="fas.globe" class="w-5 h-5 text-secondary" />
                            </div>
                            <h3 class="text-lg font-semibold">Browser Bookmarklet</h3>
                        </div>

                        <x-collapse>
                            <x-slot:heading>
                                <div class="flex items-center gap-2">
                                    <x-icon name="fas.circle-info" class="w-5 h-5" />
                                    Setup Instructions
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <div class="prose prose-sm max-w-none">
                                    @if ($temporaryTokenValue)
                                    <div class="alert alert-success mb-4">
                                        <x-icon name="fas.wand-magic-sparkles" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>✨ Auto-populated!</strong> Your new token is ready to use below.</p>
                                            <p class="text-xs opacity-70 mt-1">The actual token value is showing (will reset on page refresh)</p>
                                        </div>
                                    </div>
                                    @elseif (!empty($apiTokens))
                                    <div class="alert alert-info mb-4">
                                        <x-icon name="fas.circle-info" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>Using your token:</strong> {{ $this->latestTokenName() }}</p>
                                            <p class="text-xs opacity-70 mt-1">Paste your actual token value in place of the placeholder</p>
                                        </div>
                                    </div>
                                    @endif

                                    <p class="text-sm mb-3">{{ $temporaryTokenValue ? 'Ready to copy! The code below has your actual token:' : (!empty($apiTokens) ? 'Copy the code below and replace the placeholder with your token:' : 'Create a token first, then use this code:') }}</p>

                                    <div class="bg-base-300 p-4 rounded-lg mb-4">
                                        <div class="flex items-center justify-between mb-2">
                                            <p class="text-sm font-medium">Bookmarklet Code</p>
                                            <x-button
                                                onclick="navigator.clipboard.writeText(document.getElementById('bookmarklet-code').textContent); window.dispatchEvent(new CustomEvent('toast-success', { detail: { message: 'Bookmarklet copied to clipboard!' } }))"
                                                class="btn-xs btn-outline">
                                                <x-icon name="o-clipboard" class="w-3 h-3" />
                                                Copy
                                            </x-button>
                                        </div>
                                        <pre class="text-xs overflow-x-auto" id="bookmarklet-code"><code>javascript:(function(){
    const token = '{{ $this->getTokenForExamples() }}';
    const url = window.location.href;
    fetch('{{ url('/api/fetch/bookmarks') }}', {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${token}`,
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({ url })
    })
    .then(r => r.json())
    .then(d => alert(d.success ? '✓ Saved to Fetch!' : 'Error: ' + d.message))
    .catch(e => alert('Error saving bookmark'));
})();</code></pre>
                                    </div>

                                    <p class="text-sm mb-2"><strong>Step 1:</strong> Copy the code above and replace the token placeholder with your actual token value</p>
                                    <p class="text-sm mb-2"><strong>Step 2:</strong> Create a new bookmark and paste the code as the URL</p>
                                    <p class="text-sm"><strong>Step 3:</strong> Click the bookmark on any page to save it to Fetch</p>

                                    @if (empty($apiTokens))
                                    <div class="alert alert-warning mt-4">
                                        <x-icon name="fas.triangle-exclamation" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p>Create a token above first, then come back to get your personalized bookmarklet code!</p>
                                        </div>
                                    </div>
                                    @endif
                                </div>
                            </x-slot:content>
                        </x-collapse>
                    </div>
                </div>

                <!-- cURL Example -->
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-lg bg-accent/10 flex items-center justify-center">
                                <x-icon name="o-command-line" class="w-5 h-5 text-accent" />
                            </div>
                            <h3 class="text-lg font-semibold">Command Line (cURL)</h3>
                        </div>

                        <x-collapse>
                            <x-slot:heading>
                                <div class="flex items-center gap-2">
                                    <x-icon name="fas.code" class="w-5 h-5" />
                                    Example Request
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <div class="prose prose-sm max-w-none">
                                    @if ($temporaryTokenValue)
                                    <div class="alert alert-success mb-4">
                                        <x-icon name="fas.wand-magic-sparkles" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>✨ Auto-populated!</strong> Your new token is in the command below.</p>
                                            <p class="text-xs opacity-70 mt-1">Copy and run it directly! (resets on page refresh)</p>
                                        </div>
                                    </div>
                                    @elseif (!empty($apiTokens))
                                    <div class="alert alert-info mb-4">
                                        <x-icon name="fas.circle-info" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>Using your token:</strong> {{ $this->latestTokenName() }}</p>
                                            <p class="text-xs opacity-70 mt-1">Replace the placeholder with your actual token</p>
                                        </div>
                                    </div>
                                    @endif

                                    <div class="flex items-center justify-between mb-2">
                                        <p class="text-sm font-medium">Request Example</p>
                                        <x-button
                                            onclick="navigator.clipboard.writeText(document.getElementById('curl-code').textContent); window.dispatchEvent(new CustomEvent('toast-success', { detail: { message: 'cURL command copied!' } }))"
                                            class="btn-xs btn-outline">
                                            <x-icon name="o-clipboard" class="w-3 h-3" />
                                            Copy
                                        </x-button>
                                    </div>
                                    <pre class="bg-base-300 p-4 rounded-lg text-xs overflow-x-auto" id="curl-code"><code>curl -X POST {{ url('/api/fetch/bookmarks') }} \
  -H "Authorization: Bearer {{ $this->getTokenForExamples() }}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"url": "https://example.com/article", "fetch_immediately": true}'</code></pre>

                                    <p class="text-sm mt-4"><strong>Response:</strong></p>
                                    <pre class="bg-base-300 p-4 rounded-lg text-xs overflow-x-auto"><code>{
  "success": true,
  "bookmark": {
    "id": "uuid",
    "url": "https://example.com/article",
    "title": "Article Title",
    "status": "pending"
  },
  "job_dispatched": true
}</code></pre>
                                </div>
                            </x-slot:content>
                        </x-collapse>
                    </div>
                </div>

                <!-- JavaScript Example -->
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-lg bg-info/10 flex items-center justify-center">
                                <x-icon name="o-code-bracket-square" class="w-5 h-5 text-info" />
                            </div>
                            <h3 class="text-lg font-semibold">JavaScript / Browser Extension</h3>
                        </div>

                        <x-collapse>
                            <x-slot:heading>
                                <div class="flex items-center gap-2">
                                    <x-icon name="fas.code" class="w-5 h-5" />
                                    Example Code
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <div class="prose prose-sm max-w-none">
                                    @if ($temporaryTokenValue)
                                    <div class="alert alert-success mb-4">
                                        <x-icon name="fas.wand-magic-sparkles" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>✨ Auto-populated!</strong> Your new token is in the code below.</p>
                                            <p class="text-xs opacity-70 mt-1">Ready to use! (resets on page refresh)</p>
                                        </div>
                                    </div>
                                    @elseif (!empty($apiTokens))
                                    <div class="alert alert-info mb-4">
                                        <x-icon name="fas.circle-info" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>Using your token:</strong> {{ $this->latestTokenName() }}</p>
                                            <p class="text-xs opacity-70 mt-1">Replace the placeholder with your actual token</p>
                                        </div>
                                    </div>
                                    @endif

                                    <div class="flex items-center justify-between mb-2">
                                        <p class="text-sm font-medium">JavaScript Code</p>
                                        <x-button
                                            onclick="navigator.clipboard.writeText(document.getElementById('javascript-code').textContent); window.dispatchEvent(new CustomEvent('toast-success', { detail: { message: 'JavaScript code copied!' } }))"
                                            class="btn-xs btn-outline">
                                            <x-icon name="o-clipboard" class="w-3 h-3" />
                                            Copy
                                        </x-button>
                                    </div>
                                    <pre class="bg-base-300 p-4 rounded-lg text-xs overflow-x-auto" id="javascript-code"><code>const API_TOKEN = '{{ $this->getTokenForExamples() }}';
const API_ENDPOINT = '{{ url('/api/fetch/bookmarks') }}';

async function saveToFetch(url) {
  try {
    const response = await fetch(API_ENDPOINT, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${API_TOKEN}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        url: url,
        fetch_immediately: true
      })
    });

    const data = await response.json();

    if (data.success) {
      console.log('Bookmark saved:', data.bookmark);
      return data.bookmark;
    } else {
      throw new Error(data.message || 'Failed to save bookmark');
    }
  } catch (error) {
    console.error('Error saving bookmark:', error);
    throw error;
  }
}

// Usage
saveToFetch('https://example.com/article')
  .then(bookmark => console.log('Saved!', bookmark))
  .catch(error => console.error('Failed:', error));</code></pre>

                                    <div class="alert alert-info mt-4">
                                        <x-icon name="fas.lightbulb" class="w-5 h-5" />
                                        <div class="text-sm">
                                            <p><strong>Browser Extension Tip:</strong> You can use this code in a Chrome/Firefox extension to add a "Save to Fetch" button to your toolbar!</p>
                                        </div>
                                    </div>
                                </div>
                            </x-slot:content>
                        </x-collapse>
                    </div>
                </div>
            </div>
        </x-tab>
    </x-tabs>

    <!-- Create Token Modal -->
    @if ($showTokenCreateModal)
    <div class="modal modal-open">
        <div class="modal-box">
            <h3 class="font-bold text-lg mb-4">Create API Token</h3>

            <form wire:submit="createApiToken">
                <div class="form-control mb-4">
                    <label class="label">
                        <span class="label-text">Token Name</span>
                    </label>
                    <input
                        type="text"
                        wire:model="newTokenName"
                        placeholder="e.g., iPhone Shortcuts, Chrome Extension"
                        class="input input-bordered"
                        autofocus
                        required />
                    @error('newTokenName')
                    <label class="label">
                        <span class="label-text-alt text-error">{{ $message }}</span>
                    </label>
                    @enderror
                    <label class="label">
                        <span class="label-text-alt">Give it a descriptive name so you know where it's used</span>
                    </label>
                </div>

                <div class="modal-action">
                    <x-button type="button" wire:click="$set('showTokenCreateModal', false)" class="btn-ghost">
                        Cancel
                    </x-button>
                    <x-button type="submit" class="btn-primary">
                        Create Token
                    </x-button>
                </div>
            </form>
        </div>
        <div class="modal-backdrop" wire:click="$set('showTokenCreateModal', false)"></div>
    </div>
    @endif

    <!-- New Token Display Modal -->
    @if ($newlyCreatedToken)
    <div class="modal modal-open">
        <div class="modal-box max-w-2xl">
            <h3 class="font-bold text-lg mb-4">Token Created Successfully!</h3>

            <div class="alert alert-warning mb-4">
                <x-icon name="fas.triangle-exclamation" class="w-5 h-5" />
                <div class="text-sm">
                    <p class="font-semibold">Copy this token now!</p>
                    <p>For security reasons, you won't be able to see it again.</p>
                </div>
            </div>

            <div class="form-control mb-4">
                <label class="label">
                    <span class="label-text font-medium">Your API Token</span>
                </label>
                <div class="flex gap-2">
                    <input
                        type="text"
                        value="{{ $newlyCreatedToken }}"
                        readonly
                        class="input input-bordered flex-1 font-mono text-sm"
                        id="new-token-value"
                        onclick="this.select()" />
                    <x-button
                        onclick="navigator.clipboard.writeText(document.getElementById('new-token-value').value); window.dispatchEvent(new CustomEvent('toast-success', { detail: { message: 'Token copied to clipboard!' } }))"
                        class="btn-primary">
                        <x-icon name="o-clipboard" class="w-4 h-4" />
                        Copy
                    </x-button>
                </div>
            </div>

            <div class="modal-action">
                <x-button wire:click="closeTokenModal" class="btn-primary">
                    I've Copied My Token
                </x-button>
            </div>
        </div>
    </div>
    @endif

    <!-- Toast notifications -->
    <x-toast position="toast-top toast-end" />
</div>