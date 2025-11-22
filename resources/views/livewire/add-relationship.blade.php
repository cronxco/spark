<div>
    <x-form wire:submit="save">
        <!-- Relationship Type -->
        <div class="form-control">
            <label class="label">
                <span class="label-text">Relationship Type</span>
            </label>
            <select wire:model.live="relationshipType" class="select select-bordered w-full">
                @foreach ($relationshipTypes as $type => $config)
                    <option value="{{ $type }}">
                        {{ $config['display_name'] }}
                        @if ($config['is_directional'])
                            →
                        @else
                            ↔
                        @endif
                    </option>
                @endforeach
            </select>
            <label class="label">
                <span class="label-text-alt">
                    @php
                        $currentType = $relationshipTypes[$relationshipType] ?? null;
                    @endphp
                    @if ($currentType)
                        {{ $currentType['description'] }}
                    @endif
                </span>
            </label>
        </div>

        <!-- Target Type -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Connect To</span>
                </label>
                <select wire:model.live="toType" class="select select-bordered w-full" @if ($selectedTarget) disabled @endif>
                    <option value="">Select type...</option>
                    <option value="{{ \App\Models\Event::class }}">Event</option>
                    <option value="{{ \App\Models\EventObject::class }}">Object</option>
                    <option value="{{ \App\Models\Block::class }}">Block</option>
                </select>
            </div>

            <!-- Search -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Search</span>
                </label>
                <x-input
                    wire:model.live.debounce.300ms="searchQuery"
                    placeholder="Type to search..."
                    icon="fas-magnifying-glass"
                    :disabled="!$toType || $selectedTarget"
                />
            </div>
        </div>

        <!-- Selected Target Display -->
        @if ($selectedTarget)
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Selected Item</span>
                </label>
                <div class="flex items-center gap-3 p-4 rounded-lg bg-accent/10 border-2 border-accent">
                    @php
                        if ($selectedTarget instanceof \App\Models\Event) {
                            $icon = 'fas-calendar';
                            $title = $selectedTarget->action;
                            $subtitle = $selectedTarget->time?->format('j M Y H:i');
                            $badge = 'Event';
                        } elseif ($selectedTarget instanceof \App\Models\EventObject) {
                            $icon = 'o-cube';
                            $title = $selectedTarget->title;
                            $subtitle = $selectedTarget->concept . ' / ' . $selectedTarget->type;
                            $badge = 'Object';
                        } elseif ($selectedTarget instanceof \App\Models\Block) {
                            $icon = 'fas-grip';
                            $title = $selectedTarget->type;
                            $subtitle = $selectedTarget->time?->format('j M Y');
                            $badge = 'Block';
                        }
                    @endphp
                    <x-icon name="{{ $icon }}" class="w-6 h-6 text-accent" />
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold truncate">{{ $title }}</div>
                        <div class="text-sm text-base-content/60 truncate">{{ $subtitle }}</div>
                    </div>
                    <span class="badge badge-accent">{{ $badge }}</span>
                    <button type="button" wire:click="clearSelection" class="btn btn-ghost btn-sm btn-circle">
                        <x-icon name="fas-xmark" class="w-4 h-4" />
                    </button>
                </div>
            </div>
        @endif

        <!-- Search Results (fixed height container) -->
        <div class="form-control">
            @if ($toType && !$selectedTarget && $searchResults->isNotEmpty())
                <label class="label">
                    <span class="label-text">Results ({{ $searchResults->count() }})</span>
                </label>
                <div class="h-64 overflow-y-auto space-y-1 rounded-lg border border-base-300 p-2">
                    @foreach ($searchResults as $result)
                        @php
                            if ($result instanceof \App\Models\Event) {
                                $icon = 'fas-calendar';
                                $title = $result->action;
                                $subtitle = $result->time?->format('M j, Y g:i A');
                                $badge = 'Event';
                                $badgeClass = 'badge-primary';
                            } elseif ($result instanceof \App\Models\EventObject) {
                                $icon = 'o-cube';
                                $title = $result->title;
                                $subtitle = $result->concept . ' / ' . $result->type;
                                $badge = 'Object';
                                $badgeClass = 'badge-secondary';
                            } elseif ($result instanceof \App\Models\Block) {
                                $icon = 'fas-grip';
                                $title = $result->type;
                                $subtitle = $result->time?->format('M j, Y');
                                $badge = 'Block';
                                $badgeClass = 'badge-accent';
                            }
                        @endphp
                        <button
                            type="button"
                            wire:click="selectTarget('{{ $result->id }}')"
                            class="w-full flex items-center gap-2 p-2 rounded hover:bg-base-200 transition-colors text-left"
                        >
                            <x-icon name="{{ $icon }}" class="w-4 h-4 flex-shrink-0" />
                            <div class="flex-1 min-w-0">
                                <div class="font-medium truncate text-sm">{{ $title }}</div>
                                <div class="text-xs text-base-content/60 truncate">{{ $subtitle }}</div>
                            </div>
                            <span class="badge {{ $badgeClass }} badge-xs">{{ $badge }}</span>
                        </button>
                    @endforeach
                </div>
            @elseif ($toType && !$selectedTarget && filled($searchQuery))
                <div class="h-64 flex items-center justify-center text-base-content/60 rounded-lg border border-base-300">
                    <div class="text-center">
                        <x-icon name="fas-magnifying-glass" class="w-8 h-8 mx-auto mb-2 text-base-content/30" />
                        <p class="text-sm">No results found</p>
                    </div>
                </div>
            @endif
        </div>

        <!-- Value Fields (always visible when relationship type supports it) -->
        @if ($this->supportsValue())
            <div class="space-y-4">
                <div class="divider text-sm text-base-content/60">Value (Optional)</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-input
                        label="Value"
                        wire:model="value"
                        type="number"
                        step="0.01"
                        placeholder="0.00"
                        hint="Value"
                    />
                    <x-input
                        label="Unit"
                        wire:model="valueUnit"
                        placeholder="e.g., GBP, USD"
                        hint="Unit"
                    />
                </div>
                <x-input
                    label="Multiplier"
                    wire:model="valueMultiplier"
                    type="number"
                    step="0.01"
                    placeholder="1.00"
                    hint="E.g. 100 for pence to pounds"
                />
            </div>
        @endif

        <!-- Metadata (always visible, in collapse) -->
        <div class="collapse collapse-arrow bg-base-200/50 rounded-lg">
            <input type="checkbox" />
            <div class="collapse-title text-sm font-medium">
                Metadata (Advanced)
            </div>
            <div class="collapse-content">
                <div class="pt-2">
                    <x-textarea
                        label="Metadata JSON"
                        wire:model="metadata"
                        placeholder='{"key": "value"}'
                        hint=""
                        rows="3"
                    />
                </div>
            </div>
        </div>

        <x-slot:actions>
            <x-button label="Cancel" @click="$wire.dispatch('close-modal')" class="btn-outline" />
            <x-button
                label="Create Relationship"
                class="btn-primary"
                type="submit"
                spinner="save"
                :disabled="!$selectedTarget"
            />
        </x-slot:actions>
    </x-form>
</div>
