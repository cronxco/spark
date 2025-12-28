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
