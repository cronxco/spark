<?php

use App\Integrations\PluginRegistry;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    use Toast;

    /** @var array<string, array<string, mixed>> */
    public array $pluginActionTypes = [];

    /** @var array<string, array<string>> */
    public array $dbActionsByService = [];

    /** @var array<string, array<string>> */
    public array $dbBlockTypesByService = [];

    /** @var array<string, array<string>> */
    public array $dbObjectTypesByService = [];

    /** @var array<string, array<string, mixed>> */
    public array $allPluginBlockTypes = [];

    /** @var array<string, int> */
    public array $blockCountsByType = [];

    /** @var array<string, bool> */
    public array $collapse = [
        'undefined_actions' => false,
        'unknown_services' => false,
        'undefined_block_types' => false,
        'undefined_object_types' => false,
        'orphaned_events' => false,
        'orphaned_blocks' => false,
        'orphaned_objects' => false,
        'invalid_integrations' => false,
        'plugin_config_issues' => false,
        'embedding_health' => false,
        'block_types_custom_layouts' => false,
    ];

    public function mount(): void
    {
        $this->loadData();
    }

    public function toggle(string $key): void
    {
        $this->collapse[$key] = ! ($this->collapse[$key] ?? false);
    }

    /**
     * @return array<int, array{service: string, actions: array<int, string>}>
     */
    public function getUndefinedActionsProperty(): array
    {
        $results = [];
        foreach ($this->dbActionsByService as $service => $actions) {
            $defined = array_keys($this->pluginActionTypes[$service] ?? []);
            $missing = array_values(array_diff($actions, $defined));
            if (! empty($missing)) {
                $results[] = [
                    'service' => $service,
                    'actions' => $missing,
                ];
            }
        }

        return $results;
    }

    /**
     * @return array<int, string>
     */
    public function getUnknownServicesProperty(): array
    {
        $servicesInDb = array_keys($this->dbActionsByService);
        $servicesInPlugins = PluginRegistry::getAllPlugins()->map(fn ($c) => $c::getIdentifier())->values()->all();

        return array_values(array_diff($servicesInDb, $servicesInPlugins));
    }

    /**
     * @return array<int, array{service: string, block_types: array<int, string>}>
     */
    public function getUndefinedBlockTypesProperty(): array
    {
        $results = [];
        foreach ($this->dbBlockTypesByService as $service => $blockTypes) {
            $pluginClass = PluginRegistry::getPlugin($service);
            $defined = $pluginClass ? array_keys($pluginClass::getBlockTypes()) : [];
            $missing = array_values(array_diff($blockTypes, $defined));
            if (! empty($missing)) {
                $results[] = [
                    'service' => $service,
                    'block_types' => $missing,
                ];
            }
        }

        return $results;
    }

    /**
     * @return array<int, array{service: string, object_types: array<int, string>}>
     */
    public function getUndefinedObjectTypesProperty(): array
    {
        $results = [];
        foreach ($this->dbObjectTypesByService as $service => $objectTypes) {
            $pluginClass = PluginRegistry::getPlugin($service);
            $defined = $pluginClass ? array_keys($pluginClass::getObjectTypes()) : [];
            $missing = array_values(array_diff($objectTypes, $defined));
            if (! empty($missing)) {
                $results[] = [
                    'service' => $service,
                    'object_types' => $missing,
                ];
            }
        }

        return $results;
    }

    /**
     * @return array<int, array{count: int, sample_ids: array<int, string>}>
     */
    public function getOrphanedEventsProperty(): array
    {
        $orphanedEvents = Event::whereDoesntHave('integration')->limit(100)->get();

        return [
            'count' => $orphanedEvents->count(),
            'sample_ids' => $orphanedEvents->pluck('id')->take(10)->toArray(),
        ];
    }

    /**
     * @return array<int, array{count: int, sample_ids: array<int, string>}>
     */
    public function getOrphanedBlocksProperty(): array
    {
        $orphanedBlocks = Block::whereDoesntHave('event')->limit(100)->get();

        return [
            'count' => $orphanedBlocks->count(),
            'sample_ids' => $orphanedBlocks->pluck('id')->take(10)->toArray(),
        ];
    }

    /**
     * @return array<int, array{count: int, sample_ids: array<int, string>}>
     */
    public function getOrphanedObjectsProperty(): array
    {
        $orphanedObjects = EventObject::whereDoesntHave('actorEvents')
            ->whereDoesntHave('targetEvents')
            ->limit(100)
            ->get();

        return [
            'count' => $orphanedObjects->count(),
            'sample_ids' => $orphanedObjects->pluck('id')->take(10)->toArray(),
        ];
    }

    /**
     * @return array<int, array{issue: string, count: int, details: array<string, mixed>}>
     */
    public function getInvalidIntegrationsProperty(): array
    {
        $issues = [];

        // Integrations with unknown services
        $unknownServices = Integration::whereNotIn(
            'service',
            PluginRegistry::getAllPlugins()->map(fn ($c) => $c::getIdentifier())->values()
        )->get();

        if ($unknownServices->count() > 0) {
            $issues[] = [
                'issue' => 'Integrations with unknown services',
                'count' => $unknownServices->count(),
                'details' => $unknownServices->groupBy('service')->map->count()->toArray(),
            ];
        }

        // Integrations without groups
        $noGroup = Integration::whereNull('integration_group_id')
            ->orWhereDoesntHave('group')
            ->get();

        if ($noGroup->count() > 0) {
            $issues[] = [
                'issue' => 'Integrations without valid groups',
                'count' => $noGroup->count(),
                'details' => $noGroup->pluck('service')->countBy()->toArray(),
            ];
        }

        return $issues;
    }

    /**
     * @return array<int, array{plugin: string, issues: array<int, string>}>
     */
    public function getPluginConfigIssuesProperty(): array
    {
        $issues = [];

        foreach (PluginRegistry::getAllPlugins() as $identifier => $pluginClass) {
            $pluginIssues = [];

            try {
                // Check required methods exist and return valid data
                if (! method_exists($pluginClass, 'getActionTypes') || empty($pluginClass::getActionTypes())) {
                    $pluginIssues[] = 'No action types defined';
                }

                if (! method_exists($pluginClass, 'getIcon') || empty($pluginClass::getIcon())) {
                    $pluginIssues[] = 'Missing or empty icon';
                }

                if (! method_exists($pluginClass, 'getDomain')) {
                    $pluginIssues[] = 'Missing getDomain method';
                } else {
                    $domain = $pluginClass::getDomain();
                    if (! in_array($domain, PluginRegistry::getValidDomains())) {
                        $pluginIssues[] = "Invalid domain: {$domain}";
                    }
                }

                if (! method_exists($pluginClass, 'getAccentColor')) {
                    $pluginIssues[] = 'Missing getAccentColor method';
                }
            } catch (\Exception $e) {
                $pluginIssues[] = 'Exception during validation: ' . $e->getMessage();
            }

            if (! empty($pluginIssues)) {
                $issues[] = [
                    'plugin' => $identifier,
                    'issues' => $pluginIssues,
                ];
            }
        }

        return $issues;
    }

    /**
     * Get all block types with custom layout status, grouped by service
     *
     * @return array<string, array<int, array{type: string, display_name: string, has_custom_layout: bool, block_count: int, is_high_volume: bool, icon: string}>>
     */
    public function getBlockTypesWithCustomLayoutsProperty(): array
    {
        $highVolumeThreshold = 100;
        $grouped = [];

        foreach ($this->allPluginBlockTypes as $type => $data) {
            $service = $data['service'];
            $hasCustomLayout = view()->exists("blocks.types.{$type}");
            $blockCount = $this->blockCountsByType[$type] ?? 0;
            $isHighVolume = $blockCount > $highVolumeThreshold;

            if (! isset($grouped[$service])) {
                $grouped[$service] = [];
            }

            $grouped[$service][] = [
                'type' => $type,
                'display_name' => $data['display_name'],
                'has_custom_layout' => $hasCustomLayout,
                'block_count' => $blockCount,
                'is_high_volume' => $isHighVolume,
                'icon' => $data['icon'],
            ];
        }

        // Sort by block count descending within each service
        foreach ($grouped as $service => $types) {
            usort($grouped[$service], fn ($a, $b) => $b['block_count'] <=> $a['block_count']);
        }

        // Sort services by total block count
        uksort($grouped, function ($a, $b) use ($grouped) {
            $totalA = array_sum(array_column($grouped[$a], 'block_count'));
            $totalB = array_sum(array_column($grouped[$b], 'block_count'));

            return $totalB <=> $totalA;
        });

        return $grouped;
    }

    /**
     * Get embedding health statistics
     *
     * @return array{events: array, blocks: array, objects: array, models_by_version: array, overall_coverage: float}
     */
    public function getEmbeddingHealthProperty(): array
    {
        // Events stats
        $totalEvents = Event::count();
        $eventsWithEmbeddings = Event::whereNotNull('embeddings')->count();
        $eventsNullEmbeddings = $totalEvents - $eventsWithEmbeddings;
        $eventsCoverage = $totalEvents > 0 ? round(($eventsWithEmbeddings / $totalEvents) * 100, 1) : 0;

        // Get average age of event embeddings
        $avgEventEmbeddingAge = Event::whereNotNull('embeddings')
            ->whereRaw("metadata->>'embedding_generated_at' IS NOT NULL")
            ->selectRaw("AVG(EXTRACT(EPOCH FROM (NOW() - (metadata->>'embedding_generated_at')::timestamp)) / 86400) as avg_days")
            ->value('avg_days');

        // Blocks stats
        $totalBlocks = Block::count();
        $blocksWithEmbeddings = Block::whereNotNull('embeddings')->count();
        $blocksNullEmbeddings = $totalBlocks - $blocksWithEmbeddings;
        $blocksCoverage = $totalBlocks > 0 ? round(($blocksWithEmbeddings / $totalBlocks) * 100, 1) : 0;

        // Objects stats
        $totalObjects = EventObject::count();
        $objectsWithEmbeddings = EventObject::whereNotNull('embeddings')->count();
        $objectsNullEmbeddings = $totalObjects - $objectsWithEmbeddings;
        $objectsCoverage = $totalObjects > 0 ? round(($objectsWithEmbeddings / $totalObjects) * 100, 1) : 0;

        // Overall coverage
        $totalAll = $totalEvents + $totalBlocks + $totalObjects;
        $totalWithEmbeddings = $eventsWithEmbeddings + $blocksWithEmbeddings + $objectsWithEmbeddings;
        $overallCoverage = $totalAll > 0 ? round(($totalWithEmbeddings / $totalAll) * 100, 1) : 0;

        // Get models by embedding version
        $modelsByVersion = DB::table(DB::raw("(
            SELECT metadata->>'embedding_model' as model, COUNT(*) as count FROM events WHERE embeddings IS NOT NULL GROUP BY metadata->>'embedding_model'
            UNION ALL
            SELECT metadata->>'embedding_model' as model, COUNT(*) as count FROM blocks WHERE embeddings IS NOT NULL GROUP BY metadata->>'embedding_model'
            UNION ALL
            SELECT metadata->>'embedding_model' as model, COUNT(*) as count FROM objects WHERE embeddings IS NOT NULL GROUP BY metadata->>'embedding_model'
        ) as combined"))
            ->selectRaw('model, SUM(count) as total_count')
            ->groupBy('model')
            ->orderByDesc('total_count')
            ->get()
            ->toArray();

        // Coverage by service/domain
        $eventsByService = Event::selectRaw('service, COUNT(*) as total, COUNT(embeddings) as with_embeddings')
            ->groupBy('service')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $coverage = $row->total > 0 ? round(($row->with_embeddings / $row->total) * 100, 1) : 0;

                return [
                    'service' => $row->service,
                    'total' => $row->total,
                    'with_embeddings' => $row->with_embeddings,
                    'coverage' => $coverage,
                ];
            })
            ->toArray();

        $eventsByDomain = Event::selectRaw('domain, COUNT(*) as total, COUNT(embeddings) as with_embeddings')
            ->groupBy('domain')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) {
                $coverage = $row->total > 0 ? round(($row->with_embeddings / $row->total) * 100, 1) : 0;

                return [
                    'domain' => $row->domain,
                    'total' => $row->total,
                    'with_embeddings' => $row->with_embeddings,
                    'coverage' => $coverage,
                ];
            })
            ->toArray();

        return [
            'events' => [
                'total' => $totalEvents,
                'with_embeddings' => $eventsWithEmbeddings,
                'null_embeddings' => $eventsNullEmbeddings,
                'coverage' => $eventsCoverage,
                'avg_age_days' => $avgEventEmbeddingAge ? round($avgEventEmbeddingAge, 1) : null,
            ],
            'blocks' => [
                'total' => $totalBlocks,
                'with_embeddings' => $blocksWithEmbeddings,
                'null_embeddings' => $blocksNullEmbeddings,
                'coverage' => $blocksCoverage,
            ],
            'objects' => [
                'total' => $totalObjects,
                'with_embeddings' => $objectsWithEmbeddings,
                'null_embeddings' => $objectsNullEmbeddings,
                'coverage' => $objectsCoverage,
            ],
            'models_by_version' => $modelsByVersion,
            'overall_coverage' => $overallCoverage,
            'events_by_service' => $eventsByService,
            'events_by_domain' => $eventsByDomain,
        ];
    }

    /**
     * Get all sense check sections organized with issues first, then clean sections
     *
     * @return array<int, array{key: string, title: string, description: string, icon: string, badge_class: string, issue_count: int, has_issues: bool}>
     */
    public function getSenseCheckSectionsProperty(): array
    {
        $sections = [
            [
                'key' => 'undefined_actions',
                'title' => 'Actions present in database but not defined in plugin files',
                'description' => 'Distinct `events.action` that have no corresponding entry in plugin `getActionTypes()`.',
                'icon' => 'o-exclamation-triangle',
                'issue_count' => count($this->undefinedActions),
            ],
            [
                'key' => 'unknown_services',
                'title' => 'Unknown services in events',
                'description' => '`events.service` values that aren\'t registered in plugins.',
                'icon' => 'o-question-mark-circle',
                'issue_count' => count($this->unknownServices),
            ],
            [
                'key' => 'undefined_block_types',
                'title' => 'Block types present in database but not defined in plugin files',
                'description' => 'Distinct `blocks.block_type` per service with no corresponding entry in plugin `getBlockTypes()`.',
                'icon' => 'o-cube-transparent',
                'issue_count' => count($this->undefinedBlockTypes),
            ],
            [
                'key' => 'undefined_object_types',
                'title' => 'Object types present in events but not defined in plugin files',
                'description' => 'Distinct `objects.type` used as actor/target per service with no corresponding entry in plugin `getObjectTypes()`.',
                'icon' => 'o-tag',
                'issue_count' => count($this->undefinedObjectTypes),
            ],
            [
                'key' => 'orphaned_events',
                'title' => 'Orphaned database records',
                'description' => 'Events without integrations, blocks without events, and objects without references.',
                'icon' => 'o-trash',
                'issue_count' => $this->orphanedEvents['count'] + $this->orphanedBlocks['count'] + $this->orphanedObjects['count'],
            ],
            [
                'key' => 'invalid_integrations',
                'title' => 'Invalid integration records',
                'description' => 'Integrations with missing groups or unknown services.',
                'icon' => 'o-x-circle',
                'issue_count' => count($this->invalidIntegrations),
            ],
            [
                'key' => 'plugin_config_issues',
                'title' => 'Plugin configuration issues',
                'description' => 'Missing required methods, invalid domains, or configuration errors in plugins.',
                'icon' => 'o-cog-6-tooth',
                'issue_count' => count($this->pluginConfigIssues),
            ],
            [
                'key' => 'embedding_health',
                'title' => 'Embedding Health Dashboard',
                'description' => 'Monitor semantic search embedding coverage, quality, and versioning across events, blocks, and objects.',
                'icon' => 'o-sparkles',
                'issue_count' => 0, // Informational only
            ],
            [
                'key' => 'block_types_custom_layouts',
                'title' => 'Block Types with Custom Layouts',
                'description' => 'Coverage of custom card layouts for block types across all plugins. Shows which block types have custom layouts and highlights high-volume types (>100 blocks) that could benefit from custom layouts.',
                'icon' => 'o-rectangle-stack',
                'issue_count' => 0, // Informational only
            ],
        ];

        // Add computed properties
        foreach ($sections as &$section) {
            $section['has_issues'] = $section['issue_count'] > 0;
            $section['badge_class'] = $section['has_issues'] ? 'badge-error' : 'badge-success';
        }

        // Sort: sections with issues first, then clean sections
        usort($sections, function ($a, $b) {
            // First, sort by whether they have issues (issues first)
            if ($a['has_issues'] && ! $b['has_issues']) {
                return -1;
            }
            if (! $a['has_issues'] && $b['has_issues']) {
                return 1;
            }
            // Within same issue status, sort by issue count (desc) or alphabetically
            if ($a['has_issues'] && $b['has_issues']) {
                return $b['issue_count'] <=> $a['issue_count'];
            }

            return strcmp($a['title'], $b['title']);
        });

        return $sections;
    }

    private function loadData(): void
    {
        // Plugin action types keyed by service
        $this->pluginActionTypes = PluginRegistry::getAllPlugins()
            ->mapWithKeys(function (string $pluginClass) {
                $service = $pluginClass::getIdentifier();

                return [
                    $service => $pluginClass::getActionTypes(),
                ];
            })
            ->toArray();

        // DB actions grouped by service
        $actions = Event::query()
            ->select('service', 'action')
            ->distinct()
            ->get()
            ->groupBy('service')
            ->map(fn (Collection $rows) => $rows->pluck('action')->filter()->unique()->values()->all());

        $this->dbActionsByService = $actions->toArray();

        // DB block types grouped by service (via events)
        $blockTypes = Block::query()
            ->select('events.service', 'blocks.block_type')
            ->join('events', 'events.id', '=', 'blocks.event_id')
            ->whereNotNull('blocks.block_type')
            ->distinct()
            ->get()
            ->groupBy('service')
            ->map(fn (Collection $rows) => $rows->pluck('block_type')->filter()->unique()->values()->all());

        $this->dbBlockTypesByService = $blockTypes->toArray();

        // DB object types (actor/target) grouped by service (via events)
        $actorTypes = Event::query()
            ->select('events.service', 'ao.type as object_type')
            ->leftJoin('objects as ao', 'ao.id', '=', 'events.actor_id')
            ->whereNotNull('ao.type')
            ->distinct()
            ->get();

        $targetTypes = Event::query()
            ->select('events.service', 'to.type as object_type')
            ->leftJoin('objects as to', 'to.id', '=', 'events.target_id')
            ->whereNotNull('to.type')
            ->distinct()
            ->get();

        $objectTypes = $actorTypes->concat($targetTypes)
            ->groupBy('service')
            ->map(fn (Collection $rows) => $rows->pluck('object_type')->filter()->unique()->values()->all());

        $this->dbObjectTypesByService = $objectTypes->toArray();

        // All plugin block types with their configurations
        $this->allPluginBlockTypes = PluginRegistry::getAllPlugins()
            ->flatMap(function (string $pluginClass) {
                $service = $pluginClass::getIdentifier();
                $blockTypes = $pluginClass::getBlockTypes();

                return collect($blockTypes)->map(function ($config, $type) use ($service, $pluginClass) {
                    return [
                        'service' => $service,
                        'type' => $type,
                        'display_name' => $config['display_name'] ?? ucwords(str_replace('_', ' ', $type)),
                        'icon' => $config['icon'] ?? 'o-cube',
                        'plugin_class' => $pluginClass,
                    ];
                });
            })
            ->keyBy('type')
            ->toArray();

        // Block counts by type
        $this->blockCountsByType = Block::query()
            ->select('block_type', DB::raw('COUNT(*) as count'))
            ->whereNotNull('block_type')
            ->groupBy('block_type')
            ->pluck('count', 'block_type')
            ->toArray();
    }
}; ?>

<div>
    <x-header title="Sense Check" subtitle="Find functional inconsistencies across data and plugins" separator />

    <div class="space-y-2">
        @foreach ($this->senseCheckSections as $section)
        <x-collapse wire:model="collapse.{{ $section['key'] }}" separator class="bg-base-100">
            <x-slot:heading>
                <div class="flex items-center gap-3 w-full" wire:click="toggle('{{ $section['key'] }}')">
                    <x-icon :name="$section['icon']" class="w-5 h-5" />
                    <span class="flex-1 text-left">{{ $section['title'] }}</span>
                    @if ($section['has_issues'])
                    <x-badge :value="$section['issue_count']" class="{{ $section['badge_class'] }}" />
                    @else
                    <x-badge value="✓" class="{{ $section['badge_class'] }}" />
                    @endif
                </div>
            </x-slot:heading>
            <x-slot:content>
                <div class="text-sm text-base-content/70 mb-4">{{ $section['description'] }}</div>

                @if ($section['key'] === 'undefined_actions')
                @php($items = $this->undefinedActions)
                @if (empty($items))
                <div class="alert alert-success">No inconsistencies found 🎉</div>
                @else
                <div class="space-y-3">
                    @foreach ($items as $item)
                    <div class="border border-base-300 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-semibold">{{ strtoupper($item['service']) }}</div>
                            <span class="badge badge-warning">{{ count($item['actions']) }} missing</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($item['actions'] as $action)
                            <a href="{{ route('admin.events.index', ['serviceFilter' => $item['service'], 'actionFilter' => $action]) }}"
                                class="badge badge-outline hover:badge-warning transition-colors">
                                {{ $action }}
                            </a>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif

                @elseif ($section['key'] === 'unknown_services')
                @php($items = $this->unknownServices)
                @if (empty($items))
                <div class="alert alert-success">All services are registered 🎉</div>
                @else
                <div class="flex flex-wrap gap-2">
                    @foreach ($items as $service)
                    <a href="{{ route('admin.events.index', ['serviceFilter' => $service]) }}"
                        class="badge badge-error hover:badge-error-hover transition-colors">
                        {{ $service }}
                    </a>
                    @endforeach
                </div>
                @endif

                @elseif ($section['key'] === 'undefined_block_types')
                @php($items = $this->undefinedBlockTypes)
                @if (empty($items))
                <div class="alert alert-success">No inconsistencies found 🎉</div>
                @else
                <div class="space-y-3">
                    @foreach ($items as $item)
                    <div class="border border-base-300 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-semibold">{{ strtoupper($item['service']) }}</div>
                            <span class="badge badge-warning">{{ count($item['block_types']) }} missing</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($item['block_types'] as $type)
                            <a href="{{ route('admin.blocks.index', ['serviceFilter' => $item['service'], 'blockTypeFilter' => $type]) }}"
                                class="badge badge-outline hover:badge-warning transition-colors">
                                {{ $type }}
                            </a>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif

                @elseif ($section['key'] === 'undefined_object_types')
                @php($items = $this->undefinedObjectTypes)
                @if (empty($items))
                <div class="alert alert-success">No inconsistencies found 🎉</div>
                @else
                <div class="space-y-3">
                    @foreach ($items as $item)
                    <div class="border border-base-300 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-semibold">{{ strtoupper($item['service']) }}</div>
                            <span class="badge badge-warning">{{ count($item['object_types']) }} missing</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($item['object_types'] as $type)
                            <a href="{{ route('admin.objects.index', ['typeFilter' => $type]) }}"
                                class="badge badge-outline hover:badge-warning transition-colors">
                                {{ $type }}
                            </a>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif

                @elseif ($section['key'] === 'orphaned_events')
                @php($orphanedEvents = $this->orphanedEvents)
                @php($orphanedBlocks = $this->orphanedBlocks)
                @php($orphanedObjects = $this->orphanedObjects)
                @if ($orphanedEvents['count'] === 0 && $orphanedBlocks['count'] === 0 && $orphanedObjects['count'] === 0)
                <div class="alert alert-success">No orphaned records found 🎉</div>
                @else
                <div class="space-y-3">
                    @if ($orphanedEvents['count'] > 0)
                    <div class="border border-red-200 rounded-lg p-4 bg-red-50">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-semibold text-red-700">Events without integrations</div>
                            <a href="{{ route('admin.events.index') }}" class="badge badge-error hover:badge-error-hover transition-colors">
                                {{ $orphanedEvents['count'] }} found
                            </a>
                        </div>
                        @if (!empty($orphanedEvents['sample_ids']))
                        <div class="text-sm text-red-600">Sample IDs: {{ implode(', ', array_slice($orphanedEvents['sample_ids'], 0, 3)) }}{{ count($orphanedEvents['sample_ids']) > 3 ? '...' : '' }}</div>
                        @endif
                    </div>
                    @endif

                    @if ($orphanedBlocks['count'] > 0)
                    <div class="border border-red-200 rounded-lg p-4 bg-red-50">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-semibold text-red-700">Blocks without events</div>
                            <a href="{{ route('admin.blocks.index') }}" class="badge badge-error hover:badge-error-hover transition-colors">
                                {{ $orphanedBlocks['count'] }} found
                            </a>
                        </div>
                        @if (!empty($orphanedBlocks['sample_ids']))
                        <div class="text-sm text-red-600">Sample IDs: {{ implode(', ', array_slice($orphanedBlocks['sample_ids'], 0, 3)) }}{{ count($orphanedBlocks['sample_ids']) > 3 ? '...' : '' }}</div>
                        @endif
                    </div>
                    @endif

                    @if ($orphanedObjects['count'] > 0)
                    <div class="border border-red-200 rounded-lg p-4 bg-red-50">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-semibold text-red-700">Objects without event references</div>
                            <a href="{{ route('admin.objects.index') }}" class="badge badge-error hover:badge-error-hover transition-colors">
                                {{ $orphanedObjects['count'] }} found
                            </a>
                        </div>
                        @if (!empty($orphanedObjects['sample_ids']))
                        <div class="text-sm text-red-600">Sample IDs: {{ implode(', ', array_slice($orphanedObjects['sample_ids'], 0, 3)) }}{{ count($orphanedObjects['sample_ids']) > 3 ? '...' : '' }}</div>
                        @endif
                    </div>
                    @endif
                </div>
                @endif

                @elseif ($section['key'] === 'invalid_integrations')
                @php($items = $this->invalidIntegrations)
                @if (empty($items))
                <div class="alert alert-success">No invalid integrations found 🎉</div>
                @else
                <div class="space-y-3">
                    @foreach ($items as $item)
                    <div class="border border-red-200 rounded-lg p-4 bg-red-50">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-semibold text-red-700">{{ $item['issue'] }}</div>
                            <a href="{{ route('integrations.index') }}" class="badge badge-error hover:badge-error-hover transition-colors">
                                {{ $item['count'] }} found
                            </a>
                        </div>
                        @if (!empty($item['details']))
                        <div class="flex flex-wrap gap-1">
                            @foreach ($item['details'] as $service => $count)
                            <a href="{{ route('admin.events.index', ['serviceFilter' => $service]) }}"
                                class="badge badge-outline hover:badge-warning transition-colors">
                                {{ $service }}: {{ $count }}
                            </a>
                            @endforeach
                        </div>
                        @endif
                    </div>
                    @endforeach
                </div>
                @endif

                @elseif ($section['key'] === 'plugin_config_issues')
                @php($items = $this->pluginConfigIssues)
                @if (empty($items))
                <div class="alert alert-success">All plugins properly configured 🎉</div>
                @else
                <div class="space-y-3">
                    @foreach ($items as $item)
                    <div class="border border-red-200 rounded-lg p-4 bg-red-50">
                        <div class="flex items-center justify-between mb-2">
                            <div class="font-semibold text-red-700">{{ strtoupper($item['plugin']) }}</div>
                            <span class="badge badge-error">{{ count($item['issues']) }} issues</span>
                        </div>
                        <ul class="text-sm text-red-600 space-y-1">
                            @foreach ($item['issues'] as $issue)
                            <li>• {{ $issue }}</li>
                            @endforeach
                        </ul>
                    </div>
                    @endforeach
                </div>
                @endif

                @elseif ($section['key'] === 'embedding_health')
                @php($health = $this->embeddingHealth)

                @if (!empty($health) && isset($health['events'], $health['blocks'], $health['objects']))
                {{-- Overall Stats --}}
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="stat bg-gradient-to-br from-warning/5 to-warning/25 rounded-lg border border-warning/50">
                        <div class="stat-title flex items-center gap-2">
                            <x-icon name="o-sparkles" class="w-4 h-4 text-warning" />
                            Overall Coverage
                        </div>
                        <div class="stat-value {{ $health['overall_coverage'] > 80 ? 'text-success' : ($health['overall_coverage'] > 50 ? 'text-warning' : 'text-error') }}">
                            {{ $health['overall_coverage'] }}%
                        </div>
                        <div class="stat-desc">Across all models</div>
                    </div>
                    <div class="stat bg-base-200 rounded-lg">
                        <div class="stat-title">Events</div>
                        <div class="stat-value text-primary text-2xl">{{ number_format($health['events']['with_embeddings']) }}</div>
                        <div class="stat-desc">{{ $health['events']['coverage'] }}% of {{ number_format($health['events']['total']) }}</div>
                    </div>
                    <div class="stat bg-base-200 rounded-lg">
                        <div class="stat-title">Blocks</div>
                        <div class="stat-value text-secondary text-2xl">{{ number_format($health['blocks']['with_embeddings']) }}</div>
                        <div class="stat-desc">{{ $health['blocks']['coverage'] }}% of {{ number_format($health['blocks']['total']) }}</div>
                    </div>
                    <div class="stat bg-base-200 rounded-lg">
                        <div class="stat-title">Objects</div>
                        <div class="stat-value text-accent text-2xl">{{ number_format($health['objects']['with_embeddings']) }}</div>
                        <div class="stat-desc">{{ $health['objects']['coverage'] }}% of {{ number_format($health['objects']['total']) }}</div>
                    </div>
                </div>

                {{-- Embedding Models Used --}}
                @if (!empty($health['models_by_version']))
                <div class="mb-6">
                    <h4 class="font-semibold mb-3 flex items-center gap-2">
                        <x-icon name="o-cube" class="w-4 h-4" />
                        Embedding Models in Use
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        @foreach ($health['models_by_version'] as $modelData)
                        <div class="border border-base-300 rounded-lg p-3 bg-base-100">
                            <div class="text-sm font-mono text-primary">{{ $modelData->model ?? 'Unknown' }}</div>
                            <div class="text-2xl font-bold">{{ number_format($modelData->total_count) }}</div>
                            <div class="text-xs text-base-content/60">records</div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Missing Embeddings Summary --}}
                @php
                    $totalMissing = ($health['events']['null_embeddings'] ?? 0) + ($health['blocks']['null_embeddings'] ?? 0) + ($health['objects']['null_embeddings'] ?? 0);
                @endphp
                @if ($totalMissing > 0)
                <div class="alert alert-warning mb-6">
                    <x-icon name="o-exclamation-triangle" class="w-5 h-5" />
                    <div>
                        <div class="font-semibold">{{ number_format($totalMissing) }} records without embeddings</div>
                        <div class="text-sm">
                            Events: {{ number_format($health['events']['null_embeddings'] ?? 0) }} |
                            Blocks: {{ number_format($health['blocks']['null_embeddings'] ?? 0) }} |
                            Objects: {{ number_format($health['objects']['null_embeddings'] ?? 0) }}
                        </div>
                    </div>
                    <div>
                        <a href="{{ route('admin.sense-check.index') }}" class="btn btn-sm btn-warning">
                            Regenerate Missing
                        </a>
                    </div>
                </div>
                @else
                <div class="alert alert-success mb-6">
                    <x-icon name="o-check-circle" class="w-5 h-5" />
                    <span>All records have embeddings! 🎉</span>
                </div>
                @endif

                {{-- Coverage by Service --}}
                @if (!empty($health['events_by_service']))
                <div class="mb-6">
                    <h4 class="font-semibold mb-3 flex items-center gap-2">
                        <x-icon name="o-server-stack" class="w-4 h-4" />
                        Coverage by Service (Top 10)
                    </h4>
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th class="text-right">Total Events</th>
                                    <th class="text-right">With Embeddings</th>
                                    <th class="text-right">Coverage</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($health['events_by_service'] as $serviceData)
                                <tr class="hover">
                                    <td>
                                        @php($pluginClass = \App\Integrations\PluginRegistry::getPlugin($serviceData['service']))
                                        @if ($pluginClass)
                                        <div class="flex items-center gap-2">
                                            <x-icon :name="$pluginClass::getIcon()" class="w-4 h-4" />
                                            {{ $pluginClass::getDisplayName() }}
                                        </div>
                                        @else
                                        {{ $serviceData['service'] }}
                                        @endif
                                    </td>
                                    <td class="text-right">{{ number_format($serviceData['total']) }}</td>
                                    <td class="text-right">{{ number_format($serviceData['with_embeddings']) }}</td>
                                    <td class="text-right">
                                        <span class="badge {{ $serviceData['coverage'] > 90 ? 'badge-success' : ($serviceData['coverage'] > 70 ? 'badge-warning' : 'badge-error') }} badge-sm">
                                            {{ $serviceData['coverage'] }}%
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <div class="w-20 h-2 bg-base-300 rounded-full overflow-hidden">
                                            <div class="h-full bg-success" style="width: {{ $serviceData['coverage'] }}%"></div>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif

                {{-- Coverage by Domain --}}
                @if (!empty($health['events_by_domain']))
                <div class="mb-6">
                    <h4 class="font-semibold mb-3 flex items-center gap-2">
                        <x-icon name="o-globe-alt" class="w-4 h-4" />
                        Coverage by Domain
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach ($health['events_by_domain'] as $domainData)
                        <div class="border border-base-300 rounded-lg p-3 bg-base-100">
                            <div class="flex items-center justify-between mb-2">
                                <div class="badge badge-outline">{{ $domainData['domain'] }}</div>
                                <span class="badge {{ $domainData['coverage'] > 90 ? 'badge-success' : ($domainData['coverage'] > 70 ? 'badge-warning' : 'badge-error') }} badge-sm">
                                    {{ $domainData['coverage'] }}%
                                </span>
                            </div>
                            <div class="text-sm text-base-content/70">
                                {{ number_format($domainData['with_embeddings']) }} / {{ number_format($domainData['total']) }} events
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Embedding Age --}}
                @if (isset($health['events']['avg_age_days']) && $health['events']['avg_age_days'] !== null)
                <div class="stat bg-base-200 rounded-lg">
                    <div class="stat-title">Average Embedding Age</div>
                    <div class="stat-value text-sm">{{ $health['events']['avg_age_days'] }} days</div>
                    <div class="stat-desc">For events with generation timestamps</div>
                </div>
                @endif
                @else
                <div class="alert alert-error">
                    <x-icon name="o-exclamation-circle" class="w-5 h-5" />
                    <span>Failed to load embedding health data</span>
                </div>
                @endif

                @elseif ($section['key'] === 'block_types_custom_layouts')
                @php($grouped = $this->blockTypesWithCustomLayouts)
                @php($totalTypes = array_sum(array_map('count', $grouped)))
                @php($typesWithLayouts = collect($grouped)->flatten(1)->where('has_custom_layout', true)->count())
                @php($coveragePercent = $totalTypes > 0 ? round(($typesWithLayouts / $totalTypes) * 100, 1) : 0)

                {{-- Summary Stats --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="stat bg-base-200 rounded-lg">
                        <div class="stat-title">Total Block Types</div>
                        <div class="stat-value text-primary">{{ $totalTypes }}</div>
                        <div class="stat-desc">Across all plugins</div>
                    </div>
                    <div class="stat bg-base-200 rounded-lg">
                        <div class="stat-title">Custom Layouts</div>
                        <div class="stat-value text-success">{{ $typesWithLayouts }}</div>
                        <div class="stat-desc">Types with custom card layouts</div>
                    </div>
                    <div class="stat bg-base-200 rounded-lg">
                        <div class="stat-title">Coverage</div>
                        <div class="stat-value {{ $coveragePercent > 50 ? 'text-success' : 'text-warning' }}">{{ $coveragePercent }}%</div>
                        <div class="stat-desc">{{ $totalTypes - $typesWithLayouts }} remaining</div>
                    </div>
                </div>

                @if (empty($grouped))
                <div class="alert alert-info">No block types found in plugins</div>
                @else
                <div class="space-y-4">
                    @foreach ($grouped as $service => $types)
                    @php($pluginClass = \App\Integrations\PluginRegistry::getPlugin($service))
                    @php($serviceIcon = $pluginClass ? $pluginClass::getIcon() : 'o-cube')
                    @php($serviceDisplayName = $pluginClass ? $pluginClass::getDisplayName() : strtoupper($service))
                    @php($serviceTotalBlocks = array_sum(array_column($types, 'block_count')))
                    @php($serviceCustomLayoutCount = collect($types)->where('has_custom_layout', true)->count())

                    <div class="border border-base-300 rounded-lg p-4">
                        {{-- Service Header --}}
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-2">
                                <x-icon :name="$serviceIcon" class="w-5 h-5" />
                                <span class="font-semibold">{{ $serviceDisplayName }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="badge badge-ghost badge-sm">{{ $serviceTotalBlocks }} blocks total</span>
                                <span class="badge badge-success badge-sm">{{ $serviceCustomLayoutCount }}/{{ count($types) }} with layouts</span>
                            </div>
                        </div>

                        {{-- Block Types Table --}}
                        <div class="overflow-x-auto">
                            <table class="table table-xs">
                                <thead>
                                    <tr>
                                        <th>Block Type</th>
                                        <th>Display Name</th>
                                        <th class="text-center">Custom Layout?</th>
                                        <th class="text-right">Block Count</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($types as $blockType)
                                    <tr class="hover">
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <x-icon :name="$blockType['icon']" class="w-4 h-4" />
                                                <code class="text-xs">{{ $blockType['type'] }}</code>
                                            </div>
                                        </td>
                                        <td>{{ $blockType['display_name'] }}</td>
                                        <td class="text-center">
                                            @if ($blockType['has_custom_layout'])
                                            <span class="badge badge-success badge-sm">✓</span>
                                            @elseif ($blockType['is_high_volume'])
                                            <span class="badge badge-warning badge-sm">✗</span>
                                            @else
                                            <span class="badge badge-ghost badge-sm">✗</span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @if ($blockType['is_high_volume'])
                                            <span class="badge badge-warning badge-sm">{{ number_format($blockType['block_count']) }}</span>
                                            @else
                                            <span class="text-sm">{{ number_format($blockType['block_count']) }}</span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            <a href="{{ route('admin.blocks.index', ['blockTypeFilter' => $blockType['type']]) }}"
                                               class="btn btn-ghost btn-xs">
                                                View Blocks
                                            </a>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
                @endif
            </x-slot:content>
        </x-collapse>
        @endforeach
    </div>
</div>