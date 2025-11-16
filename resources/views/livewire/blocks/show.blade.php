<?php

use App\Models\Block;
use App\Models\Event;
use Livewire\Volt\Component;
use function Livewire\Volt\layout;
use App\Integrations\PluginRegistry;
use Spatie\Activitylog\Models\Activity;

layout('components.layouts.app');

new class extends Component {
    public Block $block;
    public bool $showSidebar = false;
    public string $comment = '';
    public bool $activityOpen = true;
    public bool $blockMetaOpen = false;
    public bool $showEditBlockModal = false;
    public bool $showManageRelationshipsModal = false;
    public bool $showAddRelationshipModal = false;

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
    ];

    public function mount(Block $block): void
    {
        $this->block = $block->load(['event', 'relationshipsFrom', 'relationshipsTo']);
    }

    public function toggleSidebar(): void
    {
        $this->showSidebar = ! $this->showSidebar;
    }

    public function getRelationships()
    {
        return $this->block->relationships()->get();
    }

    public function getRelatedBlocks()
    {
        // Find blocks from the same event
        return Block::where('event_id', $this->block->event_id)
            ->where('id', '!=', $this->block->id)
            ->orderBy('time', 'desc')
            ->limit(5)
            ->get();
    }

    public function getActivities()
    {
        return Activity::forSubject($this->block)
            ->latest()
            ->get();
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
            'relationships' => $this->block->relationships->map(function ($rel) {
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

        $this->js("
            const blob = new Blob([" . json_encode($json) . "], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'block-{$this->block->id}-" . now()->format('Y-m-d-His') . ".json';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        ");

        $this->success('Block exported as JSON!');
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
        $this->success($what . ' copied to clipboard!');
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
            'relationshipsTo'
        ]);
    }

    public function closeEditModal(): void
    {
        $this->showEditBlockModal = false;
        $this->showManageRelationshipsModal = false;
        $this->showAddRelationshipModal = false;
    }
};

?>

<div>
    @if ($this->block)
    <div class="space-y-6">
        <!-- Header -->
        <x-header title="Block Details" separator>
            <x-slot:actions>
                <x-button
                    wire:click="toggleSidebar"
                    class="btn-ghost btn-sm"
                    title="{{ $this->showSidebar ? 'Hide details' : 'Show details' }}"
                    aria-label="{{ $this->showSidebar ? 'Hide details' : 'Show details' }}"
                    data-hotkey="d">
                    <x-icon name="{{ $this->showSidebar ? 'o-x-mark' : 'o-adjustments-horizontal' }}" class="w-4 h-4" />
                </x-button>
            </x-slot:actions>
        </x-header>

        <!-- Block Overview Card -->
        <x-card class="bg-base-200 shadow">
            <div class="flex flex-col sm:flex-row items-start gap-4">
                <!-- Block Icon -->
                <div class="flex-shrink-0 self-center sm:self-start">
                    <div class="w-12 h-12 rounded-full bg-base-200 flex items-center justify-center">
                        <x-icon name="{{ $this->getBlockIcon($this->block->block_type, $this->block->event?->service) }}"
                            class="w-6 h-6" />
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
                    <x-metadata-list :data="$meta" />
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
            <div class="border border-base-300 rounded-lg p-4 hover:bg-base-50 transition-colors">
                <a href="{{ route('events.show', $this->block->event->id) }}"
                    class="block hover:text-primary transition-colors">
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 rounded-full bg-base-200 flex items-center justify-center flex-shrink-0 mt-1">
                            <x-icon name="o-bolt" class="w-4 h-4" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2 mb-1">
                                <span class="font-medium">
                                    {{ format_action_title($this->block->event->action) }}
                                    @if (should_display_action_with_object($this->block->event->action, $this->block->event->service))
                                    @if ($this->block->event->target)
                                    <span class="text-base-content/80">{{ ' ' . $this->block->event->target->title }}</span>
                                    @elseif ($this->block->event->actor)
                                    <span class="text-base-content/80">{{ ' ' . $this->block->event->actor->title }}</span>
                                    @endif
                                    @endif
                                </span>
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
                                <x-badge :value="$this->block->event->service" class="badge-xs badge-outline" />
                                @if ($this->block->event->integration)
                                <span>·</span>
                                <x-badge :value="$this->block->event->integration->name" class="badge-xs badge-outline" />
                                @endif
                            </div>
                        </div>
                        <x-icon name="o-chevron-right" class="w-4 h-4 text-base-content/40 flex-shrink-0 mt-1" />
                    </div>
                </a>
            </div>
        </x-card>
        @endif

        <!-- Drawer for Technical Details -->
        <x-drawer wire:model="showSidebar" right title="Block Details" with-close-button separator class="w-11/12 lg:w-1/3">
            <div class="space-y-6">
                <!-- Primary Information (Always Visible) -->
                <div class="pb-4 border-b border-base-200">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/80">Primary Information</h3>
                        <button
                            wire:click="exportAsJson"
                            class="btn btn-ghost btn-xs gap-1"
                            title="Export complete block with event and relationships">
                            <x-icon name="o-arrow-down-tray" class="w-3 h-3" />
                            <span class="hidden sm:inline">Export JSON</span>
                        </button>
                    </div>
                    <dl>
                        <x-metadata-row label="Block ID" :value="$this->block->id" copyable />
                        <x-metadata-row label="Title" :value="$this->block->title" />
                        <x-metadata-row label="Block Type" :value="Str::headline($this->block->block_type)" />
                        @if ($this->block->value)
                            <x-metadata-row label="Value">
                                {!! format_event_value_display($this->block->formatted_value, $this->block->value_unit, $this->block->event?->service, $this->block->block_type, 'block') !!}
                            </x-metadata-row>
                        @endif
                        <x-metadata-row label="Time">
                            {{ to_user_timezone($this->block->time, auth()->user())->format('M j, Y g:i A') }}
                            <span class="text-base-content/60">({{ to_user_timezone($this->block->time, auth()->user())->diffForHumans() }})</span>
                        </x-metadata-row>
                        <x-metadata-row label="Created">
                            {{ to_user_timezone($this->block->created_at, auth()->user())->format('M j, Y g:i A') }}
                            <span class="text-base-content/60">({{ to_user_timezone($this->block->created_at, auth()->user())->diffForHumans() }})</span>
                        </x-metadata-row>
                        <x-metadata-row label="Last Updated">
                            {{ to_user_timezone($this->block->updated_at, auth()->user())->format('M j, Y g:i A') }}
                            <span class="text-base-content/60">({{ to_user_timezone($this->block->updated_at, auth()->user())->diffForHumans() }})</span>
                        </x-metadata-row>
                        @if ($this->block->url)
                            <x-metadata-row label="URL">
                                <a href="{{ $this->block->url }}" target="_blank" class="link link-primary text-sm truncate max-w-full block">
                                    {{ $this->block->url }}
                                </a>
                            </x-metadata-row>
                        @endif
                        @if ($this->block->media_url)
                            <x-metadata-row label="Media URL">
                                <a href="{{ $this->block->media_url }}" target="_blank" class="link link-primary text-sm truncate max-w-full block">
                                    {{ $this->block->media_url }}
                                </a>
                            </x-metadata-row>
                        @endif
                        @if ($this->block->event)
                            <x-metadata-row label="Related Event">
                                <a href="{{ route('events.show', $this->block->event->id) }}" class="link link-primary text-sm">
                                    {{ format_action_title($this->block->event->action) }}
                                </a>
                            </x-metadata-row>
                        @endif
                    </dl>
                </div>

                <!-- Relationships -->
                <x-card class="bg-base-100 shadow">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/80 flex items-center gap-2">
                            <x-icon name="o-arrows-right-left" class="w-4 h-4" />
                            Relationships
                        </h3>
                        <button type="button" wire:click="handleOpenManageRelationshipsModal" class="btn btn-xs btn-outline" title="Manage relationships" data-hotkey="r">
                            <x-icon name="o-plus" class="w-3 h-3" />
                        </button>
                    </div>
                    @php $sidebarRelationships = $this->getRelationships(); @endphp
                    @if ($sidebarRelationships->isEmpty())
                    <div class="text-center py-4 text-base-content/60 text-sm">
                        No relationships yet
                    </div>
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
                            <x-icon name="{{ \App\Services\RelationshipTypeRegistry::getIcon($relationship->type) }}" class="w-3 h-3 text-accent flex-shrink-0" />
                            <x-icon name="{{ $icon }}" class="w-3 h-3 flex-shrink-0" />
                            <span class="text-sm truncate flex-1">{{ $title }}</span>
                        </a>
                        @endforeach
                    </div>
                    @if ($sidebarRelationships->count() > 10)
                    <div class="text-center mt-2">
                        <button wire:click="handleOpenManageRelationshipsModal" class="text-xs text-accent hover:underline">
                            View all {{ $sidebarRelationships->count() }}
                        </button>
                    </div>
                    @endif
                    @endif
                </x-card>

                <!-- Activity Timeline -->
                <x-collapse wire:model="activityOpen">
                    <x-slot:heading>
                        <div class="text-sm font-semibold uppercase tracking-wider text-base-content/80 flex items-center gap-2">
                            <x-icon name="o-clock" class="w-4 h-4" />
                            Activity
                        </div>
                    </x-slot:heading>
                    <x-slot:content>
                        @php $activities = $this->getActivities(); @endphp
                        @if ($activities->isEmpty())
                        <div class="text-sm text-base-content/70">No activity yet.</div>
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
                    </x-slot:content>
                </x-collapse>

                <!-- Add Comment -->
                <x-card class="bg-base-100 shadow">
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/80 mb-4 flex items-center gap-2">
                        <x-icon name="o-chat-bubble-left" class="w-4 h-4" />
                        Comment
                    </h3>
                    <x-form wire:submit="addComment">
                        <x-textarea wire:model="comment" rows="2" placeholder="Add a comment..." />
                        <div class="mt-3 flex justify-end">
                            <x-button type="submit" class="btn-primary btn-sm" label="Post" />
                        </div>
                    </x-form>
                </x-card>

                <!-- Technical Metadata -->
                @php $meta = is_array($this->block->metadata ?? null) ? $this->block->metadata : []; @endphp
                @if (!empty($meta))
                <x-collapse wire:model="blockMetaOpen">
                    <x-slot:heading>
                        <div class="text-sm font-semibold uppercase tracking-wider text-base-content/80 flex items-center justify-between gap-2 w-full">
                            <div class="flex items-center gap-2">
                                <x-icon name="o-cog-6-tooth" class="w-4 h-4" />
                                Technical Metadata
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

        <!-- Linked Blocks -->
        @if ($this->getRelatedBlocks()->isNotEmpty())
        <x-card class="bg-base-200 shadow">
            <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                <x-icon name="o-squares-2x2" class="w-5 h-5" />
                Linked Blocks ({{ $this->getRelatedBlocks()->count() }})
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($this->getRelatedBlocks() as $relatedBlock)
                <div class="border border-base-300 bg-base-100 rounded-lg p-3 hover:bg-base-50 transition-colors">
                    <div class="flex items-start justify-between gap-3 mb-2">
                        <a href="{{ route('blocks.show', $relatedBlock->id) }}"
                            class="font-semibold text-base-content hover:text-primary transition-colors text-base flex-1">
                            {{ $relatedBlock->title }}
                        </a>
                        @if ($relatedBlock->value)
                        <span class="text-lg font-bold flex-shrink-0">{!! format_event_value_display($relatedBlock->formatted_value, $relatedBlock->value_unit, $this->block->event?->service, $relatedBlock->block_type, 'block') !!}</span>
                        @endif
                    </div>
                    @php $relMeta = is_array($relatedBlock->metadata ?? null) ? $relatedBlock->metadata : []; @endphp
                    @if (!empty($relMeta))
                    <div class="mb-2">
                        <x-metadata-list :data="$relMeta" />
                    </div>
                    @endif
                    <div class="flex items-center gap-2 text-xs text-base-content/60">
                        @if ($relatedBlock->time)
                        <div class="flex items-center gap-1">
                            <x-icon name="o-clock" class="w-3 h-3" />
                            {{ to_user_timezone($relatedBlock->time, auth()->user())->format('H:i') }}
                        </div>
                        @endif
                        @if ($relatedBlock->url)
                        <div class="flex items-center gap-1">
                            <x-icon name="o-link" class="w-3 h-3" />
                            <a href="{{ $relatedBlock->url }}" target="_blank" class="hover:underline">
                                View
                            </a>
                        </div>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        </x-card>
        @endif

        <!-- Relationships -->
        @php $relationships = $this->getRelationships(); @endphp
        @if ($relationships->isNotEmpty())
        <x-card class="bg-base-200/50 border-2 border-accent/10">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold flex items-center gap-2">
                    <x-icon name="o-arrows-right-left" class="w-5 h-5 text-accent" />
                    Relationships ({{ $relationships->count() }})
                </h3>
                <x-button
                    icon="o-cog-6-tooth"
                    class="btn-sm btn-ghost"
                    wire:click="handleOpenManageRelationshipsModal"
                    label="Manage" />
            </div>

            <div class="space-y-3">
                @foreach ($relationships->take(5) as $relationship)
                @php
                // Determine if this block is "from" or "to" in the relationship
                $isFrom = $relationship->from_type === get_class($block) && $relationship->from_id === $block->id;
                $relatedModel = $isFrom ? $relationship->to : $relationship->from;
                $direction = $isFrom ? '→' : '←';

                // Get display info for related model
                // Initialize defaults
                $icon = 'o-question-mark-circle';
                $title = 'Unknown';
                $subtitle = null;
                $route = '#';
                $badgeText = 'Unknown';
                $badgeClass = 'badge-ghost';

                if ($relatedModel instanceof \App\Models\Event) {
                $icon = 'o-calendar';
                $title = $relatedModel->action;
                $subtitle = $relatedModel->time?->format('M j, Y g:i A');
                $route = route('events.show', $relatedModel);
                $badgeText = 'Event';
                $badgeClass = 'badge-primary';
                } elseif ($relatedModel instanceof \App\Models\EventObject) {
                $icon = 'o-cube';
                $title = $relatedModel->title;
                $subtitle = $relatedModel->concept;
                $route = route('objects.show', $relatedModel);
                $badgeText = 'Object';
                $badgeClass = 'badge-secondary';
                } elseif ($relatedModel instanceof \App\Models\Block) {
                $icon = 'o-squares-2x2';
                $title = $relatedModel->type;
                $subtitle = $relatedModel->time?->format('M j, Y');
                $route = route('blocks.show', $relatedModel);
                $badgeText = 'Block';
                $badgeClass = 'badge-accent';
                }
                @endphp

                <div class="flex items-center gap-2 p-3 rounded-lg bg-base-100">
                    <!-- Relationship Type Icon -->
                    <div class="tooltip" data-tip="{{ \App\Services\RelationshipTypeRegistry::getDisplayName($relationship->type) }}">
                        <x-icon name="{{ \App\Services\RelationshipTypeRegistry::getIcon($relationship->type) }}" class="w-4 h-4 text-accent" />
                    </div>

                    <!-- Direction -->
                    @if (\App\Services\RelationshipTypeRegistry::isDirectional($relationship->type))
                    <span class="text-base-content/40 text-sm">{{ $direction }}</span>
                    @else
                    <span class="text-base-content/40 text-sm">↔</span>
                    @endif

                    <!-- Related Entity -->
                    <a href="{{ $route }}" class="flex items-center gap-2 flex-1 min-w-0 hover:text-accent transition-colors">
                        <x-icon name="{{ $icon }}" class="w-4 h-4 flex-shrink-0" />
                        <div class="min-w-0 flex-1">
                            <div class="font-medium truncate text-sm">{{ $title }}</div>
                            @if ($subtitle)
                            <div class="text-xs text-base-content/60 truncate">{{ $subtitle }}</div>
                            @endif
                        </div>
                    </a>

                    <!-- Badge -->
                    <span class="badge {{ $badgeClass }} badge-xs">{{ $badgeText }}</span>

                    <!-- Value (if present) -->
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
        @endif
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