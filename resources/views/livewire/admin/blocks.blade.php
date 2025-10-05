<?php

use App\Models\Block;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Illuminate\Support\Str;
use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component {
    use WithPagination, Toast;

    public string $search = '';
    public string $blockTypeFilter = '';
    public string $serviceFilter = '';
    public array $selectedBlocks = [];
    public bool $selectAll = false;
    public int $perPage = 25;

    protected $queryString = [
        'search' => ['except' => ''],
        'blockTypeFilter' => ['except' => ''],
        'serviceFilter' => ['except' => ''],
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

    public function toggleSelectAll(): void
    {
        if ($this->selectAll) {
            $this->selectedBlocks = $this->getBlocks()->pluck('id')->toArray();
        } else {
            $this->selectedBlocks = [];
        }
    }

    public function toggleBlockSelection(string $blockId): void
    {
        if (in_array($blockId, $this->selectedBlocks)) {
            $this->selectedBlocks = array_filter($this->selectedBlocks, fn($id) => $id !== $blockId);
        } else {
            $this->selectedBlocks[] = $blockId;
        }

        // Update select all checkbox state
        $this->selectAll = count($this->selectedBlocks) === $this->getBlocks()->count();
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
            $this->selectAll = false;
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

        return $query->orderBy('time', 'desc')->paginate($this->perPage);
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
        if (!$blockType) {
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
                        <x-icon name="o-trash" class="w-4 h-4 mr-1" />
                        Delete Selected ({{ count($selectedBlocks) }})
                    </button>
                @endif
            </div>
        </x-slot:actions>
    </x-header>

    <div class="flex flex-col gap-6">
        <!-- Search and Filters -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <div class="space-y-4">
                    <!-- Search -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Search</span>
                        </label>
                        <div class="join w-full">
                            <input
                                type="text"
                                class="input input-bordered join-item flex-1"
                                placeholder="Search by title, block type, or service..."
                                wire:model.live.debounce.300ms="search"
                            />
                            @if ($search)
                                <button class="btn btn-ghost join-item" wire:click="$set('search', '')">
                                    <x-icon name="o-x-mark" class="w-4 h-4" />
                                </button>
                            @endif
                        </div>
                    </div>

                    <!-- Filters Row -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- Block Type Filter -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Block Type</span>
                            </label>
                            <select class="select select-bordered" wire:model.live="blockTypeFilter">
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
                            <select class="select select-bordered" wire:model.live="serviceFilter">
                                <option value="">All Services</option>
                                @foreach ($this->getUniqueServices() as $service)
                                    <option value="{{ $service }}">{{ ucfirst($service) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Clear Filters -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">&nbsp;</span>
                            </label>
                            <button class="btn btn-outline" wire:click="clearFilters">
                                <x-icon name="o-x-mark" class="w-4 h-4 mr-2" />
                                Clear Filters
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Results -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                @if ($this->getBlocks()->isEmpty())
                    <div class="text-center py-12">
                        <x-icon name="o-cube-transparent" class="w-16 h-16 mx-auto text-base-300 mb-4" />
                        <h3 class="text-lg font-semibold mb-2">No blocks found</h3>
                        <p class="text-base-content/60">
                            @if ($search || $blockTypeFilter || $serviceFilter)
                                Try adjusting your filters to find blocks.
                            @else
                                Your blocks will appear here once they're created.
                            @endif
                        </p>
                    </div>
                @else
                    <!-- Results header -->
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-4">
                            <label class="label cursor-pointer">
                                <input
                                    type="checkbox"
                                    class="checkbox"
                                    wire:model="selectAll"
                                    wire:change="toggleSelectAll"
                                />
                                <span class="label-text ml-2">Select all</span>
                            </label>
                            <span class="text-sm text-base-content/60">
                                {{ $this->getBlocks()->total() }} total blocks
                            </span>
                        </div>
                        <div class="text-sm text-base-content/60">
                            Page {{ $this->getBlocks()->currentPage() }} of {{ $this->getBlocks()->lastPage() }}
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>ID</th>
                                    <th>Title</th>
                                    <th>Block Type</th>
                                    <th>Value</th>
                                    <th>Service</th>
                                    <th>Event ID</th>
                                    <th>Time</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->getBlocks() as $block)
                                    <tr class="hover">
                                        <td>
                                            <input
                                                type="checkbox"
                                                class="checkbox"
                                                value="{{ $block->id }}"
                                                wire:model="selectedBlocks"
                                                wire:change="toggleBlockSelection('{{ $block->id }}')"
                                            />
                                        </td>
                                        <td>
                                            <code class="text-xs">{{ $this->truncateId($block->id) }}</code>
                                        </td>
                                        <td>
                                            <div class="font-medium">{{ $block->title ?: 'N/A' }}</div>
                                        </td>
                                        <td>
                                            @if ($block->block_type)
                                                <span class="badge badge-outline">{{ $this->prettifyBlockType($block->block_type) }}</span>
                                            @else
                                                <span class="text-base-content/50">N/A</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="font-mono text-sm">
                                                {{ $this->formatValue($block->value, $block->value_multiplier, $block->value_unit) }}
                                            </span>
                                        </td>
                                        <td>
                                            @if ($block->event)
                                                <span class="badge badge-primary badge-outline">{{ $block->event->service }}</span>
                                            @else
                                                <span class="text-base-content/50">N/A</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($block->event)
                                                <a href="{{ route('events.show', $block->event) }}" class="link link-primary">
                                                    <code class="text-xs">{{ $this->truncateId($block->event->id) }}</code>
                                                </a>
                                            @else
                                                <span class="text-error">Missing Event</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="text-sm" title="{{ $block->time?->toDayDateTimeString() }}">
                                                {{ $block->time?->diffForHumans() ?: 'N/A' }}
                                            </span>
                                        </td>
                                        <td>
                                            <div class="flex items-center gap-1">
                                                <a href="{{ route('blocks.show', $block) }}" class="btn btn-ghost btn-xs">
                                                    <x-icon name="o-eye" class="w-3 h-3" />
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="mt-4">
                        {{ $this->getBlocks()->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>