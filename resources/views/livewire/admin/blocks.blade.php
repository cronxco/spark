<?php

use App\Models\Block;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
    public string $blockTypeFilter = '';
    public string $serviceFilter = '';
    public array $selectedBlocks = [];
    public int $perPage = 25;
    public array $sortBy = ['column' => 'time', 'direction' => 'desc'];

    protected $queryString = [
        'search' => ['except' => ''],
        'blockTypeFilter' => ['except' => ''],
        'serviceFilter' => ['except' => ''],
        'sortBy' => ['except' => ['column' => 'time', 'direction' => 'desc']],
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

    public function updatedBlockTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedServiceFilter(): void
    {
        $this->resetPage();
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
            DB::transaction(function () {
                // Delete blocks
                Block::whereIn('id', $this->selectedBlocks)->delete();
            });

            $count = count($this->selectedBlocks);
            $this->success("Successfully deleted {$count} block(s).");

            $this->selectedBlocks = [];
            $this->resetPage();
        } catch (\Exception $e) {
            $this->error('Failed to delete blocks: ' . $e->getMessage());
        }
    }

    public function getBlocks()
    {
        $query = Block::with(['event.integration'])
            ->whereHas('event.integration', function ($q) {
                $q->where('user_id', Auth::id());
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
        $sortColumn = $this->sortBy['column'] ?? 'time';
        $sortDirection = $this->sortBy['direction'] ?? 'desc';
        $query->orderBy($sortColumn, $sortDirection);

        return $query->paginate($this->perPage);
    }

    public function getUniqueBlockTypes()
    {
        return Block::whereHas('event.integration', function ($q) {
            $q->where('user_id', Auth::id());
        })->distinct()->pluck('block_type')->filter()->sort()->values();
    }

    public function getUniqueServices()
    {
        return Block::whereHas('event.integration', function ($q) {
            $q->where('user_id', Auth::id());
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
    <x-header title="Blocks Admin" subtitle="Manage and monitor your block data" separator>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @if (count($selectedBlocks) > 0)
                <button class="btn btn-error btn-sm" wire:click="bulkDelete"
                    onclick="return confirm('Are you sure you want to delete {{ count($selectedBlocks) }} block(s)? This action cannot be undone.')">
                    <x-icon name="fas.trash" class="w-4 h-4 mr-1" />
                    Delete Selected ({{ count($selectedBlocks) }})
                </button>
                @endif
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

    <!-- Blocks Table -->
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <x-table
                :headers="$this->headers()"
                :rows="$this->getBlocks()"
                :sort-by="$sortBy"
                with-pagination
                per-page=" perPage"
                :per-page-values="[10, 25, 50, 100]"
                selectable
                selectable-key="id"
                wire:model.live="selectedBlocks"
                class="[&_table]:!static [&_td]:!static">

                @scope('cell_id', $block)
                <code class="text-xs hidden md:inline">{{ $this->truncateId($block->id) }}</code>
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
                        <p class="text-sm text-base-content/70">Try adjusting your search or filters</p>
                        @endif
                    </div>
                </x-slot:empty>
            </x-table>
        </div>
    </div>
</div>