<div>
    <div class="space-y-4">
        <!-- Header with Type Filter -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
            <h3 class="text-lg font-semibold text-base-content flex items-center gap-2">
                <x-icon name="fas.clock-rotate-left" class="w-5 h-5 text-info" />
                Recently Viewed
            </h3>

            <!-- Type Filter Tabs -->
            <div class="flex flex-wrap gap-1">
                <button
                    wire:click="setTypeFilter('all')"
                    class="btn btn-xs {{ $typeFilter === 'all' ? 'btn-primary' : 'btn-ghost' }}">
                    All
                </button>
                <button
                    wire:click="setTypeFilter('events')"
                    class="btn btn-xs {{ $typeFilter === 'events' ? 'btn-primary' : 'btn-ghost' }}">
                    <x-icon name="fas.bolt" class="w-3 h-3" />
                    Events
                </button>
                <button
                    wire:click="setTypeFilter('objects')"
                    class="btn btn-xs {{ $typeFilter === 'objects' ? 'btn-primary' : 'btn-ghost' }}">
                    <x-icon name="o-cube" class="w-3 h-3" />
                    Objects
                </button>
                <button
                    wire:click="setTypeFilter('blocks')"
                    class="btn btn-xs {{ $typeFilter === 'blocks' ? 'btn-primary' : 'btn-ghost' }}">
                    <x-icon name="fas.grip" class="w-3 h-3" />
                    Blocks
                </button>
            </div>
        </div>

        <!-- Items List -->
        @if ($this->items->isEmpty())
            <div class="text-center py-8 text-base-content/60">
                <x-icon name="fas.clock-rotate-left" class="w-12 h-12 mx-auto mb-3 opacity-50" />
                <p class="text-sm">No recently viewed items yet.</p>
                <p class="text-xs mt-1">Items you view will appear here.</p>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($this->items as $item)
                    <a
                        href="{{ $this->getItemRoute($item) }}"
                        class="flex items-center gap-3 p-3 rounded-lg bg-base-100 hover:bg-base-200 border border-base-200 hover:border-base-300 transition-colors group"
                        wire:navigate>
                        <!-- Icon -->
                        <div class="w-8 h-8 rounded-full bg-{{ $item->type === 'App\Models\Event' ? 'primary' : ($item->type === 'App\Models\EventObject' ? 'secondary' : 'accent') }}/10 flex items-center justify-center flex-shrink-0">
                            <x-icon name="{{ $this->getItemIcon($item) }}" class="w-4 h-4 text-{{ $item->type === 'App\Models\Event' ? 'primary' : ($item->type === 'App\Models\EventObject' ? 'secondary' : 'accent') }}" />
                        </div>

                        <!-- Content -->
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-sm truncate group-hover:text-primary transition-colors">
                                {{ $this->getItemTitle($item) }}
                            </div>
                            <div class="flex items-center gap-2 text-xs text-base-content/60">
                                <span class="badge badge-xs badge-outline">{{ $item->type_label }}</span>
                                @if ($this->getItemSubtitle($item))
                                    <span class="truncate">{{ $this->getItemSubtitle($item) }}</span>
                                @endif
                            </div>
                        </div>

                        <!-- Viewed Time -->
                        <div class="flex-shrink-0 text-xs text-base-content/50">
                            {{ $item->viewed_at->diffForHumans(short: true) }}
                        </div>

                        <!-- Arrow -->
                        <x-icon name="fas.chevron-right" class="w-4 h-4 text-base-content/30 flex-shrink-0 group-hover:text-base-content/60 transition-colors" />
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
