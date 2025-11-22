<?php

use App\Jobs\DeleteBinItemsBatch;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    use Toast, WithPagination;

    public string $search = '';
    public string $typeFilter = '';
    public array $selectedItems = [];
    public int $perPage = 25;
    public array $sortBy = ['column' => 'deleted_at', 'direction' => 'desc'];

    protected $queryString = [
        'search' => ['except' => ''],
        'typeFilter' => ['except' => ''],
        'sortBy' => ['except' => ['column' => 'deleted_at', 'direction' => 'desc']],
        'perPage' => ['except' => 25],
        'page' => ['except' => 1],
    ];

    public function mount(): void
    {
        // Initialize any required data
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'typeFilter']);
        $this->resetPage();
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => 'ID', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'type', 'label' => 'Type', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'title', 'label' => 'Title', 'sortable' => false],
            ['key' => 'subtitle', 'label' => 'Subtitle', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'deleted_at', 'label' => 'Deleted At', 'sortable' => true],
            ['key' => 'created_at', 'label' => 'Created At', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
        ];
    }

    public function bulkRestore(): void
    {
        if (empty($this->selectedItems)) {
            $this->error('No items selected for restoration.');

            return;
        }

        $restoredCount = 0;

        // Group items by type for proper restore handling
        $itemsByType = [];
        foreach ($this->selectedItems as $itemId) {
            $item = $this->findDeletedItem($itemId);
            if ($item) {
                $type = $this->getItemType($item);
                $itemsByType[$type][] = $item;
            }
        }

        // Restore in proper order (top-down)
        // 1. Integration Groups first
        if (isset($itemsByType['integration_group'])) {
            foreach ($itemsByType['integration_group'] as $group) {
                $group->restore();
                $restoredCount++;
            }
        }

        // 2. Integrations
        if (isset($itemsByType['integration'])) {
            foreach ($itemsByType['integration'] as $integration) {
                $integration->restore();
                $restoredCount++;
            }
        }

        // 3. Events
        if (isset($itemsByType['event'])) {
            foreach ($itemsByType['event'] as $event) {
                $event->restore();
                $restoredCount++;
            }
        }

        // 4. Blocks
        if (isset($itemsByType['block'])) {
            foreach ($itemsByType['block'] as $block) {
                $block->restore();
                $restoredCount++;
            }
        }

        // 5. Objects
        if (isset($itemsByType['object'])) {
            foreach ($itemsByType['object'] as $object) {
                $object->restore();
                $restoredCount++;
            }
        }

        $this->selectedItems = [];

        $this->success("Restored {$restoredCount} item(s).");
        $this->resetPage();
    }

    public function bulkDelete(): void
    {
        if (empty($this->selectedItems)) {
            $this->error('No items selected for deletion.');

            return;
        }

        $deletedCount = 0;

        // Group items by type for proper cascade handling
        $itemsByType = [];
        foreach ($this->selectedItems as $itemId) {
            $item = $this->findDeletedItem($itemId);
            if ($item) {
                $type = $this->getItemType($item);
                $itemsByType[$type][] = $item;
            }
        }

        // Delete in proper order to handle cascades (bottom-up)
        // 1. Blocks first (no dependencies)
        if (isset($itemsByType['block'])) {
            foreach ($itemsByType['block'] as $block) {
                $block->forceDelete();
                $deletedCount++;
            }
        }

        // 2. Events (after blocks are deleted)
        if (isset($itemsByType['event'])) {
            foreach ($itemsByType['event'] as $event) {
                // First delete all blocks for this event
                Block::where('event_id', $event->id)->forceDelete();
                $event->forceDelete();
                $deletedCount++;
            }
        }

        // 3. Integrations (after events are deleted)
        if (isset($itemsByType['integration'])) {
            foreach ($itemsByType['integration'] as $integration) {
                // First delete all events and their blocks for this integration
                $eventIds = Event::where('integration_id', $integration->id)->pluck('id');
                Block::whereIn('event_id', $eventIds)->forceDelete();
                Event::where('integration_id', $integration->id)->forceDelete();
                $integration->forceDelete();
                $deletedCount++;
            }
        }

        // 4. Integration Groups (after integrations are deleted)
        if (isset($itemsByType['integration_group'])) {
            foreach ($itemsByType['integration_group'] as $group) {
                // First delete all integrations, events, and blocks for this group
                $integrationIds = Integration::where('integration_group_id', $group->id)->pluck('id');
                $eventIds = Event::whereIn('integration_id', $integrationIds)->pluck('id');
                Block::whereIn('event_id', $eventIds)->forceDelete();
                Event::whereIn('integration_id', $integrationIds)->forceDelete();
                Integration::where('integration_group_id', $group->id)->forceDelete();
                $group->forceDelete();
                $deletedCount++;
            }
        }

        // 5. Objects (no dependencies, but check if still referenced)
        if (isset($itemsByType['object'])) {
            foreach ($itemsByType['object'] as $object) {
                // Check if object is still referenced by any events
                $isReferenced = Event::withTrashed()
                    ->where('actor_id', $object->id)
                    ->orWhere('target_id', $object->id)
                    ->exists();

                if (! $isReferenced) {
                    $object->forceDelete();
                    $deletedCount++;
                }
            }
        }

        $this->selectedItems = [];

        $this->success("Permanently deleted {$deletedCount} item(s).");
        $this->resetPage();
    }

    public function getDeletedItems()
    {
        // If filtering by specific type, use optimized single-model query
        if ($this->typeFilter) {
            return $this->getDeletedItemsByType($this->typeFilter);
        }

        // Get counts and items from each model efficiently
        $queries = $this->buildOptimizedQueries();
        $totalCounts = $this->getTotalCounts($queries);
        $totalItems = array_sum($totalCounts);

        if ($totalItems === 0) {
            return new Illuminate\Pagination\LengthAwarePaginator(
                collect([]),
                0,
                $this->perPage,
                $this->getPage(),
                ['path' => request()->url(), 'pageName' => 'page']
            );
        }

        // Calculate pagination
        $perPage = $this->perPage;
        $currentPage = $this->getPage();
        $offset = ($currentPage - 1) * $perPage;

        // Get items for current page using efficient chunked approach
        $items = $this->getItemsForPage($queries, $offset, $perPage);

        return new Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $totalItems,
            $perPage,
            $currentPage,
            [
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        );
    }

    public function getUniqueTypes()
    {
        return ['event', 'object', 'block', 'integration', 'integration_group'];
    }

    public function deleteAll(): void
    {
        // Dispatch the job to permanently delete all soft-deleted items
        DeleteBinItemsBatch::dispatch(Auth::id());

        $this->success('Deletion process started. All items will be permanently deleted.');
    }

    public function truncateId(string $id): string
    {
        return Str::limit($id, 8, '');
    }

    public function formatDate($date): string
    {
        return $date ? $date->format('M j, Y g:i A') : 'Never';
    }

    private function getItemType($item): string
    {
        if ($item instanceof Event) {
            return 'event';
        }
        if ($item instanceof EventObject) {
            return 'object';
        }
        if ($item instanceof Block) {
            return 'block';
        }
        if ($item instanceof Integration) {
            return 'integration';
        }
        if ($item instanceof IntegrationGroup) {
            return 'integration_group';
        }

        throw new InvalidArgumentException('Unknown item type');
    }

    private function findDeletedItem(string $itemId)
    {
        // Try to find the item in each model's trashed records
        $models = [
            'event' => Event::onlyTrashed(),
            'object' => EventObject::onlyTrashed(),
            'block' => Block::onlyTrashed(),
            'integration' => Integration::onlyTrashed(),
            'integration_group' => IntegrationGroup::onlyTrashed(),
        ];

        foreach ($models as $type => $query) {
            $item = $query->where('id', $itemId)->first();
            if ($item) {
                return $item;
            }
        }

        return null;
    }

    private function buildOptimizedQueries()
    {
        $userId = Auth::id();

        $queries = [];

        // Events query
        $eventsQuery = Event::onlyTrashed()
            ->whereHas('integration', fn($q) => $q->where('user_id', $userId))
            ->with(['target:id,title', 'integration:id,user_id'])
            ->orderBy('deleted_at', 'desc');

        if ($this->search) {
            $eventsQuery->where(function ($q) {
                $q->where('service', 'ilike', '%' . $this->search . '%')
                    ->orWhere('action', 'ilike', '%' . $this->search . '%')
                    ->orWhereHas('target', fn($tq) => $tq->where('title', 'ilike', '%' . $this->search . '%'));
            });
        }
        $queries['event'] = $eventsQuery;

        // Objects query
        $objectsQuery = EventObject::onlyTrashed()
            ->where('user_id', $userId)
            ->orderBy('deleted_at', 'desc');

        if ($this->search) {
            $objectsQuery->where(function ($q) {
                $q->where('title', 'ilike', '%' . $this->search . '%')
                    ->orWhere('concept', 'ilike', '%' . $this->search . '%')
                    ->orWhere('type', 'ilike', '%' . $this->search . '%');
            });
        }
        $queries['object'] = $objectsQuery;

        // Blocks query
        $blocksQuery = Block::onlyTrashed()
            ->whereHas('event.integration', fn($q) => $q->where('user_id', $userId))
            ->with(['event:id,integration_id'])
            ->orderBy('deleted_at', 'desc');

        if ($this->search) {
            $blocksQuery->where(function ($q) {
                $q->where('title', 'ilike', '%' . $this->search . '%')
                    ->orWhere('block_type', 'ilike', '%' . $this->search . '%');
            });
        }
        $queries['block'] = $blocksQuery;

        // Integrations query
        $integrationsQuery = Integration::onlyTrashed()
            ->where('user_id', $userId)
            ->orderBy('deleted_at', 'desc');

        if ($this->search) {
            $integrationsQuery->where(function ($q) {
                $q->where('name', 'ilike', '%' . $this->search . '%')
                    ->orWhere('service', 'ilike', '%' . $this->search . '%')
                    ->orWhere('instance_type', 'ilike', '%' . $this->search . '%');
            });
        }
        $queries['integration'] = $integrationsQuery;

        // Integration Groups query
        $integrationGroupsQuery = IntegrationGroup::onlyTrashed()
            ->where('user_id', $userId)
            ->orderBy('deleted_at', 'desc');

        if ($this->search) {
            $integrationGroupsQuery->where(function ($q) {
                $q->where('service', 'ilike', '%' . $this->search . '%')
                    ->orWhere('account_id', 'ilike', '%' . $this->search . '%');
            });
        }
        $queries['integration_group'] = $integrationGroupsQuery;

        return $queries;
    }

    private function getTotalCounts($queries)
    {
        $counts = [];
        foreach ($queries as $type => $query) {
            $counts[$type] = $query->count();
        }

        return $counts;
    }

    private function getItemsForPage($queries, $offset, $limit)
    {
        $items = collect();
        $remaining = $limit;
        $currentOffset = $offset;

        // Get items from each query in deleted_at desc order
        foreach ($queries as $type => $query) {
            if ($remaining <= 0) {
                break;
            }

            $count = $query->count();

            if ($currentOffset >= $count) {
                // Skip this entire query
                $currentOffset -= $count;

                continue;
            }

            // Get items from this query
            $queryItems = $query
                ->offset($currentOffset)
                ->limit($remaining)
                ->get()
                ->map(function ($item) use ($type) {
                    return $this->formatItemForDisplay($item, $type);
                });

            $items = $items->merge($queryItems);
            $remaining -= $queryItems->count();
            $currentOffset = 0; // Reset offset for subsequent queries
        }

        // Sort the final collection by deleted_at
        return $items->sortByDesc('deleted_at')->values();
    }

    private function getDeletedItemsByType($type)
    {
        $query = match ($type) {
            'event' => Event::onlyTrashed()
                ->whereHas('integration', fn($q) => $q->where('user_id', Auth::id()))
                ->with(['target:id,title', 'integration:id,user_id']),
            'object' => EventObject::onlyTrashed()->where('user_id', Auth::id()),
            'block' => Block::onlyTrashed()
                ->whereHas('event.integration', fn($q) => $q->where('user_id', Auth::id()))
                ->with(['event:id,integration_id']),
            'integration' => Integration::onlyTrashed()->where('user_id', Auth::id()),
            'integration_group' => IntegrationGroup::onlyTrashed()->where('user_id', Auth::id()),
            default => throw new InvalidArgumentException("Unknown type: {$type}")
        };

        // Apply search filter
        if ($this->search) {
            $query = $this->applySearchToQuery($query, $type);
        }

        $paginated = $query->orderBy('deleted_at', 'desc')
            ->paginate($this->perPage, ['*'], 'page', $this->getPage());

        // Transform the paginated results
        $paginated->getCollection()->transform(function ($item) use ($type) {
            return $this->formatItemForDisplay($item, $type);
        });

        return $paginated;
    }

    private function applySearchToQuery($query, $type)
    {
        return match ($type) {
            'event' => $query->where(function ($q) {
                $q->where('service', 'ilike', '%' . $this->search . '%')
                    ->orWhere('action', 'ilike', '%' . $this->search . '%')
                    ->orWhereHas('target', fn($tq) => $tq->where('title', 'ilike', '%' . $this->search . '%'));
            }),
            'object' => $query->where(function ($q) {
                $q->where('title', 'ilike', '%' . $this->search . '%')
                    ->orWhere('concept', 'ilike', '%' . $this->search . '%')
                    ->orWhere('type', 'ilike', '%' . $this->search . '%');
            }),
            'block' => $query->where(function ($q) {
                $q->where('title', 'ilike', '%' . $this->search . '%')
                    ->orWhere('block_type', 'ilike', '%' . $this->search . '%');
            }),
            'integration' => $query->where(function ($q) {
                $q->where('name', 'ilike', '%' . $this->search . '%')
                    ->orWhere('service', 'ilike', '%' . $this->search . '%')
                    ->orWhere('instance_type', 'ilike', '%' . $this->search . '%');
            }),
            'integration_group' => $query->where(function ($q) {
                $q->where('service', 'ilike', '%' . $this->search . '%')
                    ->orWhere('account_id', 'ilike', '%' . $this->search . '%');
            }),
        };
    }

    private function formatItemForDisplay($item, $type)
    {
        return (object) match ($type) {
            'event' => [
                'id' => $item->id,
                'type' => 'event',
                'type_label' => 'Event',
                'title' => $item->service . ' - ' . $item->action,
                'subtitle' => $item->target?->title ?? 'No target',
                'deleted_at' => $item->deleted_at,
                'created_at' => $item->created_at,
            ],
            'object' => [
                'id' => $item->id,
                'type' => 'object',
                'type_label' => 'Object',
                'title' => $item->title,
                'subtitle' => $item->concept . ' - ' . $item->type,
                'deleted_at' => $item->deleted_at,
                'created_at' => $item->created_at,
            ],
            'block' => [
                'id' => $item->id,
                'type' => 'block',
                'type_label' => 'Block',
                'title' => $item->title,
                'subtitle' => $item->block_type,
                'deleted_at' => $item->deleted_at,
                'created_at' => $item->created_at,
            ],
            'integration' => [
                'id' => $item->id,
                'type' => 'integration',
                'type_label' => 'Integration',
                'title' => $item->name,
                'subtitle' => $item->service . ' - ' . $item->instance_type,
                'deleted_at' => $item->deleted_at,
                'created_at' => $item->created_at,
            ],
            'integration_group' => [
                'id' => $item->id,
                'type' => 'integration_group',
                'type_label' => 'Integration Group',
                'title' => $item->service,
                'subtitle' => $item->account_id,
                'deleted_at' => $item->deleted_at,
                'created_at' => $item->created_at,
            ],
        };
    }
};

?>

<div>
    <x-header title="Bin" subtitle="Manage soft-deleted items" separator>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @if (count($selectedItems) > 0)
                <button class="btn btn-success btn-sm" wire:click="bulkRestore">
                    <x-icon name="o-arrow-uturn-left" class="w-4 h-4 mr-1" />
                    Restore Selected ({{ count($selectedItems) }})
                </button>
                <button class="btn btn-error btn-sm" wire:click="bulkDelete"
                    onclick="return confirm('Are you sure you want to permanently delete {{ count($selectedItems) }} item(s)? This action cannot be undone.')">
                    <x-icon name="fas.trash" class="w-4 h-4 mr-1" />
                    Delete Selected ({{ count($selectedItems) }})
                </button>
                @endif

                <!-- Delete All Button -->
                <button class="btn btn-error btn-sm" wire:click="deleteAll"
                    onclick="return confirm('Are you sure you want to permanently delete ALL items in the bin? This action cannot be undone.')">
                    <x-icon name="fas.fire" class="w-4 h-4 mr-1" />
                    Delete All
                </button>
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Desktop Filters -->
    <div class="hidden lg:block card bg-base-200 shadow mb-6">
        <div class="card-body">
            <div class="flex flex-row gap-4">
                <!-- Search -->
                <div class="form-control flex-1">
                    <label class="label">
                        <span class="label-text">Search</span>
                    </label>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search deleted items..."
                        class="input input-bordered w-full" />
                </div>

                <!-- Type Filter -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Type</span>
                    </label>
                    <select class="select select-bordered w-full" wire:model.live="typeFilter">
                        <option value="">All Types</option>
                        @foreach ($this->getUniqueTypes() as $type)
                        <option value="{{ $type }}">{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Clear Filters Button -->
                @if ($search || $typeFilter)
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">&nbsp;</span>
                    </label>
                    <button class="btn btn-outline" wire:click="clearFilters">
                        <x-icon name="fas.xmark" class="w-4 h-4" />
                        Clear
                    </button>
                </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Mobile Filters -->
    <div class="lg:hidden mb-4">
        <x-collapse separator class="bg-base-200">
            <x-slot:heading>
                <div class="flex items-center gap-2">
                    <x-icon name="fas.filter" class="w-5 h-5" />
                    Filters
                    @if ($search || $typeFilter)
                    <x-badge value="Active" class="badge-primary badge-xs" />
                    @endif
                </div>
            </x-slot:heading>
            <x-slot:content>
                <div class="flex flex-col gap-4">
                    <!-- Search -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Search</span>
                        </label>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Search deleted items..."
                            class="input input-bordered w-full" />
                    </div>

                    <!-- Type Filter -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Type</span>
                        </label>
                        <select class="select select-bordered w-full" wire:model.live="typeFilter">
                            <option value="">All Types</option>
                            @foreach ($this->getUniqueTypes() as $type)
                            <option value="{{ $type }}">{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Clear Filters Button -->
                    @if ($search || $typeFilter)
                    <button class="btn btn-outline" wire:click="clearFilters">
                        <x-icon name="fas.xmark" class="w-4 h-4" />
                        Clear Filters
                    </button>
                    @endif
                </div>
            </x-slot:content>
        </x-collapse>
    </div>

    <!-- Bin Items Table -->
    <div class="card bg-base-200 shadow card-xs sm:card-md">
        <div class="card-body">
            <x-table
                :headers="$this->headers()"
                :rows="$this->getDeletedItems()"
                :sort-by="$sortBy"
                with-pagination
                per-page="perPage"
                :per-page-values="[10, 25, 50, 100]"
                selectable
                selectable-key="id"
                wire:model.live="selectedItems"
                class="[&_table]:!static [&_td]:!static">

                @scope('cell_id', $item)
                <span class="font-mono text-xs hidden md:inline" title="{{ $item->id }}">
                    {{ $this->truncateId($item->id) }}
                </span>
                @endscope

                @scope('cell_type', $item)
                <span class="badge badge-outline">{{ $item->type_label }}</span>
                @endscope

                @scope('cell_title', $item)
                <span class="text-sm sm:hidden">{{ $item->type_label }}:</span>
                <br />
                {{ $item->title }}
                @endscope

                @scope('cell_subtitle', $item)
                {{ $item->subtitle }}
                @endscope

                @scope('cell_deleted_at', $item)
                <x-uk-date :date="$item->deleted_at" />
                @endscope

                @scope('cell_created_at', $item)
                <x-uk-date :date="$item->created_at" />
                @endscope

                @scope('actions', $item)
                <div class="flex gap-2 hidden sm:inline-flex">
                    <button
                        wire:click="bulkRestore"
                        class="btn btn-success btn-xs"
                        title="Restore">
                        <x-icon name="o-arrow-uturn-left" class="w-3 h-3" />
                    </button>
                    <button
                        wire:click="bulkDelete"
                        wire:confirm="Are you sure you want to permanently delete this item? This action cannot be undone."
                        class="btn btn-error btn-xs"
                        title="Delete Permanently">
                        <x-icon name="fas.trash" class="w-3 h-3" />
                    </button>
                </div>
                @endscope

                <x-slot:empty>
                    <div class="text-center py-12">
                        <x-icon name="fas.trash" class="w-16 h-16 mx-auto mb-4 text-base-content/70" />
                        <p class="font-medium text-base-content mb-2">No deleted items found</p>
                        @if ($search || $typeFilter)
                        <p class="text-sm text-base-content/70">Try adjusting your search or filters</p>
                        @endif
                    </div>
                </x-slot:empty>
            </x-table>
        </div>
    </div>
</div>