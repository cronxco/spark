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
    public bool $showCreateTagModal = false;
    public bool $showEditEventModal = false;
    public bool $showTagModal = false;
    public bool $showManageRelationshipsModal = false;
    public bool $showAddRelationshipModal = false;

    protected $listeners = [
        'open-tag-modal' => 'handleOpenTagModal',
        'open-edit-event-modal' => 'handleOpenEditModal',
        'open-manage-relationships-modal' => 'handleOpenManageRelationshipsModal',
        'open-add-relationship-modal' => 'handleOpenAddRelationshipModal',
        'delete-event' => 'handleDeleteEvent',
        'event-updated' => 'handleEventUpdated',
        'tags-updated' => 'handleTagsUpdated',
        'relationship-created' => 'handleRelationshipUpdated',
        'relationship-deleted' => 'handleRelationshipUpdated',
        'close-modal' => 'closeModals',
    ];

    public function mount(Event $event): void
    {
        $this->event = $event->load([
            'actor',
            'target',
            'integration',
            'blocks',
            'tags',
            'actor.tags',
            'target.tags',
            'relationshipsFrom',
            'relationshipsTo'
        ]);
    }

    public function getRelationships()
    {
        return $this->event->relationships()->get();
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

    public function getEventAnomalies()
    {
        // Check if this event has any associated anomalies
        if (!$this->event->value || !$this->event->value_unit) {
            return collect();
        }

        $userId = optional(auth()->guard('web')->user())->id;
        if (!$userId) {
            return collect();
        }

        // Find metric statistic for this event
        $metricStatistic = App\Models\MetricStatistic::where('user_id', $userId)
            ->where('service', $this->event->service)
            ->where('action', $this->event->action)
            ->where('value_unit', $this->event->value_unit)
            ->first();

        if (!$metricStatistic) {
            return collect();
        }

        // Find anomalies that reference this event
        return App\Models\MetricTrend::where('metric_statistic_id', $metricStatistic->id)
            ->anomalies()
            ->whereJsonContains('metadata->event_id', $this->event->id)
            ->with('metricStatistic')
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

    public function addTag(string $value, ?string $type = null): void
    {
        $name = trim((string) $value);
        if ($name === '') {
            return;
        }

        if (str_starts_with($name, 'tag-whitelist-') || str_starts_with($name, 'tag-initial-')) {
            return;
        }

        // If type not explicitly provided, infer from value prefix (e.g., type:label or type_label)
        $detectedType = $type !== null ? trim($type) : null;
        if ($detectedType === null) {
            if (preg_match('/^([A-Za-z0-9-]+)[_:](.+)$/', $name, $m) === 1) {
                $detectedType = strtolower($m[1]);
                $name = trim($m[2]);
            }
        } else {
            // Strip matching prefix from the visible value if present
            if (preg_match('/^' . preg_quote($detectedType, '/') . '[_:](.+)$/i', $name, $m) === 1) {
                $name = trim($m[1]);
            }
        }

        // Default free-form tags to 'spark' unless they are emoji-only
        if ($detectedType === null) {
            $detectedType = preg_match('/^\\p{Extended_Pictographic}(?:[\\x{FE0F}\\x{FE0E}])?(?:\\x{200D}\\p{Extended_Pictographic}(?:[\\x{FE0F}\\x{FE0E}])?)*$/u', $name) === 1
                ? 'emoji'
                : 'spark';
        }

        $tag = Tag::findOrCreate($name, $detectedType);
        // Ensure type persisted in case library returned an existing tag without the type set
        if (($tag->type ?? null) !== $detectedType) {
            $tag->type = $detectedType;
            $tag->save();
        }

        $this->event->attachTag($tag);
        $this->event->refresh()->loadMissing('tags');
        Log::info('Tag added to event', ['event_id' => (string) $this->event->id, 'tag' => $name, 'type' => $detectedType, 'tags_now' => $this->event->tags->pluck('name')->all()]);
    }

    public function removeTag(string $value, ?string $type = null): void
    {
        $name = trim((string) $value);
        if ($name === '') {
            return;
        }

        if (str_starts_with($name, 'tag-whitelist-') || str_starts_with($name, 'tag-initial-')) {
            return;
        }

        // If type not explicitly provided, infer from value prefix (e.g., type:label or type_label)
        $detectedType = $type !== null ? trim($type) : null;
        if ($detectedType === null) {
            if (preg_match('/^([A-Za-z0-9-]+)[_:](.+)$/', $name, $m) === 1) {
                $detectedType = strtolower($m[1]);
                $name = trim($m[2]);
            }
        } else {
            // Strip matching prefix from the visible value if present
            if (preg_match('/^' . preg_quote($detectedType, '/') . '[_:](.+)$/i', $name, $m) === 1) {
                $name = trim($m[1]);
            }
        }

        // Default free-form tags to 'spark' unless they are emoji-only
        if ($detectedType === null) {
            $detectedType = preg_match('/^\\p{Extended_Pictographic}(?:[\\x{FE0F}\\x{FE0E}])?(?:\\x{200D}\\p{Extended_Pictographic}(?:[\\x{FE0F}\\x{FE0E}])?)*$/u', $name) === 1
                ? 'emoji'
                : 'spark';
        }

        $this->event->detachTag($name, $detectedType);
        $this->event->refresh()->loadMissing('tags');
        Log::info('Tag removed from event', ['event_id' => (string) $this->event->id, 'tag' => $name, 'type' => $detectedType, 'tags_now' => $this->event->tags->pluck('name')->all()]);
    }

    public function notifyCopied(string $what): void
    {
        $this->success($what . ' copied to clipboard!');
    }

    public function openCreateTagModal(): void
    {
        $this->showCreateTagModal = true;
    }

    public function closeCreateTagModal(): void
    {
        $this->showCreateTagModal = false;
    }

    public function handleTagCreated(): void
    {
        $this->event->refresh()->loadMissing('tags');
        $this->showCreateTagModal = false;
    }

    public function handleOpenTagModal(): void
    {
        $this->showTagModal = true;
    }

    public function handleOpenEditModal(): void
    {
        $this->showEditEventModal = true;
    }

    public function handleDeleteEvent(): void
    {
        $this->event->delete();
        $this->redirect(route('today.main'), navigate: true);
    }

    public function handleEventUpdated(): void
    {
        $this->event->refresh()->load([
            'actor',
            'target',
            'integration',
            'blocks',
            'tags',
            'actor.tags',
            'target.tags'
        ]);
        $this->showEditEventModal = false;
    }

    public function handleTagsUpdated(): void
    {
        $this->event->refresh()->load([
            'actor',
            'target',
            'integration',
            'blocks',
            'tags',
            'actor.tags',
            'target.tags'
        ]);
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
        $this->event->refresh()->load([
            'relationshipsFrom',
            'relationshipsTo'
        ]);
    }

    public function closeModals(): void
    {
        $this->showEditEventModal = false;
        $this->showTagModal = false;
        $this->showManageRelationshipsModal = false;
        $this->showAddRelationshipModal = false;
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
                    <script type="application/json" id="event-json-{{ $this->event->id }}">
                        {
                            !!json_encode($this - > event - > toArray(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!
                        }
                    </script>
                    <x-button
                        icon="o-clipboard"
                        class="btn-ghost btn-xs"
                        label=""
                        title="Copy event JSON"
                        onclick="(function(){ var el=document.getElementById('event-json-{{ $this->event->id }}'); if(!el){return;} var text; try{ text=JSON.stringify(JSON.parse(el.textContent), null, 2);}catch(e){ text=el.textContent; } navigator.clipboard.writeText(text).then(function(){ $wire.notifyCopied('Event'); }); })()" />
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
                            <div class="flex flex-col sm:flex-row items-center sm:items-start justify-between gap-2 mb-2">
                                <h2 class="text-xl sm:text-2xl lg:text-3xl font-bold text-base-content leading-tight">
                                    {{ $this->formatAction($this->event->action) }}
                                    @if (should_display_action_with_object($this->event->action, $this->event->service))
                                    @if ($this->event->target)
                                    {{ $this->event->target->title }}
                                    @elseif ($this->event->actor)
                                    {{ $this->event->actor->title }}
                                    @endif
                                    @endif
                                </h2>

                                @if ($this->event->value)
                                <div class="text-2xl sm:text-3xl lg:text-4xl font-bold text-primary flex-shrink-0">
                                    {!! format_event_value_display($this->event->formatted_value, $this->event->value_unit, $this->event->service, $this->event->action, 'action') !!}
                                </div>
                                @endif
                            </div>
                        </div>

                        <!-- Key Metadata -->
                        <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2 text-sm">
                            <div class="flex items-center gap-2">
                                <x-icon name="o-clock" class="w-4 h-4 text-base-content/60 flex-shrink-0" />
                                <span class="text-base-content/70">{{ to_user_timezone($this->event->time, auth()->user())->format('d/m/Y H:i') }} · {{ to_user_timezone($this->event->time, auth()->user())->diffForHumans() }}</span>
                            </div>
                            <span class="hidden sm:inline">·</span>
                            <span class="sm:hidden w-full"></span>
                            @if ($this->event->domain)
                            <x-badge class="badge-xs badge-outline">
                                <x-slot:value>
                                    <x-icon name="fas.lines-leaning" class="w-3 h-3 text-base-content/40" />
                                    {{ str::Headline($this->event->domain) }}
                                </x-slot:value>
                            </x-badge>
                            <x-icon name="o-arrow-right" class="w-3 h-3 text-base-content/40" />
                            @endif
                            <x-badge class="badge-xs badge-outline">
                                <x-slot:value>
                                    <x-icon name="fas.bell-concierge" class="w-3 h-3 text-base-content/40" />
                                    {{ str::Headline($this->event->service) }}
                                </x-slot:value>
                            </x-badge>
                            @if ($this->event->integration && (str::Headline($this->event->integration->instance_type) !== str::Headline($this->event->integration->name)))
                            <x-icon name="o-arrow-right" class="w-3 h-3 text-base-content/40" />
                            <x-badge class="badge-xs badge-outline">
                                <x-slot:value>
                                    <x-icon name="fas.font-awesome" class="w-3 h-3 text-base-content/40" />
                                    {{ str::Headline($this->event->integration->instance_type) }}
                                </x-slot:value>
                            </x-badge>
                            @endif
                            @if ($this->event->integration)
                            <x-icon name="o-arrow-right" class="w-3 h-3 text-base-content/40" />
                            <x-badge class="badge-xs badge-outline">
                                <x-slot:value>
                                    <x-icon name="fas.thumbtack" class="w-3 h-3 text-base-content/40" />
                                    {{ str::Headline($this->event->integration->name) }}
                                </x-slot:value>
                            </x-badge>
                            @endif
                        </div>

                        <!-- Actor & Target Flow -->
                        @if ($this->event->actor || $this->event->target)
                        <div class="mt-4 lg:mt-6 p-3 lg:p-4 rounded-lg bg-base-300/50 border-2 border-info/20">
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
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-arrow-down" class="w-4 h-4 text-base-content/40 sm:hidden" />
                                    <x-icon name="o-arrow-right" class="w-4 h-4 text-base-content/40 hidden sm:block" />
                                    <span class="text-sm text-base-content/70 font-medium">{{ $this->formatAction($this->event->action) }}</span>
                                    <x-icon name="o-arrow-down" class="w-4 h-4 text-base-content/40 sm:hidden" />
                                    <x-icon name="o-arrow-right" class="w-4 h-4 text-base-content/40 hidden sm:block" />
                                </div>
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
                            <div class="flex flex-wrap justify-center gap-2">
                                @foreach ($this->event->tags as $tag)
                                <x-spark-tag :tag="$tag" />
                                @endforeach
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </x-card>

            <!-- Anomaly Information -->
            @php $anomalies = $this->getEventAnomalies(); @endphp
            @if ($anomalies->isNotEmpty())
            <x-card class="bg-warning/5 border-2 border-warning/30">
                <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                    <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-warning" />
                    Anomaly Detected
                </h3>
                <div class="space-y-3">
                    @foreach ($anomalies as $anomaly)
                    <div class="rounded-lg bg-base-100 p-4 border border-warning/20">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-2">
                                    @if ($anomaly->getDirection() === 'up')
                                    <x-icon name="o-arrow-trending-up" class="h-5 w-5 text-warning" />
                                    @else
                                    <x-icon name="o-arrow-trending-down" class="h-5 w-5 text-warning" />
                                    @endif
                                    <span class="font-semibold text-warning">{{ $anomaly->getTypeLabel() }}</span>
                                </div>
                                <p class="text-sm text-base-content/70 mb-3">
                                    This event's value is <strong>{{ number_format($anomaly->deviation, 2) }} standard deviations</strong> away from the normal range for this metric.
                                </p>
                                <div class="grid grid-cols-3 gap-4 text-sm">
                                    <div>
                                        <div class="text-xs text-base-content/60 mb-1">This Event</div>
                                        <div class="font-semibold text-warning">
                                            {!! format_event_value_display($anomaly->current_value, $anomaly->metricStatistic->value_unit, $anomaly->metricStatistic->service, $anomaly->metricStatistic->action) !!}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-base-content/60 mb-1">Normal Average</div>
                                        <div class="font-semibold">
                                            {!! format_event_value_display($anomaly->baseline_value, $anomaly->metricStatistic->value_unit, $anomaly->metricStatistic->service, $anomaly->metricStatistic->action) !!}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-base-content/60 mb-1">Normal Range</div>
                                        <div class="font-semibold text-xs">
                                            {!! format_event_value_display($anomaly->metadata['normal_lower_bound'] ?? 0, $anomaly->metricStatistic->value_unit, $anomaly->metricStatistic->service, $anomaly->metricStatistic->action) !!}
                                            -
                                            {!! format_event_value_display($anomaly->metadata['normal_upper_bound'] ?? 0, $anomaly->metricStatistic->value_unit, $anomaly->metricStatistic->service, $anomaly->metricStatistic->action) !!}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <a href="{{ route('metrics.show', $anomaly->metricStatistic->id) }}"
                                class="btn btn-warning btn-sm flex-shrink-0">
                                View Metric
                            </a>
                        </div>
                    </div>
                    @endforeach
                </div>
            </x-card>
            @endif

            <!-- Event Context Chart -->
            @if ($this->event->value && $this->event->value_unit)
            <div class="min-h-[250px]">
                <livewire:charts.event-context-chart :event="$this->event" wire:lazy :key="'event-context-chart-' . $this->event->id" />
            </div>
            @endif

            <!-- Linked Blocks - Compact Grid View -->
            @if ($this->event->blocks->isNotEmpty())
            <x-card class="bg-base-200/50 border-2 border-info/10">
                <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                    <x-icon name="o-squares-2x2" class="w-5 h-5 text-info" />
                    Linked Blocks ({{ $this->event->blocks->count() }})
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($this->event->blocks as $block)
                    <div class="border-2 border-info/30 bg-base-100/80 rounded-lg p-3 hover:bg-base-50 transition-colors shadow-sm">
                        <div class="flex items-start justify-between gap-3 mb-2">
                            <a href="{{ route('blocks.show', $block->id) }}"
                                class="font-semibold text-base-content hover:text-primary transition-colors text-base flex-1">
                                {{ $block->title }}
                            </a>
                            @if ($block->value)
                            <span class="text-lg font-bold text-primary flex-shrink-0">{!! format_event_value_display($block->formatted_value, $block->value_unit, $this->event->service, $block->block_type, 'block') !!}</span>
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
                                {{ to_user_timezone($block->time, auth()->user())->format('H:i') }}
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
            <x-card class="bg-base-200 shadow">
                <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                    <x-icon name="o-arrow-path" class="w-5 h-5 text-warning" />
                    Related Events
                </h3>
                <div class="space-y-3">
                    @foreach ($this->getRelatedEvents() as $relatedEvent)
                    <div class="border border-base-200 bg-base-100 rounded-lg p-3 hover:bg-base-50 transition-colors">
                        <a href="{{ route('events.show', $relatedEvent->id) }}"
                            class="block hover:text-primary transition-colors">
                            <div class="flex items-start gap-3">
                                <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0 mt-1">
                                    <x-icon name="{{ $this->getEventIcon($relatedEvent->action, $relatedEvent->service) }}"
                                        class="w-4 h-4 {{ $this->getEventColor($relatedEvent->action) }}" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-2 mb-1">
                                        <span class="font-medium">
                                            {{ $this->formatAction($relatedEvent->action) }}
                                            @if (should_display_action_with_object($relatedEvent->action, $relatedEvent->service))
                                            @if ($relatedEvent->target)
                                            <span class="text-base-content/80">{{ ' ' . $relatedEvent->target->title }}</span>
                                            @elseif ($relatedEvent->actor)
                                            <span class="text-base-content/80">{{ ' ' . $relatedEvent->actor->title }}</span>
                                            @endif
                                            @endif
                                        </span>
                                        @if ($relatedEvent->value)
                                        <span class="text-sm text-primary font-semibold flex-shrink-0">
                                            {!! format_event_value_display($relatedEvent->formatted_value, $relatedEvent->value_unit, $relatedEvent->service, $relatedEvent->action, 'action') !!}
                                        </span>
                                        @endif
                                    </div>
                                    <div class="text-sm text-base-content/70 flex flex-wrap items-center gap-1">
                                        <span>{{ to_user_timezone($relatedEvent->time, auth()->user())->format('d/m/Y H:i') }}</span>
                                        @if ($relatedEvent->domain)
                                        <span>·</span>
                                        <x-badge class="badge-xs badge-outline">
                                            <x-slot:value>
                                                {{ Str::lower($relatedEvent->domain) }}
                                            </x-slot:value>
                                        </x-badge>
                                        @endif
                                        <x-badge class="badge-xs badge-outline">
                                            <x-slot:value>
                                                {{ Str::lower($relatedEvent->service) }}
                                            </x-slot:value>
                                        </x-badge>
                                        @if ($relatedEvent->integration)
                                        <x-badge class="badge-xs badge-outline">
                                            <x-slot:value>
                                                {{ Str::lower($relatedEvent->integration->name) }}
                                            </x-slot:value>
                                        </x-badge>
                                        @endif
                                        @if ($relatedEvent->tags && count($relatedEvent->tags) > 0)
                                        <span>·</span>
                                        @foreach ($relatedEvent->tags as $tag)
                                        <x-spark-tag :tag="$tag" size="xs" />
                                        @endforeach
                                        @endif
                                    </div>
                                </div>
                                <x-icon name="o-chevron-right" class="w-4 h-4 text-base-content/40 flex-shrink-0 mt-1" />
                            </div>
                        </a>
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
                    // Determine if this event is "from" or "to" in the relationship
                    $isFrom = $relationship->from_type === get_class($event) && $relationship->from_id === $event->id;
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

        <!-- Drawer for Technical Details -->
        <x-drawer wire:model="showSidebar" right title="Event Details" separator with-close-button class="w-11/12 lg:w-1/3">
            <div class="space-y-4 lg:space-y-6">
                <!-- Tags Manager -->
                <x-card class="mb-0 !p-2">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-base-content flex items-center gap-2">
                            <x-icon name="o-tag" class="w-5 h-5 text-primary" />
                            Tags
                        </h3>
                        <button type="button" wire:click="openCreateTagModal" class="btn btn-xs btn-ghost btn-circle" title="Create new tag">
                            <x-icon name="o-plus" class="w-3 h-3" />
                        </button>
                    </div>
                    <div class="space-y-2" wire:key="event-tags-{{ $this->event->id }}" wire:ignore>
                        <input id="tag-input-{{ $this->event->id }}" data-tagify data-initial="tag-initial-{{ $this->event->id }}" data-suggestions-id="tag-suggestions-{{ $this->event->id }}" aria-label="Tags" class="input input-sm w-full" placeholder="Add tags" data-hotkey="t" />
                        <script type="application/json" id="tag-initial-{{ $this->event->id }}">
                            {
                                !!json_encode($this - > event - > tags - > map(fn($tag) => ['value' => (string) $tag - > name, 'type' => $tag - > type ? (string) $tag - > type : null]) - > values() - > all()) !!
                            }
                        </script>
                        <script type="application/json" id="tag-suggestions-{{ $this->event->id }}">
                            {
                                !!json_encode(\Spatie\ Tags\ Tag::query() - > select(['name', 'type']) - > get() - > map(fn($tag) => ['value' => (string) $tag - > name, 'type' => $tag - > type ? (string) $tag - > type : null]) - > values() - > all()) !!
                            }
                        </script>
                    </div>
                </x-card>

                <!-- Relationships -->
                <x-card class="mb-0 !p-2">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-base-content flex items-center gap-2">
                            <x-icon name="o-arrows-right-left" class="w-5 h-5 text-accent" />
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
                        $isFrom = $relationship->from_type === get_class($event) && $relationship->from_id === $event->id;
                        $relatedModel = $isFrom ? $relationship->to : $relationship->from;

                        // Initialize defaults
                        $icon = 'o-question-mark-circle';
                        $title = 'Unknown';
                        $route = '#';
                        $badgeClass = 'badge-ghost';

                        if ($relatedModel instanceof \App\Models\Event) {
                        $icon = 'o-calendar';
                        $title = $relatedModel->action;
                        $route = route('events.show', $relatedModel);
                        $badgeClass = 'badge-primary';
                        } elseif ($relatedModel instanceof \App\Models\EventObject) {
                        $icon = 'o-cube';
                        $title = $relatedModel->title;
                        $route = route('objects.show', $relatedModel);
                        $badgeClass = 'badge-secondary';
                        } elseif ($relatedModel instanceof \App\Models\Block) {
                        $icon = 'o-squares-2x2';
                        $title = $relatedModel->type;
                        $route = route('blocks.show', $relatedModel);
                        $badgeClass = 'badge-accent';
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
                        <x-timeline-item title="{{ $title }}" subtitle="{{ $subtitle }}">
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
                                    <x-spark-tag :tag="$tag" />
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
                                    <x-spark-tag :tag="$tag" />
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
                        <div class="text-lg font-semibold text-base-content flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <x-icon name="o-cog-6-tooth" class="w-5 h-5 text-warning" />
                                Event Metadata
                            </div>
                            <script type="application/json" id="event-meta-json-{{ $this->event->id }}">
                                {
                                    !!json_encode($this - > event - > event_metadata, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!
                                }
                            </script>
                            <x-button
                                icon="o-clipboard"
                                label="Copy"
                                class="btn-ghost btn-xs"
                                title="Copy JSON"
                                onclick="(function(){ var el=document.getElementById('event-meta-json-{{ $this->event->id }}'); if(!el){return;} var text; try{ text=JSON.stringify(JSON.parse(el.textContent), null, 2);}catch(e){ text=el.textContent; } navigator.clipboard.writeText(text).then(function(){ $wire.notifyCopied('Event metadata'); }); })()" />
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
                        <div class="text-lg font-semibold text-base-content flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <x-icon name="o-cog-6-tooth" class="w-5 h-5 text-secondary" />
                                Actor Metadata
                            </div>
                            <script type="application/json" id="actor-meta-json-{{ $this->event->id }}">
                                {
                                    !!json_encode($this - > event - > actor - > metadata, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!
                                }
                            </script>
                            <x-button
                                icon="o-clipboard"
                                label="Copy"
                                class="btn-ghost btn-xs"
                                title="Copy JSON"
                                onclick="(function(){ var el=document.getElementById('actor-meta-json-{{ $this->event->id }}'); if(!el){return;} var text; try{ text=JSON.stringify(JSON.parse(el.textContent), null, 2);}catch(e){ text=el.textContent; } navigator.clipboard.writeText(text).then(function(){ $wire.notifyCopied('Actor metadata'); }); })()" />
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
                        <div class="text-lg font-semibold text-base-content flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <x-icon name="o-cog-6-tooth" class="w-5 h-5 text-accent" />
                                Target Metadata
                            </div>
                            <script type="application/json" id="target-meta-json-{{ $this->event->id }}">
                                {
                                    !!json_encode($this - > event - > target - > metadata, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!
                                }
                            </script>
                            <x-button
                                icon="o-clipboard"
                                label="Copy"
                                class="btn-ghost btn-xs"
                                title="Copy JSON"
                                onclick="(function(){ var el=document.getElementById('target-meta-json-{{ $this->event->id }}'); if(!el){return;} var text; try{ text=JSON.stringify(JSON.parse(el.textContent), null, 2);}catch(e){ text=el.textContent; } navigator.clipboard.writeText(text).then(function(){ $wire.notifyCopied('Target metadata'); }); })()" />
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

    <!-- Create Tag Modal -->
    <x-modal wire:model="showCreateTagModal" title="Create New Tag" subtitle="Define a new tag with a specific type" separator>
        <livewire:create-tag :key="'create-tag-event-' . $this->event->id" @tag-created="handleTagCreated" />
    </x-modal>

    <!-- Tag Management Modal -->
    <x-modal wire:model="showTagModal" title="Manage Tags" subtitle="Add or remove tags for this event" separator>
        <livewire:manage-event-tags :event="$this->event" :key="'manage-tags-event-' . $this->event->id" />
    </x-modal>

    <!-- Edit Event Modal -->
    <x-modal wire:model="showEditEventModal" title="Edit Event" subtitle="Update event details" separator>
        <livewire:edit-event :event="$this->event" :key="'edit-event-' . $this->event->id" />
    </x-modal>

    <!-- Manage Relationships Modal -->
    <x-modal wire:model="showManageRelationshipsModal" title="Manage Relationships" subtitle="View and manage connections to other items" separator box-class="[max-width:1024px]">
        <livewire:manage-relationships
            :model-type="get_class($this->event)"
            :model-id="(string) $this->event->id"
            :key="'manage-relationships-event-' . $this->event->id" />
    </x-modal>

    <!-- Add Relationship Modal -->
    <x-modal wire:model="showAddRelationshipModal" title="Add Relationship" subtitle="Create a connection to another item" separator box-class="[max-width:1024px]">
        <livewire:add-relationship
            :from-type="get_class($this->event)"
            :from-id="(string) $this->event->id"
            :key="'add-relationship-event-' . $this->event->id" />
    </x-modal>
</div>