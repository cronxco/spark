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

