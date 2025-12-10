<?php

use App\Integrations\PluginRegistry;
use App\Models\Block;
use App\Models\Event;
use Illuminate\Support\Facades\Log;
use Livewire\Volt\Component;
use Spatie\Activitylog\Models\Activity;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    public Block $block;
    public bool $showSidebar = false;
    public string $comment = '';
    public bool $activityOpen = true;
    public bool $blockMetaOpen = false;
    public bool $showEditBlockModal = false;
    public bool $showManageRelationshipsModal = false;
    public bool $showAddRelationshipModal = false;

    // Progressive loading state flags
    public bool $relationshipsLoaded = false;
    public bool $tasksLoaded = false;
    public bool $relatedBlocksLoaded = false;
    public bool $activitiesLoaded = false;
    public bool $drawerContentLoaded = false;

    // Collapse states
    public bool $tasksOpen = false;

    // Cached data
    public $cachedRelationships = null;
    public $cachedRelatedBlocks = null;
    public $cachedActivities = null;

    protected $listeners = [
        'jump-to-parent-event' => 'handleJumpToParentEvent',
        'open-edit-block-modal' => 'handleOpenEditModal',
        'open-manage-relationships-modal' => 'handleOpenManageRelationshipsModal',
        'open-add-relationship-modal' => 'handleOpenAddRelationshipModal',
        'delete-block' => 'handleDeleteBlock',
        'block-updated' => 'handleBlockUpdated',
        'relationship-created' => 'handleRelationshipUpdated',
        'relationship-deleted' => 'handleRelationshipUpdated',
        'close-modal' => 'closeEditModal',
        'drawer-opened' => 'loadDrawerContent',
    ];

    public function mount(Block $block): void
    {
        // Load only the event relationship initially
        $this->block = $block->load(['event']);

        // Track this view in the activity log (debounced to prevent duplicate views)
        $this->block->logViewIfNotRecent(5);
    }

    public function loadRelationships(): void
    {
        if ($this->relationshipsLoaded) {
            return;
        }

        $this->block->load(['relationshipsFrom', 'relationshipsTo']);
        $this->cachedRelationships = $this->block->allRelationships()->get();
        $this->relationshipsLoaded = true;
    }

    public function loadTasks(): void
    {
        if ($this->tasksLoaded) {
            return;
        }

        // Calculate smart default for collapse state
        $this->tasksOpen = $this->shouldExpandTasksSection();
        $this->tasksLoaded = true;
    }

    public function loadRelatedBlocks(): void
    {
        if ($this->relatedBlocksLoaded) {
            return;
        }

        $this->cachedRelatedBlocks = $this->fetchRelatedBlocks();
        $this->relatedBlocksLoaded = true;
    }

    public function loadActivities(): void
    {
        if ($this->activitiesLoaded) {
            return;
        }

        $this->cachedActivities = Activity::forSubject($this->block)->latest()->get();
        $this->activitiesLoaded = true;
    }

    /**
     * Load drawer-specific content when drawer is opened.
     * This is called only when the drawer is opened to avoid loading
     * unnecessary data if the user never opens the drawer.
     */
    public function loadDrawerContent(): void
    {
        if ($this->drawerContentLoaded) {
            return;
        }

        // Load only the data needed for drawer that isn't already loaded
        if (! $this->relationshipsLoaded) {
            $this->loadRelationships();
        }
        if (! $this->tasksLoaded) {
            $this->loadTasks();
        }
        if (! $this->activitiesLoaded) {
            $this->loadActivities();
        }

        $this->drawerContentLoaded = true;
    }

    public function toggleSidebar(): void
    {
        $this->showSidebar = ! $this->showSidebar;
    }

    public function getRelationships()
    {
        if (! $this->relationshipsLoaded) {
            return collect();
        }

        return $this->cachedRelationships ?? collect();
    }

    public function getRelatedBlocks()
    {
        if (! $this->relatedBlocksLoaded) {
            return collect();
        }

        return $this->cachedRelatedBlocks ?? collect();
    }

    public function getActivities()
    {
        if (! $this->activitiesLoaded) {
            return collect();
        }

        return $this->cachedActivities ?? collect();
    }

    public function addComment(): void
    {
        $text = trim($this->comment);
        if ($text === '') {
            return;
        }

        activity('changelog')
            ->performedOn($this->block)
            ->causedBy(auth()->guard('web')->user())
            ->event('comment')
            ->withProperties(['comment' => $text])
            ->log('comment');

        $this->comment = '';
    }

    public function formatJson($data)
    {
        if (is_array($data) || is_object($data)) {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return $data;
    }

    public function getCompleteBlockData(): array
    {
        return [
            'block' => $this->block->toArray(),
            'event' => $this->block->event?->toArray(),
            'relationships' => $this->block->allRelationships()->get()->map(function ($rel) {
                return [
                    'type' => $rel->type,
                    'from' => ['type' => $rel->from_type, 'id' => $rel->from_id],
                    'to' => ['type' => $rel->to_type, 'id' => $rel->to_id],
                    'value' => $rel->value,
                    'value_unit' => $rel->value_unit,
                    'metadata' => $rel->metadata,
                ];
            })->toArray(),
            'related_blocks' => $this->getRelatedBlocks()->toArray(),
        ];
    }

    public function exportAsJson(): void
    {
        $data = $this->getCompleteBlockData();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->js('
            const blob = new Blob([' . json_encode($json) . "], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'block-{$this->block->id}-" . now()->format('Y-m-d-His') . ".json';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            const toast = document.createElement('div');
            toast.className = 'toast toast-top toast-center z-50';
            toast.innerHTML = `
                <div class='alert alert-success shadow-lg'>
                    <svg xmlns='http://www.w3.org/2000/svg' class='stroke-current shrink-0 h-5 w-5' fill='none' viewBox='0 0 24 24'>
                        <path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' />
                    </svg>
                    <span>Block exported!</span>
                </div>
            `;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 2000);
        ");
    }

    public function getBlockIcon($blockType, $service = null)
    {
        // Try to get icon from plugin configuration first if service is available
        if ($service) {
            $pluginClass = PluginRegistry::getPlugin($service);
            if ($pluginClass) {
                $blockTypes = $pluginClass::getBlockTypes();
                if (isset($blockTypes[$blockType]) && isset($blockTypes[$blockType]['icon'])) {
                    return $blockTypes[$blockType]['icon'];
                }
            }
        }

        // Fallback to default icon if plugin doesn't have this block type
        return 'o-squares-2x2';
    }

    public function notifyCopied(string $what): void
    {
        $this->js("
            const toast = document.createElement('div');
            toast.className = 'toast toast-top toast-center z-50';
            toast.innerHTML = `
                <div class='alert alert-success shadow-lg'>
                    <svg xmlns='http://www.w3.org/2000/svg' class='stroke-current shrink-0 h-5 w-5' fill='none' viewBox='0 0 24 24'>
                        <path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' />
                    </svg>
                    <span>" . addslashes($what) . "</span>
                </div>
            `;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 2000);
        ");
    }

    public function handleJumpToParentEvent(): void
    {
        if ($this->block->event_id) {
            $this->redirect(route('events.show', $this->block->event_id), navigate: true);
        }
    }

    public function handleOpenEditModal(): void
    {
        $this->showEditBlockModal = true;
    }

    public function handleDeleteBlock(): void
    {
        $this->block->delete();
        $this->redirect(route('today.main'), navigate: true);
    }

    public function handleBlockUpdated(): void
    {
        $this->block->refresh()->load(['event']);
        $this->showEditBlockModal = false;
    }

    public function handleOpenManageRelationshipsModal(): void
    {
        $this->showManageRelationshipsModal = true;
        $this->showAddRelationshipModal = false;
    }

    public function handleOpenAddRelationshipModal(): void
    {
        $this->showAddRelationshipModal = true;
        $this->showManageRelationshipsModal = false;
    }

    public function handleRelationshipUpdated(): void
    {
        $this->block->refresh()->load([
            'relationshipsFrom',
            'relationshipsTo',
        ]);

        // Refresh cached relationships if already loaded
        if ($this->relationshipsLoaded) {
            $this->cachedRelationships = $this->block->allRelationships()->get();
        }
    }

    public function closeEditModal(): void
    {
        $this->showEditBlockModal = false;
        $this->showManageRelationshipsModal = false;
        $this->showAddRelationshipModal = false;
    }

    protected function shouldExpandTasksSection(): bool
    {
        // Expand if there are failed or pending tasks
        $metadata = $this->block->metadata ?? [];
        $executions = $metadata['task_executions'] ?? [];

        foreach ($executions as $execution) {
            $status = $execution['last_attempt']['status'] ?? null;
            if (in_array($status, ['failed', 'pending'])) {
                return true;
            }
        }

        return false;
    }

    protected function fetchRelatedBlocks()
    {
        // Use semantic search if embeddings exist
        if (! empty($this->block->embeddings)) {
            try {
                $embedding = json_decode($this->block->embeddings, true);

                if (is_array($embedding) && count($embedding) > 0) {
                    // Get user's integration IDs for security
                    $userIntegrationIds = auth()->user()->integrations()->pluck('id')->toArray();

                    // Perform semantic search with temporal weighting
                    return Block::semanticSearch($embedding, threshold: 1.2, limit: 5, temporalWeight: 0.015)
                        ->whereHas('event', function ($q) use ($userIntegrationIds) {
                            $q->whereIn('integration_id', $userIntegrationIds);
                        })
                        ->where('id', '!=', $this->block->id) // Exclude current block
                        ->with(['event.integration'])
                        ->get();
                }
            } catch (\Exception $e) {
                Log::warning('Semantic search failed for related blocks on block', [
                    'block_id' => $this->block->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to original logic if embeddings don't exist or semantic search fails
        return Block::where('event_id', $this->block->event_id)
            ->where('id', '!=', $this->block->id)
            ->orderBy('time', 'desc')
            ->limit(5)
            ->get();
    }
};

?>

<div x-data="{ drawerOpen: @entangle('showSidebar').live }"
     x-init="$watch('drawerOpen', value => {
         if (value) {
             setTimeout(() => $wire.dispatch('drawer-opened'), 50);
         }
     })">
    @if ($this->block)
    <!-- Two-column layout: main content + optional drawer -->
    <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
        <!-- Main Content Area -->
        <div class="flex-1 space-y-4 lg:space-y-6">
            <!-- Header -->
        <x-header title="Block Details" separator>
            <x-slot:actions>
                <x-button
                    @click="drawerOpen = !drawerOpen"
                    class="btn-ghost btn-sm"
                    ::title="drawerOpen ? 'Hide details' : 'Show details'"
                    ::aria-label="drawerOpen ? 'Hide details' : 'Show details'"
                    data-hotkey="d">
                    <x-icon name="o-adjustments-horizontal" class="w-4 h-4" />
                </x-button>
            </x-slot:actions>
        </x-header>

        <!-- Block Overview Card -->
        <x-card class="bg-base-200 shadow">
            <div class="flex flex-col sm:flex-row items-start gap-4 lg:gap-6">
                <!-- Block Icon -->
                <div class="flex-shrink-0 self-center sm:self-start">
                    <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-base-200 flex items-center justify-center">
                        <x-icon name="{{ $this->getBlockIcon($this->block->block_type, $this->block->event?->service) }}"
                            class="w-6 h-6 sm:w-8 sm:h-8" />
                    </div>
                </div>

                <!-- Block Info -->
                <div class="flex-1">
                    <div class="mb-4 text-center sm:text-left">
                        <div class="flex flex-col sm:flex-row items-center sm:items-start justify-between gap-2 mb-2">
                            <h2 class="text-xl sm:text-2xl lg:text-3xl font-bold text-base-content leading-tight">
                                {{ $this->block->title }}
                            </h2>

                            @if ($this->block->value)
                            <div class="text-2xl sm:text-3xl lg:text-4xl font-bold flex-shrink-0">
                                {!! format_event_value_display($this->block->formatted_value, $this->block->value_unit, $this->block->event?->service, $this->block->block_type, 'block') !!}
                            </div>
                            @endif
                        </div>
                    </div>

                    @php $meta = is_array($this->block->metadata ?? null) ? $this->block->metadata : []; @endphp
                    @if (!empty($meta))
                    <div class="mt-3 overflow-hidden min-w-0 w-full">
                        <x-metadata-list :data="$meta" />
                    </div>
                    @endif

                    <!-- Block Metadata -->
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        @if ($this->block->time)
                        <div class="flex items-center gap-2">
                            <x-icon name="o-clock" class="w-4 h-4 text-base-content/60 flex-shrink-0" />
                            <span class="text-base-content/70">{{ to_user_timezone($this->block->time, auth()->user())->format('d/m/Y H:i') }}</span>
                        </div>
                        @endif
                        @if ($this->block->time)
                        <span class="text-base-content/40">|</span>
                        @endif
                        @if ($this->block->url)
                        <div class="flex items-center gap-2">
                            <x-icon name="o-link" class="w-4 h-4 text-base-content/60" />
                            <span class="text-base-content/70">URL:</span>
                            <a href="{{ $this->block->url }}" target="_blank" class="font-medium hover:underline">
                                View
                            </a>
                        </div>
                        @endif
                        @if ($this->block->media_url)
                        <div class="flex items-center gap-2">
                            <x-icon name="o-photo" class="w-4 h-4 text-base-content/60" />
                            <span class="text-base-content/70">Media:</span>
                            <a href="{{ $this->block->media_url }}" target="_blank" class="font-medium hover:underline">
                                View
                            </a>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </x-card>

        <!-- Related Event -->
        @if ($this->block->event)
        <x-card class="bg-base-200 shadow">
            <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                <x-icon name="o-bolt" class="w-5 h-5" />
                Related Event
            </h3>
            <div class="border border-base-300 rounded-lg p-3 hover:bg-base-50 transition-colors">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-base-200 flex items-center justify-center flex-shrink-0 mt-1">
                        <x-icon name="o-bolt" class="w-4 h-4" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2 mb-1">
                            <div class="flex items-center flex-wrap gap-1">
                                <x-event-ref :event="$this->block->event" :showService="false" />
                                @if (should_display_action_with_object($this->block->event->action, $this->block->event->service))
                                @if ($this->block->event->target)
                                <x-object-ref :object="$this->block->event->target" />
                                @elseif ($this->block->event->actor)
                                <x-object-ref :object="$this->block->event->actor" />
                                @endif
                                @endif
                            </div>
                            @if ($this->block->event->value)
                            <span class="text-sm font-semibold flex-shrink-0">
                                {!! format_event_value_display($this->block->event->formatted_value, $this->block->event->value_unit, $this->block->event->service, $this->block->event->action, 'action') !!}
                            </span>
                            @endif
                        </div>
                        <div class="text-sm text-base-content/70 flex flex-wrap items-center gap-1">
                            <span>{{ to_user_timezone($this->block->event->time, auth()->user())->format('d/m/Y H:i') }}</span>
                            @if ($this->block->event->domain)
                            <span>·</span>
                            <x-badge :value="$this->block->event->domain" class="badge-xs badge-outline" />
                            @endif
                            <span>·</span>
                            @if ($this->block->event->integration)
                            <x-integration-ref :integration="$this->block->event->integration" :showStatus="false" />
                            @else
                            <x-badge :value="$this->block->event->service" class="badge-xs badge-outline" />
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </x-card>
        @endif

        <!-- Linked Blocks -->
        <div wire:init="loadRelatedBlocks">
            @if ($relatedBlocksLoaded && $this->getRelatedBlocks()->isNotEmpty())
            <x-card class="bg-base-200 shadow">
                <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                    <x-icon name="o-squares-2x2" class="w-5 h-5 text-info" />
                    Related Blocks
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($this->getRelatedBlocks() as $relatedBlock)
                    <a href="{{ route('blocks.show', $relatedBlock) }}"
                        class="border border-base-200 bg-base-100 rounded-lg p-3 hover:bg-base-50 transition-colors">
                        <div class="flex items-start gap-3">
                            <div class="w-8 h-8 rounded-full bg-info/10 flex items-center justify-center flex-shrink-0">
                                <x-icon name="{{ $this->getBlockIcon($relatedBlock->block_type, $relatedBlock->event?->service) }}"
                                    class="w-4 h-4 text-info" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium text-sm line-clamp-1">{{ $relatedBlock->title }}</div>
                                <div class="text-xs text-base-content/70">
                                    {{ $relatedBlock->block_type }}
                                    @if ($relatedBlock->time)
                                    · {{ $relatedBlock->time->diffForHumans() }}
                                    @endif
                                </div>
                                @if ($relatedBlock->value)
                                <div class="text-sm font-semibold text-info mt-1">
                                    {!! format_event_value_display($relatedBlock->formatted_value, $relatedBlock->value_unit, $relatedBlock->event?->service, $relatedBlock->block_type, 'block') !!}
                                </div>
                                @endif
                            </div>
                        </div>
                    </a>
                    @endforeach
                </div>
            </x-card>
            @elseif (! $relatedBlocksLoaded)
            <x-card class="bg-base-200 shadow">
                <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                    <x-icon name="o-squares-2x2" class="w-5 h-5 text-info" />
                    Related Blocks
                </h3>
                <x-skeleton-loader type="block-grid" />
            </x-card>
            @endif
        </div>

        <!-- Relationships Section -->
        <div wire:init="loadRelationships">
        @php $relationships = $this->getRelationships(); @endphp
        @if ($relationshipsLoaded && $relationships->isNotEmpty())
        <x-card class="bg-base-200 shadow">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-base-content flex items-center gap-2">
                    <x-icon name="o-link" class="w-5 h-5 text-accent" />
                    Relationships ({{ $relationships->count() }})
                </h3>
                <div class="flex items-center gap-2">
                    <x-button wire:click="handleOpenAddRelationshipModal" class="btn-xs btn-ghost" title="Add relationship">
                        <x-icon name="o-plus" class="w-4 h-4" />
                    </x-button>
                    <x-button wire:click="handleOpenManageRelationshipsModal" class="btn-xs btn-ghost" title="Manage relationships">
                        <x-icon name="o-pencil" class="w-4 h-4" />
                    </x-button>
                </div>
            </div>
            <div class="space-y-2">
                @foreach ($relationships->take(5) as $relationship)
                @php
                // Determine which entity is the "other" one
                $isFrom = $relationship->from_type === get_class($this->block) && $relationship->from_id == $this->block->id;
                $relatedModel = $isFrom ? $relationship->toModel : $relationship->fromModel;
                $direction = $isFrom ? '→' : '←';

                // Determine icon and route based on model type
                $icon = 'o-document';
                $title = 'Unknown';
                $subtitle = '';
                $route = '#';
                $badgeText = '';
                $badgeClass = '';

                if ($relatedModel instanceof \App\Models\Event) {
                $icon = 'o-bolt';
                $title = $relatedModel->action;
                $subtitle = $relatedModel->time?->format('M j, Y');
                $route = route('events.show', $relatedModel);
                $badgeText = 'Event';
                $badgeClass = 'badge-outline';
                } elseif ($relatedModel instanceof \App\Models\EventObject) {
                $icon = 'o-cube';
                $title = $relatedModel->title;
                $subtitle = $relatedModel->concept;
                $route = route('objects.show', $relatedModel);
                $badgeText = 'Object';
                $badgeClass = 'badge-outline';
                } elseif ($relatedModel instanceof \App\Models\Block) {
                $icon = 'o-squares-2x2';
                $title = $relatedModel->type;
                $subtitle = $relatedModel->time?->format('M j, Y');
                $route = route('blocks.show', $relatedModel);
                $badgeText = 'Block';
                $badgeClass = 'badge-outline';
                }
                @endphp

                <div class="flex items-center gap-2 p-3 rounded-lg bg-base-100">
                    <div class="tooltip" data-tip="{{ \App\Services\RelationshipTypeRegistry::getDisplayName($relationship->type) }}">
                        <x-icon name="{{ \App\Services\RelationshipTypeRegistry::getIcon($relationship->type) }}" class="w-4 h-4 text-accent" />
                    </div>
                    @if (\App\Services\RelationshipTypeRegistry::isDirectional($relationship->type))
                    <span class="text-base-content/40 text-sm">{{ $direction }}</span>
                    @else
                    <span class="text-base-content/40 text-sm">↔</span>
                    @endif
                    <div class="flex-1 min-w-0">
                        @if ($relatedModel instanceof \App\Models\Event)
                            <x-event-ref :event="$relatedModel" :showService="true" />
                        @elseif ($relatedModel instanceof \App\Models\EventObject)
                            <x-object-ref :object="$relatedModel" :showType="true" />
                        @elseif ($relatedModel instanceof \App\Models\Block)
                            <x-block-ref :block="$relatedModel" :showType="true" />
                        @else
                            <a href="{{ $route }}" class="flex items-center gap-2 hover:text-accent transition-colors">
                                <x-icon name="{{ $icon }}" class="w-4 h-4 flex-shrink-0" />
                                <span class="font-medium truncate text-sm">{{ $title }}</span>
                            </a>
                        @endif
                    </div>
                    @if ($relationship->value !== null)
                    <div class="text-xs font-mono text-info">
                        @if ($relationship->value_unit)
                        {{ $relationship->value_unit }}
                        @endif
                        {{ number_format($relationship->value / ($relationship->value_multiplier ?? 1), 2) }}
                    </div>
                    @endif
                </div>
                @endforeach

                @if ($relationships->count() > 5)
                <div class="text-center pt-2">
                    <button wire:click="handleOpenManageRelationshipsModal" class="text-sm text-accent hover:underline">
                        View all {{ $relationships->count() }} relationships
                    </button>
                </div>
                @endif
            </div>
        </x-card>
        @elseif (! $relationshipsLoaded)
        <x-card class="bg-base-200 shadow">
            <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                <x-icon name="o-link" class="w-5 h-5 text-accent" />
                Relationships
            </h3>
            <x-skeleton-loader type="relationship-list" />
        </x-card>
        @endif
        </div>

        <!-- Drawer for Technical Details -->
        <x-drawer wire:model="showSidebar" right title="Block Details" with-close-button separator class="w-11/12 lg:w-1/3">
            <div class="space-y-4">
                <!-- Primary Information (Always Visible) -->
                <div class="pb-4 border-b border-base-200">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/80">Information</h3>
                        <button
                            wire:click="exportAsJson"
                            class="btn btn-ghost btn-xs gap-1"
                            title="Export complete block with event and relationships">
                            <x-icon name="o-arrow-down-tray" class="w-3 h-3" />
                            <span class="hidden sm:inline">Export</span>
                        </button>
                    </div>
                    <dl>
                        <x-metadata-row label="Block ID" :value="$this->block->id" copyable />
                        <x-metadata-row label="Title" :value="$this->block->title" />
                        <x-metadata-row label="Block Type" :value="Str::headline($this->block->block_type)" />
                        @if ($this->block->value)
                            <x-metadata-row label="Value" :copy-value="$this->block->formatted_value">
                                {!! format_event_value_display($this->block->formatted_value, $this->block->value_unit, $this->block->event?->service, $this->block->block_type, 'block') !!}
                            </x-metadata-row>
                        @endif
                        <x-metadata-row label="Time" :copy-value="$this->block->time?->toIso8601String()">
                            <x-uk-date :date="$this->block->time" />
                        </x-metadata-row>
                        <x-metadata-row label="Created" :copy-value="$this->block->created_at?->toIso8601String()">
                            <x-uk-date :date="$this->block->created_at" />
                        </x-metadata-row>
                        <x-metadata-row label="Last Updated" :copy-value="$this->block->updated_at?->toIso8601String()">
                            <x-uk-date :date="$this->block->updated_at" />
                        </x-metadata-row>
                        @if ($this->block->url)
                            <x-metadata-row label="URL" :copy-value="$this->block->url">
                                <a href="{{ $this->block->url }}" target="_blank" class="hover:underline">
                                    {{ $this->block->url }}
                                </a>
                            </x-metadata-row>
                        @endif
                        @if ($this->block->media_url)
                            <x-metadata-row label="Media URL" :copy-value="$this->block->media_url">
                                <a href="{{ $this->block->media_url }}" target="_blank" class="hover:underline">
                                    {{ $this->block->media_url }}
                                </a>
                            </x-metadata-row>
                        @endif
                        @if ($this->block->event)
                            <x-metadata-row label="Related Event" :copy-value="format_action_title($this->block->event->action)">
                                <a href="{{ route('events.show', $this->block->event->id) }}" class="hover:underline">
                                    {{ format_action_title($this->block->event->action) }}
                                </a>
                            </x-metadata-row>
                        @endif
                    </dl>
                </div>

                <!-- Relationships -->
                <div class="pb-4 border-b border-base-200">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/80">
                            Relationships
                        </h3>
                        <button type="button" wire:click="handleOpenManageRelationshipsModal" class="btn btn-xs btn-ghost btn-circle" title="Manage relationships" data-hotkey="r">
                            <x-icon name="o-plus" class="w-3 h-3" />
                        </button>
                    </div>
                    @if (!$drawerContentLoaded || !$relationshipsLoaded)
                    <x-skeleton-loader type="relationship-list" />
                    @else
                    @php $sidebarRelationships = $this->getRelationships(); @endphp
                    @if ($sidebarRelationships->isEmpty())
                    <x-empty-state
                        icon="o-arrows-right-left"
                        message="No relationships yet"
                        actionEvent="handleOpenAddRelationshipModal"
                        actionLabel="Add Relationship" />
                    @else
                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        @foreach ($sidebarRelationships->take(10) as $relationship)
                        @php
                        $isFrom = $relationship->from_type === get_class($block) && $relationship->from_id === $block->id;
                        $relatedModel = $isFrom ? $relationship->to : $relationship->from;

                        // Initialize defaults
                        $icon = 'o-question-mark-circle';
                        $title = 'Unknown';
                        $route = '#';

                        if ($relatedModel instanceof \App\Models\Event) {
                        $icon = 'o-calendar';
                        $title = $relatedModel->action;
                        $route = route('events.show', $relatedModel);
                        } elseif ($relatedModel instanceof \App\Models\EventObject) {
                        $icon = 'o-cube';
                        $title = $relatedModel->title;
                        $route = route('objects.show', $relatedModel);
                        } elseif ($relatedModel instanceof \App\Models\Block) {
                        $icon = 'o-squares-2x2';
                        $title = $relatedModel->type;
                        $route = route('blocks.show', $relatedModel);
                        }
                        @endphp
                        <a href="{{ $route }}" class="flex items-center gap-2 p-2 rounded hover:bg-base-200 transition-colors">
                            <x-icon name="{{ \App\Services\RelationshipTypeRegistry::getIcon($relationship->type) }}" class="w-3 h-3 flex-shrink-0" />
                            <x-icon name="{{ $icon }}" class="w-3 h-3 flex-shrink-0" />
                            <span class="text-sm truncate flex-1">{{ $title }}</span>
                        </a>
                        @endforeach
                    </div>
                    @if ($sidebarRelationships->count() > 10)
                    <div class="text-center mt-2">
                        <button wire:click="handleOpenManageRelationshipsModal" class="text-xs hover:underline">
                            View all {{ $sidebarRelationships->count() }}
                        </button>
                    </div>
                    @endif
                    @endif
                    @endif
                </div>

                <!-- Tasks -->
                <div class="pb-4 border-b border-base-200">
                    @if ($drawerContentLoaded && $tasksLoaded)
                        @php
                            $metadata = $block->metadata ?? [];
                            $executions = $metadata['task_executions'] ?? [];
                            $failedCount = collect($executions)->filter(fn($e) => ($e['last_attempt']['status'] ?? null) === 'failed')->count();
                            $pendingCount = collect($executions)->filter(fn($e) => in_array($e['last_attempt']['status'] ?? null, ['pending', 'running']))->count();
                            $executedCount = collect($executions)->filter(fn($e) => in_array($e['last_attempt']['status'] ?? null, ['success', 'failed', 'running', 'pending']))->count();
                        @endphp
                        <x-collapse wire:model="tasksOpen">
                            <x-slot:heading>
                                <div class="text-sm font-semibold uppercase tracking-wider text-base-content/80 flex items-center justify-between w-full">
                                    <div class="flex items-center gap-2">
                                        <span>Tasks</span>
                                        @if ($failedCount > 0)
                                            <x-badge class="badge-xs badge-error">{{ $failedCount }} failed</x-badge>
                                        @endif
                                        @if ($pendingCount > 0)
                                            <x-badge class="badge-xs badge-warning">{{ $pendingCount }} pending</x-badge>
                                        @endif
                                        @if ($executedCount > 0 && $failedCount === 0 && $pendingCount === 0)
                                            <x-badge class="badge-xs badge-ghost">{{ $executedCount }} executed</x-badge>
                                        @endif
                                    </div>
                                    <button
                                        type="button"
                                        wire:click.stop="$dispatch('tasks-rerun-initiated')"
                                        class="btn btn-xs btn-ghost"
                                        title="Re-run all tasks">
                                        <x-icon name="fas.rotate" class="w-3 h-3" />
                                    </button>
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <livewire:task-execution-section :model="$block" :key="'tasks-block-' . $block->id" />
                            </x-slot:content>
                        </x-collapse>
                    @else
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/80 mb-3">Tasks</h3>
                        <x-skeleton-loader type="list-item" :count="2" />
                    @endif
                </div>

                <!-- Comment -->
                <div class="pb-4 border-b border-base-200">
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/80 mb-3">
                        Comment
                    </h3>
                    <x-form wire:submit="addComment">
                        <x-textarea wire:model="comment" rows="2" placeholder="Add a comment..." />
                        <div class="mt-2 flex justify-end">
                            <x-button type="submit" class="btn-primary btn-sm" label="Post" />
                        </div>
                    </x-form>
                </div>

                <!-- Activity Timeline -->
                <x-collapse wire:model="activityOpen">
                    <x-slot:heading>
                        <div class="text-sm font-semibold uppercase tracking-wider text-base-content/80">
                            Activity
                        </div>
                    </x-slot:heading>
                    <x-slot:content>
                        @if (!$drawerContentLoaded || !$activitiesLoaded)
                        <x-skeleton-loader type="list-item" :count="3" />
                        @else
                        @php $activities = $this->getActivities(); @endphp
                        @if ($activities->isEmpty())
                        <x-empty-state
                            icon="o-clock"
                            message="No activity yet"
                            actionEvent="addComment"
                            actionLabel="Add Comment" />
                        @else
                        @php
                        $activities = $this->getActivities();
                        $timeline = collect();
                        if ($this->block?->created_at) {
                        $timeline->push((object) [
                        '__synthetic' => true,
                        'event' => 'created',
                        'created_at' => $this->block->created_at,
                        'properties' => [],
                        'description' => '',
                        ]);
                        }
                        foreach ($activities as $a) { $timeline->push($a); }
                        $timeline = $timeline->sortByDesc(fn($a) => $a->created_at)->values();
                        @endphp
                        @foreach ($timeline as $activity)
                        @php
                        $modelLabel = 'Block';
                        $event = strtolower((string) ($activity->event ?? ($activity->description ?? '')));
                        $title = in_array($event, ['created','updated','deleted','restored'])
                        ? $modelLabel . ' ' . ucfirst($event)
                        : ($event === 'comment' ? 'Comment' : ucfirst($event));
                        $subtitle = $activity->created_at?->format('d/m/Y H:i');
                        $props = is_array($activity->properties ?? null) ? $activity->properties : (object) ($activity->properties ?? []);
                        $changes = [];
                        $new = $props['attributes'] ?? [];
                        $old = $props['old'] ?? [];
                        foreach ($new as $k => $v) {
                        if ($k === 'updated_at') { continue; }
                        $before = $old[$k] ?? null;
                        $after = $v;
                        $changes[] = $k . ': ' . (is_scalar($before) ? (string) $before : json_encode($before)) . ' → ' . (is_scalar($after) ? (string) $after : json_encode($after));
                        }
                        if (($props['comment'] ?? null) !== null) {
                        $desc = (string) $props['comment'];
                        } else {
                        $desc = '';
                        }
                        @endphp
                        <x-timeline-item title="{{ $title }}" subtitle="{{ $subtitle }}" description="{{ $desc }}" />
                        @if (!empty($new) || !empty($old))
                        <div class="mt-2 mb-4">
                            <x-change-details :new="$new" :old="$old" />
                        </div>
                        @endif
                        @endforeach
                        @endif
                        @endif
                    </x-slot:content>
                </x-collapse>

                <!-- Technical Metadata -->
                @php $meta = is_array($this->block->metadata ?? null) ? $this->block->metadata : []; @endphp
                @if (!empty($meta))
                <x-collapse wire:model="blockMetaOpen">
                    <x-slot:heading>
                        <div class="text-sm font-semibold uppercase tracking-wider text-base-content/80 flex items-center justify-between gap-2 w-full">
                            <div>
                                Metadata
                            </div>
                            <script type="application/json" id="block-meta-json-{{ $this->block->id }}">
                                {
                                    !!json_encode($meta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!
                                }
                            </script>
                            <x-button
                                icon="o-clipboard"
                                class="btn-ghost btn-xs"
                                title="Copy JSON"
                                onclick="(function(){ var el=document.getElementById('block-meta-json-{{ $this->block->id }}'); if(!el){return;} var text; try{ text=JSON.stringify(JSON.parse(el.textContent), null, 2);}catch(e){ text=el.textContent; } navigator.clipboard.writeText(text).then(function(){ $wire.notifyCopied('Block metadata'); }); })()" />
                        </div>
                    </x-slot:heading>
                    <x-slot:content>
                        <x-metadata-list :data="$meta" />
                    </x-slot:content>
                </x-collapse>
                @endif
            </div>
        </x-drawer>
    </div>
    @else
    <div class="text-center py-12">
        <x-icon name="o-exclamation-triangle" class="w-16 h-16 text-base-content/70 mx-auto mb-4" />
        <h3 class="text-lg font-medium text-base-content mb-2">Block Not Found</h3>
        <p class="text-base-content/70 mb-6">The requested block could not be found.</p>
        <x-button href="{{ route('events.index') }}" class="btn-primary">
            Back to Events
        </x-button>
    </div>
    @endif

    <!-- Edit Block Modal -->
    <x-modal wire:model="showEditBlockModal" title="Edit Block" subtitle="Update block details" separator>
        <livewire:edit-block :block="$this->block" :key="'edit-block-' . $this->block->id" />
    </x-modal>

    <!-- Manage Relationships Modal -->
    <x-modal wire:model="showManageRelationshipsModal" title="Manage Relationships" subtitle="View and manage connections to other items" separator box-class="[max-width:1024px]">
        <livewire:manage-relationships
            :model-type="get_class($this->block)"
            :model-id="(string) $this->block->id"
            :key="'manage-relationships-block-' . $this->block->id" />
    </x-modal>

    <!-- Add Relationship Modal -->
    <x-modal wire:model="showAddRelationshipModal" title="Add Relationship" subtitle="Create a connection to another item" separator box-class="[max-width:1024px]">
        <livewire:add-relationship
            :from-type="get_class($this->block)"
            :from-id="(string) $this->block->id"
            :key="'add-relationship-block-' . $this->block->id" />
    </x-modal>
</div>