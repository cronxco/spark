<?php

use App\Integrations\PluginRegistry;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Support\Collection;
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
        'data_consistency_issues' => false,
        'block_custom_layouts' => false,
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
     * @return array<int, array{issue: string, count: int, details?: array<string, mixed>}>
     */
    public function getDataConsistencyIssuesProperty(): array
    {
        $issues = [];

        // Events with missing timestamps
        $missingTime = Event::whereNull('time')->count();
        if ($missingTime > 0) {
            $issues[] = [
                'issue' => 'Events with missing timestamps',
                'count' => $missingTime,
            ];
        }

        // Events with future timestamps (more than 1 day ahead)
        $futureEvents = Event::where('time', '>', now()->addDay())->count();
        if ($futureEvents > 0) {
            $issues[] = [
                'issue' => 'Events with future timestamps (>1 day ahead)',
                'count' => $futureEvents,
            ];
        }

        // Blocks without titles
        $noTitle = Block::where(function ($q) {
            $q->whereNull('title')
                ->orWhere('title', '')
                ->orWhere('title', 'like', '%null%');
        })->count();
        if ($noTitle > 0) {
            $issues[] = [
                'issue' => 'Blocks without proper titles',
                'count' => $noTitle,
            ];
        }

        // Duplicate events (same integration, service, action, time within 1 minute)
        $duplicates = Event::selectRaw('COUNT(*) as count')
            ->groupBy(['integration_id', 'service', 'action'])
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->sum('count');

        if ($duplicates > 0) {
            $issues[] = [
                'issue' => 'Potentially duplicate events',
                'count' => $duplicates,
                'details' => ['note' => 'Events with same integration, service, and action'],
            ];
        }

        return $issues;
    }

    /**
     * Get block types with custom card layouts information
     *
     * @return array<int, array{service: string, block_type: string, display_name: string, has_custom_layout: bool, block_count: int, is_high_volume: bool}>
     */
    public function getBlockCustomLayoutsProperty(): array
    {
        $results = [];

        // Get all block types from plugins
        foreach (PluginRegistry::getAllPlugins() as $identifier => $pluginClass) {
            $blockTypes = $pluginClass::getBlockTypes();

            foreach ($blockTypes as $blockType => $config) {
                // Check if custom layout exists
                $hasCustomLayout = view()->exists("blocks.types.{$blockType}");

                // Get count of blocks of this type
                $blockCount = Block::where('block_type', $blockType)->count();

                // Consider high volume if > 100 blocks
                $isHighVolume = $blockCount > 100;

                $results[] = [
                    'service' => $identifier,
                    'block_type' => $blockType,
                    'display_name' => $config['display_name'] ?? str_replace('_', ' ', $blockType),
                    'has_custom_layout' => $hasCustomLayout,
                    'block_count' => $blockCount,
                    'is_high_volume' => $isHighVolume,
                ];
            }
        }

        // Sort: high volume without layouts first, then by count descending
        usort($results, function ($a, $b) {
            // Priority 1: High volume without custom layout
            $aHighNoLayout = $a['is_high_volume'] && ! $a['has_custom_layout'];
            $bHighNoLayout = $b['is_high_volume'] && ! $b['has_custom_layout'];
            if ($aHighNoLayout && ! $bHighNoLayout) {
                return -1;
            }
            if (! $aHighNoLayout && $bHighNoLayout) {
                return 1;
            }

            // Priority 2: Block count descending
            return $b['block_count'] <=> $a['block_count'];
        });

        return $results;
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
                'key' => 'data_consistency_issues',
                'title' => 'Data consistency issues',
                'description' => 'Missing timestamps, future dates, duplicate events, and other data quality issues.',
                'icon' => 'o-clock',
                'issue_count' => count($this->dataConsistencyIssues),
            ],
            [
                'key' => 'block_custom_layouts',
                'title' => 'Block types with custom card layouts',
                'description' => 'Overview of which block types have custom display layouts defined. High-volume block types without custom layouts are highlighted.',
                'icon' => 'o-rectangle-stack',
                'issue_count' => 0, // This is informational, not an issue
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

                    @elseif ($section['key'] === 'data_consistency_issues')
                        @php($items = $this->dataConsistencyIssues)
                        @if (empty($items))
                            <div class="alert alert-success">No data consistency issues found 🎉</div>
                        @else
                            <div class="space-y-3">
                                @foreach ($items as $item)
                                    <div class="border border-yellow-200 rounded-lg p-4 bg-yellow-50">
                                        <div class="flex items-center justify-between mb-2">
                                            <div class="font-semibold text-yellow-700">{{ $item['issue'] }}</div>
                                            <span class="badge badge-warning">{{ $item['count'] }} found</span>
                                        </div>
                                        @if (!empty($item['details']['note']))
                                            <div class="text-sm text-yellow-600">{{ $item['details']['note'] }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                    @elseif ($section['key'] === 'block_custom_layouts')
                        @php($items = $this->blockCustomLayouts)
                        @php($totalTypes = count($items))
                        @php($withLayouts = collect($items)->filter(fn($item) => $item['has_custom_layout'])->count())
                        @php($highVolumeWithoutLayouts = collect($items)->filter(fn($item) => $item['is_high_volume'] && !$item['has_custom_layout'])->count())

                        <div class="mb-4 flex items-center gap-4 text-sm">
                            <div class="badge badge-lg badge-primary">{{ $totalTypes }} total block types</div>
                            <div class="badge badge-lg badge-success">{{ $withLayouts }} with custom layouts</div>
                            @if($highVolumeWithoutLayouts > 0)
                                <div class="badge badge-lg badge-warning">{{ $highVolumeWithoutLayouts }} high-volume without layouts</div>
                            @endif
                        </div>

                        <div class="overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Service</th>
                                        <th>Block Type</th>
                                        <th>Display Name</th>
                                        <th class="text-center">Custom Layout</th>
                                        <th class="text-right">Block Count</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($items as $item)
                                        @php
                                            $pluginClass = \App\Integrations\PluginRegistry::getPlugin($item['service']);
                                            $icon = $pluginClass ? $pluginClass::getIcon() : 'o-squares-2x2';
                                            $serviceName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($item['service']);

                                            // Determine row styling
                                            if ($item['is_high_volume'] && !$item['has_custom_layout']) {
                                                $rowClass = 'bg-warning/5 hover:bg-warning/10';
                                            } elseif ($item['has_custom_layout']) {
                                                $rowClass = 'bg-success/5 hover:bg-success/10';
                                            } else {
                                                $rowClass = 'hover:bg-base-200';
                                            }
                                        @endphp
                                        <tr class="{{ $rowClass }}">
                                            <td>
                                                <div class="flex items-center gap-2">
                                                    <x-icon :name="$icon" class="w-4 h-4" />
                                                    {{ $serviceName }}
                                                </div>
                                            </td>
                                            <td>
                                                <code class="text-xs">{{ $item['block_type'] }}</code>
                                            </td>
                                            <td>{{ $item['display_name'] }}</td>
                                            <td class="text-center">
                                                @if($item['has_custom_layout'])
                                                    <x-icon name="o-check-circle" class="w-5 h-5 text-success inline-block" />
                                                @else
                                                    <x-icon name="o-x-circle" class="w-5 h-5 text-base-content/30 inline-block" />
                                                @endif
                                            </td>
                                            <td class="text-right">
                                                <a href="{{ route('admin.blocks.index', ['blockTypeFilter' => $item['block_type']]) }}"
                                                   class="hover:underline">
                                                    @if($item['is_high_volume'])
                                                        <span class="badge badge-warning badge-sm">{{ number_format($item['block_count']) }}</span>
                                                    @else
                                                        {{ number_format($item['block_count']) }}
                                                    @endif
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 text-sm text-base-content/70 space-y-1">
                            <p><strong>Legend:</strong></p>
                            <ul class="list-disc list-inside space-y-1">
                                <li><span class="inline-block w-4 h-4 bg-success/10 border border-success/20 rounded mr-1"></span> Block type has custom layout</li>
                                <li><span class="inline-block w-4 h-4 bg-warning/10 border border-warning/20 rounded mr-1"></span> High-volume block type (>100 blocks) without custom layout</li>
                            </ul>
                            <p class="mt-2"><strong>Custom layouts location:</strong> <code class="text-xs">resources/views/blocks/types/{block_type}.blade.php</code></p>
                        </div>
                    @endif
                </x-slot:content>
            </x-collapse>
        @endforeach
    </div>
</div>
