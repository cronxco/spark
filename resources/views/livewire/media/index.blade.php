<div>
    {{-- Header with Bulk Actions --}}
    <x-header title="Media Gallery" subtitle="Browse and manage your media collection" separator progress-indicator>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @if (count($selectedItems) > 0)
                    <x-button
                        label="Delete Selected ({{ count($selectedItems) }})"
                        icon="o-trash"
                        class="btn-error btn-sm"
                        wire:click="bulkDelete"
                        onclick="return confirm('Delete {{ count($selectedItems) }} media item(s)? This cannot be undone.')"
                    />
                @endif
            </div>
        </x-slot:actions>
    </x-header>

    <div class="space-y-4 lg:space-y-6">
        {{-- Desktop Filters (Hidden on Mobile) --}}
        <div class="hidden lg:block card bg-base-200 shadow">
            <div class="card-body">
                <div class="flex flex-row gap-4">
                    {{-- Search --}}
                    <div class="form-control flex-1">
                        <label class="label">
                            <span class="label-text">Search</span>
                        </label>
                        <input
                            type="text"
                            class="input input-bordered w-full"
                            placeholder="Search media by name or filename..."
                            wire:model.live.debounce.300ms="search"
                        />
                    </div>

                    {{-- Model Type Filter --}}
                    <div class="form-control w-48">
                        <label class="label">
                            <span class="label-text">Model Type</span>
                        </label>
                        <select class="select select-bordered" wire:model.live="modelFilter" multiple size="1">
                            <option value="">All Types</option>
                            @foreach ($modelTypes as $type)
                                <option value="{{ $type['id'] }}">{{ $type['name'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Collection Filter --}}
                    <div class="form-control w-48">
                        <label class="label">
                            <span class="label-text">Collection</span>
                        </label>
                        <select class="select select-bordered" wire:model.live="collectionFilter" multiple size="1">
                            <option value="">All Collections</option>
                            @foreach ($collections as $collection)
                                <option value="{{ $collection['id'] }}">{{ $collection['name'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- MIME Type Filter --}}
                    <div class="form-control w-48">
                        <label class="label">
                            <span class="label-text">File Type</span>
                        </label>
                        <select class="select select-bordered" wire:model.live="mimeFilter" multiple size="1">
                            <option value="">All Types</option>
                            @foreach ($mimeTypes as $mimeType)
                                <option value="{{ $mimeType['id'] }}">{{ Str::after($mimeType['name'], '/') }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Clear Filters --}}
                    @if ($search || !empty($modelFilter) || !empty($collectionFilter) || !empty($mimeFilter))
                        <div class="form-control content-end">
                            <label class="label">
                                <span class="label-text">&nbsp;</span>
                            </label>
                            <button class="btn btn-outline" wire:click="clearFilters">
                                <x-icon name="o-x-mark" class="w-4 h-4" />
                                Clear
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Mobile Filters (Collapsible) --}}
        <div class="lg:hidden">
            <x-collapse separator class="bg-base-200">
                <x-slot:heading>
                    <div class="flex items-center gap-2">
                        <x-icon name="o-funnel" class="w-5 h-5" />
                        Filters
                        @if ($search || !empty($modelFilter) || !empty($collectionFilter) || !empty($mimeFilter))
                            <x-badge value="Active" class="badge-primary badge-xs" />
                        @endif
                    </div>
                </x-slot:heading>
                <x-slot:content>
                    <div class="flex flex-col gap-4">
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Search</span>
                            </label>
                            <input
                                type="text"
                                class="input input-bordered w-full"
                                placeholder="Search media..."
                                wire:model.live.debounce.300ms="search"
                            />
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Model Type</span>
                            </label>
                            <select class="select select-bordered w-full" wire:model.live="modelFilter" multiple>
                                @foreach ($modelTypes as $type)
                                    <option value="{{ $type['id'] }}">{{ $type['name'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Collection</span>
                            </label>
                            <select class="select select-bordered w-full" wire:model.live="collectionFilter" multiple>
                                @foreach ($collections as $collection)
                                    <option value="{{ $collection['id'] }}">{{ $collection['name'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">File Type</span>
                            </label>
                            <select class="select select-bordered w-full" wire:model.live="mimeFilter" multiple>
                                @foreach ($mimeTypes as $mimeType)
                                    <option value="{{ $mimeType['id'] }}">{{ Str::after($mimeType['name'], '/') }}</option>
                                @endforeach
                            </select>
                        </div>

                        @if ($search || !empty($modelFilter) || !empty($collectionFilter) || !empty($mimeFilter))
                            <button class="btn btn-outline" wire:click="clearFilters">
                                <x-icon name="o-x-mark" class="w-4 h-4" />
                                Clear Filters
                            </button>
                        @endif
                    </div>
                </x-slot:content>
            </x-collapse>
        </div>

        {{-- Stats Summary --}}
        @if ($mediaItems->total() > 0)
            <div class="flex items-center justify-between text-sm text-base-content/70 px-2">
                <div>
                    Showing {{ $mediaItems->firstItem() }} to {{ $mediaItems->lastItem() }} of {{ $mediaItems->total() }} media items
                </div>
                <div class="flex items-center gap-2">
                    <label class="label">
                        <span class="label-text">Per page:</span>
                    </label>
                    <select class="select select-bordered select-sm" wire:model.live="perPage">
                        <option value="12">12</option>
                        <option value="24">24</option>
                        <option value="48">48</option>
                        <option value="96">96</option>
                    </select>
                </div>
            </div>
        @endif

        {{-- Media Grid --}}
        @if ($mediaItems->count() > 0)
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 lg:gap-6">
                @foreach ($mediaItems as $media)
                    <x-media-card :media="$media" wire:key="media-{{ $media->id }}" />
                @endforeach
            </div>

            {{-- Pagination --}}
            <div class="mt-8">
                {{ $mediaItems->links() }}
            </div>
        @else
            {{-- Empty State --}}
            <div class="card bg-base-200 shadow">
                <div class="card-body">
                    <div class="flex flex-col items-center text-center py-12">
                        <div class="w-16 h-16 rounded-full bg-base-300 flex items-center justify-center mb-4">
                            <x-icon name="o-photo" class="w-8 h-8 text-base-content/50" />
                        </div>
                        <h3 class="text-xl font-semibold mb-2">No media found</h3>
                        <p class="text-base-content/70 mb-4">
                            @if ($search || !empty($modelFilter) || !empty($collectionFilter) || !empty($mimeFilter))
                                Try adjusting your filters to find media
                            @else
                                No media has been uploaded yet
                            @endif
                        </p>
                        @if ($search || !empty($modelFilter) || !empty($collectionFilter) || !empty($mimeFilter))
                            <button class="btn btn-primary btn-sm" wire:click="clearFilters">
                                <x-icon name="o-x-mark" class="w-4 h-4" />
                                Clear Filters
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
