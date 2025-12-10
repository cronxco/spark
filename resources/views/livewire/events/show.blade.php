<?php

use App\Integrations\PluginRegistry;
use App\Models\Event;
use App\Traits\HasProgressiveLoading;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Spatie\Activitylog\Models\Activity;
use Spatie\Tags\Tag;

new class extends Component
{
    use HasProgressiveLoading;

    public Event $event;
    public bool $showSidebar = false;
    public string $comment = '';
    public bool $activityOpen = true;
    public bool $detailsOpen = true;
    public bool $technicalOpen = false;
    public bool $actorOpen = true;
    public bool $targetOpen = true;
    public bool $eventMetaOpen = false;
    public bool $actorMetaOpen = false;
    public bool $targetMetaOpen = false;
    public bool $targetContentOpen = true;
    public bool $showCreateTagModal = false;
    public bool $showEditEventModal = false;
    public bool $showTagModal = false;
    public bool $showManageRelationshipsModal = false;
    public bool $showAddRelationshipModal = false;

    // Progressive loading state flags
    public bool $coreLoaded = false;
    public bool $tagsLoaded = false;
    public bool $blocksLoaded = false;
    public bool $mediaLoaded = false;
    public bool $relationshipsLoaded = false;
    public bool $tasksLoaded = false;
    public bool $relatedEventsLoaded = false;
    public bool $activitiesLoaded = false;
    public bool $drawerContentLoaded = false;

    // Collapse states
    public bool $tasksOpen = false;

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
        'drawer-opened' => 'loadDrawerContent',
    ];

    // -------------------------------------------------------------------------
    // Public Methods
    // -------------------------------------------------------------------------

    public function mount(Event $event): void
    {
        // Load only the bare minimum - just the event with integration for display
        $this->event = $event->load(['integration']);

        // Track this view in the activity log (debounced to prevent duplicate views)
        $this->event->logViewIfNotRecent(5);

        // Start progressive loading chain
        $this->startProgressiveLoading();
    }

    /**
     * Load actor, target, and their tags (core relationships for main display)
     */
    public function loadCore(): void
    {
        if ($this->coreLoaded) {
            return;
        }
        $this->event->load(['actor.tags', 'target.tags']);
        $this->coreLoaded = true;
    }

    /**
     * Load tags for the event
     */
    public function loadTags(): void
    {
        if ($this->tagsLoaded) {
            return;
        }
        $this->event->load(['tags']);
        $this->tagsLoaded = true;
    }

    /**
     * Load blocks linked to the event
     */
    public function loadBlocks(): void
    {
        if ($this->blocksLoaded) {
            return;
        }
        $this->event->load(['blocks.media']);
        $this->blocksLoaded = true;
    }

    /**
     * Load media for actor and target objects
     */
    public function loadMedia(): void
    {
        if ($this->mediaLoaded) {
            return;
        }
        // Load media collections for actor and target if they exist
        if ($this->event->actor) {
            $this->event->actor->load(['media']);
        }
        if ($this->event->target) {
            $this->event->target->load(['media']);
        }
        $this->mediaLoaded = true;
    }

    /**
     * Load relationships with their related models
     */
    public function loadRelationships(): void
    {
        if ($this->relationshipsLoaded) {
            return;
        }
        $this->event->load(['relationshipsFrom', 'relationshipsTo']);
        $this->relationshipsLoaded = true;
    }

    /**
     * Load task execution information
     */
    public function loadTasks(): void
    {
        if ($this->tasksLoaded) {
            return;
        }

        // Calculate smart default for collapse state
        $this->tasksOpen = $this->shouldExpandTasksSection();
        $this->tasksLoaded = true;
    }

    /**
     * Load related events via semantic search (expensive operation)
     */
    public function loadRelatedEvents(): void
    {
        if ($this->relatedEventsLoaded) {
            return;
        }
        // Simply mark as loaded - the computed property will do the work
        $this->relatedEventsLoaded = true;
    }

    /**
     * Load activity log entries
     */
    public function loadActivities(): void
    {
        if ($this->activitiesLoaded) {
            return;
        }
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

    #[Computed]
    public function relationships()
    {
        if (! $this->relationshipsLoaded) {
            return collect();
        }

        return $this->event->allRelationships()->get();
    }

    #[Computed]
    public function actorMedia()
    {
        if (! $this->mediaLoaded || ! $this->event->actor) {
            return collect();
        }

        return $this->event->actor->getMedia('screenshots')
            ->merge($this->event->actor->getMedia('downloaded_images'));
    }

    #[Computed]
    public function targetMedia()
    {
        if (! $this->mediaLoaded || ! $this->event->target) {
            return collect();
        }

        return $this->event->target->getMedia('screenshots')
            ->merge($this->event->target->getMedia('downloaded_images'));
    }

    #[Computed]
    public function relatedEvents()
    {
        if (! $this->relatedEventsLoaded) {
            return collect();
        }

        // Use semantic search if embeddings exist
        if (! empty($this->event->embeddings)) {
            try {
                $embedding = json_decode($this->event->embeddings, true);

                if (is_array($embedding) && count($embedding) > 0) {
                    // Get user's integration IDs for security
                    $userIntegrationIds = auth()->user()->integrations()->pluck('id')->toArray();

                    // Perform semantic search with temporal weighting
                    return Event::semanticSearch($embedding, threshold: 1.2, limit: 5, temporalWeight: 0.015)
                        ->whereIn('integration_id', $userIntegrationIds)
                        ->where('id', '!=', $this->event->id) // Exclude current event
                        ->with(['actor', 'target', 'integration', 'tags'])
                        ->get();
                }
            } catch (\Exception $e) {
                Log::warning('Semantic search failed for related events', [
                    'event_id' => $this->event->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to original logic if embeddings don't exist or semantic search fails
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

    #[Computed]
    public function eventAnomalies()
    {
        // Check if this event has any associated anomalies
        if (! $this->event->value || ! $this->event->value_unit) {
            return collect();
        }

        $userId = optional(auth()->guard('web')->user())->id;
        if (! $userId) {
            return collect();
        }

        // Find metric statistic for this event
        $metricStatistic = App\Models\MetricStatistic::where('user_id', $userId)
            ->where('service', $this->event->service)
            ->where('action', $this->event->action)
            ->where('value_unit', $this->event->value_unit)
            ->first();

        if (! $metricStatistic) {
            return collect();
        }

        // Find anomalies that reference this event
        return App\Models\MetricTrend::where('metric_statistic_id', $metricStatistic->id)
            ->anomalies()
            ->whereJsonContains('metadata->event_id', $this->event->id)
            ->with('metricStatistic')
            ->get();
    }

    #[Computed]
    public function activities()
    {
        if (! $this->activitiesLoaded) {
            return collect();
        }

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
        ];

        return $icons[strtolower($action)] ?? 'fas.bolt';
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
        $this->showSidebar = ! $this->showSidebar;
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
        $this->event->refresh()->load(['integration']);
        // Reload any sections that were already loaded
        if ($this->coreLoaded) {
            $this->event->load(['actor.tags', 'target.tags']);
        }
        if ($this->tagsLoaded) {
            $this->event->load(['tags']);
        }
        if ($this->blocksLoaded) {
            $this->event->load(['blocks']);
        }
        $this->showEditEventModal = false;
    }

    public function handleTagsUpdated(): void
    {
        $this->event->refresh()->loadMissing(['tags']);
        $this->tagsLoaded = true;
    }

    public function getCompleteEventData(): array
    {
        return [
            'event' => $this->event->toArray(),
            'actor' => $this->event->actor?->toArray(),
            'target' => $this->event->target?->toArray(),
            'blocks' => $this->event->blocks->toArray(),
            'tags' => $this->event->tags->toArray(),
            'relationships' => $this->event->allRelationships()->get()->map(function ($rel) {
                return [
                    'type' => $rel->type,
                    'from' => ['type' => $rel->from_type, 'id' => $rel->from_id],
                    'to' => ['type' => $rel->to_type, 'id' => $rel->to_id],
                    'value' => $rel->value,
                    'value_unit' => $rel->value_unit,
                    'metadata' => $rel->metadata,
                ];
            })->toArray(),
            'metadata' => [
                'event' => $this->event->event_metadata ?? [],
                'actor' => $this->event->actor_metadata ?? [],
                'target' => $this->event->target_metadata ?? [],
            ],
        ];
    }

    public function exportAsJson(): void
    {
        $data = $this->getCompleteEventData();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->js('
            const blob = new Blob([' . json_encode($json) . "], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'event-{$this->event->id}-" . now()->format('Y-m-d-His') . ".json';
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
                    <span>Event exported!</span>
                </div>
            `;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 2000);
        ");
    }

    /**
     * Get complete event data without embeddings fields.
     */
    public function getCompleteEventDataWithoutEmbeddings(): array
    {
        $data = $this->getCompleteEventData();

        // Strip embeddings from event
        if (isset($data['event'])) {
            $data['event'] = $this->stripEmbeddings($data['event']);
        }

        // Strip embeddings from actor
        if (isset($data['actor']) && is_array($data['actor'])) {
            $data['actor'] = $this->stripEmbeddings($data['actor']);
        }

        // Strip embeddings from target
        if (isset($data['target']) && is_array($data['target'])) {
            $data['target'] = $this->stripEmbeddings($data['target']);
        }

        return $data;
    }

    /**
     * Copy event data to clipboard (without embeddings).
     */
    public function copyEventWithoutEmbeddings(): void
    {
        $data = $this->getCompleteEventDataWithoutEmbeddings();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->js('
            navigator.clipboard.writeText(' . json_encode($json) . ").then(function() {
                const toast = document.createElement('div');
                toast.className = 'toast toast-top toast-center z-50';
                toast.innerHTML = `
                    <div class='alert alert-success shadow-lg'>
                        <svg xmlns='http://www.w3.org/2000/svg' class='stroke-current shrink-0 h-5 w-5' fill='none' viewBox='0 0 24 24'>
                            <path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' />
                        </svg>
                        <span>Event copied to clipboard!</span>
                    </div>
                `;
                document.body.appendChild(toast);
                setTimeout(() => {
                    toast.classList.add('opacity-0');
                    setTimeout(() => toast.remove(), 300);
                }, 2000);
            });
        ");
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
            'relationshipsTo',
        ]);
    }

    public function closeModals(): void
    {
        $this->showEditEventModal = false;
        $this->showTagModal = false;
        $this->showManageRelationshipsModal = false;
        $this->showAddRelationshipModal = false;
    }

    // -------------------------------------------------------------------------
    // Protected Methods
    // -------------------------------------------------------------------------

    /**
     * Define the loading tiers for progressive loading.
     * Priority order optimized for main view content first, drawer content later.
     * Tier 1-3: Main page visible content (core data, tags, blocks)
     * Tier 4-5: Supporting content (media, related events)
     * Tier 6-7: Drawer-only content (loaded on-demand when drawer opens)
     */
    protected function getLoadingTiers(): array
    {
        return [
            1 => ['loadCore'],               // Actor/target (shown in main view) - highest priority
            2 => ['loadTags', 'loadBlocks'], // Visible in main view
            3 => ['loadMedia'],              // Media for actor/target objects
            4 => ['loadRelatedEvents'],      // Shown in main view, but lower priority
            5 => ['loadRelationships'],      // Drawer-only (loaded on-demand)
            6 => ['loadTasks'],              // Drawer-only (loaded on-demand)
            7 => ['loadActivities'],         // Drawer-only (loaded on-demand)
        ];
    }

    /**
     * Determine if tasks section should be expanded by default
     */
    protected function shouldExpandTasksSection(): bool
    {
        // Expand if there are failed or pending tasks
        $metadata = $this->event->event_metadata ?? [];
        $executions = $metadata['task_executions'] ?? [];

        foreach ($executions as $execution) {
            $status = $execution['last_attempt']['status'] ?? null;
            if (in_array($status, ['failed', 'pending'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove embeddings field from an array recursively.
     */
    private function stripEmbeddings(array $data): array
    {
        unset($data['embeddings']);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->stripEmbeddings($value);
            }
        }

        return $data;
    }
};
?>

<div x-data="{ drawerOpen: @entangle('showSidebar').live }"
     x-init="$watch('drawerOpen', value => {
         if (value) {
             setTimeout(() => $wire.dispatch('drawer-opened'), 50);
         }
     })">
    @if ($this->event)
    <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
        <!-- Main Content Area -->
        <div class="flex-1 space-y-4 lg:space-y-6">
            <!-- Header -->
            <x-header title="Event Details" separator>
                <x-slot:actions>
                    <x-button
                        @click="drawerOpen = !drawerOpen"
                        class="btn-ghost btn-sm"
                        ::title="drawerOpen ? 'Hide details' : 'Show details'"
                        ::aria-label="drawerOpen ? 'Hide details' : 'Show details'"
                        data-hotkey="d">
                        <x-icon name="fas.sliders" class="w-4 h-4" />
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
                                    <x-action-ref :action="$this->event->action" :service="$this->event->service" variant="text" />
                                    @if (should_display_action_with_object($this->event->action, $this->event->service))
                                    @if ($this->event->target)
                                    <x-object-ref :object="$this->event->target" variant="text" />
                                    @elseif ($this->event->actor)
                                    <x-object-ref :object="$this->event->actor" variant="text" />
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
                                <x-icon name="fas.clock" class="w-4 h-4 text-base-content/60 flex-shrink-0" />
                                <span class="text-base-content/70">{{ to_user_timezone($this->event->time, auth()->user())->format('d/m/Y H:i') }} · {{ to_user_timezone($this->event->time, auth()->user())->diffForHumans() }}</span>
                            </div>
                            <span class="hidden sm:inline">·</span>
                            <span class="sm:hidden w-full"></span>
                            @if ($this->event->domain)
                            <x-badge class="badge-xs badge-outline">
                                <x-slot:value>
                                    <x-icon name="fas.layer-group" class="w-3 h-3 text-base-content/40" />
                                    {{ str::Headline($this->event->domain) }}
                                </x-slot:value>
                            </x-badge>
                            <x-icon name="fas.arrow-right" class="w-3 h-3 text-base-content/40" />
                            @endif
                            <x-badge class="badge-xs badge-outline">
                                <x-slot:value>
                                    <x-icon name="fas.bell-concierge" class="w-3 h-3 text-base-content/40" />
                                    {{ str::Headline($this->event->service) }}
                                </x-slot:value>
                            </x-badge>
                            @if ($this->event->integration && (str::Headline($this->event->integration->instance_type) !== str::Headline($this->event->integration->name)))
                            <x-icon name="fas.arrow-right" class="w-3 h-3 text-base-content/40" />
                            <x-badge class="badge-xs badge-outline">
                                <x-slot:value>
                                    <x-icon name="fas.cube" class="w-3 h-3 text-base-content/40" />
                                    {{ str::Headline($this->event->integration->instance_type) }}
                                </x-slot:value>
                            </x-badge>
                            @endif
                            @if ($this->event->integration)
                            <x-icon name="fas.arrow-right" class="w-3 h-3 text-base-content/40" />
                            <x-integration-ref :integration="$this->event->integration" :showStatus="false" />
                            @endif
                        </div>

                        <!-- Actor & Target Flow (Progressive) -->
                        @if ($this->event->actor_id || $this->event->target_id)
                        <div class="mt-4 lg:mt-6 p-3 lg:p-4 rounded-lg bg-base-300/50 border-2 border-info/20">
                            @if ($coreLoaded)
                            <div class="flex flex-col sm:flex-row items-center justify-center gap-3 lg:gap-4">
                                @if ($this->event->actor)
                                <div class="flex items-center gap-2">
                                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-secondary/10 flex items-center justify-center">
                                        <x-icon name="fas.user" class="w-4 h-4 sm:w-5 sm:h-5 text-secondary" />
                                    </div>
                                    <x-object-ref :object="$this->event->actor" />
                                </div>
                                @endif

                                @if ($this->event->actor && $this->event->target)
                                <div class="flex items-center gap-2">
                                    <x-icon name="fas.arrow-down" class="w-4 h-4 text-base-content/40 sm:hidden" />
                                    <x-icon name="fas.arrow-right" class="w-4 h-4 text-base-content/40 hidden sm:block" />
                                    <x-action-ref :action="$this->event->action" :service="$this->event->service" />
                                    <x-icon name="fas.arrow-down" class="w-4 h-4 text-base-content/40 sm:hidden" />
                                    <x-icon name="fas.arrow-right" class="w-4 h-4 text-base-content/40 hidden sm:block" />
                                </div>
                                @endif

                                @if ($this->event->target)
                                <div class="flex items-center gap-2">
                                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-accent/10 flex items-center justify-center">
                                        <x-icon name="fas.arrow-trend-up" class="w-4 h-4 sm:w-5 sm:h-5 text-accent" />
                                    </div>
                                    <x-object-ref :object="$this->event->target" />
                                </div>
                                @endif
                            </div>
                            @else
                            <x-skeleton-loader type="avatar-row" />
                            @endif
                        </div>
                        @endif

                        <!-- Tags (Progressive) -->
                        <div class="mt-4">
                            @if ($tagsLoaded && $this->event->tags->isNotEmpty())
                            <div class="flex flex-wrap justify-center gap-2">
                                @foreach ($this->event->tags as $tag)
                                <x-spark-tag :tag="$tag" />
                                @endforeach
                            </div>
                            @elseif (! $tagsLoaded)
                            <x-skeleton-loader type="tags" class="justify-center" />
                            @endif
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Anomaly Information -->
            @php $anomalies = $this->eventAnomalies; @endphp
            @if ($anomalies->isNotEmpty())
            <x-card class="bg-warning/5 border-2 border-warning/30">
                <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                    <x-icon name="fas.triangle-exclamation" class="w-5 h-5 text-warning" />
                    Anomaly Detected
                </h3>
                <div class="space-y-3">
                    @foreach ($anomalies as $anomaly)
                    <div class="rounded-lg bg-base-100 p-4 border border-warning/20">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-2">
                                    @if ($anomaly->getDirection() === 'up')
                                    <x-icon name="fas.arrow-trend-up" class="h-5 w-5 text-warning" />
                                    @else
                                    <x-icon name="fas.arrow-trend-down" class="h-5 w-5 text-warning" />
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

            <!-- Target Object Content -->
            @if ($this->event->target?->content)
            <div class="mb-6">
                <x-collapse wire:model="targetContentOpen">
                    <x-slot:heading>
                        <div class="text-lg font-semibold text-base-content flex items-center gap-2">
                            <x-icon name="fas.file-lines" class="w-5 h-5 text-info" />
                            {{ $this->event->target->title }}
                        </div>
                    </x-slot:heading>
                    <x-slot:content>
                        <div class="max-w-prose mx-auto pt-4">
                            <div class="prose dark:prose-invert prose-base lg:prose-lg">
                                {!! Str::markdown($this->event->target->content) !!}
                            </div>
                        </div>
                    </x-slot:content>
                </x-collapse>
            </div>
            @endif

            <!-- Linked Blocks (Progressive) -->
            <div>
                @if ($blocksLoaded && $this->event->blocks->isNotEmpty())
                <div>
                    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                        <x-icon name="fas.grip" class="w-5 h-5 text-info" />
                        Linked Blocks ({{ $this->event->blocks->count() }})
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach ($this->event->blocks as $block)
                            <x-block-card :block="$block" />
                        @endforeach
                    </div>
                </div>
                @elseif (! $blocksLoaded)
                <div>
                    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                        <x-icon name="fas.grip" class="w-5 h-5 text-info" />
                        Linked Blocks
                    </h3>
                    <x-skeleton-loader type="block-grid" />
                </div>
                @endif
            </div>

            <!-- Media Gallery (Progressive) -->
            @php
                $actorMedia = $this->actorMedia;
                $targetMedia = $this->targetMedia;
                $allMedia = $actorMedia->merge($targetMedia);
            @endphp
            @if ($mediaLoaded && $allMedia->isNotEmpty())
            <div>
                <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                    <x-icon name="fas.images" class="w-5 h-5 text-secondary" />
                    Media ({{ $allMedia->count() }})
                </h3>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    @foreach ($allMedia->take(8) as $media)
                    @php
                        // Use helper function for S3 signed URLs
                        $fullUrl = get_media_object_url($media);
                    @endphp
                    <div class="aspect-square rounded-lg overflow-hidden bg-base-200 border border-base-300">
                        {!! render_media_object_responsive($media, [
                            'alt' => $media->name,
                            'class' => 'w-full h-full object-cover hover:scale-105 transition-transform cursor-pointer',
                            'loading' => 'lazy',
                            'onclick' => "window.open('" . addslashes($fullUrl) . "', '_blank')",
                        ]) !!}
                    </div>
                    @endforeach
                </div>
                @if ($allMedia->count() > 8)
                <p class="text-sm text-base-content/60 mt-2 text-center">
                    +{{ $allMedia->count() - 8 }} more items
                </p>
                @endif
            </div>
            @elseif (! $mediaLoaded)
            <div>
                <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                    <x-icon name="fas.images" class="w-5 h-5 text-secondary" />
                    Media
                </h3>
                <x-skeleton-loader type="block-grid" />
            </div>
            @endif

            <!-- Related Events (Progressive) -->
            <div>
                @if ($relatedEventsLoaded && $this->relatedEvents->isNotEmpty())
                <div class="relative">
                    <div class="bg-gradient-to-br from-warning/5 to-warning/25 rounded-lg p-4 border border-warning/50">
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="fas.rotate" class="w-5 h-5 text-warning" />
                            Related Events
                        </h3>
                        <div class="space-y-3">
                            @foreach ($this->relatedEvents as $relatedEvent)
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
                                                <div class="flex items-center gap-2 flex-shrink-0">
                                                    @if (isset($relatedEvent->similarity))
                                                    @php
                                                        $similarity = round((1 - $relatedEvent->similarity) * 100);
                                                        $daysAgo = isset($relatedEvent->days_ago) ? round($relatedEvent->days_ago) : null;
                                                    @endphp
                                                    <span class="badge badge-warning badge-xs">{{ $similarity }}% match</span>
                                                    @if ($daysAgo !== null)
                                                        @if ($daysAgo === 0)
                                                            <span class="text-xs">🔥</span>
                                                        @elseif ($daysAgo === 1)
                                                            <span class="text-xs">⏰</span>
                                                        @elseif ($daysAgo < 7)
                                                            <span class="text-xs opacity-70">{{ $daysAgo }}d</span>
                                                        @endif
                                                    @endif
                                                    @endif
                                                    @if ($relatedEvent->value)
                                                    <span class="text-sm text-primary font-semibold">
                                                        {!! format_event_value_display($relatedEvent->formatted_value, $relatedEvent->value_unit, $relatedEvent->service, $relatedEvent->action, 'action') !!}
                                                    </span>
                                                    @endif
                                                </div>
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
                                        <x-icon name="fas.chevron-right" class="w-4 h-4 text-base-content/40 flex-shrink-0 mt-1" />
                                    </div>
                                </a>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    <!-- AI Badge -->
                    <div class="absolute -top-2 -right-2 bg-warning rounded-full p-1.5 shadow">
                        <x-icon name="fas.wand-magic-sparkles" class="w-3 h-3 text-warning-content" />
                    </div>
                </div>
                @elseif (! $relatedEventsLoaded)
                <div class="relative">
                    <div class="bg-gradient-to-br from-warning/5 to-warning/25 rounded-lg p-4 border border-warning/50">
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="fas.rotate" class="w-5 h-5 text-warning" />
                            Related Events
                        </h3>
                        <x-skeleton-loader type="event-list" />
                    </div>
                    <!-- AI Badge -->
                    <div class="absolute -top-2 -right-2 bg-warning rounded-full p-1.5 shadow">
                        <x-icon name="fas.wand-magic-sparkles" class="w-3 h-3 text-warning-content" />
                    </div>
                </div>
                @endif
            </div>

            <!-- Relationships (Progressive) -->
            <div>
            @php $relationships = $this->relationships; @endphp
            @if ($relationshipsLoaded && $relationships->isNotEmpty())
            <x-card class="bg-base-200/50 border-2 border-accent/10">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold flex items-center gap-2">
                        <x-icon name="fas.right-left" class="w-5 h-5 text-accent" />
                        Relationships ({{ $relationships->count() }})
                    </h3>
                    <x-button
                        icon="fas.gear"
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
                    $icon = 'fas.calendar';
                    $title = $relatedModel->action;
                    $subtitle = $relatedModel->time?->format('M j, Y g:i A');
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
                    $icon = 'fas.grip';
                    $title = $relatedModel->type;
                    $subtitle = $relatedModel->time?->format('M j, Y');
                    $route = route('blocks.show', $relatedModel);
                    $badgeText = 'Block';
                    $badgeClass = 'badge-outline';
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
            @elseif (! $relationshipsLoaded)
            <x-card class="bg-base-200/50 border-2 border-accent/10">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold flex items-center gap-2">
                        <x-icon name="fas.right-left" class="w-5 h-5 text-accent" />
                        Relationships
                    </h3>
                </div>
                <x-skeleton-loader type="relationship-list" />
            </x-card>
            @endif
            </div>
        </div>

        <!-- Drawer for Event Details -->
        <x-drawer wire:model="showSidebar" right title="Event Details" separator with-close-button class="w-11/12 lg:w-1/3">
            <div class="space-y-4">
                <!-- Primary Information (Always Visible) -->
                <div class="pb-4 border-b border-base-200">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/80">Information</h3>
                        <div class="flex gap-1">
                            <button
                                wire:click="copyEventWithoutEmbeddings"
                                class="btn btn-ghost btn-xs gap-1"
                                title="Copy event data to clipboard (without embeddings)">
                                <x-icon name="fas.clipboard" class="w-3 h-3" />
                                <span class="hidden sm:inline">Copy</span>
                            </button>
                            <button
                                wire:click="exportAsJson"
                                class="btn btn-ghost btn-xs gap-1"
                                title="Export complete event with all related data">
                                <x-icon name="fas.download" class="w-3 h-3" />
                                <span class="hidden sm:inline">Export</span>
                            </button>
                        </div>
                    </div>
                    <dl>
                        <x-metadata-row label="Event ID" :value="$this->event->id" copyable />
                        <x-metadata-row label="Action" :value="$this->formatAction($this->event->action)" />
                        <x-metadata-row label="Time" :copy-value="$this->event->time?->toIso8601String()">
                            <x-uk-date :date="$this->event->time" />
                        </x-metadata-row>
                        @if ($this->event->value)
                            <x-metadata-row label="Value" :copy-value="$this->event->formatted_value">
                                {!! format_event_value_display($this->event->formatted_value, $this->event->value_unit, $this->event->service, $this->event->action, 'action') !!}
                            </x-metadata-row>
                        @endif
                        <x-metadata-row label="Service" :value="str::headline($this->event->service)" />
                        @if ($this->event->domain)
                            <x-metadata-row label="Domain" :value="str::headline($this->event->domain)" />
                        @endif
                        @if ($this->event->integration)
                            <x-metadata-row label="Integration" :value="$this->event->integration->name" />
                        @endif
                        @if ($this->event->actor)
                            <x-metadata-row label="Actor" :copy-value="$this->event->actor->title">
                                <a href="{{ route('objects.show', $this->event->actor->id) }}" class="hover:underline">
                                    {{ $this->event->actor->title }}
                                </a>
                            </x-metadata-row>
                        @endif
                        @if ($this->event->target)
                            <x-metadata-row label="Target" :copy-value="$this->event->target->title">
                                <a href="{{ route('objects.show', $this->event->target->id) }}" class="hover:underline">
                                    {{ $this->event->target->title }}
                                </a>
                            </x-metadata-row>
                        @endif
                    </dl>
                </div>

                    <!-- Tags -->
                    <div class="pb-4 border-b border-base-200">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/80">
                                Tags
                            </h3>
                            <button type="button" wire:click="openCreateTagModal" class="btn btn-xs btn-ghost btn-circle" title="Create new tag">
                                <x-icon name="fas.plus" class="w-3 h-3" />
                            </button>
                        </div>
                        <div wire:key="event-tags-{{ $this->event->id }}" wire:ignore>
                            <input id="tag-input-{{ $this->event->id }}" data-tagify data-initial="tag-initial-{{ $this->event->id }}" data-suggestions-id="tag-suggestions-{{ $this->event->id }}" aria-label="Tags" class="input input-sm w-full" placeholder="Add tags" data-hotkey="t" />
                            <script type="application/json" id="tag-initial-{{ $this->event->id }}">
                                {!! json_encode($this->event->tags->map(fn($tag) => ['value' => (string) $tag->name, 'type' => $tag->type ? (string) $tag->type : null])->values()->all()) !!}
                            </script>
                            <script type="application/json" id="tag-suggestions-{{ $this->event->id }}">
                                {!! json_encode(\Spatie\Tags\Tag::query()->select(['name', 'type'])->get()->map(fn($tag) => ['value' => (string) $tag->name, 'type' => $tag->type ? (string) $tag->type : null])->values()->all()) !!}
                            </script>
                        </div>
                    </div>

                    <!-- Relationships -->
                    <div class="pb-4 border-b border-base-200">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/80">
                                Relationships
                            </h3>
                            <button type="button" wire:click="handleOpenManageRelationshipsModal" class="btn btn-xs btn-ghost btn-circle" title="Manage relationships" data-hotkey="r">
                                <x-icon name="fas.plus" class="w-3 h-3" />
                            </button>
                        </div>
                        @if (!$drawerContentLoaded || !$relationshipsLoaded)
                        <x-skeleton-loader type="relationship-list" />
                        @else
                        @php $sidebarRelationships = $this->relationships; @endphp
                        @if ($sidebarRelationships->isEmpty())
                        <x-empty-state
                            icon="fas.right-left"
                            message="No relationships yet"
                            actionEvent="handleOpenAddRelationshipModal"
                            actionLabel="Add Relationship" />
                        @else
                        <div class="space-y-1.5 max-h-48 overflow-y-auto">
                            @foreach ($sidebarRelationships->take(10) as $relationship)
                            @php
                            $isFrom = $relationship->from_type === get_class($event) && $relationship->from_id === $event->id;
                            $relatedModel = $isFrom ? $relationship->to : $relationship->from;
                            $icon = 'o-question-mark-circle';
                            $title = 'Unknown';
                            $route = '#';

                            if ($relatedModel instanceof \App\Models\Event) {
                                $icon = 'fas.calendar';
                                $title = $relatedModel->action;
                                $route = route('events.show', $relatedModel);
                            } elseif ($relatedModel instanceof \App\Models\EventObject) {
                                $icon = 'o-cube';
                                $title = $relatedModel->title;
                                $route = route('objects.show', $relatedModel);
                            } elseif ($relatedModel instanceof \App\Models\Block) {
                                $icon = 'fas.grip';
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
                    @if ($drawerContentLoaded && $tasksLoaded)
                        @php
                            $metadata = $event->event_metadata ?? [];
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
                                <livewire:task-execution-section :model="$event" :key="'tasks-event-' . $event->id" />
                            </x-slot:content>
                        </x-collapse>
                    @else
                        <div class="pb-4 border-b border-base-200">
                            <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/80 mb-3">Tasks</h3>
                            <x-skeleton-loader type="list-item" :count="2" />
                        </div>
                    @endif

                    <!-- Comment -->
                    <div class="pb-4 border-b border-base-200">
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/80 mb-3">
                            Comment
                        </h3>
                        <x-form wire:submit="addComment">
                            <x-textarea wire:model="comment" rows="2" placeholder="Add a comment..." class="textarea-sm" />
                            <div class="mt-2 flex justify-end">
                                <x-button type="submit" class="btn-primary btn-sm" label="Post" />
                            </div>
                        </x-form>
                    </div>

                <!-- Activity Timeline (Collapsible, Default: Open) -->
                <x-collapse wire:model="activityOpen">
                    <x-slot:heading>
                        <div class="text-sm font-semibold uppercase tracking-wider text-base-content/80">
                            Activity
                        </div>
                    </x-slot:heading>
                    <x-slot:content>
                        @if (!$drawerContentLoaded || !$activitiesLoaded)
                        <x-skeleton-loader type="list-item" count="3" />
                        @else
                        @php $activities = $this->activities; @endphp
                        @php
                        $activities = $this->activities;
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
                        @endif

                    </x-slot:content>
                </x-collapse>

                <!-- Details (Collapsible, Default: Open) -->
                @if ($coreLoaded && ($this->event->actor || $this->event->target))
                <x-collapse wire:model="detailsOpen">
                    <x-slot:heading>
                        <div class="text-sm font-semibold uppercase tracking-wider text-base-content/80">
                            Details
                        </div>
                    </x-slot:heading>
                    <x-slot:content>
                        @if ($this->event->actor)
                        <div class="mb-6">
                            <div class="flex items-center gap-2 mb-3">
                                <h4 class="text-sm font-semibold">Actor</h4>
                            </div>
                            <dl>
                                <x-metadata-row label="Title" :value="$this->event->actor->title" />
                                @if ($this->event->actor->type)
                                    <x-metadata-row label="Type" :value="$this->event->actor->type" />
                                @endif
                                @if ($this->event->actor->concept)
                                    <x-metadata-row label="Concept" :value="$this->event->actor->concept" />
                                @endif
                                @if ($this->event->actor->url)
                                    <x-metadata-row label="URL" :copy-value="$this->event->actor->url">
                                        <a href="{{ $this->event->actor->url }}" target="_blank" class="hover:underline">View</a>
                                    </x-metadata-row>
                                @endif
                                @if ($this->event->actor->tags->isNotEmpty())
                                    <x-metadata-row label="Tags" :copyable="false">
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($this->event->actor->tags as $tag)
                                                <x-spark-tag :tag="$tag" size="xs" />
                                            @endforeach
                                        </div>
                                    </x-metadata-row>
                                @endif
                            </dl>
                        </div>
                        @endif

                        @if ($this->event->target)
                        <div>
                            <div class="flex items-center gap-2 mb-3">
                                <h4 class="text-sm font-semibold">Target</h4>
                            </div>
                            <dl>
                                <x-metadata-row label="Title" :value="$this->event->target->title" />
                                @if ($this->event->target->type)
                                    <x-metadata-row label="Type" :value="$this->event->target->type" />
                                @endif
                                @if ($this->event->target->concept)
                                    <x-metadata-row label="Concept" :value="$this->event->target->concept" />
                                @endif
                                @if ($this->event->target->url)
                                    <x-metadata-row label="URL" :copy-value="$this->event->target->url">
                                        <a href="{{ $this->event->target->url }}" target="_blank" class="hover:underline">View</a>
                                    </x-metadata-row>
                                @endif
                                @if ($this->event->target->tags->isNotEmpty())
                                    <x-metadata-row label="Tags" :copyable="false">
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($this->event->target->tags as $tag)
                                                <x-spark-tag :tag="$tag" size="xs" />
                                            @endforeach
                                        </div>
                                    </x-metadata-row>
                                @endif
                            </dl>
                        </div>
                        @endif
                    </x-slot:content>
                </x-collapse>
                @endif

                <!-- Technical Metadata (Collapsible, Default: Closed) -->
                @if (
                    ($this->event->event_metadata && count($this->event->event_metadata) > 0) ||
                    ($this->event->actor && $this->event->actor->metadata && count($this->event->actor->metadata) > 0) ||
                    ($this->event->target && $this->event->target->metadata && count($this->event->target->metadata) > 0)
                )
                <x-collapse wire:model="technicalOpen">
                    <x-slot:heading>
                        <div class="text-sm font-semibold uppercase tracking-wider text-base-content/80">
                            Metadata
                        </div>
                    </x-slot:heading>
                    <x-slot:content>
                        @if ($this->event->event_metadata && count($this->event->event_metadata) > 0)
                        <div class="mb-6">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <h4 class="text-sm font-semibold">Event Metadata</h4>
                                </div>
                                <script type="application/json" id="event-meta-json-{{ $this->event->id }}">
                                    {!! json_encode($this->event->event_metadata, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
                                </script>
                                <x-button
                                    icon="o-clipboard"
                                    label="Copy"
                                    class="btn-ghost btn-xs"
                                    title="Copy JSON"
                                    onclick="(function(){ var el=document.getElementById('event-meta-json-{{ $this->event->id }}'); if(!el){return;} var text; try{ text=JSON.stringify(JSON.parse(el.textContent), null, 2);}catch(e){ text=el.textContent; } navigator.clipboard.writeText(text).then(function(){ $wire.notifyCopied('Event metadata'); }); })()" />
                            </div>
                            <x-metadata-list :data="$this->event->event_metadata" />
                        </div>
                        @endif

                        @if ($this->event->actor && $this->event->actor->metadata && count($this->event->actor->metadata) > 0)
                        <div class="mb-6">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <h4 class="text-sm font-semibold">Actor Metadata</h4>
                                </div>
                                <script type="application/json" id="actor-meta-json-{{ $this->event->id }}">
                                    {!! json_encode($this->event->actor->metadata, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
                                </script>
                                <x-button
                                    icon="o-clipboard"
                                    label="Copy"
                                    class="btn-ghost btn-xs"
                                    title="Copy JSON"
                                    onclick="(function(){ var el=document.getElementById('actor-meta-json-{{ $this->event->id }}'); if(!el){return;} var text; try{ text=JSON.stringify(JSON.parse(el.textContent), null, 2);}catch(e){ text=el.textContent; } navigator.clipboard.writeText(text).then(function(){ $wire.notifyCopied('Actor metadata'); }); })()" />
                            </div>
                            <x-metadata-list :data="$this->event->actor->metadata" />
                        </div>
                        @endif

                        @if ($this->event->target && $this->event->target->metadata && count($this->event->target->metadata) > 0)
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <h4 class="text-sm font-semibold">Target Metadata</h4>
                                </div>
                                <script type="application/json" id="target-meta-json-{{ $this->event->id }}">
                                    {!! json_encode($this->event->target->metadata, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
                                </script>
                                <x-button
                                    icon="o-clipboard"
                                    label="Copy"
                                    class="btn-ghost btn-xs"
                                    title="Copy JSON"
                                    onclick="(function(){ var el=document.getElementById('target-meta-json-{{ $this->event->id }}'); if(!el){return;} var text; try{ text=JSON.stringify(JSON.parse(el.textContent), null, 2);}catch(e){ text=el.textContent; } navigator.clipboard.writeText(text).then(function(){ $wire.notifyCopied('Target metadata'); }); })()" />
                            </div>
                            <x-metadata-list :data="$this->event->target->metadata" />
                        </div>
                        @endif
                    </x-slot:content>
                </x-collapse>
                @endif
            </div>
        </x-drawer>
    </div>
    @endif
    @if (! $this->event)
    <div class="text-center py-8">
        <x-icon name="fas.triangle-exclamation" class="w-12 h-12 text-warning mx-auto mb-4" />
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