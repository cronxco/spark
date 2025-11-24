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
    public bool $relatedBlocksLoaded = false;
    public bool $relatedEventsLoaded = false;
    public bool $activitiesLoaded = false;

    // -------------------------------------------------------------------------
    // Protected Methods & Properties
    // -------------------------------------------------------------------------

    /**
     * Define the loading tiers for progressive loading.
     * Priority order: Tags -> Direct Events -> Media -> Relationships -> Semantic Blocks -> Semantic Events -> Activities
     */
    protected function getLoadingTiers(): array
    {
        return [
            1 => ['loadTags'],
            2 => ['loadDirectEvents'],
            3 => ['loadMedia'],
            4 => ['loadRelationships'],
            5 => ['loadRelatedBlocks'],
            6 => ['loadRelatedEvents'],
            7 => ['loadActivities'],
        ];
    }

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
    ];

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

        return $this->object->getMedia(['screenshots', 'downloaded_images', 'pdfs', 'downloaded_videos', 'downloaded_documents']);
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
                if (isset($objectTypes[$concept]) && isset($objectTypes[$concept]['icon'])) {
                    return $objectTypes[$concept]['icon'];
                }
            }
        }

        // Fallback to hardcoded icons if plugin doesn't have this object type
        $icons = [
            'user' => 'fas.user',
            'post' => 'fas.file-lines',
            'comment' => 'fas.comment',
            'like' => 'fas.heart',
            'share' => 'fas.share',
            'file' => 'fas.file',
            'image' => 'fas.image',
            'video' => 'o-video-camera',
            'audio' => 'fas.music',
            'link' => 'fas.link',
            'location' => 'fas.location-dot',
            'event' => 'fas.calendar',
            'group' => 'fas.users',
            'page' => 'fas.file-lines',
            'product' => 'o-shopping-bag',
            'order' => 'o-shopping-cart',
            'payment' => 'fas.credit-card',
            'transaction' => 'fas.rotate',
            'account' => 'fas.money-bills',
            'wallet' => 'fas.wallet',
            'goal' => 'fas.flag',
            'task' => 'fas.circle-check',
            'project' => 'fas.folder',
            'team' => 'fas.users',
            'organization' => 'fas.building',
            'website' => 'fas.globe',
            'app' => 'fas.mobile-screen',
            'device' => 'fas.desktop',
            'integration' => 'fas.puzzle-piece',
            'webhook' => 'fas.bell',
            'notification' => 'o-bell-alert',
            'message' => 'fas.envelope',
            'email' => 'fas.envelope',
            'sms' => 'fas.comments',
            'push' => 'fas.mobile-screen',
            'alert' => 'fas.triangle-exclamation',
            'warning' => 'fas.triangle-exclamation',
            'error' => 'fas.circle-xmark',
            'success' => 'fas.circle-check',
            'info' => 'fas.circle-info',
            'question' => 'o-question-mark-circle',
            'help' => 'o-question-mark-circle',
            'support' => 'fas.heart',
            'feedback' => 'o-chat-bubble-oval-left-ellipsis',
            'review' => 'fas.star',
            'rating' => 'fas.star',
            'poll' => 'fas.chart-simple',
            'survey' => 'o-clipboard-document-list',
            'form' => 'o-clipboard-document',
            'submission' => 'fas.paper-plane',
            'contact' => 'fas.user',
            'lead' => 'fas.user-plus',
            'customer' => 'fas.user',
            'client' => 'o-briefcase',
            'partner' => 'fas.users',
            'vendor' => 'o-truck',
            'supplier' => 'fas.store',
            'service' => 'o-wrench-screwdriver',
            'tool' => 'o-wrench',
            'plugin' => 'fas.puzzle-piece',
            'extension' => 'fas.puzzle-piece',
            'addon' => 'fas.circle-plus',
            'module' => 'fas.grip',
            'component' => 'o-cube',
            'widget' => 'fas.grip',
            'gadget' => 'fas.gear',
            'feature' => 'fas.wand-magic-sparkles',
            'function' => 'fas.gear',
            'method' => 'fas.gear',
            'api' => 'fas.code',
            'endpoint' => 'fas.code',
            'route' => 'fas.map',
            'path' => 'fas.map',
            'url' => 'fas.link',
            'domain' => 'fas.globe',
            'subdomain' => 'fas.globe',
            'ip' => 'fas.server',
            'server' => 'fas.server',
            'database' => 'o-circle-stack',
            'table' => 'o-table-cells',
            'record' => 'fas.file',
            'row' => 'o-table-cells',
            'column' => 'o-table-cells',
            'field' => 'fas.layer-group',
            'property' => 'fas.tag',
            'attribute' => 'fas.tag',
            'parameter' => 'fas.tag',
            'variable' => 'fas.tag',
            'constant' => 'fas.tag',
            'function' => 'fas.gear',
            'class' => 'o-cube',
            'object' => 'o-cube',
            'instance' => 'o-cube',
            'entity' => 'o-cube',
            'model' => 'o-cube',
            'view' => 'fas.eye',
            'template' => 'fas.file-lines',
            'layout' => 'fas.grip',
            'style' => 'o-paint-brush',
            'theme' => 'o-paint-brush',
            'color' => 'o-swatch',
            'font' => 'fas.file-lines',
            'icon' => 'fas.image',
            'logo' => 'fas.image',
            'banner' => 'fas.image',
            'background' => 'fas.image',
            'texture' => 'fas.image',
            'pattern' => 'fas.image',
            'gradient' => 'fas.image',
            'shadow' => 'fas.image',
            'border' => 'fas.image',
            'outline' => 'fas.image',
            'stroke' => 'fas.image',
            'fill' => 'fas.image',
            'opacity' => 'fas.image',
            'transparency' => 'fas.image',
            'blur' => 'fas.image',
            'sharpness' => 'fas.image',
            'resolution' => 'fas.image',
            'quality' => 'fas.image',
            'format' => 'fas.tag',
            'encoding' => 'fas.tag',
            'compression' => 'fas.tag',
            'encryption' => 'fas.lock',
            'hash' => 'o-finger-print',
            'signature' => 'o-finger-print',
            'certificate' => 'o-academic-cap',
            'key' => 'fas.key',
            'token' => 'fas.key',
            'secret' => 'fas.lock',
            'password' => 'fas.lock',
            'auth' => 'fas.shield-halved',
            'authentication' => 'fas.shield-halved',
            'authorization' => 'fas.shield-halved',
            'permission' => 'fas.shield-halved',
            'role' => 'fas.shield-halved',
            'group' => 'fas.users',
            'team' => 'fas.users',
            'organization' => 'fas.building',
            'company' => 'fas.building',
            'business' => 'fas.building',
            'enterprise' => 'fas.building',
            'startup' => 'o-rocket-launch',
            'project' => 'fas.folder',
            'campaign' => 'o-megaphone',
            'initiative' => 'fas.flag',
            'strategy' => 'fas.lightbulb',
            'plan' => 'o-clipboard-document-list',
            'roadmap' => 'fas.map',
            'timeline' => 'fas.clock',
            'schedule' => 'fas.calendar',
            'deadline' => 'fas.clock',
            'milestone' => 'fas.flag',
            'checkpoint' => 'fas.circle-check',
            'phase' => 'fas.rotate',
            'stage' => 'fas.rotate',
            'step' => 'fas.rotate',
            'level' => 'fas.rotate',
            'tier' => 'fas.rotate',
            'category' => 'fas.tag',
            'tag' => 'fas.tag',
            'label' => 'fas.tag',
            'keyword' => 'fas.tag',
            'topic' => 'fas.tag',
            'subject' => 'fas.tag',
            'genre' => 'fas.tag',
            'style' => 'o-paint-brush',
            'mood' => 'fas.heart',
            'emotion' => 'fas.heart',
            'feeling' => 'fas.heart',
            'sentiment' => 'fas.heart',
            'tone' => 'fas.music',
            'voice' => 'fas.microphone',
            'personality' => 'fas.user',
            'character' => 'fas.user',
            'identity' => 'o-finger-print',
            'profile' => 'fas.user',
            'avatar' => 'fas.user',
            'picture' => 'fas.image',
            'photo' => 'fas.image',
            'image' => 'fas.image',
            'video' => 'o-video-camera',
            'audio' => 'fas.music',
            'sound' => 'fas.music',
            'music' => 'fas.music',
            'song' => 'fas.music',
            'track' => 'fas.music',
            'album' => 'fas.music',
            'playlist' => 'fas.list',
            'podcast' => 'fas.music',
            'episode' => 'fas.music',
            'show' => 'o-tv',
            'series' => 'o-tv',
            'season' => 'o-tv',
            'chapter' => 'fas.book-open',
            'book' => 'fas.book-open',
            'novel' => 'fas.book-open',
            'story' => 'fas.book-open',
            'article' => 'o-newspaper',
            'blog' => 'o-newspaper',
            'post' => 'fas.file-lines',
            'page' => 'fas.file-lines',
            'document' => 'fas.file-lines',
            'file' => 'fas.file',
            'folder' => 'fas.folder',
            'directory' => 'fas.folder',
            'archive' => 'fas.box-archive',
            'backup' => 'fas.box-archive',
            'snapshot' => 'o-camera',
            'version' => 'fas.tag',
            'revision' => 'fas.tag',
            'edit' => 'fas.pen',
            'change' => 'fas.rotate',
            'update' => 'fas.rotate',
            'modification' => 'fas.pen',
            'adjustment' => 'fas.pen',
            'correction' => 'fas.pen',
            'fix' => 'o-wrench',
            'repair' => 'o-wrench',
            'maintenance' => 'o-wrench',
            'improvement' => 'fas.arrow-trend-up',
            'enhancement' => 'fas.arrow-trend-up',
            'optimization' => 'fas.arrow-trend-up',
            'performance' => 'fas.chart-simple',
            'speed' => 'fas.clock',
            'efficiency' => 'fas.chart-simple',
            'productivity' => 'fas.chart-simple',
            'quality' => 'fas.star',
            'reliability' => 'fas.shield-halved',
            'stability' => 'fas.shield-halved',
            'security' => 'fas.shield-halved',
            'privacy' => 'fas.lock',
            'confidentiality' => 'fas.lock',
            'integrity' => 'fas.shield-halved',
            'authenticity' => 'fas.shield-halved',
            'validity' => 'fas.shield-halved',
            'accuracy' => 'fas.circle-check',
            'precision' => 'fas.circle-check',
            'exactness' => 'fas.circle-check',
            'correctness' => 'fas.circle-check',
            'truth' => 'fas.circle-check',
            'fact' => 'fas.circle-check',
            'reality' => 'fas.circle-check',
            'existence' => 'fas.circle-check',
            'presence' => 'fas.circle-check',
            'availability' => 'fas.circle-check',
            'accessibility' => 'fas.circle-check',
            'usability' => 'fas.circle-check',
            'functionality' => 'fas.gear',
            'capability' => 'fas.gear',
            'capacity' => 'fas.gear',
            'potential' => 'fas.gear',
            'ability' => 'fas.gear',
            'skill' => 'fas.gear',
            'talent' => 'fas.gear',
            'expertise' => 'fas.gear',
            'knowledge' => 'o-academic-cap',
            'wisdom' => 'o-academic-cap',
            'intelligence' => 'o-academic-cap',
            'understanding' => 'o-academic-cap',
            'comprehension' => 'o-academic-cap',
            'awareness' => 'fas.eye',
            'consciousness' => 'fas.eye',
            'recognition' => 'fas.eye',
            'identification' => 'fas.eye',
            'detection' => 'fas.eye',
            'discovery' => 'fas.magnifying-glass',
            'exploration' => 'fas.magnifying-glass',
            'investigation' => 'fas.magnifying-glass',
            'research' => 'fas.magnifying-glass',
            'analysis' => 'fas.chart-simple',
            'examination' => 'fas.magnifying-glass',
            'inspection' => 'fas.magnifying-glass',
            'review' => 'fas.eye',
            'evaluation' => 'fas.star',
            'assessment' => 'fas.star',
            'judgment' => 'fas.scale-balanced',
            'decision' => 'fas.circle-check',
            'choice' => 'fas.circle-check',
            'selection' => 'fas.circle-check',
            'option' => 'fas.circle-check',
            'alternative' => 'fas.circle-check',
            'possibility' => 'fas.circle-check',
            'opportunity' => 'fas.circle-check',
            'chance' => 'fas.circle-check',
            'probability' => 'fas.chart-simple',
            'likelihood' => 'fas.chart-simple',
            'certainty' => 'fas.circle-check',
            'confidence' => 'fas.circle-check',
            'trust' => 'fas.shield-halved',
            'belief' => 'fas.heart',
            'faith' => 'fas.heart',
            'hope' => 'fas.heart',
            'dream' => 'fas.heart',
            'vision' => 'fas.eye',
            'goal' => 'fas.flag',
            'target' => 'fas.flag',
            'objective' => 'fas.flag',
            'purpose' => 'fas.flag',
            'intention' => 'fas.flag',
            'motivation' => 'fas.flag',
            'inspiration' => 'fas.lightbulb',
            'creativity' => 'fas.lightbulb',
            'imagination' => 'fas.lightbulb',
            'innovation' => 'fas.lightbulb',
            'invention' => 'fas.lightbulb',
            'creation' => 'fas.circle-plus',
            'production' => 'fas.gear',
            'generation' => 'fas.gear',
            'formation' => 'fas.gear',
            'development' => 'fas.gear',
            'growth' => 'fas.arrow-trend-up',
            'expansion' => 'fas.arrow-trend-up',
            'extension' => 'fas.arrow-trend-up',
            'enlargement' => 'fas.arrow-trend-up',
            'increase' => 'fas.arrow-trend-up',
            'decrease' => 'fas.arrow-trend-down',
            'reduction' => 'fas.arrow-trend-down',
            'diminution' => 'fas.arrow-trend-down',
            'contraction' => 'fas.arrow-trend-down',
            'shrinkage' => 'fas.arrow-trend-down',
            'compression' => 'fas.arrow-trend-down',
            'condensation' => 'fas.arrow-trend-down',
            'concentration' => 'fas.arrow-trend-down',
            'focus' => 'fas.arrow-trend-down',
            'attention' => 'fas.eye',
            'awareness' => 'fas.eye',
            'consciousness' => 'fas.eye',
            'recognition' => 'fas.eye',
            'understanding' => 'o-academic-cap',
            'comprehension' => 'o-academic-cap',
            'knowledge' => 'o-academic-cap',
            'wisdom' => 'o-academic-cap',
            'intelligence' => 'o-academic-cap',
            'expertise' => 'o-academic-cap',
            'skill' => 'fas.gear',
            'talent' => 'fas.gear',
            'ability' => 'fas.gear',
            'capability' => 'fas.gear',
            'capacity' => 'fas.gear',
            'potential' => 'fas.gear',
            'functionality' => 'fas.gear',
            'usability' => 'fas.gear',
            'accessibility' => 'fas.gear',
            'availability' => 'fas.gear',
            'presence' => 'fas.gear',
            'existence' => 'fas.gear',
            'reality' => 'fas.gear',
            'truth' => 'fas.gear',
            'fact' => 'fas.gear',
            'accuracy' => 'fas.gear',
            'precision' => 'fas.gear',
            'exactness' => 'fas.gear',
            'correctness' => 'fas.gear',
            'validity' => 'fas.gear',
            'authenticity' => 'fas.gear',
            'integrity' => 'fas.gear',
            'security' => 'fas.gear',
            'privacy' => 'fas.gear',
            'confidentiality' => 'fas.gear',
            'stability' => 'fas.gear',
            'reliability' => 'fas.gear',
            'quality' => 'fas.gear',
            'performance' => 'fas.gear',
            'efficiency' => 'fas.gear',
            'productivity' => 'fas.gear',
            'speed' => 'fas.gear',
            'optimization' => 'fas.gear',
            'enhancement' => 'fas.gear',
            'improvement' => 'fas.gear',
            'maintenance' => 'fas.gear',
            'repair' => 'fas.gear',
            'fix' => 'fas.gear',
            'correction' => 'fas.gear',
            'adjustment' => 'fas.gear',
            'modification' => 'fas.gear',
            'change' => 'fas.gear',
            'update' => 'fas.gear',
            'revision' => 'fas.gear',
            'version' => 'fas.gear',
            'edit' => 'fas.gear',
            'snapshot' => 'fas.gear',
            'backup' => 'fas.gear',
            'archive' => 'fas.gear',
            'directory' => 'fas.gear',
            'folder' => 'fas.gear',
            'file' => 'fas.gear',
            'document' => 'fas.gear',
            'page' => 'fas.gear',
            'post' => 'fas.gear',
            'blog' => 'fas.gear',
            'article' => 'fas.gear',
            'story' => 'fas.gear',
            'novel' => 'fas.gear',
            'book' => 'fas.gear',
            'chapter' => 'fas.gear',
            'series' => 'fas.gear',
            'show' => 'fas.gear',
            'episode' => 'fas.gear',
            'season' => 'fas.gear',
            'podcast' => 'fas.gear',
            'track' => 'fas.gear',
            'song' => 'fas.gear',
            'music' => 'fas.gear',
            'audio' => 'fas.gear',
            'sound' => 'fas.gear',
            'video' => 'fas.gear',
            'image' => 'fas.gear',
            'photo' => 'fas.gear',
            'picture' => 'fas.gear',
            'avatar' => 'fas.gear',
            'profile' => 'fas.gear',
            'identity' => 'fas.gear',
            'character' => 'fas.gear',
            'personality' => 'fas.gear',
            'voice' => 'fas.gear',
            'tone' => 'fas.gear',
            'sentiment' => 'fas.gear',
            'feeling' => 'fas.gear',
            'emotion' => 'fas.gear',
            'mood' => 'fas.gear',
            'style' => 'fas.gear',
            'genre' => 'fas.gear',
            'subject' => 'fas.gear',
            'topic' => 'fas.gear',
            'keyword' => 'fas.gear',
            'label' => 'fas.gear',
            'tag' => 'fas.gear',
            'category' => 'fas.gear',
            'tier' => 'fas.gear',
            'level' => 'fas.gear',
            'stage' => 'fas.gear',
            'phase' => 'fas.gear',
            'step' => 'fas.gear',
            'checkpoint' => 'fas.gear',
            'milestone' => 'fas.gear',
            'deadline' => 'fas.gear',
            'schedule' => 'fas.gear',
            'timeline' => 'fas.gear',
            'roadmap' => 'fas.gear',
            'plan' => 'fas.gear',
            'strategy' => 'fas.gear',
            'initiative' => 'fas.gear',
            'campaign' => 'fas.gear',
            'project' => 'fas.gear',
            'startup' => 'fas.gear',
            'enterprise' => 'fas.gear',
            'business' => 'fas.gear',
            'company' => 'fas.gear',
            'organization' => 'fas.gear',
            'team' => 'fas.gear',
            'group' => 'fas.gear',
            'role' => 'fas.gear',
            'permission' => 'fas.gear',
            'authorization' => 'fas.gear',
            'authentication' => 'fas.gear',
            'auth' => 'fas.gear',
            'secret' => 'fas.gear',
            'token' => 'fas.gear',
            'key' => 'fas.gear',
            'certificate' => 'fas.gear',
            'signature' => 'fas.gear',
            'hash' => 'fas.gear',
            'encryption' => 'fas.gear',
            'compression' => 'fas.gear',
            'encoding' => 'fas.gear',
            'format' => 'fas.gear',
            'quality' => 'fas.gear',
            'resolution' => 'fas.gear',
            'sharpness' => 'fas.gear',
            'blur' => 'fas.gear',
            'transparency' => 'fas.gear',
            'opacity' => 'fas.gear',
            'fill' => 'fas.gear',
            'stroke' => 'fas.gear',
            'outline' => 'fas.gear',
            'border' => 'fas.gear',
            'pattern' => 'fas.gear',
            'texture' => 'fas.gear',
            'background' => 'fas.gear',
            'banner' => 'fas.gear',
            'logo' => 'fas.gear',
            'icon' => 'fas.gear',
            'font' => 'fas.gear',
            'color' => 'fas.gear',
            'theme' => 'fas.gear',
            'style' => 'fas.gear',
            'layout' => 'fas.gear',
            'template' => 'fas.gear',
            'view' => 'fas.gear',
            'instance' => 'fas.gear',
            'object' => 'fas.gear',
            'class' => 'fas.gear',
            'function' => 'fas.gear',
            'constant' => 'fas.gear',
            'variable' => 'fas.gear',
            'parameter' => 'fas.gear',
            'attribute' => 'fas.gear',
            'property' => 'fas.gear',
            'column' => 'fas.gear',
            'row' => 'fas.gear',
            'record' => 'fas.gear',
            'table' => 'fas.gear',
            'database' => 'fas.gear',
            'server' => 'fas.gear',
            'ip' => 'fas.gear',
            'domain' => 'fas.gear',
            'subdomain' => 'fas.gear',
            'url' => 'fas.gear',
            'path' => 'fas.gear',
            'route' => 'fas.gear',
            'endpoint' => 'fas.gear',
            'api' => 'fas.gear',
            'method' => 'fas.gear',
            'function' => 'fas.gear',
            'addon' => 'fas.gear',
            'extension' => 'fas.gear',
            'plugin' => 'fas.gear',
            'module' => 'fas.gear',
            'component' => 'fas.gear',
            'widget' => 'fas.gear',
            'gadget' => 'fas.gear',
            'tool' => 'fas.gear',
            'service' => 'fas.gear',
            'supplier' => 'fas.gear',
            'vendor' => 'fas.gear',
            'partner' => 'fas.gear',
            'client' => 'fas.gear',
            'customer' => 'fas.gear',
            'lead' => 'fas.gear',
            'contact' => 'fas.gear',
            'submission' => 'fas.gear',
            'form' => 'fas.gear',
            'survey' => 'fas.gear',
            'poll' => 'fas.gear',
            'rating' => 'fas.gear',
            'review' => 'fas.gear',
            'feedback' => 'fas.gear',
            'support' => 'fas.gear',
            'help' => 'fas.gear',
            'question' => 'fas.gear',
            'info' => 'fas.gear',
            'success' => 'fas.gear',
            'error' => 'fas.gear',
            'warning' => 'fas.gear',
            'alert' => 'fas.gear',
            'notification' => 'fas.gear',
            'webhook' => 'fas.gear',
            'integration' => 'fas.gear',
            'device' => 'fas.gear',
            'app' => 'fas.gear',
            'website' => 'fas.gear',
            'team' => 'fas.gear',
            'goal' => 'fas.gear',
            'wallet' => 'fas.gear',
            'account' => 'fas.gear',
            'transaction' => 'fas.gear',
            'payment' => 'fas.gear',
            'order' => 'fas.gear',
            'product' => 'fas.gear',
            'page' => 'fas.gear',
            'group' => 'fas.gear',
            'event' => 'fas.gear',
            'location' => 'fas.gear',
            'link' => 'fas.gear',
            'audio' => 'fas.gear',
            'video' => 'fas.gear',
            'image' => 'fas.gear',
            'photo' => 'fas.gear',
            'picture' => 'fas.gear',
            'file' => 'fas.gear',
            'document' => 'fas.gear',
            'post' => 'fas.gear',
            'comment' => 'fas.gear',
            'like' => 'fas.gear',
            'share' => 'fas.gear',
            'user' => 'fas.gear',
        ];

        // Try to match by type first, then concept
        if (isset($icons[strtolower($type)])) {
            return $icons[strtolower($type)];
        }

        if (isset($icons[strtolower($concept)])) {
            return $icons[strtolower($concept)];
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
};

?>

<div>
    @if ($this->object)
    <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
        <!-- Main Content Area -->
        <div class="flex-1 space-y-4 lg:space-y-6">
            <!-- Header -->
            <x-header title="Object Details" separator>
                <x-slot:actions>
                    <x-button
                        wire:click="toggleSidebar"
                        class="btn-ghost btn-sm"
                        title="{{ $this->showSidebar ? 'Hide details' : 'Show details' }}"
                        aria-label="{{ $this->showSidebar ? 'Hide details' : 'Show details' }}"
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
                        <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-base-200 flex items-center justify-center">
                            <x-icon name="{{ $this->getObjectIcon($this->object->type, $this->object->concept) }}"
                                class="w-6 h-6 sm:w-8 sm:h-8" />
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
                            <x-badge class="badge badge-outline">
                                <x-slot:value>
                                    <x-icon name="fas.layer-group" class="w-3 h-3 text-base-content/40" />
                                    {{ str::Headline($this->object->concept) }}
                                </x-slot:value>
                            </x-badge>
                            @endif
                            @if ($this->object->type)
                            <x-icon name="fas.arrow-right" class="w-3 h-3 text-base-content/40" />
                            <x-badge class="badge badge-outline">
                                <x-slot:value>
                                    <x-icon name="fas.tag" class="w-3 h-3 text-base-content/40" />
                                    {{ str::Headline($this->object->type) }}
                                </x-slot:value>
                            </x-badge>
                            @endif
                        </div>

                        <!-- URLs -->
                        @if ($this->object->url || $this->object->media_url)
                        <div class="mt-4 lg:mt-6 p-3 lg:p-4 rounded-lg bg-base-300/50 border-2 border-info/20">
                            <div class="flex flex-col sm:flex-row items-center justify-center gap-3 lg:gap-4">
                                @if ($this->object->url)
                                <a href="{{ $this->object->url }}" target="_blank"
                                    class="flex items-center gap-2 px-4 py-2 bg-info/10 hover:bg-info/20 text-info font-medium rounded-lg transition-colors">
                                    <x-icon name="fas.link" class="w-4 h-4" />
                                    <span>{{ $this->object->url }}</span>
                                </a>
                                @endif
                                @if ($this->object->media_url)
                                <a href="{{ $this->object->media_url }}" target="_blank"
                                    class="flex items-center gap-2 px-4 py-2 bg-info/10 hover:bg-info/20 text-info font-medium rounded-lg transition-colors">
                                    <x-icon name="fas.image" class="w-4 h-4" />
                                    <span>{{ $this->object->media_url }}</span>
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

            <!-- Content Section -->
            @if ($this->object->content)
            <x-card class="bg-base-100 shadow">
                <div class="max-w-prose mx-auto">
                    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                        <x-icon name="fas.file-lines" class="w-5 h-5 text-info" />
                        Content
                    </h3>
                    <div class="prose dark:prose-invert prose-base lg:prose-lg">
                        {!! Str::markdown($this->object->content) !!}
                    </div>
                </div>
            </x-card>
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
                        <a href="{{ route('events.show', $event->id) }}"
                            class="block hover:text-primary transition-colors">
                            <div class="flex items-start gap-3">
                                <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0 mt-1">
                                    <x-icon name="fas.bolt" class="w-4 h-4 text-primary" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-2 mb-1">
                                        <span class="font-medium">
                                            {{ $this->formatAction($event->action) }}
                                            @if (should_display_action_with_object($event->action, $event->service))
                                                @if ($event->target && $event->target_id !== $this->object->id)
                                                    <span class="text-base-content/80">{{ ' ' . $event->target->title }}</span>
                                                @elseif ($event->actor && $event->actor_id !== $this->object->id)
                                                    <span class="text-base-content/80">{{ ' ' . $event->actor->title }}</span>
                                                @endif
                                            @endif
                                        </span>
                                        @if ($event->value)
                                        <span class="text-sm font-semibold text-primary">
                                            {!! format_event_value_display($event->formatted_value, $event->value_unit, $event->service, $event->action, 'action') !!}
                                        </span>
                                        @endif
                                    </div>
                                    <div class="text-sm text-base-content/70 flex flex-wrap items-center gap-1">
                                        <span>{{ $event->time->format('d/m/Y H:i') }}</span>
                                        <span>·</span>
                                        <x-badge :value="$event->service" class="badge-xs badge-outline" />
                                        @if ($event->integration)
                                        <span>·</span>
                                        <x-badge :value="$event->integration->name" class="badge-xs badge-outline" />
                                        @endif
                                        @if ($event->tags && count($event->tags) > 0)
                                        <span>·</span>
                                        @foreach ($event->tags->take(3) as $tag)
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
                    <div class="aspect-square rounded-lg overflow-hidden bg-base-200 border border-base-300">
                        @if (Str::startsWith($media->mime_type, 'image/'))
                        <img
                            src="{{ $media->getUrl('thumbnail') ?: $media->getUrl() }}"
                            alt="{{ $media->name }}"
                            class="w-full h-full object-cover hover:scale-105 transition-transform cursor-pointer"
                            loading="lazy"
                            onclick="window.open('{{ $media->getUrl() }}', '_blank')"
                        />
                        @elseif (Str::startsWith($media->mime_type, 'video/'))
                        <div class="w-full h-full flex items-center justify-center bg-base-300 cursor-pointer" onclick="window.open('{{ $media->getUrl() }}', '_blank')">
                            <x-icon name="fas.play-circle" class="w-12 h-12 text-base-content/40" />
                        </div>
                        @else
                        <div class="w-full h-full flex flex-col items-center justify-center bg-base-300 cursor-pointer p-2" onclick="window.open('{{ $media->getUrl() }}', '_blank')">
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
                            <a href="{{ route('events.show', $event->id) }}"
                                class="block hover:text-primary transition-colors">
                                <div class="flex items-start gap-3">
                                    <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0 mt-1">
                                        <x-icon name="fas.bolt" class="w-4 h-4 text-primary" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-start justify-between gap-2 mb-1">
                                            <span class="font-medium">
                                                {{ $this->formatAction($event->action) }}
                                                @if (should_display_action_with_object($event->action, $event->service))
                                                @if ($event->target)
                                                <span class="text-base-content/80">{{ ' ' . $event->target->title }}</span>
                                                @elseif ($event->actor)
                                                <span class="text-base-content/80">{{ ' ' . $event->actor->title }}</span>
                                                @endif
                                                @endif
                                            </span>
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
                                            </div>
                                        </div>
                                        <div class="text-sm text-base-content/70 flex flex-wrap items-center gap-1">
                                            <span>{{ $event->time->format('d/m/Y H:i') }}</span>
                                            @if ($event->domain)
                                            <span>·</span>
                                            <x-badge :value="$event->domain" class="badge-xs badge-outline" />
                                            @endif
                                            <span>·</span>
                                            <x-badge :value="$event->service" class="badge-xs badge-outline" />
                                            @if ($event->integration)
                                            <span>·</span>
                                            <x-badge :value="$event->integration->name" class="badge-xs badge-outline" />
                                            @endif
                                            @if ($event->tags && count($event->tags) > 0)
                                            <span>·</span>
                                            @foreach ($event->tags as $tag)
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
                        @if (! $relationshipsLoaded)
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

                    <!-- Activity Timeline -->
                    <x-collapse wire:model="activityOpen">
                        <x-slot:heading>
                            <div class="text-sm font-semibold uppercase tracking-wider text-base-content/80">
                                Activity
                            </div>
                        </x-slot:heading>
                        <x-slot:content>
                            @if (! $activitiesLoaded)
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