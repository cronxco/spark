<?php

namespace App\Livewire;

use App\Cards\CardRegistry;
use App\Integrations\DailyCheckin\DailyCheckinPlugin;
use App\Integrations\Outline\OutlineApi;
use App\Integrations\PluginRegistry;
use App\Jobs\Outline\OutlinePullTodayDayNote;
use App\Models\Event;
use App\Models\Integration;
use App\Traits\HasProgressiveLoading;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

class Day extends Component
{
    use HasProgressiveLoading;

    // UI State
    public string $view = 'index';
    public ?string $eventId = null;
    public string $search = '';
    public string $date;
    public string $pollMode = 'visible';
    public bool $dayNoteOpen = false;

    // Day Note State
    public ?string $dayNoteDocId = null;
    public ?string $dayNoteIntegrationId = null;
    public string $dayNoteText = '';
    public bool $dayNoteSaving = false;
    public ?string $dayNoteSavedAt = null;
    public int $dayNoteAutoSaveMs = 800;

    // Progressive Loading State Flags
    public bool $coreEventsLoaded = false;
    public bool $additionalDataLoaded = false;
    public bool $dayNoteLoaded = false;
    public bool $checkinStatusLoaded = false;
    public bool $cardStreamsLoaded = false;

    // Cached Data (for filtering)
    public ?Collection $allEvents = null;
    public ?Collection $filteredEvents = null;

    public function mount(): void
    {
        // Initialize date from route
        try {
            if (request()->routeIs('today.*')) {
                $this->date = Carbon::today()->format('Y-m-d');
            } elseif (request()->routeIs('day.yesterday')) {
                $this->date = Carbon::yesterday()->format('Y-m-d');
            } elseif (request()->routeIs('tomorrow')) {
                $this->date = Carbon::tomorrow()->format('Y-m-d');
            } else {
                $param = request()->route('date');
                $this->date = $param ? Carbon::parse($param)->format('Y-m-d') : Carbon::today()->format('Y-m-d');
            }
        } catch (Throwable $e) {
            $this->date = Carbon::today()->format('Y-m-d');
        }

        // Initialize collections
        $this->allEvents = collect();
        $this->filteredEvents = collect();

        // Start progressive loading
        $this->startProgressiveLoading();
    }

    // -------------------------------------------------------------------------
    // Progressive Loading Tier Methods
    // -------------------------------------------------------------------------

    /**
     * Tier 1: Load core event data with actor and target objects
     * This is crucial for displaying events properly
     */
    public function loadCoreEvents(): void
    {
        if ($this->coreEventsLoaded) {
            return;
        }

        try {
            $selectedDate = Carbon::parse($this->date);
        } catch (Throwable $e) {
            $selectedDate = Carbon::today();
        }

        // Load events with actor and target relationships
        // These are essential for display and must load together
        $query = Event::select([
            'id',
            'integration_id',
            'service',
            'action',
            'time',
            'value',
            'value_unit',
            'value_multiplier',
            'domain',
            'actor_id',
            'target_id',
        ])
            ->with(['actor', 'target']) // Load actor and target immediately
            ->whereHas('integration', function ($q) {
                $userId = optional(auth()->guard('web')->user())->id;
                if ($userId) {
                    $q->where('user_id', $userId);
                } else {
                    $q->whereRaw('1 = 0');
                }
            })
            ->whereDate('time', $selectedDate)
            ->orderBy('time', 'desc');

        $this->allEvents = $query->get();

        Log::info('Day: Loaded core events with actor and target', [
            'count' => $this->allEvents->count(),
            'date' => $this->date,
        ]);

        // Apply initial filtering
        $this->applyFilters();

        Log::info('Day: After filtering', [
            'count' => $this->filteredEvents->count(),
        ]);

        $this->coreEventsLoaded = true;
    }

    /**
     * Tier 2: Load additional data (integration, tags, blocks)
     * These provide context and details for the events
     */
    public function loadAdditionalData(): void
    {
        if ($this->additionalDataLoaded || ! $this->coreEventsLoaded || $this->allEvents === null || $this->allEvents->isEmpty()) {
            return;
        }

        // Load integration, tags, and blocks relationships together
        $this->allEvents->load(['integration', 'tags', 'blocks']);

        Log::info('Day: Loaded additional data (integration, tags, blocks)', [
            'count' => $this->allEvents->count(),
        ]);

        $this->additionalDataLoaded = true;
    }

    /**
     * Tier 3: Load day note (eager, but lower priority)
     */
    public function loadDayNote(): void
    {
        if ($this->dayNoteLoaded) {
            return;
        }

        // One-off background Outline pull before loading, to ensure freshness
        try {
            $outlineIntegration = Integration::query()
                ->where('service', 'outline')
                ->where('user_id', optional(auth()->guard('web')->user())->id)
                ->first();

            if ($outlineIntegration) {
                OutlinePullTodayDayNote::dispatch($outlineIntegration, $this->date)->onQueue('pull');
            }
        } catch (Throwable $e) {
            // ignore if dispatch fails; we'll still load cached note
        }

        $this->dayNoteDocId = null;
        $this->dayNoteIntegrationId = null;
        $this->dayNoteText = '';
        $this->dayNoteSavedAt = null;

        // Find day note event in our loaded events
        if ($this->allEvents === null || $this->allEvents->isEmpty()) {
            $this->dayNoteLoaded = true;

            return;
        }

        $event = $this->allEvents->first(function ($e) {
            return $e->service === 'outline' && $e->action === 'had_day_note';
        });

        if (! $event) {
            $this->dayNoteLoaded = true;

            return;
        }

        // Ensure target is loaded
        if (! $event->relationLoaded('target')) {
            $event->load('target');
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

        $this->dayNoteLoaded = true;
    }

    /**
     * Tier 3: Load check-in status
     */
    public function loadCheckinStatus(): void
    {
        if ($this->checkinStatusLoaded) {
            return;
        }

        // This is computed on-demand via the checkinStatus computed property
        // Just mark as loaded so the UI knows it's safe to call
        $this->checkinStatusLoaded = true;
    }

    /**
     * Tier 4: Load card streams for FAB (background, lowest priority)
     */
    public function loadCardStreams(): void
    {
        if ($this->cardStreamsLoaded) {
            return;
        }

        // This is computed on-demand via the availableStreams computed property
        // Just mark as loaded so the UI knows it's safe to call
        $this->cardStreamsLoaded = true;
    }

    public function updatedSearch(): void
    {
        // Re-apply filters on cached data (no database query)
        $this->applyFilters();
    }

    // -------------------------------------------------------------------------
    // Computed Properties
    // -------------------------------------------------------------------------

    #[Computed(persist: false)]
    public function events(): Collection
    {
        return $this->filteredEvents ?? collect();
    }

    #[Computed]
    public function checkinStatus(): string
    {
        if (! $this->checkinStatusLoaded) {
            return 'red'; // Default until loaded
        }

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
    }

    #[Computed]
    public function dateLabel(): string
    {
        $user = auth()->guard('web')->user();
        $today = user_today($user);

        try {
            $date = Carbon::parse($this->date);
        } catch (Throwable $e) {
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
    }

    #[Computed]
    public function groupedEvents(): array
    {
        if (! $this->coreEventsLoaded) {
            return [];
        }

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
                if ($sample->relationLoaded('target') && $sample->target && $sample->target->type) {
                    $type = $sample->target->type;
                } elseif ($sample->relationLoaded('actor') && $sample->actor && $sample->actor->type) {
                    $type = $sample->actor->type;
                }
                if ($type) {
                    $objectTypePlural = Str::plural(Str::headline($type));
                }
            }

            $group['formatted_action'] = format_action_title($group['action']);
            $group['count'] = $count;
            $group['object_type_plural'] = $objectTypePlural;
            $group['summary'] = $group['formatted_action'] . ' ' . $count . ' ' . $objectTypePlural;
        }

        return $groups;
    }

    #[Computed]
    public function initialCollapsedGroups(): array
    {
        $groups = $this->groupedEvents;
        $collapsed = [];

        foreach ($groups as $group) {
            // Auto-collapse if 4 or more events in group
            if (count($group['events']) >= 4) {
                $collapsed[$group['key']] = true;
            }
        }

        return $collapsed;
    }

    #[Computed]
    public function availableStreams(): Collection
    {
        if (! $this->cardStreamsLoaded) {
            return collect();
        }

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
        } catch (Throwable $e) {
            Log::error('FAB Debug: Error getting streams', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return collect();
        }
    }

    // -------------------------------------------------------------------------
    // Navigation Methods
    // -------------------------------------------------------------------------

    public function navigateToDate(): void
    {
        try {
            $selected = Carbon::parse($this->date)->startOfDay();
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
        } catch (Throwable $e) {
            // Ignore
        }
    }

    public function updatedDate($value): void
    {
        $this->navigateToDate();
    }

    public function previousDay(): void
    {
        try {
            $current = Carbon::parse($this->date);
        } catch (Throwable $e) {
            $current = Carbon::today();
        }

        $this->date = $current->copy()->subDay()->format('Y-m-d');
        $this->navigateToDate();
    }

    public function nextDay(): void
    {
        try {
            $current = Carbon::parse($this->date);
        } catch (Throwable $e) {
            $current = Carbon::today();
        }

        $this->date = $current->copy()->addDay()->format('Y-m-d');
        $this->navigateToDate();
    }

    // -------------------------------------------------------------------------
    // Group Management
    // -------------------------------------------------------------------------
    // Note: Group collapse/expand is now handled client-side via Alpine.js
    // for instant performance without server roundtrips

    public function togglePollMode(): void
    {
        $this->pollMode = $this->pollMode === 'keep' ? 'visible' : 'keep';
    }

    // -------------------------------------------------------------------------
    // Day Note Methods
    // -------------------------------------------------------------------------

    public function saveDayNote(): void
    {
        if (empty($this->dayNoteDocId) || empty($this->dayNoteIntegrationId)) {
            return;
        }

        $this->dayNoteSaving = true;

        try {
            /** @var Integration $integration */
            $integration = Integration::findOrFail($this->dayNoteIntegrationId);
            $api = new OutlineApi($integration);
            $api->updateDocumentContent($this->dayNoteDocId, $this->dayNoteText, null, true);

            // Trigger a lightweight targeted refresh for this date to reconcile tasks/blocks
            OutlinePullTodayDayNote::dispatch($integration, $this->date)->onQueue('pull');

            $this->dayNoteSavedAt = now()->toIso8601String();
        } catch (Throwable $e) {
            // Swallow; UI can show failure later if needed
        } finally {
            $this->dayNoteSaving = false;
        }
    }

    public function updatedDayNoteText($value): void
    {
        if ($this->dayNoteSaving) {
            return;
        }
        if (! is_string($value)) {
            return;
        }
        $this->saveDayNote();
    }

    public function refreshDayNoteFromOutline(): void
    {
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
            $doc = $api->getDocument($this->dayNoteDocId);
            $remoteText = (string) ($doc['data']['text'] ?? ($doc['text'] ?? ''));
            if ($remoteText !== '' && $remoteText !== $this->dayNoteText) {
                $this->dayNoteText = $remoteText;
            }
        } catch (Throwable $e) {
            // ignore transient errors
        }
    }

    // -------------------------------------------------------------------------
    // Helper Methods (for view)
    // -------------------------------------------------------------------------

    public function formatAction($action): string
    {
        return format_action_title($action);
    }

    public function getEventIcon($action, $service): string
    {
        // Try to get icon from plugin configuration first
        $pluginClass = PluginRegistry::getPlugin($service);
        if ($pluginClass) {
            $actionTypes = $pluginClass::getActionTypes();
            if (isset($actionTypes[$action]) && isset($actionTypes[$action]['icon'])) {
                return $actionTypes[$action]['icon'];
            }
        }

        // Fallback to hardcoded icons
        $icons = [
            'create' => 'fas.circle-plus',
            'update' => 'fas.rotate',
            'delete' => 'fas.trash',
            'move' => 'fas.arrow-right',
            'copy' => 'fas.copy',
            'share' => 'fas.share',
            'like' => 'fas.heart',
            'comment' => 'fas.comment',
            'follow' => 'fas.user-plus',
            'unfollow' => 'fas.user-minus',
            'join' => 'fas.users',
            'leave' => 'fas.users',
            'start' => 'fas.play',
            'stop' => 'fas.stop',
            'pause' => 'fas.pause',
            'resume' => 'fas.play',
            'complete' => 'fas.circle-check',
            'fail' => 'fas.circle-xmark',
            'cancel' => 'fas.xmark',
            'approve' => 'fas.check',
            'reject' => 'fas.xmark',
            'publish' => 'fas.globe',
            'unpublish' => 'fas.eye-slash',
            'archive' => 'fas.box-archive',
            'restore' => 'fas.rotate',
            'login' => 'fas.right-from-bracket',
            'logout' => 'fas.right-to-bracket',
            'purchase' => 'o-shopping-cart',
            'refund' => 'fas.rotate',
            'transfer' => 'fas.arrow-right',
            'withdraw' => 'fas.arrow-down',
            'deposit' => 'fas.arrow-up',
            'listen' => 'fas.music',
            'watch' => 'o-video-camera',
            'read' => 'fas.book-open',
            'write' => 'fas.pen',
            'send' => 'fas.paper-plane',
            'receive' => 'fas.inbox',
            'download' => 'fas.download',
            'upload' => 'fas.upload',
            'save' => 'fas.bookmark',
            'bookmark' => 'fas.bookmark',
            'favorite' => 'fas.heart',
            'rate' => 'fas.star',
            'review' => 'fas.comment-dots',
            'subscribe' => 'fas.bell',
            'unsubscribe' => 'fas.bell-slash',
            'block' => 'fas.ban',
            'unblock' => 'fas.circle-check',
            'mute' => 'fas.volume-xmark',
            'unmute' => 'fas.volume-high',
            'pin' => 'fas.location-dot',
            'unpin' => 'fas.location-dot',
            'lock' => 'fas.lock',
            'unlock' => 'fas.lock-open',
            'hide' => 'fas.eye-slash',
            'show' => 'fas.eye',
            'enable' => 'fas.check',
            'disable' => 'fas.xmark',
            'activate' => 'fas.power-off',
            'deactivate' => 'fas.power-off',
            'connect' => 'fas.link',
            'disconnect' => 'o-link-slash',
            'sync' => 'fas.rotate',
            'backup' => 'fas.box-archive',
            'export' => 'fas.download',
            'import' => 'fas.upload',
            'install' => 'fas.arrow-down',
            'uninstall' => 'fas.trash',
            'upgrade' => 'fas.arrow-trend-up',
            'downgrade' => 'fas.arrow-trend-down',
            'pot' => 'fas.arrow-right',
            'add' => 'fas.plus',
            'remove' => 'fas.minus',
            'increase' => 'fas.arrow-trend-up',
            'decrease' => 'fas.arrow-trend-down',
        ];

        return $icons[strtolower($action)] ?? 'fas.bolt';
    }

    public function getEventColor($action): string
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

    public function getAccentColorForService($service): string
    {
        $pluginClass = PluginRegistry::getPlugin($service);
        if ($pluginClass) {
            return 'text-' . ($pluginClass::getAccentColor() ?: 'primary');
        }

        return 'text-primary';
    }

    public function getBadgeAccentForService($service): string
    {
        $pluginClass = PluginRegistry::getPlugin($service);
        if ($pluginClass) {
            return 'badge-' . ($pluginClass::getAccentColor() ?: 'primary');
        }

        return 'badge-primary';
    }

    public function valueColorClass($event): string
    {
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
    }

    public function isDurationUnit($unit): bool
    {
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
    }

    public function formatDurationShort($value, $unit): string
    {
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
        } else {
            // seconds and aliases
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
    }

    public function formatValueDisplay($event): string
    {
        $value = $event->formatted_value ?? $event->value;
        $unit = $event->value_unit;

        if ($this->isDurationUnit($unit)) {
            return $this->formatDurationShort($value, $unit);
        }

        return format_event_value_display($value, $unit, $event->service, $event->action, 'action');
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function render(): View
    {
        return view('livewire.day');
    }

    // -------------------------------------------------------------------------
    // Progressive Loading Configuration
    // -------------------------------------------------------------------------

    protected function getLoadingTiers(): array
    {
        return [
            1 => ['loadCoreEvents'],                        // Critical: Events with actor & target
            2 => ['loadAdditionalData'],                    // Important: Integration, tags, blocks
            3 => ['loadDayNote', 'loadCheckinStatus'],      // Nice-to-have: Day note + checkin
            4 => ['loadCardStreams'],                       // Background: FAB streams
        ];
    }

    // -------------------------------------------------------------------------
    // Filtering and Search
    // -------------------------------------------------------------------------

    /**
     * Apply filters to cached event data (no re-query)
     */
    protected function applyFilters(): void
    {
        if ($this->allEvents === null) {
            $this->filteredEvents = collect();

            return;
        }

        $filtered = $this->allEvents;

        // Apply search filter if present
        if ($this->search) {
            $filtered = $filtered->filter(function ($event) {
                // Search in event properties
                if (stripos($event->action, $this->search) !== false) {
                    return true;
                }
                if (stripos($event->domain, $this->search) !== false) {
                    return true;
                }
                if (stripos($event->service, $this->search) !== false) {
                    return true;
                }
                if (stripos($event->value_unit, $this->search) !== false) {
                    return true;
                }

                // Search in actor/target if loaded
                if ($event->relationLoaded('actor') && $event->actor) {
                    if (stripos($event->actor->title, $this->search) !== false) {
                        return true;
                    }
                    if (stripos($event->actor->content, $this->search) !== false) {
                        return true;
                    }
                    if (stripos($event->actor->concept, $this->search) !== false) {
                        return true;
                    }
                    if (stripos($event->actor->type, $this->search) !== false) {
                        return true;
                    }
                }

                if ($event->relationLoaded('target') && $event->target) {
                    if (stripos($event->target->title, $this->search) !== false) {
                        return true;
                    }
                    if (stripos($event->target->content, $this->search) !== false) {
                        return true;
                    }
                    if (stripos($event->target->concept, $this->search) !== false) {
                        return true;
                    }
                    if (stripos($event->target->type, $this->search) !== false) {
                        return true;
                    }
                }

                return false;
            });
        }

        // Filter out hidden actions (using plugin registry)
        $filtered = $filtered->filter(function ($event) {
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

        $this->filteredEvents = $filtered->values();
    }
}
