<div>
    <div class="space-y-4">
        @if ($relationships->isEmpty())
            <!-- Empty State -->
            <div class="text-center py-8">
                <x-icon name="fas-right-left" class="w-12 h-12 text-base-content/30 mx-auto mb-2" />
                <p class="text-base-content/60">No relationships yet</p>
                <p class="text-sm text-base-content/40 mt-1">Create connections to other events, objects, or blocks</p>
            </div>
        @else
            <!-- Relationships List -->
            @foreach ($relationships->groupBy('type') as $type => $rels)
                <div class="mb-6">
                    <!-- Type Header -->
                    <div class="flex items-center gap-2 mb-3 pb-2 border-b border-base-300">
                        <x-icon name="{{ $this->getRelationshipIcon($type) }}" class="w-5 h-5 text-accent" />
                        <span class="font-semibold text-base-content">{{ $this->getRelationshipDisplayName($type) }}</span>
                        <span class="text-xs text-base-content/50">({{ $rels->count() }})</span>
                        @if ($this->isDirectional($type))
                            <x-icon name="fas-arrow-right" class="w-3 h-3 text-base-content/40 ml-1" />
                        @else
                            <x-icon name="fas-right-left" class="w-3 h-3 text-base-content/40 ml-1" />
                        @endif
                    </div>

                    <!-- Relationships -->
                    <div class="space-y-2">
                        @foreach ($rels as $relationship)
                            @php
                                // Determine if this model is "from" or "to" in the relationship
                                $isFrom = $relationship->from_type === $modelType && $relationship->from_id === $modelId;
                                $relatedModel = $isFrom ? $relationship->to : $relationship->from;
                                $direction = $isFrom ? '→' : '←';

                                // Get display info for related model
                                if ($relatedModel instanceof \App\Models\Event) {
                                    $icon = 'fas-calendar';
                                    $title = $relatedModel->action;
                                    $subtitle = $relatedModel->time?->format('M j, Y g:i A');
                                    $route = route('events.show', $relatedModel);
                                    $badgeText = 'Event';
                                    $badgeClass = 'badge-primary';
                                } elseif ($relatedModel instanceof \App\Models\EventObject) {
                                    $icon = 'o-cube';
                                    $title = $relatedModel->title;
                                    $subtitle = $relatedModel->concept . ' / ' . $relatedModel->type;
                                    $route = route('objects.show', $relatedModel);
                                    $badgeText = 'Object';
                                    $badgeClass = 'badge-secondary';
                                } elseif ($relatedModel instanceof \App\Models\Block) {
                                    $icon = 'fas-grip';
                                    $title = $relatedModel->type;
                                    $subtitle = $relatedModel->time?->format('M j, Y');
                                    $route = route('blocks.show', $relatedModel);
                                    $badgeText = 'Block';
                                    $badgeClass = 'badge-accent';
                                } else {
                                    $icon = 'o-question-mark-circle';
                                    $title = 'Unknown';
                                    $subtitle = '';
                                    $route = '#';
                                    $badgeText = 'Unknown';
                                    $badgeClass = 'badge-ghost';
                                }
                            @endphp

                            <div class="flex items-center gap-3 p-3 rounded-lg bg-base-200/50 hover:bg-base-200 transition-colors">
                                <!-- Direction Indicator -->
                                @if ($this->isDirectional($type))
                                    <div class="text-xl text-base-content/40 font-mono">{{ $direction }}</div>
                                @else
                                    <div class="text-xl text-base-content/40 font-mono">↔</div>
                                @endif

                                <!-- Related Entity -->
                                <a href="{{ $route }}" class="flex items-center gap-2 flex-1 min-w-0 hover:text-accent transition-colors">
                                    <x-icon name="{{ $icon }}" class="w-5 h-5 flex-shrink-0" />
                                    <div class="min-w-0 flex-1">
                                        <div class="font-medium truncate">{{ $title }}</div>
                                        @if ($subtitle)
                                            <div class="text-xs text-base-content/60 truncate">{{ $subtitle }}</div>
                                        @endif
                                    </div>
                                </a>

                                <!-- Badge -->
                                <span class="badge {{ $badgeClass }} badge-sm">{{ $badgeText }}</span>

                                <!-- Value (if present) -->
                                @if ($relationship->value !== null)
                                    <div class="text-sm font-mono text-info">
                                        @if ($relationship->value_unit)
                                            {{ $relationship->value_unit }}
                                        @endif
                                        {{ number_format($relationship->value / ($relationship->value_multiplier ?? 1), 2) }}
                                    </div>
                                @endif

                                <!-- Delete Button -->
                                <button
                                    type="button"
                                    wire:click="deleteRelationship('{{ $relationship->id }}')"
                                    wire:confirm="Are you sure you want to delete this relationship?"
                                    class="btn btn-ghost btn-sm btn-circle"
                                    title="Delete relationship"
                                >
                                    <x-icon name="fas-trash" class="w-4 h-4 text-error" />
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif
    </div>

    <!-- Actions -->
    <div class="flex gap-3 mt-6">
        <x-button label="Add Relationship" icon="fas-plus" class="btn-accent" wire:click="openAddRelationshipModal" />
        <x-button label="Close" class="btn btn-outline" @click="$wire.dispatch('close-modal')" />
    </div>
</div>
