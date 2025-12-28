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
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

new class extends Component
{
    use Toast, WithPagination;

    public ?Integration $integration = null;

    public ?IntegrationGroup $group = null;

    public string $activeTab = 'all';

    public int $perPage = 15;

    // URL subscription
    #[Validate('required|url|max:2048')]
    public string $newUrl = '';

    public array $urls = [];

    public string $urlSearch = '';

    public string $domainFilter = '';

    public string $statusFilter = 'all';

    public string $urlSortBy = 'last_changed';

    // Saved pages (NEW)
    public string $savedSearch = '';

    public string $savedDomainFilter = '';

    public string $savedStatusFilter = 'all';

    public string $savedSortBy = 'last_changed';

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

        if (! $user) {
            abort(403);
        }

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

        // Check for tab query parameter - UPDATED to include all 8 tabs
        $tab = request()->query('tab');
        $validTabs = ['all', 'saved', 'urls', 'cookies', 'discovery', 'stats', 'playwright', 'api'];
        if ($tab && in_array($tab, $validTabs)) {
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

    // ========== Computed Properties for Bookmarks and Integrations ==========

    #[Computed]
    public function bookmarks()
    {
        return Event::query()
            ->whereIn('action', ['bookmarked', 'fetched', 'bookmarked_post', 'liked_post', 'reposted'])
            ->whereHas('integration', fn ($q) => $q->where('user_id', Auth::id()))
            ->with(['target', 'blocks', 'integration', 'actor'])
            ->orderByDesc('time')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function hasReddit(): bool
    {
        return Integration::where('user_id', Auth::id())
            ->where('service', 'reddit')
            ->exists();
    }

    #[Computed]
    public function hasKarakeep(): bool
    {
        return Integration::where('user_id', Auth::id())
            ->where('service', 'karakeep')
            ->exists();
    }

    #[Computed]
    public function hasBlueSky(): bool
    {
        return Integration::where('user_id', Auth::id())
            ->where('service', 'bluesky')
            ->exists();
    }

    // ========== Helper Methods for Bookmark Display ==========

    public function getBookmarkSummary(Event $event): ?string
    {
        // Try to get tweet-sized summary from Fetch (uses 'content' field)
        $tweetSummary = $event->blocks->firstWhere('block_type', 'fetch_summary_tweet');
        if ($tweetSummary && ! empty($tweetSummary->metadata['content'])) {
            return $tweetSummary->metadata['content'];
        }

        // Try to get Karakeep AI summary (uses 'summary' field)
        $karakeepSummary = $event->blocks->firstWhere('block_type', 'bookmark_summary');
        if ($karakeepSummary && ! empty($karakeepSummary->metadata['summary'])) {
            return $karakeepSummary->metadata['summary'];
        }

        // Try to get BlueSky post content
        $postContent = $event->blocks->firstWhere('block_type', 'post_content');
        if ($postContent && ! empty($postContent->metadata['content'])) {
            return Str::limit($postContent->metadata['content'], 280);
        }

        // Last resort: event metadata description
        if (! empty($event->event_metadata['description'])) {
            return Str::limit($event->event_metadata['description'], 280);
        }

        return null;
    }

    public function getBookmarkUrl(Event $event): ?string
    {
        // Check target metadata for URL
        if ($event->target && ! empty($event->target->metadata['url'])) {
            return $event->target->metadata['url'];
        }

        // Check event metadata
        if (! empty($event->event_metadata['url'])) {
            return $event->event_metadata['url'];
        }

        // Check for link in blocks (BlueSky)
        $linkPreview = $event->blocks->firstWhere('type', 'link_preview');
        if ($linkPreview && ! empty($linkPreview->metadata['url'])) {
            return $linkPreview->metadata['url'];
        }

        return null;
    }

    public function getBookmarkTitle(Event $event): string
    {
        // Try target title first
        if ($event->target && ! empty($event->target->title)) {
            return $event->target->title;
        }

        // Try event metadata
        if (! empty($event->event_metadata['title'])) {
            return $event->event_metadata['title'];
        }

        // Fallback to action type
        return ucfirst(str_replace('_', ' ', $event->action));
    }

    public function getBookmarkImage(Event $event): ?string
    {
        // Check Fetch metadata block
        $metadataBlock = $event->blocks->firstWhere('type', 'fetch_metadata');
        if ($metadataBlock && ! empty($metadataBlock->metadata['image'])) {
            return $metadataBlock->metadata['image'];
        }

        // Check Karakeep bookmark metadata
        $karakeepMetadata = $event->blocks->firstWhere('type', 'bookmark_metadata');
        if ($karakeepMetadata && ! empty($karakeepMetadata->metadata['image'])) {
            return $karakeepMetadata->metadata['image'];
        }

        // Check BlueSky post media
        $postMedia = $event->blocks->firstWhere('type', 'post_media');
        if ($postMedia && ! empty($postMedia->metadata['images'][0])) {
            return $postMedia->metadata['images'][0];
        }

        // Check target metadata
        if ($event->target && ! empty($event->target->metadata['image'])) {
            return $event->target->metadata['image'];
        }

        return null;
    }

    // ========== NEW: Saved Pages Methods ==========

    #[Computed]
    public function savedPages()
    {
        $query = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->whereRaw("metadata->>'fetch_mode' = 'once'");

        // Apply search
        if (! empty($this->savedSearch)) {
            $query->where(function ($q) {
                $q->where('url', 'ilike', '%'.$this->savedSearch.'%')
                    ->orWhere('title', 'ilike', '%'.$this->savedSearch.'%');
            });
        }

        // Apply domain filter
        if (! empty($this->savedDomainFilter)) {
            $query->whereRaw("metadata->>'domain' = ?", [$this->savedDomainFilter]);
        }

        // Apply status filter
        if ($this->savedStatusFilter !== 'all') {
            switch ($this->savedStatusFilter) {
                case 'success':
                    $query->whereNull('metadata->last_error')
                        ->whereNotNull('metadata->last_checked_at');
                    break;
                case 'errors':
                    $query->whereNotNull('metadata->last_error');
                    break;
                case 'pending':
                    $query->whereNull('metadata->last_checked_at');
                    break;
            }
        }

        // Apply sorting
        switch ($this->savedSortBy) {
            case 'last_changed':
                $query->orderByRaw("COALESCE(metadata->>'last_checked_at', metadata->>'last_changed_at', time::text) DESC");
                break;
            case 'created_at':
                $query->orderBy('time', 'desc');
                break;
            case 'title':
                $query->orderBy('title');
                break;
            case 'domain':
                $query->orderByRaw("metadata->>'domain'");
                break;
        }

        return $query->paginate($this->perPage)->through(function ($obj) {
            $metadata = $obj->metadata ?? [];

            return [
                'id' => $obj->id,
                'object_id' => $obj->id,
                'url' => $obj->url,
                'title' => $obj->title,
                'domain' => $metadata['domain'] ?? parse_url($obj->url, PHP_URL_HOST),
                'last_checked_at' => $metadata['last_checked_at'] ?? null,
                'last_error' => $metadata['last_error'] ?? null,
                'created_at' => $obj->time,
            ];
        });
    }

    #[Computed]
    public function getSavedDomainOptions(): array
    {
        $domains = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->whereRaw("metadata->>'fetch_mode' = 'once'")
            ->whereNotNull('metadata->domain')
            ->selectRaw("DISTINCT metadata->>'domain' as domain")
            ->pluck('domain')
            ->sort()
            ->values()
            ->toArray();

        return $domains;
    }

    public function clearSavedFilters(): void
    {
        $this->savedSearch = '';
        $this->savedDomainFilter = '';
        $this->savedStatusFilter = 'all';
        $this->savedSortBy = 'last_changed';
    }

    public function reFetchSaved(string $id): void
    {
        $webpage = EventObject::findOrFail($id);

        // Verify ownership
        if ($webpage->user_id !== Auth::id()) {
            abort(403);
        }

        // Dispatch fetch job
        FetchSingleUrl::dispatch($this->integration, $webpage->id, $webpage->url, forceRefresh: true);

        $this->success('Re-fetch queued successfully');
        unset($this->savedPages);
    }

    public function deleteSaved(string $id): void
    {
        $webpage = EventObject::findOrFail($id);

        // Verify ownership
        if ($webpage->user_id !== Auth::id()) {
            abort(403);
        }

        // Force delete (not soft delete)
        $webpage->forceDelete();

        // Refresh data
        unset($this->savedPages);

        $this->success('Saved page deleted');
    }

    // ========== Fetch Integration Methods ==========
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
            return '[Your '.str_replace('Bookmark API: ', '', $this->latestTokenName()).' token]';
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
                $q->where('url', 'like', '%'.$this->urlSearch.'%')
                    ->orWhere('title', 'like', '%'.$this->urlSearch.'%');
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
                $q->where('url', 'ilike', '%'.$this->discoverySearch.'%')
                    ->orWhere('title', 'ilike', '%'.$this->discoverySearch.'%');
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
                $contextParts[] = 'from "'.Str::limit($sourceObject->title, 30).'"';
            }
        } elseif ($sourceEventId) {
            $sourceEvent = Event::find($sourceEventId);
            if ($sourceEvent && $sourceEvent->target) {
                $contextParts[] = 'from "'.Str::limit($sourceEvent->target->title, 30).'"';
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
                $this->error('Cookie parsing failed: '.($parsed['error'] ?? 'Unknown error'));

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
            $url = 'https://'.$domain;
        }

        try {
            $client = new FetchHttpClient;
            $result = $client->testUrl($url, $this->group);

            if ($result['success']) {
                $this->success('Test successful! Status: '.$result['status_code']);
            } else {
                $this->error('Test failed: '.$result['error']);
            }
        } catch (\Exception $e) {
            Log::error('Cookie test failed', [
                'domain' => $domain,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            $this->error('Test failed: '.$e->getMessage());
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
            $this->error('Failed to save discovery settings: '.$e->getMessage());
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
            $this->error('Failed to add domain: '.$e->getMessage());
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
            $this->error('Failed to remove domain: '.$e->getMessage());
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
            $this->error('Failed to clear discovered URLs: '.$e->getMessage());
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
            $this->error('Failed to start discovery: '.$e->getMessage());
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
            $this->error('Failed to remove URL: '.$e->getMessage());
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
            $this->error('Failed to toggle auto-fetch mode: '.$e->getMessage());
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
            $this->error('Failed to toggle auto-fetch: '.$e->getMessage());
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
            $this->error('Failed to ignore URL: '.$e->getMessage());
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
            $this->error('Failed to restore URL: '.$e->getMessage());
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
                $this->error('Failed to extract cookies: '.($result['error'] ?? 'Unknown error'));

                return;
            }

            if (empty($result['cookies'])) {
                $this->warning('No cookies found for domain: '.$this->cookieDomain);

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

            $this->success('Successfully extracted '.count($simpleCookies).' cookies from browser session!');
            $this->cookieDomain = '';
            $this->loadCookies();

            Log::info('Fetch: Cookies extracted from Playwright browser', [
                'domain' => $this->cookieDomain,
                'cookie_count' => count($simpleCookies),
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to extract cookies: '.$e->getMessage());
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
            $this->error('Failed to toggle auto-refresh: '.$e->getMessage());
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
            $tokenName = 'Bookmark API: '.$this->newTokenName;
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
            $this->error('Failed to create API token: '.$e->getMessage());
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
            $this->error('Failed to revoke API token: '.$e->getMessage());
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
    <x-header title="Bookmarks" subtitle="All your saved content from across your integrations" separator>
        <x-slot:actions>
            <x-button
                label="Add"
                icon="fas.plus"
                class="btn-primary btn-sm"
                @click="$dispatch('bookmark-url', { url: '', mode: 'once' })"
            />
            @if ($integration)
            <a href="{{ route('integrations.configure', $integration->id) }}" class="btn btn-outline btn-sm">
                <x-icon name="fas.gear" class="w-4 h-4" />
                Settings
            </a>
            @endif
        </x-slot:actions>
    </x-header>

    <!-- Tabs -->
    <x-tabs wire:model="activeTab">
        <x-tab name="all" label="Bookmarks" icon="fas.bookmark">
            @include('livewire.bookmarks.tabs.all')
        </x-tab>

        <x-tab name="saved" label="Saved" icon="fas.box-archive">
            @include('livewire.bookmarks.tabs.saved')
        </x-tab>

        <x-tab name="urls" label="Subscribed" icon="fas.cloud-arrow-down">
            @include('livewire.bookmarks.tabs.urls')
        </x-tab>

        <x-tab name="cookies" label="Cookies" icon="fas.lock">
            @include('livewire.bookmarks.tabs.cookies')
        </x-tab>

        <x-tab name="discovery" label="Discovery" icon="fas.magnifying-glass">
            @include('livewire.bookmarks.tabs.discovery')
        </x-tab>

        <x-tab name="stats" label="Stats" icon="fas.chart-simple">
            @include('livewire.bookmarks.tabs.stats')
        </x-tab>

        <x-tab name="playwright" label="Playwright" icon="fas.desktop">
            @include('livewire.bookmarks.tabs.playwright')
        </x-tab>

        <x-tab name="api" label="API & Share" icon="fas.key">
            @include('livewire.bookmarks.tabs.api')
        </x-tab>
    </x-tabs>
</div>
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

