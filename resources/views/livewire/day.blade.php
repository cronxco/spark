<?php

use App\Cards\CardRegistry;
use App\Integrations\DailyCheckin\DailyCheckinPlugin;
use App\Integrations\Outline\OutlineApi;
use App\Integrations\PluginRegistry;
use App\Jobs\Outline\OutlinePullTodayDayNote;
use App\Models\Event;
use App\Models\Integration;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\state;

state([
    'view' => 'index',
    'eventId' => null,
    'search' => '',
    // Day Note editor state
    'dayNoteDocId' => null,
    'dayNoteIntegrationId' => null,
    'dayNoteText' => '',
    'dayNoteSaving' => false,
    'dayNoteSavedAt' => null,
    'dayNoteAutoSaveMs' => 800,
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
    // UI state: Day Note collapse (collapsed by default)
    'dayNoteOpen' => false,
]);

layout('components.layouts.app');

$checkinStatus = computed(function () {
    $userId = optional(auth()->guard('web')->user())->id;
    if (! $userId) {
        return 'red';
    }

    $plugin = new DailyCheckinPlugin;
    $checkins = $plugin->getCheckinsForDate($userId, $this->date);

    $morningComplete = $checkins['morning'] ? true : false;
    $afternoonComplete = $checkins['afternoon'] ? true : false;

    $user = auth()->guard('web')->user();
    $currentHour = user_now($user)->hour;
    $today = user_today($user);
    $isViewingToday = Carbon::parse($this->date)->isSameDay($today);

    // If viewing a past date or future date, ignore time-based logic
    if (! $isViewingToday) {
        if ($morningComplete && $afternoonComplete) {
            return 'green';
        } elseif ($morningComplete || $afternoonComplete) {
            return 'amber';
        } else {
            return 'red';
        }
    }

    // Time-based logic for today
    if ($currentHour < 12) {
        // Morning
        return $morningComplete ? 'green' : 'amber';
    } else {
        // Afternoon
        if ($morningComplete && $afternoonComplete) {
            return 'green';
        } elseif ($morningComplete) {
            return 'amber';
        } else {
            return 'red';
        }
    }
});

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
    $user = auth()->guard('web')->user();
    $today = user_today($user);

    try {
        $date = Carbon::parse($this->date);
    } catch (\Throwable $e) {
        $date = $today;
    }

    if ($date->isSameDay($today)) {
        return 'Today';
    }

    if ($date->isSameDay($today->copy()->subDay())) {
        return 'Yesterday';
    }

    if ($date->isSameDay($today->copy()->addDay())) {
        return 'Tomorrow';
    }

    return $date->format('M j, Y');
});

// Load Outline Day Note for the selected date into editor state
$loadDayNote = function (): void {
    // One-off background Outline pull before loading, to ensure freshness
    try {
        // Attempt to find any Outline integration for this user
        $outlineIntegration = Integration::query()
            ->where('service', 'outline')
            ->where('user_id', optional(auth()->guard('web')->user())->id)
            ->first();
        if ($outlineIntegration) {
            OutlinePullTodayDayNote::dispatch($outlineIntegration, (string) $this->date)->onQueue('pull');
        }
    } catch (\Throwable $e) {
        // ignore if dispatch fails; we'll still load cached note
    }

    $this->dayNoteDocId = null;
    $this->dayNoteIntegrationId = null;
    $this->dayNoteText = '';
    $this->dayNoteSavedAt = null;

    $event = $this->events
        ->first(function ($e) {
            return $e->service === 'outline' && $e->action === 'had_day_note';
        });

    if (! $event) {
        return;
    }

    // Extract document id and current text from target object
    $metadata = $event->target?->metadata ?? [];
    $docId = $metadata['id'] ?? null;
    $text = $event->target?->content ?? '';

    if ($docId) {
        $this->dayNoteDocId = $docId;
        $this->dayNoteIntegrationId = (string) $event->integration_id;
        $this->dayNoteText = (string) ($text ?? '');
    }
};

// Save editor back to Outline and optionally trigger a refresh pull
$saveDayNote = function (): void {
    if (empty($this->dayNoteDocId) || empty($this->dayNoteIntegrationId)) {
        return;
    }

    $this->dayNoteSaving = true;

    try {
        /** @var Integration $integration */
        $integration = Integration::findOrFail($this->dayNoteIntegrationId);
        $api = new OutlineApi($integration);
        $api->updateDocumentContent((string) $this->dayNoteDocId, (string) $this->dayNoteText, null, true);

        // Trigger a lightweight targeted refresh for this date to reconcile tasks/blocks
        OutlinePullTodayDayNote::dispatch($integration, (string) $this->date)->onQueue('pull');

        $this->dayNoteSavedAt = now()->toIso8601String();
    } catch (\Throwable $e) {
        // Swallow; UI can show failure later if needed
    } finally {
        $this->dayNoteSaving = false;
    }
};

// Autosave on content change with debounce
$updatedDayNoteText = function ($value): void {
    if ($this->dayNoteSaving) {
        return;
    }
    if (! is_string($value)) {
        return;
    }
    $this->saveDayNote();
};

// Refresh Day Note from Outline if editor is not focused
$refreshDayNoteFromOutline = function (): void {
    if ($this->dayNoteEditorFocused) {
        return;
    }
    if (empty($this->dayNoteDocId) || empty($this->dayNoteIntegrationId)) {
        return;
    }
    if ($this->dayNoteSaving) {
        return;
    }

    try {
        /** @var Integration $integration */
        $integration = Integration::findOrFail($this->dayNoteIntegrationId);
        $api = new OutlineApi($integration);
        $doc = $api->getDocument((string) $this->dayNoteDocId);
        $remoteText = (string) ($doc['data']['text'] ?? ($doc['text'] ?? ''));
        if ($remoteText !== '' && $remoteText !== (string) $this->dayNoteText) {
            $this->dayNoteText = $remoteText;
        }
    } catch (\Throwable $e) {
        // ignore transient errors
    }
};

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
        'create' => 'fas-circle-plus',
        'update' => 'fas-rotate',
        'delete' => 'fas-trash',
        'move' => 'fas-arrow-right',
        'copy' => 'fas-copy',
        'share' => 'fas-share',
        'like' => 'fas-heart',
        'comment' => 'fas-comment',
        'follow' => 'fas-user-plus',
        'unfollow' => 'fas-user-minus',
        'join' => 'fas-users',
        'leave' => 'fas-users',
        'start' => 'fas-play',
        'stop' => 'fas-stop',
        'pause' => 'fas-pause',
        'resume' => 'fas-play',
        'complete' => 'fas-circle-check',
        'fail' => 'fas-circle-xmark',
        'cancel' => 'fas-xmark',
        'approve' => 'fas-check',
        'reject' => 'fas-xmark',
        'publish' => 'fas-globe',
        'unpublish' => 'fas-eye-slash',
        'archive' => 'fas-box-archive',
        'restore' => 'fas-rotate',
        'login' => 'fas-right-from-bracket',
        'logout' => 'fas-right-to-bracket',
        'purchase' => 'o-shopping-cart',
        'refund' => 'fas-rotate',
        'transfer' => 'fas-arrow-right',
        'withdraw' => 'fas-arrow-down',
        'deposit' => 'fas-arrow-up',
        'listen' => 'fas-music',
        'watch' => 'o-video-camera',
        'read' => 'fas-book-open',
        'write' => 'fas-pen',
        'send' => 'fas-paper-plane',
        'receive' => 'fas-inbox',
        'download' => 'fas-download',
        'upload' => 'fas-upload',
        'save' => 'fas-bookmark',
        'bookmark' => 'fas-bookmark',
        'favorite' => 'fas-heart',
        'rate' => 'fas-star',
        'review' => 'fas-comment-dots',
        'subscribe' => 'fas-bell',
        'unsubscribe' => 'fas-bell-slash',
        'block' => 'fas-ban',
        'unblock' => 'fas-circle-check',
        'mute' => 'fas-volume-xmark',
        'unmute' => 'fas-volume-high',
        'pin' => 'fas-location-dot',
        'unpin' => 'fas-location-dot',
        'lock' => 'fas-lock',
        'unlock' => 'fas-lock-open',
        'hide' => 'fas-eye-slash',
        'show' => 'fas-eye',
        'enable' => 'fas-check',
        'disable' => 'fas-xmark',
        'activate' => 'fas-power-off',
        'deactivate' => 'fas-power-off',
        'connect' => 'fas-link',
        'disconnect' => 'o-link-slash',
        'sync' => 'fas-rotate',
        'backup' => 'fas-box-archive',
        'restore' => 'fas-rotate',
        'export' => 'fas-download',
        'import' => 'fas-upload',
        'install' => 'fas-arrow-down',
        'uninstall' => 'fas-trash',
        'upgrade' => 'fas-arrow-trend-up',
        'downgrade' => 'fas-arrow-trend-down',
        'pot' => 'fas-arrow-right',
        'add' => 'fas-plus',
        'remove' => 'fas-minus',
        'increase' => 'fas-arrow-trend-up',
        'decrease' => 'fas-arrow-trend-down',
    ];

    return $icons[strtolower($action)] ?? 'fas-bolt';
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
        return 'text-accent dark:text-primary';
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
        'ms',
        'millisecond',
        'milliseconds',
        's',
        'sec',
        'secs',
        'second',
        'seconds',
        'm',
        'min',
        'mins',
        'minute',
        'minutes',
        'h',
        'hr',
        'hrs',
        'hour',
        'hours',
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
    if ($h > 0) {
        $parts[] = $h . 'h';
    }
    if ($m > 0 || $h > 0) {
        $parts[] = $m . 'm';
    }
    if ($h === 0) {
        $parts[] = $s . 's';
    }

    return implode('', $parts);
};

$formatValueDisplay = function ($event): string {
    $value = $event->formatted_value ?? $event->value;
    $unit = $event->value_unit;

    if ($this->isDurationUnit($unit)) {
        return $this->formatDurationShort($value, $unit);
    }

    return format_event_value_display($value, $unit, $event->service, $event->action, 'action');
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
        $hour = to_user_timezone($event->time, auth()->user())->format('H');
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

// Get available card streams with eligible cards
$availableStreams = computed(function () {
    try {
        $user = auth()->guard('web')->user();
        if (! $user) {
            Log::info('FAB Debug: No authenticated user');

            return collect();
        }

        $now = user_now($user);
        $today = user_today($user);
        Log::info('FAB Debug: Checking streams', [
            'user_id' => $user->id,
            'date' => $this->date,
            'current_time' => $now->toTimeString(),
            'current_hour' => $now->hour,
            'user_timezone' => $user->getTimezone(),
            'is_viewing_today' => Carbon::parse($this->date)->isSameDay($today),
        ]);

        $streams = CardRegistry::getStreamsWithCards($user, $this->date);

        Log::info('FAB Debug: Streams with cards found', [
            'count' => $streams->count(),
            'stream_ids' => $streams->pluck('id')->toArray(),
        ]);

        // Add eligible card IDs to each stream for client-side filtering
        return $streams->map(function ($stream) use ($user) {
            $cards = CardRegistry::getEligibleCards($stream->id, $user, $this->date);

            Log::info("FAB Debug: Eligible cards for stream {$stream->id}", [
                'count' => $cards->count(),
                'card_ids' => $cards->map(fn ($card) => $card->getId())->toArray(),
            ]);

            $stream->eligibleCardIds = $cards->map(fn ($card) => $card->getId())->toArray();
            $stream->eligibleCardsMeta = $cards->map(fn ($card) => [
                'id' => $card->getId(),
                'requiresInteraction' => $card->requiresInteraction(),
            ])->toArray();

            return $stream;
        });
    } catch (\Throwable $e) {
        Log::error('FAB Debug: Error getting streams', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        // Return empty collection if there's any error
        return collect();
    }
});

?>

<div wire:init="loadDayNote" @checkin-status-updated.window="$wire.$refresh()">
    <x-header :title="'Day — ' . $this->dateLabel" separator>
        <x-slot:actions>
            <div class="flex items-center gap-2 sm:gap-3 w-full">
                <div class="join">
                    <x-button class="join-item btn-ghost btn-sm" wire:click="previousDay">
                        <x-icon name="fas-chevron-left" class="w-4 h-4" />
                    </x-button>
                    <label class="join-item">
                        <input
                            type="date"
                            class="input input-sm"
                            wire:model.live.debounce.0ms="date"
                            @change="$wire.call('navigateToDate')" />
                    </label>
                    <x-button class="join-item btn-ghost btn-sm" wire:click="nextDay">
                        <x-icon name="fas-chevron-right" class="w-4 h-4" />
                    </x-button>
                </div>

                <div class="flex-1 min-w-0" wire:ignore.self>
                    <x-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search events..."
                        class="w-full" />
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
                        <x-icon name="{{ $this->pollMode === 'keep' ? 'fas-bolt' : 'fas-eye' }}" class="w-4 h-4" />
                    </x-button>
                </div>
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Day Note editor -->
    <div class="mb-2" x-data="{ dayNoteOpenState: @entangle('dayNoteOpen').live }">
        <x-collapse x-model="dayNoteOpenState" separator class="bg-base-200">
            <x-slot:heading>
                <div class="flex items-center gap-2">
                    <x-icon name="fas-calendar" />
                    <span>
                        {{ \Carbon\Carbon::parse($this->date)->format('j F Y') }}
                        @if ($this->dayNoteSaving)
                        - <span class="text-sm text-info">Saving…</span>
                        @elseif ($this->dayNoteSavedAt)
                        - <span class="text-sm text-success">Saved</span>
                        @endif
                    </span>
                    <!-- Check-in status indicator -->
                    <span class="ml-auto">
                        @if ($this->checkinStatus === 'green')
                        <div class="badge badge-success badge-sm gap-1">
                            <x-icon name="fas-circle-check" class="w-3 h-3" />
                        </div>
                        @elseif ($this->checkinStatus === 'amber')
                        <div class="badge badge-warning badge-sm gap-1">
                            <x-icon name="fas-clock" class="w-3 h-3" />
                        </div>
                        @else
                        <div class="badge badge-error badge-sm gap-1">
                            <x-icon name="o-exclamation-circle" class="w-3 h-3" />
                        </div>
                        @endif
                    </span>
                </div>
            </x-slot:heading>
            <x-slot:content>
                <!-- Daily Check-in -->
                <div>
                    <livewire:daily-checkin :date="$this->date" :key="'checkin-' . $this->date" />
                </div>

                <!-- Divider -->
                <div class="divider my-3"></div>

                <!-- Day Note -->
                @if ($this->dayNoteDocId)
                <x-card title="" subtitle="" class="pt-0 pl-0 pr-0 pb-0 bg-base-200 shadow">
                    <div class="space-y-3">
                        <x-markdown wire:model.live.debounce.800ms="dayNoteText" label="" :config="['maxHeight' => '200px', 'status' => 'false', 'sideBySideFullscreen' => 'false']" />
                    </div>
                </x-card>
                @else
                <x-alert title="No Day Note found for this date" icon="fas-book-open" />
                @endif
            </x-slot:content>
        </x-collapse>
    </div>

    <div class="space-y-6">
        @if ($this->events->isEmpty())
        <x-card class="bg-base-200 shadow">
            <div class="text-center py-8">
                <x-icon name="fas-calendar" class="w-12 h-12 text-base-content mx-auto mb-4" />
                <h3 class="text-lg font-semibold text-base-content mb-2">No events found for this date</h3>
            </div>
        </x-card>
        @else
        <!-- Custom Vertical Timeline View -->
        @if ($this->pollMode === 'keep')
        <div class="bg-base-100 rounded-lg p-2 sm:p-4" wire:poll.90s.keep-alive>
            @else
            <div class="bg-base-100 rounded-lg p-2 sm:p-4" wire:poll.90s.visible>
                @endif
                @php $previousHour = null; @endphp

                @foreach (($this->groupedEvents ?? []) as $eventGroup)
                @php
                $first = $eventGroup['events'][0];
                $userTime = to_user_timezone($first->time, auth()->user());
                $hour = $userTime->format('H');
                $showHourMarker = $previousHour !== $hour;
                $previousHour = $hour;
                $isCollapsed = ($this->collapsedGroups[$eventGroup['key']] ?? false);
                @endphp

                <!-- Hour marker inside spine -->
                @if ($showHourMarker)
                <div class="grid grid-cols-[1.25rem_1fr_auto] gap-3 items-center py-1 select-none">
                    <div class="relative h-8">
                        <div class="absolute left-2 top-0 bottom-0 w-px bg-base-300"></div>
                        <div class="absolute left-2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-5 h-5 rounded-full bg-base-100 ring-2 ring-base-300 flex items-center justify-center text-[10px] text-base-content/70">{{ $hour }}</div>
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
                            wire:click="toggleGroup(@js($eventGroup['key']))"
                            aria-expanded="{{ $isCollapsed ? 'false' : 'true' }}"
                            aria-label="Toggle group">
                            <x-icon name="{{ $this->getEventIcon($eventGroup['action'], $eventGroup['service']) }}" class="w-4 h-4 {{ $this->getAccentColorForService($eventGroup['service']) }}" />
                        </button>
                    </div>
                    <div class="{{ $isCollapsed ? 'py-3' : '' }}">
                        <div class="min-w-0">
                            @if ($isCollapsed)
                            <div class="truncate">
                                <span class="font-semibold">{{ $eventGroup['formatted_action'] }}</span>
                                <span class="text-base-content/90">{{ ' ' . $eventGroup['count'] . ' ' . $eventGroup['object_type_plural'] }}</span>
                            </div>
                            @else
                            @php $firstEvent = $eventGroup['events'][0]; @endphp
                            <div class="py-2 px-2">
                                <a href="{{ route('events.show', $firstEvent->id) }}" class="block hover:text-primary transition-colors min-w-0 text-xl">
                                    <span class="font-semibold">{{ $this->formatAction($firstEvent->action) }}</span>
                                    @if (should_display_action_with_object($firstEvent->action, $firstEvent->service))
                                    @if ($firstEvent->target)
                                    <span class="sm:inline block break-words font-bold min-w-0">{{ ' ' . $firstEvent->target->title }}</span>
                                    @elseif ($firstEvent->actor)
                                    <span class="sm:inline block break-words font-bold min-w-0">{{ ' ' . $firstEvent->actor->title }}</span>
                                    @endif
                                    @endif
                                </a>
                                <div class="mt-1 text-sm text-base-content/70 flex items-center flex-wrap gap-1">
                                    {{ to_user_timezone($firstEvent->time, auth()->user())->format(' H:i') }} ·
                                    <span title="{{ to_user_timezone($firstEvent->time, auth()->user())->toDayDateTimeString() }}">{{ to_user_timezone($firstEvent->time, auth()->user())->diffForHumans() }}</span>
                                    <span class="hidden sm:inline">·</span>
                                    <span class="sm:hidden w-full"></span>
                                    @if ($firstEvent->integration)
                                    <x-badge class="badge-sm sm:badge-md badge-base">
                                        <x-slot:value>
                                            {{ str::Lower($firstEvent->integration->name) }}
                                        </x-slot:value>
                                    </x-badge>
                                    @endif
                                    @if ($firstEvent->tags && count($firstEvent->tags) > 0)
                                    <span class="hidden sm:inline">·</span>
                                    <span class="sm:hidden w-full"></span>
                                    @endif
                                    @foreach ($firstEvent->tags ?? [] as $tag)<x-spark-tag :tag="$tag" size="md" fill />@endforeach
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>
                    <div class="{{ $isCollapsed ? 'py-3' : 'py-2' }} text-right pr-2">
                        @if (! $isCollapsed)
                        @php $firstEvent = $eventGroup['events'][0]; @endphp
                        @if (! is_null($firstEvent->value))
                        <span class="text-lg font-bold {{ $this->valueColorClass($firstEvent) }}">{!! $this->formatValueDisplay($firstEvent) !!}</span>
                        @endif
                        @endif
                    </div>
                </div>

                @if (! $isCollapsed)
                @php $eventsToShow = array_slice($eventGroup['events'], 1); @endphp
                @foreach ($eventsToShow as $event)
                <div class="grid grid-cols-[1.25rem_1fr_auto] gap-3">
                    <div class="relative">
                        <div class="absolute left-2 top-0 bottom-0 w-px bg-base-300"></div>
                    </div>
                    <div class="py-2 px-2">
                        <a href="{{ route('events.show', $event->id) }}" class="text-base-content block hover:text-primary transition-colors min-w-0 text-lg">
                            <span class="font-medium">{{ $this->formatAction($event->action) }}</span>
                            @if (should_display_action_with_object($event->action, $event->service))
                            @if ($event->target)
                            <span class="sm:inline block break-words font-bold min-w-0">{{ ' ' . $event->target->title }}</span>
                            @elseif ($event->actor)
                            <span class="sm:inline block break-words font-bold min-w-0">{{ ' ' . $event->actor->title }}</span>
                            @endif
                            @endif
                        </a>
                        <div class="mt-1 text-sm text-base-content/70 flex items-center flex-wrap gap-1">
                            <span title="{{ to_user_timezone($event->time, auth()->user())->toDayDateTimeString() }}">{{ to_user_timezone($event->time, auth()->user())->diffForHumans() }}</span>
                            <span class="hidden sm:inline">·</span>
                            <span class="sm:hidden w-full"></span>
                            @if ($event->integration)
                            <x-badge class="badge-sm badge-outline">
                                <x-slot:value>
                                    {{ str::Lower($event->integration->name) }}
                                </x-slot:value>
                            </x-badge>
                            @endif
                            @if ($event->tags && count($event->tags) > 0)
                            <span class="hidden sm:inline">·</span>
                            <span class="sm:hidden w-full"></span>
                            @endif
                            @foreach ($event->tags ?? [] as $tag)<x-spark-tag :tag="$tag" size="sm" />@endforeach
                        </div>
                    </div>
                    <div class="py-2 pr-2 text-right">
                        @if (! is_null($event->value))
                        <span class="text-lg font-semibold {{ $this->valueColorClass($event) }}">{!! $this->formatValueDisplay($event) !!}</span>
                        @endif
                    </div>
                </div>
                @endforeach
                @endif

                @endforeach
            </div>
            @endif
        </div>

    <!-- Floating Action Button for Card Streams -->
    @php
        try {
            $availableStreamsCount = $this->availableStreams->count();
            $hasStreams = $availableStreamsCount > 0;
        } catch (\Throwable $e) {
            $hasStreams = false;
            $availableStreamsCount = 0;
        }
    @endphp

    <script>
        console.log('=== FAB Debug Info ===');
        console.log('Current time:', new Date().toLocaleTimeString());
        console.log('Viewing date:', @js($this->date));
        console.log('Is viewing today?:', @js(Carbon::parse($this->date)->isToday()));
        console.log('Has streams:', @js($hasStreams));
        console.log('Available streams count:', @js($availableStreamsCount));
        console.log('Available streams:', @js($this->availableStreams->toArray()));

        @if (!$hasStreams)
        console.log('⚠️ No streams detected! This could mean:');
        console.log('  1. No cards are eligible based on time/date constraints');
        console.log('  2. Card eligibility logic is filtering out all cards');
        console.log('  3. CardRegistry is not returning any eligible cards');
        @endif
    </script>

    @if ($hasStreams)
    <div class="fixed bottom-6 right-6 z-40"
        x-data="{
            userId: '{{ auth()->id() }}',
            currentDate: '{{ $this->date }}',
            streams: @js($this->availableStreams->values()->toArray()),
            shouldShow: true,

            getStorageKey() {
                return `spark_card_views_${this.userId}_${this.currentDate}`;
            },

            getCardState() {
                if (!this.userId) return {};

                try {
                    const stored = localStorage.getItem(this.getStorageKey());
                    return stored ? JSON.parse(stored) : {};
                } catch (e) {
                    return {};
                }
            },

            hasUnviewedCards(stream) {
                const state = this.getCardState();
                const streamState = state[stream.id] || {};
                const eligibleCards = stream.eligibleCardsMeta || [];

                console.log('=== Checking Stream:', stream.id, '===');
                console.log('Stream state:', streamState);
                console.log('Eligible cards:', eligibleCards);

                // Check each eligible card
                for (const card of eligibleCards) {
                    const cardState = streamState[card.id];

                    console.log(`Card ${card.id}:`, {
                        cardState,
                        viewed: cardState?.viewed,
                        requiresInteraction: card.requiresInteraction,
                        interacted: cardState?.interacted
                    });

                    // Card not viewed yet
                    if (!cardState || !cardState.viewed) {
                        console.log(`✓ Card ${card.id} is unviewed - showing FAB`);
                        return true;
                    }

                    // Card requires interaction but not completed
                    if (card.requiresInteraction && !cardState.interacted) {
                        console.log(`✓ Card ${card.id} requires interaction - showing FAB`);
                        return true;
                    }
                }

                console.log('✗ No unviewed cards in stream', stream.id);
                return false;
            },

            hasAnyUnviewedCards() {
                console.log('=== FAB Visibility Check ===');
                console.log('All streams:', this.streams);
                console.log('User ID:', this.userId);
                console.log('Current Date:', this.currentDate);
                console.log('Storage Key:', this.getStorageKey());

                const result = this.streams.some(stream => this.hasUnviewedCards(stream));
                console.log('=== FAB Should Show:', result, '===');
                return result;
            },

            checkVisibility() {
                this.shouldShow = this.hasAnyUnviewedCards();
            }
        }"
        x-init="checkVisibility()"
        x-show="shouldShow"
        x-transition
        @card-stream-closed.window="checkVisibility()">
        @if ($availableStreamsCount === 1)
        <!-- Single stream: simple FAB -->
        @php $stream = $this->availableStreams->first(); @endphp
        <button
            class="btn btn-circle btn-lg btn-primary shadow-lg"
            @click="console.log('FAB clicked!', { streamId: '{{ $stream->id }}', date: '{{ $this->date }}' }); $dispatch('open-card-stream', { streamId: '{{ $stream->id }}', date: '{{ $this->date }}' }); console.log('Event dispatched');"
            aria-label="Open {{ $stream->name }} stream">
            <x-icon name="{{ $stream->icon }}" class="w-6 h-6" />
        </button>
        @else
        <!-- Multiple streams: flower FAB -->
        <div class="dropdown dropdown-top dropdown-end">
            <div tabindex="0" role="button" class="btn btn-circle btn-lg btn-primary shadow-lg m-1">
                <x-icon name="fas-layer-group" class="w-6 h-6" />
            </div>
            <ul tabindex="0" class="dropdown-content menu bg-base-200 rounded-box z-[1] w-52 p-2 shadow-xl mb-2">
                @foreach ($this->availableStreams as $stream)
                <li>
                    <button
                        @click="$dispatch('open-card-stream', { streamId: '{{ $stream->id }}', date: '{{ $this->date }}' })"
                        class="flex items-center gap-2">
                        <x-icon name="{{ $stream->icon }}" class="w-5 h-5" />
                        <span>{{ $stream->name }}</span>
                        @if ($stream->description)
                        <span class="text-xs text-base-content/60">{{ $stream->description }}</span>
                        @endif
                    </button>
                </li>
                @endforeach
            </ul>
        </div>
        @endif
    </div>
    @endif
</div>