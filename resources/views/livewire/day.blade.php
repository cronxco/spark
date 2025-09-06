<?php

use function Livewire\Volt\{state, computed, on, layout};
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Integrations\PluginRegistry;

state([
    'view' => 'index',
    'eventId' => null,
    'search' => '',
    // Selected date in Y-m-d format for native date input (driven by route)
    'date' => (function () {
        try {
            if (request()->routeIs('today.*')) {
                return Carbon::today()->format('Y-m-d');
            }
            if (request()->routeIs('day.yesterday')) {
                return Carbon::yesterday()->format('Y-m-d');
            }
            if (request()->routeIs('tomorrow')) {
                return Carbon::tomorrow()->format('Y-m-d');
            }
            $param = request()->route('date');
            if ($param) {
                return Carbon::parse($param)->format('Y-m-d');
            }
        } catch (\Throwable $e) {
            // Fallback to today if parsing fails
        }
        return Carbon::today()->format('Y-m-d');
    })(),
    // Group collapse state keyed by group key
    'collapsedGroups' => [],
    // Polling mode: 'keep' (keep-alive) or 'visible'
    'pollMode' => 'visible',
]);

layout('components.layouts.app');

$navigateToDate = function (): void {
    try {
        $selected = Carbon::parse((string) $this->date)->startOfDay();
        $today = Carbon::today();

        if ($selected->equalTo($today)) {
            $this->redirect(route('today.main'), navigate: true);
            return;
        }

        if ($selected->equalTo($today->copy()->subDay())) {
            $this->redirect(route('day.yesterday'), navigate: true);
            return;
        }

        if ($selected->equalTo($today->copy()->addDay())) {
            $this->redirect(route('tomorrow'), navigate: true);
            return;
        }

        $this->redirect(route('day.show', ['date' => $selected->format('Y-m-d')]), navigate: true);
    } catch (\Throwable $e) {
        // Ignore
    }
};

$events = computed(function () {
    try {
        $selectedDate = Carbon::parse($this->date);
    } catch (\Throwable $e) {
        $selectedDate = Carbon::today();
    }

    $query = Event::with(['actor', 'target', 'integration', 'tags'])
        ->whereHas('integration', function ($q) {
            $userId = optional(auth()->guard('web')->user())->id;
            if ($userId) {
                $q->where('user_id', $userId);
            } else {
                // Force empty result if no auth user
                $q->whereRaw('1 = 0');
            }
        })
        ->whereDate('time', $selectedDate)
        ->orderBy('time', 'desc');

    if ($this->search) {
        $query->where(function ($q) {
            $q->where('action', 'like', "%{$this->search}%")
              ->orWhere('domain', 'like', "%{$this->search}%")
              ->orWhere('service', 'like', "%{$this->search}%")
              ->orWhere('value_unit', 'like', "%{$this->search}%")
              ->orWhereHas('actor', function ($actorQuery) {
                  $actorQuery->where('title', 'like', "%{$this->search}%")
                            ->orWhere('content', 'like', "%{$this->search}%")
                            ->orWhere('concept', 'like', "%{$this->search}%")
                            ->orWhere('type', 'like', "%{$this->search}%");
              })
              ->orWhereHas('target', function ($targetQuery) {
                  $targetQuery->where('title', 'like', "%{$this->search}%")
                            ->orWhere('content', 'like', "%{$this->search}%")
                            ->orWhere('concept', 'like', "%{$this->search}%")
                            ->orWhere('type', 'like', "%{$this->search}%");
              });
        });
    }

    // Load for the selected day
    $events = $query->get();

    // Filter out actions marked as hidden in plugin configuration
    $filtered = $events->filter(function ($event) {
        $pluginClass = PluginRegistry::getPlugin($event->service);
        if (! $pluginClass) {
            return true;
        }
        $actionTypes = $pluginClass::getActionTypes();
        if (! isset($actionTypes[$event->action])) {
            return true;
        }
        $config = $actionTypes[$event->action];
        if (isset($config['hidden']) && $config['hidden'] === true) {
            return false;
        }

        return true;
    });

    return $filtered->values();
});

$updatedDate = function ($value): void {
    try {
        $selected = Carbon::parse((string) $value)->startOfDay();
        $today = Carbon::today();

        if ($selected->equalTo($today)) {
            $this->redirect(route('today.main'), navigate: true);
            return;
        }

        if ($selected->equalTo($today->copy()->subDay())) {
            $this->redirect(route('day.yesterday'), navigate: true);
            return;
        }

        if ($selected->equalTo($today->copy()->addDay())) {
            $this->redirect(route('tomorrow'), navigate: true);
            return;
        }

        $this->redirect(route('day.show', ['date' => $selected->format('Y-m-d')]), navigate: true);
    } catch (\Throwable $e) {
        // Ignore parse errors; keep current date
    }
};

$dateLabel = computed(function () {
    try {
        $date = Carbon::parse($this->date);
    } catch (\Throwable $e) {
        $date = Carbon::today();
    }

    if ($date->isToday()) {
        return 'Today';
    }

    if ($date->isYesterday()) {
        return 'Yesterday';
    }

    if ($date->isTomorrow()) {
        return 'Tomorrow';
    }

    return $date->format('M j, Y');
});

$formatAction = function ($action) {
    return format_action_title($action);
};

$formatObjectTypePlural = function ($event) {
    $type = null;
    if ($event->target && $event->target->type) {
        $type = $event->target->type;
    } elseif ($event->actor && $event->actor->type) {
        $type = $event->actor->type;
    }

    if (! $type) {
        return 'items';
    }

    return Str::plural(Str::headline($type));
};

$getEventIcon = function ($action, $service) {
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
        'listen' => 'o-musical-note',
        'watch' => 'o-video-camera',
        'read' => 'o-book-open',
        'write' => 'o-pencil',
        'send' => 'o-paper-airplane',
        'receive' => 'o-inbox',
        'download' => 'o-arrow-down-tray',
        'upload' => 'o-arrow-up-tray',
        'save' => 'o-bookmark',
        'bookmark' => 'o-bookmark',
        'favorite' => 'o-heart',
        'rate' => 'o-star',
        'review' => 'o-chat-bubble-left-ellipsis',
        'subscribe' => 'o-bell',
        'unsubscribe' => 'o-bell-slash',
        'block' => 'o-no-symbol',
        'unblock' => 'o-check-circle',
        'mute' => 'o-speaker-x-mark',
        'unmute' => 'o-speaker-wave',
        'pin' => 'o-map-pin',
        'unpin' => 'o-map-pin',
        'lock' => 'o-lock-closed',
        'unlock' => 'o-lock-open',
        'hide' => 'o-eye-slash',
        'show' => 'o-eye',
        'enable' => 'o-check',
        'disable' => 'o-x-mark',
        'activate' => 'o-power',
        'deactivate' => 'o-power',
        'connect' => 'o-link',
        'disconnect' => 'o-link-slash',
        'sync' => 'o-arrow-path',
        'backup' => 'o-archive-box',
        'restore' => 'o-arrow-path',
        'export' => 'o-arrow-down-tray',
        'import' => 'o-arrow-up-tray',
        'install' => 'o-arrow-down',
        'uninstall' => 'o-trash',
        'upgrade' => 'o-arrow-trending-up',
        'downgrade' => 'o-arrow-trending-down',
        'pot' => 'o-arrow-right',
        'add' => 'o-plus',
        'remove' => 'o-minus',
        'increase' => 'o-arrow-trending-up',
        'decrease' => 'o-arrow-trending-down',
    ];

    return $icons[strtolower($action)] ?? 'o-bolt';
};

$getEventColor = function ($action) {
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
};

$getAccentColorForService = function ($service) {
    $pluginClass = PluginRegistry::getPlugin($service);
    if ($pluginClass) {
        return 'text-' . ($pluginClass::getAccentColor() ?: 'primary');
    }
    return 'text-primary';
};

$getBadgeAccentForService = function ($service) {
    $pluginClass = PluginRegistry::getPlugin($service);
    if ($pluginClass) {
        return 'badge-' . ($pluginClass::getAccentColor() ?: 'primary');
    }
    return 'badge-primary';
};

$valueColorClass = function ($event) {
    // Only apply coloring for money domain
    if ($event->domain !== 'money') {
        return 'text-base-content';
    }

    $value = $event->formatted_value ?? $event->value;
    if ($value === null) {
        return 'text-base-content';
    }

    if (is_numeric($value)) {
        if ($value > 0) {
            return 'text-success';
        }
        if ($value < 0) {
            return 'text-error';
        }
    }

    return 'text-base-content';
};

$isDurationUnit = function ($unit): bool {
    if (! $unit) {
        return false;
    }
    $u = strtolower((string) $unit);
    $map = [
        'ms', 'millisecond', 'milliseconds',
        's', 'sec', 'secs', 'second', 'seconds',
        'm', 'min', 'mins', 'minute', 'minutes',
        'h', 'hr', 'hrs', 'hour', 'hours'
    ];
    return in_array($u, $map, true);
};

$formatDurationShort = function ($value, $unit): string {
    if ($value === null) {
        return '';
    }
    $u = strtolower((string) $unit);

    // Convert everything to seconds first
    $seconds = 0.0;
    if (in_array($u, ['ms', 'millisecond', 'milliseconds'], true)) {
        $seconds = ((float) $value) / 1000.0;
    } elseif (in_array($u, ['m', 'min', 'mins', 'minute', 'minutes'], true)) {
        $seconds = ((float) $value) * 60.0;
    } elseif (in_array($u, ['h', 'hr', 'hrs', 'hour', 'hours'], true)) {
        $seconds = ((float) $value) * 3600.0;
    } else { // seconds and aliases
        $seconds = (float) $value;
    }

    if ($seconds < 1) {
        // Show milliseconds if under a second
        $ms = (int) round($seconds * 1000);
        return $ms . 'ms';
    }

    $total = (int) round($seconds);
    $h = intdiv($total, 3600);
    $m = intdiv($total % 3600, 60);
    $s = $total % 60;

    $parts = [];
    if ($h > 0) { $parts[] = $h . 'h'; }
    if ($m > 0 || $h > 0) { $parts[] = $m . 'm'; }
    if ($h === 0) { $parts[] = $s . 's'; }

    return implode('', $parts);
};

$formatValueDisplay = function ($event): string {
    $value = $event->formatted_value ?? $event->value;
    $unit = $event->value_unit;

    if ($this->isDurationUnit($unit)) {
        return $this->formatDurationShort($value, $unit);
    }

    if ($value === null) {
        return '';
    }

    return (string) $value . ($unit ? (' ' . $unit) : '');
};

$previousDay = function () {
    try {
        $current = Carbon::parse($this->date);
    } catch (\Throwable $e) {
        $current = Carbon::today();
    }
    $this->date = $current->copy()->subDay()->format('Y-m-d');
    try {
        $selected = Carbon::parse((string) $this->date)->startOfDay();
        $today = Carbon::today();

        if ($selected->equalTo($today)) {
            $this->redirect(route('today.main'), navigate: true);
            return;
        }

        if ($selected->equalTo($today->copy()->subDay())) {
            $this->redirect(route('day.yesterday'), navigate: true);
            return;
        }

        if ($selected->equalTo($today->copy()->addDay())) {
            $this->redirect(route('tomorrow'), navigate: true);
            return;
        }

        $this->redirect(route('day.show', ['date' => $selected->format('Y-m-d')]), navigate: true);
    } catch (\Throwable $e) {
        // Ignore
    }
};

$nextDay = function () {
    try {
        $current = Carbon::parse($this->date);
    } catch (\Throwable $e) {
        $current = Carbon::today();
    }
    $this->date = $current->copy()->addDay()->format('Y-m-d');
    try {
        $selected = Carbon::parse((string) $this->date)->startOfDay();
        $today = Carbon::today();

        if ($selected->equalTo($today)) {
            $this->redirect(route('today.main'), navigate: true);
            return;
        }

        if ($selected->equalTo($today->copy()->subDay())) {
            $this->redirect(route('day.yesterday'), navigate: true);
            return;
        }

        if ($selected->equalTo($today->copy()->addDay())) {
            $this->redirect(route('tomorrow'), navigate: true);
            return;
        }

        $this->redirect(route('day.show', ['date' => $selected->format('Y-m-d')]), navigate: true);
    } catch (\Throwable $e) {
        // Ignore
    }
};

$toggleGroup = function (string $groupKey): void {
    $current = $this->collapsedGroups[$groupKey] ?? false;
    $this->collapsedGroups[$groupKey] = ! $current;
};

$expandAllGroups = function (): void {
    $groups = $this->groupedEvents;
    $new = [];
    foreach ($groups as $g) {
        $new[$g['key']] = false;
    }
    $this->collapsedGroups = $new;
};

$collapseAllGroups = function (): void {
    $groups = $this->groupedEvents;
    $new = [];
    foreach ($groups as $g) {
        $new[$g['key']] = true;
    }
    $this->collapsedGroups = $new;
};

$togglePollMode = function (): void {
    $this->pollMode = $this->pollMode === 'keep' ? 'visible' : 'keep';
};

// Toggle all groups based on current expansion state
$toggleAllGroups = function (): void {
    $groups = $this->groupedEvents;
    $anyCollapsed = false;
    foreach ($groups as $g) {
        if (($this->collapsedGroups[$g['key']] ?? false) === true) {
            $anyCollapsed = true;
            break;
        }
    }

    if ($anyCollapsed) {
        $this->expandAllGroups();
    } else {
        $this->collapseAllGroups();
    }
};

// Group consecutive events by action+service in current sort order (time desc)
$groupedEvents = computed(function () {
    $groups = [];
    $currentKey = null;
    $current = null;
    $currentHour = null;

    foreach ($this->events as $event) {
        $key = $event->service . '::' . $event->action;
        $hour = $event->time?->format('H');
        if ($currentKey !== $key || $currentHour !== $hour) {
            if ($current) {
                $groups[] = $current;
            }
            $currentKey = $key;
            $currentHour = $hour;
            $current = [
                'key' => $key . '::h:' . ($hour ?? '00') . '::' . ($event->id),
                'service' => $event->service,
                'action' => $event->action,
                'hour' => $hour,
                'events' => [],
            ];
        }

        $current['events'][] = $event;
    }

    if ($current) {
        $groups[] = $current;
    }

    // Compute summaries
    foreach ($groups as &$group) {
        $count = count($group['events']);
        $sample = $group['events'][0] ?? null;
        $objectTypePlural = 'items';
        if ($sample) {
            $type = null;
            if ($sample->target && $sample->target->type) {
                $type = $sample->target->type;
            } elseif ($sample->actor && $sample->actor->type) {
                $type = $sample->actor->type;
            }
            if ($type) {
                $objectTypePlural = Str::plural(Str::headline($type));
            }
        }

        $group['formatted_action'] = $this->formatAction($group['action']);
        $group['count'] = $count;
        $group['object_type_plural'] = $objectTypePlural;
        $group['summary'] = $group['formatted_action'] . ' ' . $count . ' ' . $objectTypePlural;
    }

    return $groups;
});

// Computed helper: are all groups currently expanded?
$areAllGroupsExpanded = computed(function () {
    $groups = $this->groupedEvents;
    foreach ($groups as $g) {
        if (($this->collapsedGroups[$g['key']] ?? false) === true) {
            return false;
        }
    }
    return true;
});

?>

<div>
        <!-- Day Index -->
        <div>
            <x-header :title="'Day — ' . $this->dateLabel" separator>
                <x-slot:actions>
                    <div class="flex items-center gap-2 sm:gap-3 w-full">
                        <div class="join">
                            <x-button class="join-item btn-ghost btn-sm" wire:click="previousDay">
                                <x-icon name="o-chevron-left" class="w-4 h-4" />
                            </x-button>
                            <label class="join-item">
                                <input
                                    type="date"
                                    class="input input-sm"
                                    wire:model.live.debounce.0ms="date"
                                    @change="$wire.call('navigateToDate')"
                                />
                            </label>
                            <x-button class="join-item btn-ghost btn-sm" wire:click="nextDay">
                                <x-icon name="o-chevron-right" class="w-4 h-4" />
                            </x-button>
                        </div>

                        <div class="flex-1 min-w-0" wire:ignore.self>
                            <x-input
                                wire:model.live.debounce.300ms="search"
                                placeholder="Search events..."
                                class="w-full"
                            />
                        </div>

                        <div class="hidden sm:flex items-center gap-2">
                                <!-- Expand/Collapse all (icon-only) -->
                                <x-button
                                    class="btn-ghost btn-sm"
                                    wire:click="toggleAllGroups"
                                    aria-label="{{ $this->areAllGroupsExpanded ? 'Collapse all' : 'Expand all' }}">
                                    <x-icon name="{{ $this->areAllGroupsExpanded ? 'o-arrows-pointing-in' : 'o-arrows-pointing-out' }}" class="w-4 h-4" />
                                </x-button>
                                <!-- Polling mode toggle: keep-alive vs visible -->
                                <x-button
                                    class="btn-ghost btn-sm"
                                    wire:click="togglePollMode"
                                    aria-label="{{ $this->pollMode === 'keep' ? 'Switch to visible polling' : 'Switch to keep-alive polling' }}"
                                    title="{{ $this->pollMode === 'keep' ? 'Polling: keep-alive' : 'Polling: visible' }}">
                                    <x-icon name="{{ $this->pollMode === 'keep' ? 'o-bolt' : 'o-eye' }}" class="w-4 h-4" />
                                </x-button>
                        </div>
                    </div>
                </x-slot:actions>
            </x-header>

            <div class="space-y-6">
                @if ($this->events->isEmpty())
                    <x-card>
                        <div class="text-center py-8">
                            <x-icon name="o-calendar" class="w-12 h-12 text-base-300 mx-auto mb-4" />
                            <h3 class="text-lg font-semibold text-base-content mb-2">No events for this date</h3>
                            <p class="text-base-content/70">Try another day using the arrows or date picker.</p>
                        </div>
                    </x-card>
                @else
                <!-- Custom Vertical Timeline View -->
                <div class="bg-base-100 rounded-lg p-2 sm:p-4" {{ $this->pollMode === 'keep' ? 'wire:poll.90s.keep-alive' : 'wire:poll.90s.visible' }}>
                    @php $previousHour = null; @endphp

                    @foreach ($this->groupedEvents as $group)
                        @php
                            $first = $group['events'][0];
                            $hour = $first->time->format('H');
                            $showHourMarker = $previousHour !== $hour;
                            $previousHour = $hour;
                            $isCollapsed = $collapsedGroups[$group['key']] ?? false;
                        @endphp

                        <!-- Hour marker inside spine -->
                        @if ($showHourMarker)
                            <div class="grid grid-cols-[1.25rem_1fr_auto] gap-3 items-center py-1 select-none">
                                <div class="relative h-8">
                                    <div class="absolute left-2 top-0 bottom-0 w-px bg-base-300"></div>
                                    <div class="absolute left-2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-5 h-5 rounded-full bg-base-100 ring-2 ring-base-300 flex items-center justify-center text-[10px] text-base-content/70">{{ $first->time->format('H') }}</div>
                                </div>
                                <div></div>
                                <div></div>
                            </div>
                        @endif

                        <!-- Group header -->
                        <div class="grid grid-cols-[1.25rem_1fr_auto] gap-3 {{ $isCollapsed ? 'py-1' : '' }}">
                            <div class="relative">
                                <div class="absolute left-2 top-0 bottom-0 w-px bg-base-300"></div>
                                <button class="absolute left-2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-7 h-7 rounded-full bg-base-100 ring-2 ring-base-300 flex items-center justify-center hover:bg-base-200"
                                        wire:click="toggleGroup('{{ $group['key'] }}')"
                                        aria-expanded="{{ $isCollapsed ? 'false' : 'true' }}"
                                        aria-label="Toggle group">
                                    <x-icon name="{{ $this->getEventIcon($group['action'], $group['service']) }}" class="w-4 h-4 {{ $this->getAccentColorForService($group['service']) }}" />
                                </button>
                            </div>
                            <div class="{{ $isCollapsed ? 'py-3' : '' }}">
                                <div class="min-w-0">
                                    @if ($isCollapsed)
                                        <div class="truncate">
                                            <span class="font-semibold">{{ $group['formatted_action'] }}</span>
                                            <span class="text-base-content/90">{{ ' ' . $group['count'] . ' ' . $group['object_type_plural'] }}</span>
                                        </div>
                                    @else
                                        @php $firstEvent = $group['events'][0]; @endphp
                                        <a href="{{ route('events.show', $firstEvent->id) }}" class="group block py-2 px-2 rounded-lg hover:bg-base-200/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                                            <div class="font-semibold truncate">
                                                {{ $this->formatAction($firstEvent->action) }}
                                                @if ($firstEvent->target)
                                                    <span class="text-base-content/80">{{ ' ' . $firstEvent->target->title }}</span>
                                                @elseif ($firstEvent->actor)
                                                    <span class="text-base-content/80">{{ ' ' . $firstEvent->actor->title }}</span>
                                                @endif
                                            </div>
                                            <div class="mt-1 text-sm text-base-content/70 flex items-center gap-2 flex-wrap">
                                                <span title="{{ $firstEvent->time->toDayDateTimeString() }}">{{ $firstEvent->time->diffForHumans() }} ·</span>

                                                @if ($firstEvent->domain)
                                                    <x-badge :value="$firstEvent->domain" class="badge-xs badge-outline" />
                                                @endif
                                                <x-badge :value="$firstEvent->service" class="badge-xs {{ $this->getBadgeAccentForService($firstEvent->service) }} badge-outline" />
                                                @if ($firstEvent->integration)
                                                    <x-badge :value="$firstEvent->integration->name" class="badge-xs badge-outline" />
                                                @endif
                                                @if ($firstEvent->tags->isNotEmpty())
                                                    <span class="hidden sm:inline">·</span>
                                                    <span class="flex flex-wrap gap-1">
                                                        @foreach ($firstEvent->tags as $tag)
                                                            <x-badge :value="$tag->name" class="badge-ghost badge-xs" />
                                                        @endforeach
                                                    </span>
                                                @endif
                                            </div>
                                        </a>
                                    @endif
                                </div>
                            </div>
                            <div class="{{ $isCollapsed ? 'py-3' : 'py-2' }} text-right pr-2">
                                @if (! $isCollapsed)
                                    @php $firstEvent = $group['events'][0]; @endphp
                                    @if (! is_null($firstEvent->value))
                                        <span class="text-sm {{ $this->valueColorClass($firstEvent) }}">{{ $this->formatValueDisplay($firstEvent) }}</span>
                                    @endif
                                @endif
                            </div>
                        </div>

                        @if (! $isCollapsed)
                            @php $eventsToShow = array_slice($group['events'], 1); @endphp
                            @foreach ($eventsToShow as $event)
                                <div class="grid grid-cols-[1.25rem_1fr_auto] gap-3">
                                    <div class="relative">
                                        <div class="absolute left-2 top-0 bottom-0 w-px bg-base-300"></div>
                                    </div>
                                    <a href="{{ route('events.show', $event->id) }}" class="group py-2 px-2 rounded-lg hover:bg-base-200/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                                        <div class="flex items-baseline gap-2">
                                            <div class="font-semibold text-base-content">
                                                {{ $this->formatAction($event->action) }}
                                                @if ($event->target)
                                                    <span class="text-base-content/80">{{ ' ' . $event->target->title }}</span>
                                                @elseif ($event->actor)
                                                    <span class="text-base-content/80">{{ ' ' . $event->actor->title }}</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="mt-1 text-sm text-base-content/70 flex items-center gap-2 flex-wrap">
                                            <span title="{{ $event->time->toDayDateTimeString() }}">{{ $event->time->diffForHumans() }} ·</span>

                                            @if ($event->domain)
                                                <x-badge :value="$event->domain" class="badge-xs badge-outline" />
                                            @endif
                                            <x-badge :value="$event->service" class="badge-xs {{ $this->getBadgeAccentForService($event->service) }} badge-outline" />
                                            @if ($event->integration)
                                                <x-badge :value="$event->integration->name" class="badge-xs badge-outline" />
                                            @endif
                                            @if ($event->tags->isNotEmpty())
                                                <span class="hidden sm:inline">·</span>
                                                <span class="flex flex-wrap gap-1">
                                                    @foreach ($event->tags as $tag)
                                                        <x-badge :value="$tag->name" class="badge-ghost badge-xs" />
                                                    @endforeach
                                                </span>
                                            @endif
                                        </div>
                                    </a>
                                    <div class="py-2 pr-2 text-right">
                                        @if (! is_null($event->value))
                                            <span class="text-sm {{ $this->valueColorClass($event) }}">{{ $this->formatValueDisplay($event) }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        @endif

                    @endforeach
                </div>
                @endif
            </div>
            </div>
</div>
