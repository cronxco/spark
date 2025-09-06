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

    public function mount(Block $block): void
    {
        $this->block = $block->load(['event']);
    }

    public function toggleSidebar(): void
    {
        $this->showSidebar = ! $this->showSidebar;
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
                        >
                            <x-icon name="{{ $this->showSidebar ? 'o-x-mark' : 'o-adjustments-horizontal' }}" class="w-4 h-4" />
                    </x-button>
                </x-slot:actions>
            </x-header>

            <!-- Block Overview Card -->
            <x-card>
                <div class="flex flex-col sm:flex-row items-start gap-4">
                    <!-- Block Icon -->
                    <div class="flex-shrink-0 self-center sm:self-start">
                        <div class="w-12 h-12 rounded-full bg-info/10 flex items-center justify-center">
                            <x-icon name="{{ $this->getBlockIcon($this->block->block_type, $this->block->event?->service) }}"
                                   class="w-6 h-6 text-info" />
                        </div>
                    </div>

                    <!-- Block Info -->
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-3">
                            <h2 class="text-xl font-semibold text-base-content">
                                {{ $this->block->title }}
                            </h2>
                            @if ($this->block->value)
                                <x-badge :value="$this->block->formatted_value . ($this->block->value_unit ? ' ' . $this->block->value_unit : '')" class="badge-info" />
                            @endif
                        </div>

                        @php $meta = is_array($this->block->metadata ?? null) ? $this->block->metadata : []; @endphp
                        @if (!empty($meta))
                            <x-metadata-list :data="$meta" />
                        @endif

                        <!-- Block Metadata -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 lg:gap-4 text-sm">
                            @if ($this->block->time)
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-clock" class="w-4 h-4 text-base-content/60" />
                                    <span class="text-base-content/70">Time:</span>
                                    <span class="font-medium">{{ $this->block->time->format('F j, Y g:i A') }}</span>
                                </div>
                            @endif
                            @if ($this->block->url)
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-link" class="w-4 h-4 text-base-content/60" />
                                    <span class="text-base-content/70">URL:</span>
                                    <a href="{{ $this->block->url }}" target="_blank" class="font-medium text-primary hover:underline">
                                        View
                                    </a>
                                </div>
                            @endif
                            @if ($this->block->media_url)
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-photo" class="w-4 h-4 text-base-content/60" />
                                    <span class="text-base-content/70">Media:</span>
                                    <a href="{{ $this->block->media_url }}" target="_blank" class="font-medium text-primary hover:underline">
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
                <x-card>
                    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                        <x-icon name="o-bolt" class="w-5 h-5 text-primary" />
                        Related Event
                    </h3>
                    <div class="border border-base-300 rounded-lg p-4 hover:bg-base-50 transition-colors">
                        <a href="{{ route('events.show', $this->block->event->id) }}"
                           class="block hover:text-primary transition-colors">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                                    <x-icon name="o-bolt" class="w-4 h-4 text-primary" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-medium">{{ format_action_title($this->block->event->action) }}</span>
                                        <x-badge :value="$this->block->event->service" class="badge-xs" />
                                        @if ($this->block->event->domain)
                                            <x-badge :value="$this->block->event->domain" class="badge-xs badge-outline" />
                                        @endif
                                    </div>
                                    <div class="text-sm text-base-content/70">
                                        {{ $this->block->event->time->format('M j, Y g:i A') }}
                                    </div>
                                    @if ($this->block->event->value)
                                        <div class="text-xs text-base-content/60 mt-1">
                                            Value: {{ $this->block->event->formatted_value }}
                                            @if ($this->block->event->value_unit)
                                                {{ $this->block->event->value_unit }}
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                <x-icon name="o-chevron-right" class="w-4 h-4 text-base-content/40" />
                            </div>
                        </a>
                    </div>
                </x-card>
            @endif

            <!-- Drawer for Technical Details -->
            <x-drawer wire:model="showSidebar" right title="Block Details" with-close-button separator class="w-11/12 lg:w-1/3">
                <div class="space-y-4 lg:space-y-6">
                    @php $meta = is_array($this->block->metadata ?? null) ? $this->block->metadata : []; @endphp
                    @if (!empty($meta))
                        <x-collapse wire:model="blockMetaOpen">
                            <x-slot:heading>
                                <div class="text-lg font-semibold text-base-content flex items-center gap-2">
                                    <x-icon name="o-cog-6-tooth" class="w-5 h-5 text-info" />
                                    Block Metadata
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <div class="bg-base-200 rounded-lg p-3">
                                    <pre class="text-xs text-base-content/80 whitespace-pre-wrap overflow-x-auto">{{ $this->formatJson($meta) }}</pre>
                                </div>
                            </x-slot:content>
                        </x-collapse>
                    @endif

                    <!-- Activity Timeline -->
                    <x-collapse wire:model="activityOpen">
                        <x-slot:heading>
                            <div class="text-lg font-semibold text-base-content flex items-center gap-2">
                                <x-icon name="o-clock" class="w-5 h-5 text-primary" />
                                Activity
                            </div>
                        </x-slot:heading>
                        <x-slot:content>
                        @php $activities = $this->getActivities(); @endphp
                        @if($activities->isEmpty())
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
                            @foreach($timeline as $activity)
                                @php
                                    $modelLabel = 'Block';
                                    $event = strtolower((string) ($activity->event ?? ($activity->description ?? '')));
                                    $title = in_array($event, ['created','updated','deleted','restored'])
                                        ? $modelLabel . ' ' . ucfirst($event)
                                        : ($event === 'comment' ? 'Comment' : ucfirst($event));
                                    $subtitle = $activity->created_at?->format('M j, Y g:i A');
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
                                    } elseif (!empty($changes)) {
                                        $desc = implode(', ', $changes);
                                    } else {
                                        $desc = '';
                                    }
                                @endphp
                                <x-timeline-item title="{{ $title }}" subtitle="{{ $subtitle }}" description="{{ $desc }}" />
                            @endforeach
                        @endif
                        </x-slot:content>
                    </x-collapse>

                    <!-- Add Comment -->
                    <x-card>
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="o-chat-bubble-left" class="w-5 h-5 text-primary" />
                            Comment
                        </h3>
                        <x-form wire:submit="addComment">
                            <x-textarea wire:model="comment" rows="3" placeholder="Add a comment..." />
                            <div class="mt-3 flex justify-end">
                                <x-button type="submit" class="btn-primary btn-sm" label="Post" />
                            </div>
                        </x-form>
                    </x-card>
                </div>
            </x-drawer>

            <!-- Related Blocks -->
                                    @if ($this->getRelatedBlocks()->isNotEmpty())
                <x-card>
                                            <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="o-squares-2x2" class="w-5 h-5 text-info" />
                            Other Blocks from Same Event ({{ $this->getRelatedBlocks()->count() }})
                        </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach ($this->getRelatedBlocks() as $relatedBlock)
                            <div class="border border-base-300 rounded-lg p-3 hover:bg-base-50 transition-colors">
                                <div class="flex items-start justify-between mb-2">
                                    <a href="{{ route('blocks.show', $relatedBlock->id) }}"
                                       class="font-medium text-base-content hover:text-primary transition-colors text-sm">
                                        {{ $relatedBlock->title }}
                                    </a>
                                    @if ($relatedBlock->value)
                                        <x-badge :value="$relatedBlock->formatted_value . ($relatedBlock->value_unit ? ' ' . $relatedBlock->value_unit : '')" class="badge-xs" />
                                    @endif
                                </div>
                                @php $relMeta = is_array($relatedBlock->metadata ?? null) ? $relatedBlock->metadata : []; @endphp
                                @if (!empty($relMeta))
                                    <div class="text-xs text-base-content/70 mb-2 line-clamp-3">
                                        <x-metadata-list :data="$relMeta" />
                                    </div>
                                @endif
                                <div class="flex items-center gap-2 text-xs text-base-content/60">
                                    @if ($relatedBlock->time)
                                        <div class="flex items-center gap-1">
                                            <x-icon name="o-clock" class="w-3 h-3" />
                                            {{ $relatedBlock->time->format('g:i A') }}
                                        </div>
                                    @endif
                                    @if ($relatedBlock->url)
                                        <div class="flex items-center gap-1">
                                            <x-icon name="o-link" class="w-3 h-3" />
                                            <a href="{{ $relatedBlock->url }}" target="_blank" class="text-primary hover:underline">
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
        </div>
    @else
        <div class="text-center py-8">
            <x-icon name="o-exclamation-triangle" class="w-12 h-12 text-warning mx-auto mb-4" />
            <h3 class="text-lg font-semibold text-base-content mb-2">Block Not Found</h3>
            <p class="text-base-content/70">The requested block could not be found.</p>
            <x-button href="{{ route('events.index') }}" class="mt-4">
                Back to Events
            </x-button>
        </div>
    @endif
</div>
