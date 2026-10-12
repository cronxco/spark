<?php

use App\Models\Block;
use App\Models\Event;
use App\Support\AdminTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Livewire\Concerns\HasAdminListControls;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    use HasAdminListControls, Toast, WithPagination;

    public string $search = '';
    public string $blockTypeFilter = '';
    public string $serviceFilter = '';
    public array $selectedBlocks = [];
    public int $perPage = 25;
    public string $view = 'table';
    public array $sortBy = ['column' => 'time', 'direction' => 'desc'];

    protected $queryString = [
        'search' => ['except' => ''],
        'blockTypeFilter' => ['except' => '', 'as' => 'blockType'],
        'serviceFilter' => ['except' => '', 'as' => 'service'],
        'sortBy' => ['except' => ['column' => 'time', 'direction' => 'desc']],
        'perPage' => ['except' => 25],
        'view' => ['except' => 'table'],
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

    public function updatedBlockTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedServiceFilter(): void
    {
        $this->resetPage();
    }

    public function updatedView(): void
    {
        if (! in_array($this->view, ['table', 'gallery'], true)) {
            $this->view = 'table';
        }
        $this->selectedBlocks = [];
        $this->resetPage();
    }

    /**
     * Latest block of each type for the viewed tenant, rendered with the real block cards.
     */
    public function getGalleryBlocks()
    {
        $latestIds = Block::query()
            ->whereNotNull('block_type')
            ->whereHas('event.integration', fn ($q) => AdminTenant::scope($q))
            ->when($this->blockTypeFilter, fn ($q) => $q->where('block_type', $this->blockTypeFilter))
            ->when($this->serviceFilter, fn ($q) => $q->whereHas('event', fn ($e) => $e->where('service', $this->serviceFilter)))
            ->selectRaw('DISTINCT ON (blocks.block_type) blocks.id')
            ->orderBy('blocks.block_type')
            ->orderByDesc('blocks.created_at')
            ->pluck('id');

        return Block::with(['event.integration'])
            ->whereIn('id', $latestIds)
            ->orderBy('block_type')
            ->get();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'blockTypeFilter', 'serviceFilter']);
        $this->resetPage();
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => 'ID', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'title', 'label' => 'Title', 'sortable' => true],
            ['key' => 'block_type', 'label' => 'Block Type', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'value', 'label' => 'Value', 'sortable' => true],
            ['key' => 'service', 'label' => 'Service', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'event_id', 'label' => 'Event', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'time', 'label' => 'Time', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
        ];
    }

    public function bulkDelete(): void
    {
        if (empty($this->selectedBlocks)) {
            $this->error('No blocks selected for deletion.');

            return;
        }

        try {
            $count = DB::transaction(function () {
                AdminTenant::recordAction('blocks_bulk_delete', ['ids' => array_values($this->selectedBlocks)]);

                // $selectedBlocks is a public Livewire property; re-resolve it
                // through the same ownership predicate getBlocks() uses.
                return Block::whereHas('event.integration', function ($q) {
                    AdminTenant::scope($q);
                })
                    ->whereIn('id', $this->selectedBlocks)
                    ->delete();
            });

            $this->success("Successfully deleted {$count} block(s).");

            $this->selectedBlocks = [];
            $this->resetPage();
        } catch (Exception $e) {
            $this->error('Failed to delete blocks: ' . $e->getMessage());
        }
    }

    public function getBlocks()
    {
        $query = Block::with(['event.integration'])
            ->whereHas('event.integration', function ($q) {
                AdminTenant::scope($q);
            });

        // Apply search filter
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('title', 'ilike', '%' . $this->search . '%')
                    ->orWhere('block_type', 'ilike', '%' . $this->search . '%')
                    ->orWhereHas('event', function ($eventQuery) {
                        $eventQuery->where('service', 'ilike', '%' . $this->search . '%');
                    });
            });
        }

        // Apply block type filter
        if ($this->blockTypeFilter) {
            $query->where('block_type', $this->blockTypeFilter);
        }

        // Apply service filter (via event relationship)
        if ($this->serviceFilter) {
            $query->whereHas('event', function ($eventQuery) {
                $eventQuery->where('service', $this->serviceFilter);
            });
        }

        // Apply sorting
        [$sortColumn, $sortDirection] = $this->resolvedSort('time');
        if ($sortColumn === 'service') {
            $query->orderBy(
                Event::query()->select('service')->whereColumn('events.id', 'blocks.event_id')->limit(1),
                $sortDirection
            );
        } else {
            $query->orderBy($sortColumn, $sortDirection);
        }

        return $query->paginate($this->resolvedPerPage());
    }

    public function getUniqueBlockTypes()
    {
        return Block::whereHas('event.integration', function ($q) {
            AdminTenant::scope($q);
        })->distinct()->pluck('block_type')->filter()->sort()->values();
    }

    public function getUniqueServices()
    {
        return Block::whereHas('event.integration', function ($q) {
            AdminTenant::scope($q);
        })->join('events', 'events.id', '=', 'blocks.event_id')
            ->distinct()
            ->pluck('events.service')
            ->filter()
            ->sort()
            ->values();
    }

    public function truncateId(string $id): string
    {
        return Str::limit($id, 8, '');
    }

    public function formatValue(?int $value, ?int $multiplier, ?string $unit): string
    {
        if ($value === null) {
            return '-';
        }

        $formattedValue = $value;
        if ($multiplier && $multiplier !== 1 && $multiplier !== 0) {
            $formattedValue = $value / $multiplier;
        }

        $result = number_format($formattedValue, 2);
        if ($unit) {
            $result .= ' ' . $unit;
        }

        return $result;
    }

    public function prettifyBlockType(?string $blockType): string
    {
        if (! $blockType) {
            return 'N/A';
        }

        return Str::title(str_replace('_', ' ', $blockType));
    }
};

?>

<div>
    <x-header title="Data" separator>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <div class="join">
                    <button class="btn btn-sm join-item {{ $view === 'table' ? 'btn-active' : '' }}" wire:click="$set('view', 'table')">
                        <x-icon name="fas.table-list" class="w-4 h-4" />
                        Table
                    </button>
                    <button class="btn btn-sm join-item {{ $view === 'gallery' ? 'btn-active' : '' }}" wire:click="$set('view', 'gallery')">
                        <x-icon name="fas.table-cells-large" class="w-4 h-4" />
                        Gallery
                    </button>
                </div>
                @if ($view === 'table' && count($selectedBlocks) > 0)
                <button class="btn btn-error btn-sm" wire:click="bulkDelete"
                    wire:confirm="Are you sure you want to delete {{ count($selectedBlocks) }} block(s)? This action cannot be undone.">
                    <x-icon name="fas.trash" class="w-4 h-4 mr-1" />
                    Delete Selected ({{ count($selectedBlocks) }})
                </button>
                @endif
            </div>
        </x-slot:actions>
    </x-header>

    <x-admin.data-tabs active="blocks" />

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
                        class="input input-bordered w-full"
                        placeholder="Search blocks..."
                        wire:model.live.debounce.300ms="search" />
                </div>

                <!-- Block Type Filter -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Block Type</span>
                    </label>
                    <select class="select select-bordered w-full" wire:model.live="blockTypeFilter">
                        <option value="">All Block Types</option>
                        @foreach ($this->getUniqueBlockTypes() as $type)
                        <option value="{{ $type }}">{{ $this->prettifyBlockType($type) }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Service Filter -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Service</span>
                    </label>
                    <select class="select select-bordered w-full" wire:model.live="serviceFilter">
                        <option value="">All Services</option>
                        @foreach ($this->getUniqueServices() as $service)
                        <option value="{{ $service }}">{{ ucfirst($service) }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Clear Filters Button -->
                @if ($search || $blockTypeFilter || $serviceFilter)
                <div class="form-control content-end">
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
                    @if ($search || $blockTypeFilter || $serviceFilter)
                    <x-badge value="Active" class="badge-success badge-xs" />
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
                            class="input input-bordered w-full"
                            placeholder="Search blocks..."
                            wire:model.live.debounce.300ms="search" />
                    </div>

                    <!-- Block Type Filter -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Block Type</span>
                        </label>
                        <select class="select select-bordered w-full" wire:model.live="blockTypeFilter">
                            <option value="">All Block Types</option>
                            @foreach ($this->getUniqueBlockTypes() as $type)
                            <option value="{{ $type }}">{{ $this->prettifyBlockType($type) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Service Filter -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Service</span>
                        </label>
                        <select class="select select-bordered w-full" wire:model.live="serviceFilter">
                            <option value="">All Services</option>
                            @foreach ($this->getUniqueServices() as $service)
                            <option value="{{ $service }}">{{ ucfirst($service) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Clear Filters Button -->
                    @if ($search || $blockTypeFilter || $serviceFilter)
                    <button class="btn btn-outline" wire:click="clearFilters">
                        <x-icon name="fas.xmark" class="w-4 h-4" />
                        Clear Filters
                    </button>
                    @endif
                </div>
            </x-slot:content>
        </x-collapse>
    </div>

    @if ($view === 'gallery')
    @php($galleryBlocks = $this->getGalleryBlocks())
    @if ($galleryBlocks->isEmpty())
    <div class="alert alert-info">
        <x-icon name="fas.circle-info" class="w-5 h-5" />
        <span>No blocks found.</span>
    </div>
    @else
    <p class="text-sm text-base-content/70 mb-4">Latest block of each type ({{ $galleryBlocks->count() }})</p>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach ($galleryBlocks as $galleryBlock)
        <div wire:key="gallery-{{ $galleryBlock->id }}">
            <x-block-card :block="$galleryBlock" />
        </div>
        @endforeach
    </div>
    @endif
    @else
    <!-- Blocks Table -->
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <x-table
                :headers="$this->headers()"
                :rows="$this->getBlocks()"
                :sort-by="$sortBy"
                with-pagination
                per-page="perPage"
                :per-page-values="[10, 25, 50, 100]"
                selectable
                selectable-key="id"
                wire:model.live="selectedBlocks"
                class="[&_table]:!static [&_td]:!static">

                @scope('cell_id', $block)
                <x-block-ref :block="$block" :showType="false" :text="'<span class=\'font-mono text-xs\'>' . $this->truncateId($block->id) . '</span>'" />
                @endscope

                @scope('cell_title', $block)
                <div class="font-medium">{{ $block->title ?: 'N/A' }}</div>
                @endscope

                @scope('cell_block_type', $block)
                @if ($block->block_type)
                <span class="text-sm">{{ $this->prettifyBlockType($block->block_type) }}</span>
                @else
                <span class="text-base-content/50">N/A</span>
                @endif
                @endscope

                @scope('cell_value', $block)
                <span class="font-mono text-sm">
                    {{ $this->formatValue($block->value, $block->value_multiplier, $block->value_unit) }}
                </span>
                @endscope

                @scope('cell_service', $block)
                @if ($block->event)
                <span class="text-sm">{{ $block->event->service }}</span>
                @else
                <span class="text-base-content/50">N/A</span>
                @endif
                @endscope

                @scope('cell_event_id', $block)
                @if ($block->event)
                <x-event-ref :event="$block->event" :showService="false" />
                @else
                <span class="text-error">Missing Event</span>
                @endif
                @endscope

                @scope('cell_time', $block)
                <x-uk-date :date="$block->time" />
                @endscope

                <x-slot:empty>
                    <div class="text-center py-12">
                        <x-icon name="fas.grip" class="w-16 h-16 mx-auto mb-4 text-base-content/70" />
                        <p class="font-medium text-base-content mb-2">No blocks found</p>
                        @if ($search || $blockTypeFilter || $serviceFilter)
                        @endif
                    </div>
                </x-slot:empty>
            </x-table>
        </div>
    </div>
    @endif
</div>
