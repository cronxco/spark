<!-- Filters -->
<div class="card bg-base-200 shadow mb-6">
    <div class="card-body">
        <div class="flex flex-col sm:flex-row gap-4">
            <div class="form-control flex-1">
                <input
                    type="text"
                    wire:model.live.debounce.300ms="savedSearch"
                    placeholder="Search saved pages..."
                    class="input input-bordered w-full" />
            </div>
            <div class="form-control sm:w-48">
                <select wire:model.live="savedDomainFilter" class="select select-bordered">
                    <option value="">All Domains</option>
                    @foreach ($this->getSavedDomainOptions() as $domain)
                    <option value="{{ $domain }}">{{ $domain }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-control sm:w-48">
                <select wire:model.live="savedStatusFilter" class="select select-bordered">
                    <option value="all">All Status</option>
                    <option value="fetched">Fetched</option>
                    <option value="error">Error</option>
                    <option value="pending">Pending</option>
                </select>
            </div>
            <div class="form-control sm:w-48">
                <select wire:model.live="savedSortBy" class="select select-bordered">
                    <option value="last_changed">Last Changed</option>
                    <option value="created_at">Created At</option>
                    <option value="title">Title</option>
                    <option value="domain">Domain</option>
                </select>
            </div>
            @if (!empty($savedSearch) || !empty($savedDomainFilter) || $savedStatusFilter !== 'all')
            <x-button wire:click="clearSavedFilters" class="btn-outline">
                <x-icon name="fas.xmark" class="w-4 h-4" />
            </x-button>
            @endif
        </div>
    </div>
</div>

<!-- Saved Pages List -->
@if ($this->savedPages->count() > 0)
<div class="space-y-4 mb-6">
    @foreach ($this->savedPages as $page)
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <div class="flex flex-col sm:flex-row sm:items-start gap-4">
                <!-- Favicon & URL -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-2">
                        <img src="https://www.google.com/s2/favicons?domain={{ $page['domain'] }}"
                            alt="Favicon"
                            class="w-4 h-4" />
                        <a href="{{ $page['url'] }}"
                            target="_blank"
                            class="text-sm font-mono text-primary hover:underline truncate"
                            title="{{ $page['url'] }}">
                            {{ $page['url'] }}
                        </a>
                    </div>
                    <a href="{{ route('events.show', $page['object_id']) }}" wire:navigate class="font-semibold mb-1 hover:text-primary inline-block">
                        {{ $page['title'] === $page['url'] ? 'Not yet fetched' : $page['title'] }}
                    </a>
                    <div class="flex flex-wrap gap-2 items-center text-sm text-base-content/70">
                        <span class="flex items-center gap-1">
                            <x-icon name="fas.globe" class="w-3 h-3" />
                            {{ $page['domain'] }}
                        </span>
                        @if ($page['last_checked_at'])
                        <span class="flex items-center gap-1">
                            <x-icon name="fas.clock" class="w-3 h-3" />
                            Fetched {{ \Carbon\Carbon::parse($page['last_checked_at'])->diffForHumans() }}
                        </span>
                        @endif
                        @if ($page['created_at'])
                        <span class="flex items-center gap-1">
                            <x-icon name="fas.calendar-plus" class="w-3 h-3" />
                            Saved {{ \Carbon\Carbon::parse($page['created_at'])->diffForHumans() }}
                        </span>
                        @endif
                    </div>
                </div>

                <!-- Status Badge & Actions -->
                <div class="flex flex-col gap-2 items-end">
                    @if ($page['last_error'])
                    <x-badge value="Error" class="badge-error" />
                    @elseif ($page['last_checked_at'])
                    <x-badge value="Fetched" class="badge-success" />
                    @else
                    <x-badge value="Pending" class="badge-warning" />
                    @endif

                    <!-- Actions Dropdown -->
                    <x-dropdown position="dropdown-end">
                        <x-slot:trigger>
                            <x-button icon="fas.ellipsis-vertical" class="btn-ghost btn-sm" />
                        </x-slot:trigger>
                        <x-menu-item
                            title="Re-fetch"
                            icon="fas.rotate"
                            wire:click="reFetchSaved('{{ $page['id'] }}')" />
                        <x-menu-separator />
                        <x-menu-item
                            title="Delete"
                            icon="fas.trash"
                            wire:click="deleteSaved('{{ $page['id'] }}')"
                            wire:confirm="Delete this saved page?" />
                    </x-dropdown>
                </div>
            </div>

            <!-- Error message if present -->
            @if ($page['last_error'])
            <div class="alert alert-error mt-2">
                <x-icon name="fas.triangle-exclamation" class="w-5 h-5" />
                <span class="text-sm">{{ $page['last_error'] }}</span>
            </div>
            @endif
        </div>
    </div>
    @endforeach
</div>

<!-- Pagination -->
<div class="mb-6">
    {{ $this->savedPages->links() }}
</div>

@else
<!-- Empty State -->
<div class="card bg-base-200 shadow">
    <div class="card-body">
        <div class="flex flex-col items-center text-center py-8">
            <div class="w-16 h-16 rounded-full bg-base-300 flex items-center justify-center mb-4">
                <x-icon name="fas.wand-magic-sparkles" class="w-8 h-8 text-base-content/50" />
            </div>
            <h3 class="text-xl font-semibold mb-2">No saved pages</h3>
            <p class="text-base-content/70 max-w-md mb-4">
                Save one-time bookmarks using the Add button or Spotlight (Cmd+K). These pages are fetched once for instant access.
            </p>
            <x-button
                label="Save a Page"
                icon="fas.plus"
                class="btn-primary btn-sm"
                @click="$dispatch('bookmark-url', { url: '', mode: 'once' })"
            />
        </div>
    </div>
</div>
@endif
