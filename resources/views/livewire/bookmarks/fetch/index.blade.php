<?php

use App\Integrations\Fetch\CookieParser;
use App\Integrations\Fetch\FetchHttpClient;
use App\Integrations\PluginRegistry;
use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component
{
    use Toast;

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
    public array $discoveredUrls = [];

    // Stats
    public array $stats = [];

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
                'name' => 'URL Fetcher',
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

        $this->loadData();
    }

    public function loadData(): void
    {
        $this->loadUrls();
        $this->loadCookies();
        $this->loadDiscoverySettings();
        $this->loadStats();
    }

    public function loadUrls(): void
    {
        $query = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage');

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

        // Load recently discovered URLs (last 50)
        $this->discoveredUrls = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->whereRaw("metadata->>'subscription_source' = 'discovered'")
            ->orderByRaw("metadata->>'discovered_at' DESC")
            ->limit(50)
            ->get()
            ->map(function ($obj) {
                $metadata = $obj->metadata ?? [];

                return [
                    'id' => $obj->id,
                    'url' => $obj->url,
                    'discovered_at' => $metadata['discovered_at'] ?? null,
                    'discovered_from_integration_id' => $metadata['discovered_from_integration_id'] ?? null,
                    'enabled' => $metadata['enabled'] ?? true,
                ];
            })
            ->toArray();
    }

    public function loadStats(): void
    {
        $totalUrls = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->count();

        $activeUrls = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->whereRaw("(metadata->>'enabled')::boolean = true")
            ->count();

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
        ];
    }

    public function subscribeToUrl(): void
    {
        $this->validate([
            'newUrl' => 'required|url|max:2048',
        ]);

        // Check if URL already exists
        $existing = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->where('url', $this->newUrl)
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
                    'subscription_source' => 'manual',
                    'subscribed_at' => now(),
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

    public function fetchNow(string $id): void
    {
        $eventObject = EventObject::find($id);

        if (! $eventObject || $eventObject->user_id !== Auth::id()) {
            $this->error('URL not found.');

            return;
        }

        try {
            FetchSingleUrl::dispatch($this->integration, $eventObject->id, $eventObject->url);
            $this->success('Fetch job queued. Check back shortly for results.');
        } catch (\Exception $e) {
            Log::error('Failed to dispatch fetch job', [
                'object_id' => $id,
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
                <x-icon name="o-cog-6-tooth" class="w-4 h-4" />
                Settings
            </a>
        </x-slot:actions>
    </x-header>

    <!-- Tabs -->
    <x-tabs wire:model="activeTab" selected="urls">
        <!-- Subscribed URLs Tab -->
        <x-tab name="urls" label="Subscribed URLs" icon="o-bookmark">
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
                            <x-icon name="o-plus" class="w-4 h-4" />
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
                            <x-icon name="o-x-mark" class="w-4 h-4" />
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
                                        <x-icon name="o-globe-alt" class="w-3 h-3" />
                                        {{ $url['domain'] }}
                                    </span>
                                    @if ($url['last_checked_at'])
                                    <span class="flex items-center gap-1">
                                        <x-icon name="o-clock" class="w-3 h-3" />
                                        Checked {{ \Carbon\Carbon::parse($url['last_checked_at'])->diffForHumans() }}
                                    </span>
                                    @endif
                                    @if ($url['last_changed_at'])
                                    <span class="flex items-center gap-1">
                                        <x-icon name="o-pencil" class="w-3 h-3" />
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

                                <!-- Actions Dropdown -->
                                <x-dropdown position="dropdown-end">
                                    <x-slot:trigger>
                                        <x-button icon="o-ellipsis-vertical" class="btn-ghost btn-sm" />
                                    </x-slot:trigger>
                                    <x-menu-item
                                        title="Fetch Now"
                                        icon="o-arrow-path"
                                        wire:click="fetchNow('{{ $url['id'] }}')" />
                                    <x-menu-item
                                        title="{{ $url['enabled'] ? 'Disable' : 'Enable' }}"
                                        icon="o-power"
                                        wire:click="toggleUrl('{{ $url['id'] }}')" />
                                    <x-menu-item
                                        title="Delete"
                                        icon="o-trash"
                                        wire:click="deleteUrl('{{ $url['id'] }}')"
                                        class="text-error" />
                                </x-dropdown>
                            </div>
                        </div>

                        <!-- Error Message -->
                        @if ($url['last_error'])
                        <div class="alert alert-error mt-4">
                            <x-icon name="o-exclamation-triangle" class="w-5 h-5" />
                            <span class="text-sm">
                                {{ $url['last_error']['message'] ?? 'Unknown error' }}
                                @if (isset($url['last_error']['timestamp']))
                                <span class="text-xs opacity-70">
                                    ({{ \Carbon\Carbon::parse($url['last_error']['timestamp'])->diffForHumans() }})
                                </span>
                                @endif
                            </span>
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
                        <x-icon name="o-bookmark" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
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
        <x-tab name="cookies" label="Cookies" icon="o-lock-closed">
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
                        <x-button type="submit" class="btn-primary">
                            <x-icon name="o-plus" class="w-4 h-4" />
                            Add Cookies
                        </x-button>
                    </form>

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
                        <div class="flex items-start justify-between">
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
                                    <x-badge value="Expires {{ $expiryDate->format('M j') }}" class="{{ $badgeClass }}" />
                                    @else
                                    <x-badge value="No expiry set" class="badge-neutral" />
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
                                    <x-icon name="o-beaker" class="w-4 h-4" />
                                    Test
                                </x-button>
                                <x-button
                                    wire:click="deleteCookies('{{ $domain['domain'] }}')"
                                    class="btn-error btn-outline btn-sm">
                                    <x-icon name="o-trash" class="w-4 h-4" />
                                    Delete
                                </x-button>
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="card bg-base-200 shadow">
                <div class="card-body">
                    <div class="text-center py-12">
                        <x-icon name="o-lock-closed" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                        <h3 class="text-lg font-medium text-base-content mb-2">No cookies configured</h3>
                        <p class="text-base-content/70">
                            Add cookies for domains that require authentication.
                        </p>
                    </div>
                </div>
            </div>
            @endif
        </x-tab>

        <!-- Discovery Settings Tab -->
        <x-tab name="discovery" label="Discovery" icon="o-magnifying-glass">
            <!-- Monitor Integrations Section -->
            <div class="card bg-base-200 shadow mb-6">
                <div class="card-body">
                    <h3 class="text-lg font-semibold mb-4">Monitor Integrations</h3>
                    <p class="text-sm text-base-content/70 mb-4">
                        Select integrations to automatically discover URLs from. Fetch will scan EventObjects and Events for URLs and subscribe to them automatically.
                    </p>

                    @if (empty($availableIntegrations))
                        <div class="alert alert-info">
                            <x-icon name="o-information-circle" class="w-6 h-6" />
                            <span>No other integrations found. Add integrations like Karakeep, Reddit, or Outline to enable URL discovery.</span>
                        </div>
                    @else
                        <div class="form-control mb-4">
                            <label class="label">
                                <span class="label-text">Select Integrations to Monitor</span>
                            </label>
                            <div class="space-y-2">
                                @foreach ($availableIntegrations as $integration)
                                    <label class="flex items-center gap-3 p-3 rounded-lg border border-base-300 hover:bg-base-300 cursor-pointer transition">
                                        <input
                                            type="checkbox"
                                            class="checkbox checkbox-primary"
                                            value="{{ $integration['id'] }}"
                                            wire:model="monitoredIntegrations"
                                        />
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
                                class="btn btn-primary"
                                wire:click="updateMonitoredIntegrations"
                            >
                                <x-icon name="o-check" class="w-4 h-4" />
                                Save Settings
                            </button>

                            <button
                                type="button"
                                class="btn btn-secondary"
                                wire:click="triggerDiscovery"
                                @if (empty($monitoredIntegrations)) disabled @endif
                            >
                                <x-icon name="o-magnifying-glass" class="w-4 h-4" />
                                Scan Now
                            </button>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Recently Discovered URLs -->
            @if (!empty($discoveredUrls))
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">Recently Discovered URLs (Last 50)</h3>

                        <div class="overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>URL</th>
                                        <th>Source</th>
                                        <th>Discovered</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($discoveredUrls as $url)
                                        <tr>
                                            <td>
                                                <div class="flex items-center gap-2">
                                                    <img
                                                        src="https://www.google.com/s2/favicons?domain={{ parse_url($url['url'], PHP_URL_HOST) }}&sz=32"
                                                        alt="favicon"
                                                        class="w-4 h-4"
                                                    />
                                                    <a href="{{ $url['url'] }}" target="_blank" class="link link-hover text-sm truncate max-w-xs">
                                                        {{ Str::limit($url['url'], 50) }}
                                                    </a>
                                                </div>
                                            </td>
                                            <td>
                                                @if (isset($url['discovered_from_integration_id']))
                                                    @php
                                                        $sourceIntegration = collect($availableIntegrations)->firstWhere('id', $url['discovered_from_integration_id']);
                                                    @endphp
                                                    @if ($sourceIntegration)
                                                        <span class="badge badge-sm badge-neutral">{{ $sourceIntegration['service_name'] }}</span>
                                                    @else
                                                        <span class="badge badge-sm badge-ghost">Unknown</span>
                                                    @endif
                                                @else
                                                    <span class="badge badge-sm badge-ghost">-</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="text-sm text-base-content/70" title="{{ $url['discovered_at'] }}">
                                                    {{ $url['discovered_at'] ? \Carbon\Carbon::parse($url['discovered_at'])->diffForHumans() : '-' }}
                                                </span>
                                            </td>
                                            <td>
                                                @if ($url['enabled'])
                                                    <span class="badge badge-sm badge-success">Active</span>
                                                @else
                                                    <span class="badge badge-sm badge-neutral">Disabled</span>
                                                @endif
                                            </td>
                                            <td>
                                                <button
                                                    type="button"
                                                    class="btn btn-ghost btn-xs"
                                                    wire:click="removeDiscoveredUrl('{{ $url['id'] }}')"
                                                    wire:confirm="Are you sure you want to remove this URL?"
                                                >
                                                    <x-icon name="o-trash" class="w-4 h-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </x-tab>

        <!-- Stats Tab -->
        <x-tab name="stats" label="Stats" icon="o-chart-bar">
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
    </x-tabs>

    <!-- Toast notifications -->
    <x-toast position="toast-top toast-end" />
</div>
