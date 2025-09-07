<?php

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Block;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use App\Integrations\PluginRegistry;
use Spatie\Activitylog\Models\Activity;
use Spatie\Tags\Tag;
use Illuminate\Support\Facades\Log;

new class extends Component {
    public Event $event;
    public bool $showSidebar = false;
    public string $comment = '';
    public bool $activityOpen = true;
    public bool $actorOpen = true;
    public bool $targetOpen = true;
    public bool $eventMetaOpen = false;
    public bool $actorMetaOpen = false;
    public bool $targetMetaOpen = false;

    public function mount(Event $event): void
    {
        $this->event = $event->load([
            'actor',
            'target',
            'integration',
            'blocks',
            'tags',
            'actor.tags',
            'target.tags'
        ]);
    }

    public function getRelatedEvents()
    {
        // Find events that share the same actor or target
        return Event::with(['actor', 'target', 'integration', 'tags'])
            ->whereHas('integration', function ($q) {
                $userId = optional(auth()->guard('web')->user())->id;
                if ($userId) {
                    $q->where('user_id', $userId);
                } else {
                    $q->whereRaw('1 = 0');
                }
            })
            ->where(function ($q) {
                if ($this->event->actor_id) {
                    $q->orWhere('actor_id', $this->event->actor_id);
                }
                if ($this->event->target_id) {
                    $q->orWhere('target_id', $this->event->target_id);
                }
            })
            ->where('id', '!=', $this->event->id)
            ->orderBy('time', 'desc')
            ->limit(5)
            ->get();
    }

    public function getActivities()
    {
        return Activity::forSubject($this->event)
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
            ->performedOn($this->event)
            ->causedBy(auth()->guard('web')->user())
            ->event('comment')
            ->withProperties(['comment' => $text])
            ->log('comment');

        $this->comment = '';
    }

    public function formatAction($action)
    {
        return format_action_title($action);
    }

    public function formatJson($data)
    {
        if (is_array($data) || is_object($data)) {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        return $data;
    }

    public function getEventIcon($action, $service)
    {
        // Try to get icon from plugin configuration first
        $pluginClass = PluginRegistry::getPlugin($service);
        if ($pluginClass) {
            $actionTypes = $pluginClass::getActionTypes();
            if (isset($actionTypes[$action]) && isset($actionTypes[$action]['icon'])) {
                return $actionTypes[$action]['icon'];
            }
        }

        // Fallback to hardcoded icons if plugin doesn't have this action type
        $icons = [
            'create' => 'o-plus-circle',
            'update' => 'o-arrow-path',
            'delete' => 'o-trash',
            'move' => 'o-arrow-right',
            'copy' => 'o-document-duplicate',
            'share' => 'o-share',
            'like' => 'o-heart',
            'comment' => 'o-chat-bubble-left',
            'follow' => 'o-user-plus',
            'unfollow' => 'o-user-minus',
            'join' => 'o-user-group',
            'leave' => 'o-user-group',
            'start' => 'o-play',
            'stop' => 'o-stop',
            'pause' => 'o-pause',
            'resume' => 'o-play',
            'complete' => 'o-check-circle',
            'fail' => 'o-x-circle',
            'cancel' => 'o-x-mark',
            'approve' => 'o-check',
            'reject' => 'o-x-mark',
            'publish' => 'o-globe-alt',
            'unpublish' => 'o-eye-slash',
            'archive' => 'o-archive-box',
            'restore' => 'o-arrow-path',
            'login' => 'o-arrow-right-on-rectangle',
            'logout' => 'o-arrow-left-on-rectangle',
            'purchase' => 'o-shopping-cart',
            'refund' => 'o-arrow-path',
            'transfer' => 'o-arrow-right',
            'withdraw' => 'o-arrow-down',
            'deposit' => 'o-arrow-up',
        ];

        return $icons[strtolower($action)] ?? 'o-bolt';
    }

    public function getEventColor($action)
    {
        $colors = [
            'create' => 'text-success',
            'update' => 'text-info',
            'delete' => 'text-error',
            'move' => 'text-warning',
            'copy' => 'text-info',
            'share' => 'text-primary',
            'like' => 'text-error',
            'comment' => 'text-info',
            'follow' => 'text-success',
            'unfollow' => 'text-warning',
            'join' => 'text-success',
            'leave' => 'text-warning',
            'start' => 'text-success',
            'stop' => 'text-error',
            'pause' => 'text-warning',
            'resume' => 'text-success',
            'complete' => 'text-success',
            'fail' => 'text-error',
            'cancel' => 'text-warning',
            'approve' => 'text-success',
            'reject' => 'text-error',
            'publish' => 'text-success',
            'unpublish' => 'text-warning',
            'archive' => 'text-neutral',
            'restore' => 'text-info',
            'login' => 'text-success',
            'logout' => 'text-warning',
            'purchase' => 'text-success',
            'refund' => 'text-info',
            'transfer' => 'text-warning',
            'withdraw' => 'text-error',
            'deposit' => 'text-success',
        ];

        return $colors[strtolower($action)] ?? 'text-primary';
    }

    public function toggleSidebar()
    {
        $this->showSidebar = !$this->showSidebar;
    }

    public function addTag(string $value): void
    {
        $name = trim((string) $value);
        if ($name === '') {
            return;
        }

        if (str_starts_with($name, 'tag-whitelist-') || str_starts_with($name, 'tag-initial-')) {
            return;
        }

        $tag = Tag::findOrCreate($name);
        $this->event->attachTag($tag);
        $this->event->refresh()->loadMissing('tags');
        Log::info('Tag added to event', ['event_id' => (string) $this->event->id, 'tag' => $name, 'tags_now' => $this->event->tags->pluck('name')->all()]);
    }

    public function removeTag(string $value): void
    {
        $name = trim((string) $value);
        if ($name === '') {
            return;
        }

        if (str_starts_with($name, 'tag-whitelist-') || str_starts_with($name, 'tag-initial-')) {
            return;
        }

        $this->event->detachTag($name);
        $this->event->refresh()->loadMissing('tags');
        Log::info('Tag removed from event', ['event_id' => (string) $this->event->id, 'tag' => $name, 'tags_now' => $this->event->tags->pluck('name')->all()]);
    }
};
?>

<div>
    @if ($this->event)
        <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
            <!-- Main Content Area -->
            <div class="flex-1 space-y-4 lg:space-y-6">
                <!-- Header -->
                <x-header title="Event Details" separator>
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

                <!-- Primary Event Information -->
                <x-card>
                    <div class="flex flex-col sm:flex-row items-start gap-4 lg:gap-6">
                        <!-- Event Icon & Action -->
                        <div class="flex-shrink-0 self-center sm:self-start">
                            <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-primary/10 flex items-center justify-center">
                                <x-icon name="{{ $this->getEventIcon($this->event->action, $this->event->service) }}"
                                       class="w-6 h-6 sm:w-8 sm:h-8 {{ $this->getEventColor($this->event->action) }}" />
                            </div>
                        </div>

                        <!-- Event Details -->
                        <div class="flex-1">
                            <div class="mb-4 text-center sm:text-left">
                                <h2 class="text-xl sm:text-2xl lg:text-3xl font-bold text-base-content mb-2 leading-tight">
                                    {{ $this->formatAction($this->event->action) }}
                                    @if ($this->event->target)
                                            {{ $this->event->target->title }}
                                    @elseif ($this->event->actor)
                                        {{ $this->event->actor->title }}
                                    @endif
                                </h2>

                                @if ($this->event->value)
                                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold text-primary">
                                        {{ $this->event->formatted_value }}{{ $this->event->value_unit ? ' ' . $this->event->value_unit : '' }}
                                    </div>
                                @endif
                            </div>

                            <!-- Key Metadata -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 lg:gap-4 text-sm">
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-clock" class="w-4 h-4 text-base-content/60 flex-shrink-0" />
                                    <span class="text-base-content/70">{{ $this->event->time->format('M j, Y g:i A') }}</span>
                                </div>
                                @if ($this->event->integration)
                                    <div class="flex items-center gap-2">
                                        <x-icon name="o-link" class="w-4 h-4 text-base-content/60 flex-shrink-0" />
                                        <span class="text-base-content/70 truncate">{{ $this->event->integration->name }}</span>
                                    </div>
                                @endif
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-bolt" class="w-4 h-4 text-base-content/60 flex-shrink-0" />
                                    <span class="text-base-content/70 truncate">{{ $this->event->service }}</span>
                                </div>
                                @if ($this->event->domain)
                                    <div class="flex items-center gap-2">
                                        <x-icon name="o-globe-alt" class="w-4 h-4 text-base-content/60 flex-shrink-0" />
                                        <span class="text-base-content/70 truncate">{{ $this->event->domain }}</span>
                                    </div>
                                @endif
                            </div>

                            <!-- Actor & Target Flow -->
                            @if ($this->event->actor || $this->event->target)
                                <div class="mt-4 lg:mt-6 p-3 lg:p-4 bg-base-100 rounded-lg border border-base-300">
                                    <div class="flex flex-col sm:flex-row items-center justify-center gap-3 lg:gap-4">
                                        @if ($this->event->actor)
                                            <div class="flex items-center gap-2">
                                                <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-secondary/10 flex items-center justify-center">
                                                    <x-icon name="o-user" class="w-4 h-4 sm:w-5 sm:h-5 text-secondary" />
                                                </div>
                                                <a href="{{ route('objects.show', $this->event->actor->id) }}"
                                                   class="font-medium text-secondary hover:underline text-sm sm:text-base">
                                                    {{ $this->event->actor->title }}
                                                </a>
                                            </div>
                                        @endif

                                        @if ($this->event->actor && $this->event->target)
                                            <x-icon name="o-arrow-down" class="w-5 h-5 sm:w-6 sm:h-6 text-base-content/40 sm:hidden" />
                                            <x-icon name="o-arrow-right" class="w-5 h-5 sm:w-6 sm:h-6 text-base-content/40 hidden sm:block" />
                                        @endif

                                        @if ($this->event->target)
                                            <div class="flex items-center gap-2">
                                                <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-accent/10 flex items-center justify-center">
                                                    <x-icon name="o-arrow-trending-up" class="w-4 h-4 sm:w-5 sm:h-5 text-accent" />
                                                </div>
                                                <a href="{{ route('objects.show', $this->event->target->id) }}"
                                                   class="font-medium text-accent hover:underline text-sm sm:text-base">
                                                    {{ $this->event->target->title }}
                                                </a>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <!-- Tags -->
                            @if ($this->event->tags->isNotEmpty())
                                <div class="mt-4">
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($this->event->tags as $tag)
                                            <x-badge :value="$tag->name" class="badge-sm" />
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </x-card>

                <!-- Blocks - Compact Grid View -->
                @if ($this->event->blocks->isNotEmpty())
                    <x-card>
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="o-squares-2x2" class="w-5 h-5 text-info" />
                            Related Blocks ({{ $this->event->blocks->count() }})
                        </h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            @foreach ($this->event->blocks as $block)
                                <div class="border border-base-300 rounded-lg p-3 hover:bg-base-50 transition-colors">
                                    <div class="flex items-start justify-between mb-2">
                                        <a href="{{ route('blocks.show', $block->id) }}"
                                           class="font-semibold text-base-content hover:text-primary transition-colors text-base">
                                            {{ $block->title }}
                                        </a>
                                        @if ($block->value)
                                            <x-badge :value="$block->formatted_value . ($block->value_unit ? ' ' . $block->value_unit : '')" class="badge-xs" />
                                        @endif
                                    </div>

                                    @php $meta = is_array($block->metadata ?? null) ? $block->metadata : []; @endphp
                                    @if (!empty($meta))
                                        <div class="mb-2">
                                            <x-metadata-list :data="$meta" />
                                        </div>
                                    @endif

                                    <div class="flex items-center gap-2 text-xs text-base-content/60">
                                        @if ($block->time)
                                            <div class="flex items-center gap-1">
                                                <x-icon name="o-clock" class="w-3 h-3" />
                                                {{ $block->time->format('g:i A') }}
                                            </div>
                                        @endif
                                        @if ($block->url)
                                            <div class="flex items-center gap-1">
                                                <x-icon name="o-link" class="w-3 h-3" />
                                                <a href="{{ $block->url }}" target="_blank" class="text-primary hover:underline">
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

                <!-- Related Events -->
                @if ($this->getRelatedEvents()->isNotEmpty())
                    <x-card>
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="o-arrow-path" class="w-5 h-5 text-warning" />
                            Related Events
                        </h3>
                        <div class="space-y-3">
                            @foreach ($this->getRelatedEvents() as $relatedEvent)
                                <div class="border border-base-300 rounded-lg p-3 hover:bg-base-50 transition-colors">
                                    <a href="{{ route('events.show', $relatedEvent->id) }}"
                                       class="block hover:text-primary transition-colors">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                                                <x-icon name="{{ $this->getEventIcon($relatedEvent->action, $relatedEvent->service) }}"
                                                       class="w-4 h-4 {{ $this->getEventColor($relatedEvent->action) }}" />
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="mb-1">
                                                    <span class="font-medium">
                                                        {{ $this->formatAction($relatedEvent->action) }}
                                                        @if ($relatedEvent->target)
                                                            {{ $relatedEvent->target->title }}
                                                        @elseif ($relatedEvent->actor)
                                                            {{ $relatedEvent->actor->title }}
                                                        @endif
                                                        @if ($relatedEvent->value)
                                                            <span class="text-primary">
                                                                ({{ $relatedEvent->formatted_value }}{{ $relatedEvent->value_unit ? ' ' . $relatedEvent->value_unit : '' }})
                                                            </span>
                                                        @endif
                                                    </span>
                                                </div>
                                                <div class="text-sm text-base-content/70">
                                                    {{ $relatedEvent->time->format('M j, Y g:i A') }}
                                                </div>
                                            </div>
                                            <x-icon name="o-chevron-right" class="w-4 h-4 text-base-content/40" />
                                        </div>
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    </x-card>
                @endif
            </div>

            <!-- Drawer for Technical Details -->
            <x-drawer wire:model="showSidebar" right title="Event Details" separator with-close-button class="w-11/12 lg:w-1/3">
                <div class="space-y-4 lg:space-y-6">
                    <!-- Tags Manager -->
                    <x-card class="mb-0 !p-2">
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="o-tag" class="w-5 h-5 text-primary" />
                            Tags
                        </h3>
                        <div class="space-y-2" wire:key="event-tags-{{ $this->event->id }}" wire:ignore>
                            <input id="tag-input-{{ $this->event->id }}" data-tagify data-initial="tag-initial-{{ $this->event->id }}" data-suggestions-id="tag-suggestions-{{ $this->event->id }}" aria-label="Tags" class="input input-sm w-full" placeholder="Add tags" />
                            <script type="application/json" id="tag-initial-{{ $this->event->id }}">{!! json_encode($this->event->tags->pluck('name')->values()->all()) !!}</script>
                            <script type="application/json" id="tag-suggestions-{{ $this->event->id }}">{!! json_encode(\Spatie\Tags\Tag::query()->pluck('name')->map(fn($n)=>(string)$n)->unique()->values()->all()) !!}</script>
                        </div>
                    </x-card>
                    <!-- Add Comment -->
                    <x-card class="!p-2">
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="o-chat-bubble-left" class="w-5 h-5 text-primary" />
                            Comment
                        </h3>
                        <x-form wire:submit="addComment">
                            <x-textarea wire:model="comment" rows="2" placeholder="Add a comment..." />
                            <div class="mt-3 flex justify-end">
                                <x-button type="submit" class="btn-primary btn-sm" label="Post" />
                            </div>
                        </x-form>
                    </x-card>
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
                                @php
                                    $activities = $this->getActivities();
                                    // newest first, synth created first as well
                                    $timeline = collect();
                                    if ($this->event?->created_at) {
                                        $timeline->push((object) [
                                            '__synthetic' => true,
                                            'event' => 'created',
                                            'created_at' => $this->event->created_at,
                                            'properties' => [],
                                            'description' => '',
                                        ]);
                                    }
                                    foreach ($activities as $a) { $timeline->push($a); }
                                    // ensure newest first
                                    $timeline = $timeline->sortByDesc(fn($a) => $a->created_at)->values();
                                @endphp
                                @foreach ($timeline as $activity)
                                    @php
                                        $modelLabel = 'Event';
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
                                        } else {
                                            $desc = '';
                                        }
                                    @endphp
                                    <x-timeline-item title="{{ $title }}" subtitle="{{ $subtitle }}" >
                                        <x-slot:description>
                                            @if (!empty($new) || !empty($old))
                                                <div class="mt-2 mb-4">
                                                    <x-change-details :new="$new" :old="$old" />
                                                </div>
                                            @else
                                                {{ $desc }}
                                            @endif
                                        </x-slot:description>
                                    </x-timeline-item>
                                    
                                @endforeach

                        </x-slot:content>
                    </x-collapse>
                    <!-- Actor Details -->
                    @if ($this->event->actor)
                        <x-collapse wire:model="actorOpen">
                            <x-slot:heading>
                                <div class="text-lg font-semibold text-base-content flex items-center gap-2">
                                    <x-icon name="o-user" class="w-5 h-5 text-secondary" />
                                    Actor Details
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <div class="space-y-3 text-sm">
                                <div>
                                    <span class="text-base-content/70">Title:</span>
                                    <div class="font-medium">{{ $this->event->actor->title }}</div>
                                </div>
                                @php $actorText = is_array($this->event->actor->metadata ?? null) ? ($this->event->actor->metadata['text'] ?? null) : null; @endphp
                                @if ($actorText)
                                    <div>
                                        <span class="text-base-content/70">Content:</span>
                                        <div class="font-medium">{{ $actorText }}</div>
                                    </div>
                                @endif
                                @if ($this->event->actor->type)
                                    <div>
                                        <span class="text-base-content/70">Type:</span>
                                        <x-badge :value="$this->event->actor->type" class="badge-sm" />
                                    </div>
                                @endif
                                @if ($this->event->actor->concept)
                                    <div>
                                        <span class="text-base-content/70">Concept:</span>
                                        <x-badge :value="$this->event->actor->concept" class="badge-sm badge-outline" />
                                    </div>
                                @endif
                                @if ($this->event->actor->url)
                                    <div>
                                        <span class="text-base-content/70">URL:</span>
                                        <a href="{{ $this->event->actor->url }}" target="_blank" class="text-primary hover:underline block">
                                            View
                                        </a>
                                    </div>
                                @endif
                                @if ($this->event->actor->tags->isNotEmpty())
                                    <div>
                                        <span class="text-base-content/70">Tags:</span>
                                        <div class="flex flex-wrap gap-1 mt-1">
                                            @foreach ($this->event->actor->tags as $tag)
                                                <x-badge :value="$tag->name" class="badge-xs" />
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                                </div>
                            </x-slot:content>
                        </x-collapse>
                    @endif

                    <!-- Target Details -->
                    @if ($this->event->target)
                        <x-collapse wire:model="targetOpen">
                            <x-slot:heading>
                                <div class="text-lg font-semibold text-base-content flex items-center gap-2">
                                    <x-icon name="o-arrow-trending-up" class="w-5 h-5 text-accent" />
                                    Target Details
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <div class="space-y-3 text-sm">
                                <div>
                                    <span class="text-base-content/70">Title:</span>
                                    <div class="font-medium">{{ $this->event->target->title }}</div>
                                </div>
                                @php $targetText = is_array($this->event->target->metadata ?? null) ? ($this->event->target->metadata['text'] ?? null) : null; @endphp
                                @if ($targetText)
                                    <div>
                                        <span class="text-base-content/70">Content:</span>
                                        <div class="font-medium">{{ $targetText }}</div>
                                    </div>
                                @endif
                                @if ($this->event->target->type)
                                    <div>
                                        <span class="text-base-content/70">Type:</span>
                                        <x-badge :value="$this->event->target->type" class="badge-sm" />
                                    </div>
                                @endif
                                @if ($this->event->target->concept)
                                    <div>
                                        <span class="text-base-content/70">Concept:</span>
                                        <x-badge :value="$this->event->target->concept" class="badge-sm badge-outline" />
                                    </div>
                                @endif
                                @if ($this->event->target->url)
                                    <div>
                                        <span class="text-base-content/70">URL:</span>
                                        <a href="{{ $this->event->target->url }}" target="_blank" class="text-primary hover:underline block">
                                            View
                                        </a>
                                    </div>
                                @endif
                                @if ($this->event->target->tags->isNotEmpty())
                                    <div>
                                        <span class="text-base-content/70">Tags:</span>
                                        <div class="flex flex-wrap gap-1 mt-1">
                                            @foreach ($this->event->target->tags as $tag)
                                                <x-badge :value="$tag->name" class="badge-xs" />
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                                </div>
                            </x-slot:content>
                        </x-collapse>
                    @endif

                    <!-- Technical Metadata -->
                    @if ($this->event->event_metadata && count($this->event->event_metadata) > 0)
                        <x-collapse wire:model="eventMetaOpen">
                            <x-slot:heading>
                                <div class="text-lg font-semibold text-base-content flex items-center gap-2">
                                    <x-icon name="o-cog-6-tooth" class="w-5 h-5 text-warning" />
                                    Event Metadata
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <x-metadata-list :data="$this->event->event_metadata" />
                            </x-slot:content>
                        </x-collapse>
                    @endif

                    @if ($this->event->actor && $this->event->actor->metadata && count($this->event->actor->metadata) > 0)
                        <x-collapse wire:model="actorMetaOpen">
                            <x-slot:heading>
                                <div class="text-lg font-semibold text-base-content flex items-center gap-2">
                                    <x-icon name="o-cog-6-tooth" class="w-5 h-5 text-secondary" />
                                    Actor Metadata
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <x-metadata-list :data="$this->event->actor->metadata" />
                            </x-slot:content>
                        </x-collapse>
                    @endif

                    @if ($this->event->target && $this->event->target->metadata && count($this->event->target->metadata) > 0)
                        <x-collapse wire:model="targetMetaOpen">
                            <x-slot:heading>
                                <div class="text-lg font-semibold text-base-content flex items-center gap-2">
                                    <x-icon name="o-cog-6-tooth" class="w-5 h-5 text-accent" />
                                    Target Metadata
                                </div>
                            </x-slot:heading>
                            <x-slot:content>
                                <x-metadata-list :data="$this->event->target->metadata" />
                            </x-slot:content>
                        </x-collapse>
                    @endif
                </div>
                <div class="pt-2">
                    <x-button label="Close" class="btn-ghost btn-block" @click="$wire.showSidebar = false" />
                </div>
            </x-drawer>
        </div>
    @endif
    @if (! $this->event)
        <div class="text-center py-8">
            <x-icon name="o-exclamation-triangle" class="w-12 h-12 text-warning mx-auto mb-4" />
            <h3 class="text-lg font-semibold text-base-content mb-2">Event Not Found</h3>
            <p class="text-base-content/70">The requested event could not be found.</p>
        </div>
    @endif
</div>
