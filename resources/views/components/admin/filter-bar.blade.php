@props([
    'searchPlaceholder' => 'Search...',
    'filters' => [],
    'active' => false,
    'clear' => 'clearFilters',
])

<div class="card bg-base-200 shadow mb-4 lg:mb-6">
    <div class="card-body">
        <div class="flex flex-col lg:flex-row lg:items-end gap-4">
            <div class="flex-1">
                <x-input
                    label="Search"
                    :placeholder="$searchPlaceholder"
                    icon="fas.magnifying-glass"
                    wire:model.live.debounce.300ms="search"
                    clearable />
            </div>
            @foreach ($filters as $filter)
                <div class="lg:min-w-44" wire:key="filter-{{ $filter['model'] }}">
                    <x-select
                        :label="$filter['label']"
                        :placeholder="$filter['placeholder'] ?? 'All'"
                        placeholder-value=""
                        :options="collect($filter['options'])->map(fn ($name, $id) => ['id' => (string) $id, 'name' => (string) $name])->values()->all()"
                        wire:model.live="{{ $filter['model'] }}" />
                </div>
            @endforeach
            @if ($active)
                <button type="button" class="btn btn-outline" wire:click="{{ $clear }}">
                    <x-icon name="fas.xmark" class="w-4 h-4" />
                    Clear
                </button>
            @endif
        </div>
    </div>
</div>
