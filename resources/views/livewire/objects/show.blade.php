<?php

use App\Integrations\PluginRegistry;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Traits\HasProgressiveLoading;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Spatie\Activitylog\Models\Activity;
use Spatie\Tags\Tag;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    use HasProgressiveLoading;

    public EventObject $object;
    public bool $showSidebar = false;
    public string $comment = '';
    public bool $activityOpen = true;
    public bool $objectMetaOpen = false;
    public bool $showCreateTagModal = false;
    public bool $showEditObjectModal = false;
    public bool $showTimeline = false;
    public bool $showTagModal = false;
    public bool $showManageRelationshipsModal = false;
    public bool $showAddRelationshipModal = false;

    // Progressive loading state flags
    public bool $tagsLoaded = false;
    public bool $directEventsLoaded = false;
    public bool $mediaLoaded = false;
    public bool $relationshipsLoaded = false;
    public bool $tasksLoaded = false;
    public bool $relatedBlocksLoaded = false;
    public bool $relatedEventsLoaded = false;
    public bool $activitiesLoaded = false;
    public bool $drawerContentLoaded = false;

    // Collapse states
    public bool $tasksOpen = false;
    public bool $contentOpen = true;

    protected $listeners = [
        'open-tag-modal' => 'handleOpenTagModal',
        'show-timeline' => 'handleShowTimeline',
        'open-edit-object-modal' => 'handleOpenEditModal',
        'open-manage-relationships-modal' => 'handleOpenManageRelationshipsModal',
        'open-add-relationship-modal' => 'handleOpenAddRelationshipModal',
        'delete-object' => 'handleDeleteObject',
        'object-updated' => 'handleObjectUpdated',
        'tags-updated' => 'handleTagsUpdated',
        'relationship-created' => 'handleRelationshipUpdated',
        'relationship-deleted' => 'handleRelationshipUpdated',
        'close-modal' => 'closeModals',
        'drawer-opened' => 'loadDrawerContent',
    ];

    // -------------------------------------------------------------------------
    // Public Methods
    // -------------------------------------------------------------------------

    public function mount(EventObject $object): void
    {
        Log::info('EventObject mount called', [
            'object_id' => $object->id,
            'user_id' => $object->user_id,
            'auth_id' => auth()->id(),
        ]);

        try {
            // Load only the bare minimum - just the object itself
            $this->object = $object;
            Log::info('EventObject mount complete');

            // Track this view in the activity log (debounced to prevent duplicate views)
            $this->object->logViewIfNotRecent(5);

            // Start progressive loading chain
            $this->startProgressiveLoading();
        } catch (\Exception $e) {
            Log::error('EventObject mount failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Load tags for the object
     */
    public function loadTags(): void
    {
        if ($this->tagsLoaded) {
            return;
        }
        $this->object->load(['tags']);
        $this->tagsLoaded = true;
    }

    /**
     * Load direct events where this object is actor or target (not semantic search)
     */
    public function loadDirectEvents(): void
    {
        if ($this->directEventsLoaded) {
            return;
        }
        $this->directEventsLoaded = true;
    }

    /**
     * Load media collections for this object
     */
    public function loadMedia(): void
    {
        if ($this->mediaLoaded) {
            return;
        }
        $this->object->load(['media']);
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
        $this->object->load(['relationshipsFrom', 'relationshipsTo']);
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
        $this->relatedEventsLoaded = true;
    }

    /**
     * Load related blocks via semantic search (expensive operation)
     */
    public function loadRelatedBlocks(): void
    {
        if ($this->relatedBlocksLoaded) {
            return;
        }
        $this->relatedBlocksLoaded = true;
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

    public function toggleSidebar(): void
    {
        $this->showSidebar = ! $this->showSidebar;
    }

    #[Computed]
    public function relationships()
    {
        if (! $this->relationshipsLoaded) {
            return collect();
        }

        return $this->object->allRelationships()->get();
    }

    #[Computed]
    public function directEvents()
    {
        if (! $this->directEventsLoaded) {
            return collect();
        }

        // Get events where this object is actor OR target (direct relationship, not semantic)
        $userId = auth()->id();
        if (! $userId) {
            return collect();
        }

        return Event::with(['actor', 'target', 'integration', 'tags'])
            ->whereHas('integration', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->where(function ($q) {
                $q->where('actor_id', $this->object->id)
                    ->orWhere('target_id', $this->object->id);
            })
            ->orderBy('time', 'desc')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function objectMedia()
    {
        if (! $this->mediaLoaded) {
            return collect();
        }

        return $this->object->getMedia('screenshots')
            ->merge($this->object->getMedia('downloaded_images'))
            ->merge($this->object->getMedia('pdfs'))
            ->merge($this->object->getMedia('downloaded_videos'))
            ->merge($this->object->getMedia('downloaded_documents'));
    }

    #[Computed]
    public function relatedEvents()
    {
        if (! $this->relatedEventsLoaded) {
            return collect();
        }

        // Use semantic search if embeddings exist
        if (! empty($this->object->embeddings)) {
            try {
                $embedding = $this->object->embeddings;

                if (is_array($embedding) && count($embedding) > 0) {
                    // Get user's integration IDs for security
                    $userIntegrationIds = auth()->user()->integrations()->pluck('id')->toArray();

                    // Perform semantic search with temporal weighting
                    return Event::semanticSearch($embedding, threshold: 1.2, limit: 5, temporalWeight: 0.015)
                        ->whereIn('integration_id', $userIntegrationIds)
                        ->with(['actor', 'target', 'integration', 'tags'])
                        ->get();
                }
            } catch (\Exception $e) {
                Log::warning('Semantic search failed for related events on object', [
                    'object_id' => $this->object->id,
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
                $q->where('actor_id', $this->object->id)
                    ->orWhere('target_id', $this->object->id);
            })
            ->orderBy('time', 'desc')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function relatedBlocks()
    {
        if (! $this->relatedBlocksLoaded) {
            return collect();
        }

        // Use semantic search if embeddings exist
        if (! empty($this->object->embeddings)) {
            try {
                $embedding = $this->object->embeddings;

                if (is_array($embedding) && count($embedding) > 0) {
                    // Get user's integration IDs for security
                    $userIntegrationIds = auth()->user()->integrations()->pluck('id')->toArray();

                    // Perform semantic search with temporal weighting
                    return Block::semanticSearch($embedding, threshold: 1.2, limit: 5, temporalWeight: 0.015)
                        ->whereHas('event', function ($q) use ($userIntegrationIds) {
                            $q->whereIn('integration_id', $userIntegrationIds);
                        })
                        ->with(['event.integration'])
                        ->get();
                }
            } catch (\Exception $e) {
                Log::warning('Semantic search failed for related blocks on object', [
                    'object_id' => $this->object->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to original logic if embeddings don't exist or semantic search fails
        return $this->object->relatedBlocks()
            ->with('event.integration')
            ->orderBy('time', 'desc')
            ->limit(12)
            ->get();
    }

    #[Computed]
    public function activities()
    {
        if (! $this->activitiesLoaded) {
            return collect();
        }

        return Activity::forSubject($this->object)
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
            ->performedOn($this->object)
            ->causedBy(auth()->guard('web')->user())
            ->event('comment')
            ->withProperties(['comment' => $text])
            ->log('comment');

        $this->comment = '';
    }

    public function toggleLock(): void
    {
        if ($this->object->isLocked()) {
            $this->object->unlock();
            $this->notifyCopied('Object unlocked');
        } else {
            $this->object->lock();
            $this->notifyCopied('Object locked');
        }
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

    public function getCompleteObjectData(): array
    {
        return [
            'object' => $this->object->toArray(),
            'tags' => $this->object->tags->toArray(),
            'relationships' => $this->object->allRelationships()->get()->map(function ($rel) {
                return [
                    'type' => $rel->type,
                    'from' => ['type' => $rel->from_type, 'id' => $rel->from_id],
                    'to' => ['type' => $rel->to_type, 'id' => $rel->to_id],
                    'value' => $rel->value,
                    'value_unit' => $rel->value_unit,
                    'metadata' => $rel->metadata,
                ];
            })->toArray(),
            'related_events' => $this->relatedEvents->toArray(),
            'related_blocks' => $this->relatedBlocks->toArray(),
        ];
    }

    public function exportAsJson(): void
    {
        $data = $this->getCompleteObjectData();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->js('
            const blob = new Blob([' . json_encode($json) . "], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'object-{$this->object->id}-" . now()->format('Y-m-d-His') . ".json';
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
                    <span>Object exported!</span>
                </div>
            `;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 2000);
        ");
    }

    public function getObjectIcon($type, $concept, $service = null)
    {
        // Try to get icon from plugin configuration first if service is available
        if ($service) {
            $pluginClass = PluginRegistry::getPlugin($service);
            if ($pluginClass) {
                $objectTypes = $pluginClass::getObjectTypes();
                if (isset($objectTypes[$type]) && isset($objectTypes[$type]['icon'])) {
                    return $objectTypes[$type]['icon'];
                }
            }
        }

        // If no service provided or not found, search through all plugins
        if (!$service || !isset($objectTypes[$type])) {
            foreach (PluginRegistry::getAllPlugins() as $pluginClass) {
                $objectTypes = $pluginClass::getObjectTypes();
                if (isset($objectTypes[$type]) && isset($objectTypes[$type]['icon'])) {
                    return $objectTypes[$type]['icon'];
                }
            }
        }

        return 'o-cube'; // Default icon
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

        $this->object->attachTag($tag);
        $this->object->refresh()->loadMissing('tags');
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

        $this->object->detachTag($name, $detectedType);
        $this->object->refresh()->loadMissing('tags');
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
        $this->object->refresh()->loadMissing('tags');
        $this->showCreateTagModal = false;
    }

    public function handleOpenTagModal(): void
    {
        $this->showTagModal = true;
    }

    public function handleShowTimeline(): void
    {
        $this->showTimeline = true;
    }

    public function handleOpenEditModal(): void
    {
        $this->showEditObjectModal = true;
    }

    public function handleDeleteObject(): void
    {
        $this->object->delete();
        $this->redirect(route('today.main'), navigate: true);
    }

    public function handleObjectUpdated(): void
    {
        $this->object->refresh();
        // Reload any sections that were already loaded
        if ($this->tagsLoaded) {
            $this->object->load(['tags']);
        }
        $this->showEditObjectModal = false;
    }

    public function handleTagsUpdated(): void
    {
        $this->object->refresh()->loadMissing(['tags']);
        $this->tagsLoaded = true;
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
        $this->object->refresh()->load([
            'relationshipsFrom',
            'relationshipsTo',
        ]);
    }

    public function closeModals(): void
    {
        $this->showEditObjectModal = false;
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
     * Tier 1-3: Main page visible content (tags, events, media)
     * Tier 4-5: Supporting content (related blocks, related events)
     * Tier 6-7: Drawer-only content (loaded on-demand when drawer opens)
     */
    protected function getLoadingTiers(): array
    {
        return [
            1 => ['loadTags'],
            2 => ['loadDirectEvents'],
            3 => ['loadMedia'],
            4 => ['loadRelatedBlocks'],
            5 => ['loadRelatedEvents'],
            6 => ['loadRelationships'],  // Drawer-only (loaded on-demand)
            7 => ['loadTasks'],           // Drawer-only (loaded on-demand)
            8 => ['loadActivities'],      // Drawer-only (loaded on-demand)
        ];
    }

    /**
     * Determine if tasks section should be expanded by default
     */
    protected function shouldExpandTasksSection(): bool
    {
        // Expand if there are failed or pending tasks
        $metadata = $this->object->metadata ?? [];
        $executions = $metadata['task_executions'] ?? [];

        foreach ($executions as $execution) {
            $status = $execution['last_attempt']['status'] ?? null;
            if (in_array($status, ['failed', 'pending'])) {
                return true;
            }
        }

        return false;
    }
};

?>

<div x-data="{ drawerOpen: @entangle('showSidebar').live }"
     x-init="$watch('drawerOpen', value => {
         if (value) {
             setTimeout(() => $wire.dispatch('drawer-opened'), 50);
         }
     })">
    @if ($this->object)
    <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
        <!-- Main Content Area -->
        <div class="flex-1 space-y-4 lg:space-y-6">
            <!-- Header -->
            <x-header title="Object Details" separator>
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

            <!-- Object Overview Card -->
            <x-card class="bg-base-200 shadow">
                <div class="flex flex-col sm:flex-row items-start gap-4 lg:gap-6">
                    <!-- Object Icon -->
                    <div class="flex-shrink-0 self-center sm:self-start">
                        <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-primary/10 flex items-center justify-center">
                            <x-icon name="{{ $this->getObjectIcon($this->object->type, $this->object->concept, $this->object->metadata['service'] ?? null) }}"
                                class="w-6 h-6 sm:w-8 sm:h-8 text-primary" />
                        </div>
                    </div>

                    <!-- Object Details -->
                    <div class="flex-1">
                        <div class="mb-4 text-center sm:text-left">
                            <div class="flex flex-col sm:flex-row items-center sm:items-start justify-between gap-2 mb-2">
                                <h2 class="text-xl sm:text-2xl lg:text-3xl font-bold text-base-content leading-tight flex items-center gap-2">
                                    {{ $this->object->title }}
                                    @if ($this->object->isLocked())
                                        <x-icon name="fas.lock" class="w-5 h-5 text-base-content/60" title="This object is locked" />
                                    @endif
                                </h2>
                            </div>
                        </div>

                        <!-- Key Metadata -->
                        <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2 text">
                            <div class="flex items-center gap-2">
                                <x-icon name="fas.clock" class="w-4 h-4 text-base-content/60 flex-shrink-0" />
                                <span class="text-base-content/70">{{ $this->object->time->format('d/m/Y H:i') }} · {{ $this->object->time->diffForHumans() }}</span>
                            </div>
                            <span class="hidden sm:inline">·</span>
                            <span class="sm:hidden w-full"></span>
                            @if ($this->object->concept)
                            <x-concept-ref :concept="$this->object->concept" :service="$this->object->metadata['service'] ?? null" />
                            @endif
                            @if ($this->object->type)
                            <x-icon name="fas.arrow-right" class="w-3 h-3 text-base-content/40" />
                            <x-type-ref :type="$this->object->type" :concept="$this->object->concept" :service="$this->object->metadata['service'] ?? null" />
                            @endif
                        </div>

                        <!-- URLs -->
                        @if ($this->object->url || $this->object->media_url)
                        <div class="mt-4 lg:mt-6 p-3 lg:p-4 rounded-lg bg-base-300/50 border-2 border-info/20">
                            <div class="flex flex-col sm:flex-row items-center justify-center gap-3 lg:gap-4">
                                @if ($this->object->url)
                                <a href="{{ $this->object->url }}" target="_blank"
                                    class="flex items-center gap-2 px-4 py-2 bg-info/10 hover:bg-info/20 text-info font-medium rounded-lg transition-colors"
                                    title="{{ $this->object->url }}">
                                    <x-icon name="fas.link" class="w-4 h-4" />
                                    <span class="truncate">{{ parse_url($this->object->url, PHP_URL_HOST) }}</span>
                                </a>
                                @endif
                            </div>
                        </div>
                        @endif

                        <!-- Tags (Progressive) -->
                        <div class="mt-4">
                            @if ($tagsLoaded && $this->object->tags->isNotEmpty())
                            <div class="flex flex-wrap justify-center gap-2">
                                @foreach ($this->object->tags as $tag)
                                <x-tag-ref :tag="$tag" />
                                @endforeach
                            </div>
                            @elseif (! $tagsLoaded)
                            <x-skeleton-loader type="tags" class="justify-center" />
                            @endif
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Content Section -->
            @if ($this->object->content)
            <div class="mb-6">
                <x-collapse wire:model="contentOpen">
                    <x-slot:heading>
                        <div class="text-lg font-semibold text-base-content flex items-center gap-2">
                            <x-icon name="fas.file-lines" class="w-5 h-5 text-info" />
                            Content
                        </div>
                    </x-slot:heading>
                    <x-slot:content>
                        <div class="max-w-prose mx-auto pt-4">
                            <div class="prose dark:prose-invert prose-base lg:prose-lg">
                                {!! Str::markdown($this->object->content) !!}
                            </div>
                        </div>
                    </x-slot:content>
                </x-collapse>
            </div>
            @endif

            <!-- Direct Events (Progressive - events where this object is actor/target) -->
            @php $directEvents = $this->directEvents; @endphp
            @if ($directEventsLoaded && $directEvents->isNotEmpty())
            <div>
                <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                    <x-icon name="fas.bolt" class="w-5 h-5 text-primary" />
                    Events ({{ $directEvents->count() }})
                </h3>
                <div class="space-y-3">
                    @foreach ($directEvents as $event)
                    <div class="border border-base-300 rounded-lg p-3 hover:bg-base-50 transition-colors bg-base-100">
                        <div class="flex items-start gap-3">
                            <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0 mt-1">
                                <x-icon name="fas.bolt" class="w-4 h-4 text-primary" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2 mb-1">
                                    <div class="flex items-center flex-wrap gap-1">
                                        @if ($event->actor)
                                            <x-object-ref :object="$event->actor" />
                                        @endif
                                        <x-event-ref :event="$event" :showService="false" />
                                        @if ($event->target)
                                            <x-object-ref :object="$event->target" />
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        @if ($event->value)
                                        <span class="text-sm font-semibold text-primary">
                                            {!! format_event_value_display($event->formatted_value, $event->value_unit, $event->service, $event->action, 'action') !!}
                                        </span>
                                        @endif
                                        <a href="{{ route('events.show', $event->id) }}"
                                           wire:navigate
                                           class="btn btn-ghost btn-xs btn-square"
                                           title="View event details">
                                            <x-icon name="fas.arrow-right" class="w-3 h-3" />
                                        </a>
                                    </div>
                                </div>
                                <div class="text-sm text-base-content/70 flex flex-wrap items-center gap-1">
                                    <span>{{ $event->time->format('d/m/Y H:i') }}</span>
                                    <span>·</span>
                                    @if ($event->integration)
                                    <x-integration-ref :integration="$event->integration" :showStatus="false" />
                                    @else
                                    <x-service-ref :service="$event->service" />
                                    @endif
                                    @if ($event->tags && count($event->tags) > 0)
                                    <span>·</span>
                                    @foreach ($event->tags->take(3) as $tag)
                                    <x-tag-ref :tag="$tag" size="xs" />
                                    @endforeach
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @elseif (! $directEventsLoaded)
            <div>
                <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                    <x-icon name="fas.bolt" class="w-5 h-5 text-primary" />
                    Events
                </h3>
                <x-skeleton-loader type="event-list" />
            </div>
            @endif

            <!-- Media Gallery (Progressive) -->
            @php $objectMedia = $this->objectMedia; @endphp
            @if ($mediaLoaded && $objectMedia->isNotEmpty())
            <div>
                <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                    <x-icon name="fas.images" class="w-5 h-5 text-secondary" />
                    Media ({{ $objectMedia->count() }})
                </h3>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    @foreach ($objectMedia->take(8) as $media)
                    @php
                        // Use helper function for S3 signed URLs
                        $fullUrl = get_media_object_url($media);
                    @endphp
                    <div class="aspect-square rounded-lg overflow-hidden bg-base-200 border border-base-300">
                        @if (Str::startsWith($media->mime_type, 'image/'))
                        {!! render_media_object_responsive($media, [
                            'alt' => $media->name,
                            'class' => 'w-full h-full object-cover hover:scale-105 transition-transform cursor-pointer',
                            'loading' => 'lazy',
                            'onclick' => "window.open('" . addslashes($fullUrl) . "', '_blank')",
                        ]) !!}
                        @elseif (Str::startsWith($media->mime_type, 'video/'))
                        <div class="w-full h-full flex items-center justify-center bg-base-300 cursor-pointer" onclick="window.open('{{ $fullUrl }}', '_blank')">
                            <x-icon name="fas.play-circle" class="w-12 h-12 text-base-content/40" />
                        </div>
                        @else
                        <div class="w-full h-full flex flex-col items-center justify-center bg-base-300 cursor-pointer p-2" onclick="window.open('{{ $fullUrl }}', '_blank')">
                            <x-icon name="fas.file" class="w-8 h-8 text-base-content/40" />
                            <span class="text-xs text-base-content/60 mt-1 truncate max-w-full">{{ $media->file_name }}</span>
                        </div>
                        @endif
                    </div>
                    @endforeach
                </div>
                @if ($objectMedia->count() > 8)
                <p class="text-sm text-base-content/60 mt-2 text-center">
                    +{{ $objectMedia->count() - 8 }} more items
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

            <!-- Related Blocks (Progressive - Semantic Search) -->
            <div>
                @if ($relatedBlocksLoaded && $this->relatedBlocks->isNotEmpty())
                <div class="relative">
                    <div class="bg-gradient-to-br from-warning/5 to-warning/25 rounded-lg p-4 border border-warning/50">
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="fas.grip" class="w-5 h-5 text-warning" />
                            Related Blocks ({{ $this->relatedBlocks->count() }})
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            @foreach ($this->relatedBlocks as $block)
                                <x-block-card :block="$block" />
                            @endforeach
                        </div>
                    </div>
                    <!-- AI Badge -->
                    <div class="absolute -top-2 -right-2 bg-warning rounded-full p-1.5 shadow">
                        <x-icon name="fas.wand-magic-sparkles" class="w-3 h-3 text-warning-content" />
                    </div>
                </div>
                @elseif (! $relatedBlocksLoaded)
                <div class="relative">
                    <div class="bg-gradient-to-br from-warning/5 to-warning/25 rounded-lg p-4 border border-warning/50">
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="fas.grip" class="w-5 h-5 text-warning" />
                            Related Blocks
                        </h3>
                        <x-skeleton-loader type="block-grid" />
                    </div>
                    <!-- AI Badge -->
                    <div class="absolute -top-2 -right-2 bg-warning rounded-full p-1.5 shadow">
                        <x-icon name="fas.wand-magic-sparkles" class="w-3 h-3 text-warning-content" />
                    </div>
                </div>
                @endif
            </div>

            <!-- Related Events (Progressive - Semantic Search) -->
            <div>
                @if ($relatedEventsLoaded && $this->relatedEvents->isNotEmpty())
                <div class="relative">
                    <div class="bg-gradient-to-br from-warning/5 to-warning/25 rounded-lg p-4 border border-warning/50">
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="fas.bolt" class="w-5 h-5 text-warning" />
                            Related Events ({{ $this->relatedEvents->count() }})
                        </h3>
                        <div class="space-y-3">
                            @foreach ($this->relatedEvents as $event)
                        <div class="border border-base-300 rounded-lg p-3 hover:bg-base-50 transition-colors bg-base-100">
                            <div class="flex items-start gap-3">
                                <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0 mt-1">
                                    <x-icon name="fas.bolt" class="w-4 h-4 text-primary" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-2 mb-1">
                                        <div class="flex items-center flex-wrap gap-1">
                                            @if ($event->actor)
                                                <x-object-ref :object="$event->actor" />
                                            @endif
                                            <x-event-ref :event="$event" :showService="false" />
                                            @if ($event->target)
                                                <x-object-ref :object="$event->target" />
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            @if (isset($event->similarity))
                                            @php
                                                $similarity = round((1 - $event->similarity) * 100);
                                                $daysAgo = isset($event->days_ago) ? round($event->days_ago) : null;
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
                                            @if ($event->value)
                                            <span class="text-sm font-semibold">
                                                {!! format_event_value_display($event->formatted_value, $event->value_unit, $event->service, $event->action, 'action') !!}
                                            </span>
                                            @endif
                                            <a href="{{ route('events.show', $event->id) }}"
                                               wire:navigate
                                               class="btn btn-ghost btn-xs btn-square"
                                               title="View event details">
                                                <x-icon name="fas.arrow-right" class="w-3 h-3" />
                                            </a>
                                        </div>
                                    </div>
                                    <div class="text-sm text-base-content/70 flex flex-wrap items-center gap-1">
                                        <span>{{ $event->time->format('d/m/Y H:i') }}</span>
                                        @if ($event->domain)
                                        <span>·</span>
                                        <x-domain-ref :domain="$event->domain" />
                                        @endif
                                        <span>·</span>
                                        <x-service-ref :service="$event->service" />
                                        @if ($event->integration)
                                        <span>·</span>
                                        <x-integration-ref :integration="$event->integration" :showStatus="false" />
                                        @endif
                                        @if ($event->tags && count($event->tags) > 0)
                                        <span>·</span>
                                        @foreach ($event->tags as $tag)
                                        <x-tag-ref :tag="$tag" size="xs" />
                                        @endforeach
                                        @endif
                                    </div>
                                </div>
                            </div>
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
                            <x-icon name="fas.bolt" class="w-5 h-5 text-warning" />
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
                        label="Manage"
                    />
                </div>

                <div class="space-y-3">
                    @foreach ($relationships->take(5) as $relationship)
                        @php
                            // Determine if this object is "from" or "to" in the relationship
                            $isFrom = $relationship->from_type === get_class($object) && $relationship->from_id === $object->id;
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

            <!-- Drawer for Technical Details -->
            <x-drawer wire:model="showSidebar" right title="Object Details" with-close-button separator class="w-11/12 lg:w-1/3">
                <div class="space-y-4">
                    <!-- Primary Information (Always Visible) -->
                    <div class="pb-4 border-b border-base-200">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/80">Information</h3>
                            <button
                                wire:click="exportAsJson"
                                class="btn btn-ghost btn-xs gap-1"
                                title="Export complete object with relationships and related data">
                                <x-icon name="fas.download" class="w-3 h-3" />
                                <span class="hidden sm:inline">Export</span>
                            </button>
                        </div>
                        <dl>
                            <x-metadata-row label="Object ID" :value="$this->object->id" copyable />
                            <x-metadata-row label="Title" :value="$this->object->title" />
                            <x-metadata-row label="Concept" :value="Str::headline($this->object->concept)" />
                            <x-metadata-row label="Type" :value="Str::headline($this->object->type)" />
                            <x-metadata-row label="Time" :copy-value="$this->object->time?->toIso8601String()">
                                <x-uk-date :date="$this->object->time" />
                            </x-metadata-row>
                            <x-metadata-row label="Created" :copy-value="$this->object->created_at?->toIso8601String()">
                                <x-uk-date :date="$this->object->created_at" />
                            </x-metadata-row>
                            <x-metadata-row label="Last Updated" :copy-value="$this->object->updated_at?->toIso8601String()">
                                <x-uk-date :date="$this->object->updated_at" />
                            </x-metadata-row>
                            @if ($this->object->url)
                                <x-metadata-row label="URL" :copy-value="$this->object->url">
                                    <a href="{{ $this->object->url }}" target="_blank" class="hover:underline">
                                        {{ $this->object->url }}
                                    </a>
                                </x-metadata-row>
                            @endif
                            @if ($this->object->media_url)
                                <x-metadata-row label="Media URL" :copy-value="$this->object->media_url">
                                    <a href="{{ $this->object->media_url }}" target="_blank" class="hover:underline">
                                        {{ $this->object->media_url }}
                                    </a>
                                </x-metadata-row>
                            @endif
                            <x-metadata-row label="Locked" :copy-value="$this->object->isLocked() ? 'Yes' : 'No'">
                                <span class="badge {{ $this->object->isLocked() ? 'badge-warning' : 'badge-ghost' }} badge-sm">
                                    {{ $this->object->isLocked() ? 'Yes' : 'No' }}
                                </span>
                            </x-metadata-row>
                        </dl>
                    </div>

                    <!-- Lock Object -->
                    <div class="pb-4 border-b border-base-200">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="text-sm font-semibold uppercase tracking-wider text-base-content/80">Lock Object</span>
                            </div>
                            <x-toggle wire:model.live="object.metadata.locked" wire:change="toggleLock" />
                        </div>
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
                        <div wire:key="object-tags-{{ $this->object->id }}" wire:ignore>
                            <input id="tag-input-{{ $this->object->id }}" data-tagify data-initial="tag-initial-{{ $this->object->id }}" data-suggestions-id="tag-suggestions-{{ $this->object->id }}" aria-label="Tags" class="input input-sm w-full" placeholder="Add tags" data-hotkey="t" />
                            <script type="application/json" id="tag-initial-{{ $this->object->id }}">
                                {!! json_encode($this->object->tags->map(fn($tag) => ['value' => (string) $tag->name, 'type' => $tag->type ? (string) $tag->type : null])->values()->all()) !!}
                            </script>
                            <script type="application/json" id="tag-suggestions-{{ $this->object->id }}">
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
                            <div class="space-y-2 max-h-64 overflow-y-auto">
                                @foreach ($sidebarRelationships->take(10) as $relationship)
                                    @php
                                        $isFrom = $relationship->from_type === get_class($object) && $relationship->from_id === $object->id;
                                        $relatedModel = $isFrom ? $relationship->to : $relationship->from;

                                        // Initialize defaults
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
                            $metadata = $object->metadata ?? [];
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
                                <livewire:task-execution-section :model="$object" :key="'tasks-object-' . $object->id" />
                            </x-slot:content>
                        </x-collapse>
                    @else
                        <div class="pb-4 border-b border-base-200">
                            <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/80 mb-3">Tasks</h3>
                            <x-skeleton-loader type="list-item" :count="2" />
                        </div>
                    @endif

                    <!-- Activity Timeline -->
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
                            @if ($activities->isEmpty())
                            <x-empty-state
                                icon="fas.clock"
                                message="No activity yet"
                                actionEvent="addComment"
                                actionLabel="Add Comment" />
                            @else
                            @php
                            $activities = $this->activities;
                            $timeline = collect();
                            if ($this->object?->created_at) {
                            $timeline->push((object) [
                            '__synthetic' => true,
                            'event' => 'created',
                            'created_at' => $this->object->created_at,
                            'properties' => [],
                            'description' => '',
                            ]);
                            }
                            foreach ($activities as $a) { $timeline->push($a); }
                            $timeline = $timeline->sortByDesc(fn($a) => $a->created_at)->values();
                            @endphp
                            @foreach ($timeline as $activity)
                            @php
                            $modelLabel = 'Object';
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

                    @if ($this->object->metadata && count($this->object->metadata) > 0)
                    <x-collapse wire:model="objectMetaOpen">
                        <x-slot:heading>
                            <div class="text-sm font-semibold uppercase tracking-wider text-base-content/80 flex items-center justify-between gap-2 w-full">
                                <div>
                                    Metadata
                                </div>
                                <script type="application/json" id="object-meta-json-{{ $this->object->id }}">
                                    {
                                        !!json_encode($this - > object - > metadata, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!
                                    }
                                </script>
                                <x-button
                                    icon="o-clipboard"
                                    class="btn-ghost btn-xs"
                                    title="Copy JSON"
                                    onclick="(function(){ var el=document.getElementById('object-meta-json-{{ $this->object->id }}'); if(!el){return;} var text; try{ text=JSON.stringify(JSON.parse(el.textContent), null, 2);}catch(e){ text=el.textContent; } navigator.clipboard.writeText(text).then(function(){ $wire.notifyCopied('Object metadata'); }); })()" />
                            </div>
                        </x-slot:heading>
                        <x-slot:content>
                            <x-metadata-list :data="$this->object->metadata" />
                        </x-slot:content>
                    </x-collapse>
                    @endif
                </div>
            </x-drawer>
        </div>
        @else
        <div class="text-center py-12">
            <x-icon name="fas.triangle-exclamation" class="w-16 h-16 text-base-content/70 mx-auto mb-4" />
            <h3 class="text-lg font-medium text-base-content mb-2">Object Not Found</h3>
            <p class="text-base-content/70">The requested object could not be found.</p>
        </div>
        @endif
    </div>

    <!-- Create Tag Modal -->
    <x-modal wire:model="showCreateTagModal" title="Create New Tag" subtitle="Define a new tag with a specific type" separator>
        <livewire:create-tag :key="'create-tag-object-' . $this->object->id" @tag-created="handleTagCreated" />
    </x-modal>

    <!-- Tag Management Modal -->
    <x-modal wire:model="showTagModal" title="Manage Tags" subtitle="Add or remove tags for this object" separator>
        <livewire:manage-object-tags :object="$this->object" :key="'manage-tags-object-' . $this->object->id" />
    </x-modal>

    <!-- Edit Object Modal -->
    <x-modal wire:model="showEditObjectModal" title="Edit Object" subtitle="Update object details" separator>
        <livewire:edit-object :object="$this->object" :key="'edit-object-' . $this->object->id" />
    </x-modal>

    <!-- Manage Relationships Modal -->
    <x-modal wire:model="showManageRelationshipsModal" title="Manage Relationships" subtitle="View and manage connections to other items" separator box-class="[max-width:1024px]">
        <livewire:manage-relationships
            :model-type="get_class($this->object)"
            :model-id="(string) $this->object->id"
            :key="'manage-relationships-object-' . $this->object->id"
        />
    </x-modal>

    <!-- Add Relationship Modal -->
    <x-modal wire:model="showAddRelationshipModal" title="Add Relationship" subtitle="Create a connection to another item" separator box-class="[max-width:1024px]">
        <livewire:add-relationship
            :from-type="get_class($this->object)"
            :from-id="(string) $this->object->id"
            :key="'add-relationship-object-' . $this->object->id"
        />
    </x-modal>
</div>