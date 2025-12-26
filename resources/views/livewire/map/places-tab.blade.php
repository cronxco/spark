{{-- Places Filters --}}
<div class="card bg-base-200">
    <div class="card-body p-4">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            {{-- Search --}}
            <div class="lg:col-span-2">
                <label class="label">
                    <span class="label-text">Search</span>
                </label>
                <input
                    type="text"
                    placeholder="Search places..."
                    class="input input-sm input-bordered w-full"
                    wire:model.live.debounce.300ms="placesSearch" />
            </div>

            {{-- Category Filter --}}
            <div>
                <label class="label">
                    <span class="label-text">Category</span>
                </label>
                <select
                    class="select select-sm select-bordered w-full"
                    wire:model.live="placesCategoryFilter">
                    <option value="">All Categories</option>
                    @foreach ($this->placesCategories as $category)
                        <option value="{{ $category['value'] }}">
                            {{ $category['label'] }} ({{ $category['count'] }})
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Sort By --}}
            <div>
                <label class="label">
                    <span class="label-text">Sort By</span>
                </label>
                <select
                    class="select select-sm select-bordered w-full"
                    wire:model.live="placesSortBy">
                    <option value="visits">Most Visited</option>
                    <option value="recent">Recently Visited</option>
                    <option value="name">Name (A-Z)</option>
                </select>
            </div>
        </div>

        {{-- Filter Actions --}}
        <div class="flex items-center justify-between pt-2 flex-wrap gap-2">
            <div class="flex items-center gap-4">
                {{-- Stats --}}
                <div class="stats stats-horizontal shadow-sm bg-base-100">
                    <div class="stat px-4 py-2">
                        <div class="stat-title text-xs">Places</div>
                        <div class="stat-value text-lg">{{ $this->placesStats['total_places'] }}</div>
                    </div>
                    <div class="stat px-4 py-2">
                        <div class="stat-title text-xs">Favorites</div>
                        <div class="stat-value text-lg">{{ $this->placesStats['favorites'] }}</div>
                    </div>
                    <div class="stat px-4 py-2">
                        <div class="stat-title text-xs">Total Visits</div>
                        <div class="stat-value text-lg">{{ number_format($this->placesStats['total_visits']) }}</div>
                    </div>
                </div>

                {{-- Favorites Toggle --}}
                <label class="label cursor-pointer gap-2">
                    <span class="label-text">Favorites only</span>
                    <input
                        type="checkbox"
                        class="toggle toggle-sm toggle-primary"
                        wire:model.live="placesFavoritesOnly" />
                </label>
            </div>

            <button
                wire:click="clearPlacesFilters"
                class="btn btn-sm btn-ghost">
                <x-icon name="o-x-mark" class="w-4 h-4" />
                Clear Filters
            </button>
        </div>
    </div>
</div>

{{-- Places Grid --}}
@if ($this->places->count() > 0)
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 mt-4">
    @foreach ($this->places as $place)
        <div class="card bg-base-200 shadow hover:shadow-lg transition-all cursor-pointer"
            wire:key="place-{{ $place->id }}">
            {{-- Card Body --}}
            <div class="card-body p-4">
                {{-- Header with favorite button --}}
                <div class="flex items-start justify-between gap-2 mb-2">
                    <a href="{{ route('places.show', $place) }}" class="flex-1">
                        <h3 class="font-semibold text-base line-clamp-2 hover:underline">
                            {{ $place->title }}
                        </h3>
                    </a>
                    <button
                        wire:click="togglePlaceFavorite('{{ $place->id }}')"
                        class="btn btn-ghost btn-xs btn-circle">
                        @if ($place->is_favorite)
                            <x-icon name="fas.star" class="w-4 h-4 text-warning" />
                        @else
                            <x-icon name="o-star" class="w-4 h-4" />
                        @endif
                    </button>
                </div>

                {{-- Category badge --}}
                @if ($place->category)
                <div class="flex items-center gap-2 mb-2">
                    <div class="badge badge-sm badge-outline">
                        {{ ucfirst($place->category) }}
                    </div>
                </div>
                @endif

                {{-- Address --}}
                <div class="text-sm text-base-content/70 line-clamp-2 mb-3">
                    <x-icon name="o-map-pin" class="w-3 h-3 inline mr-1" />
                    {{ $place->location_address ?: 'No address' }}
                </div>

                {{-- Stats --}}
                <div class="flex items-center justify-between text-xs pt-2 border-t border-base-300">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-1">
                            <x-icon name="o-arrow-path" class="w-3 h-3" />
                            <span>{{ $place->visit_count }} visits</span>
                        </div>
                        @if ($place->eventsHere()->count() > 0)
                        <div class="flex items-center gap-1">
                            <x-icon name="o-calendar" class="w-3 h-3" />
                            <span>{{ $place->eventsHere()->count() }} events</span>
                        </div>
                        @endif
                    </div>

                    {{-- Tags --}}
                    @if ($place->tags->count() > 0)
                    <div class="flex items-center gap-1">
                        <x-icon name="o-tag" class="w-3 h-3" />
                        <span>{{ $place->tags->count() }}</span>
                    </div>
                    @endif
                </div>

                {{-- Last visit --}}
                @if ($place->last_visit_at)
                <div class="text-xs text-base-content/50 mt-1">
                    Last visit: {{ \Carbon\Carbon::parse($place->last_visit_at)->diffForHumans() }}
                </div>
                @endif
            </div>

            {{-- Quick actions footer --}}
            <div class="card-actions justify-end p-3 pt-0">
                <a
                    href="{{ route('places.show', $place) }}"
                    class="btn btn-sm btn-ghost">
                    <x-icon name="o-arrow-right" class="w-4 h-4" />
                    View
                </a>
            </div>
        </div>
    @endforeach
</div>

{{-- Pagination --}}
<div class="mt-4">
    {{ $this->places->links() }}
</div>
@else
{{-- Empty State --}}
<div class="card bg-base-200 mt-4">
    <div class="card-body text-center p-12">
        <x-icon name="o-map-pin" class="w-16 h-16 mx-auto mb-4 text-base-content/30" />
        <h3 class="text-lg font-semibold mb-2">No places found</h3>
        <p class="text-sm text-base-content/70 mb-4">
            @if ($placesSearch || $placesCategoryFilter || $placesFavoritesOnly)
                No places match your current filters. Try adjusting your search.
            @else
                Places will be automatically created as you use integrations with location data.
            @endif
        </p>
        @if ($placesSearch || $placesCategoryFilter || $placesFavoritesOnly)
        <button
            wire:click="clearPlacesFilters"
            class="btn btn-sm btn-primary">
            Clear Filters
        </button>
        @endif
    </div>
</div>
@endif
