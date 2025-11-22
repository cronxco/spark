<?php

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Relationship;
use App\Services\RelationshipTypeRegistry;
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
    public string $typeFilter = '';
    public string $fromTypeFilter = '';
    public string $toTypeFilter = '';
    public array $selectedRelationships = [];
    public int $perPage = 25;
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    protected $queryString = [
        'search' => ['except' => ''],
        'typeFilter' => ['except' => ''],
        'fromTypeFilter' => ['except' => ''],
        'toTypeFilter' => ['except' => ''],
        'sortBy' => ['except' => ['column' => 'created_at', 'direction' => 'desc']],
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

    public function updatedFromTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedToTypeFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'typeFilter', 'fromTypeFilter', 'toTypeFilter']);
        $this->resetPage();
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => 'ID', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'from', 'label' => 'From', 'sortable' => false],
            ['key' => 'type', 'label' => 'Type', 'sortable' => true],
            ['key' => 'to', 'label' => 'To', 'sortable' => false],
            ['key' => 'value', 'label' => 'Value', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'created_at', 'label' => 'Created', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
        ];
    }

    public function bulkDelete(): void
    {
        if (empty($this->selectedRelationships)) {
            $this->error('No relationships selected for deletion.');

            return;
        }

        try {
            DB::transaction(function () {
                Relationship::whereIn('id', $this->selectedRelationships)->delete();
            });

            $count = count($this->selectedRelationships);
            $this->success("Successfully deleted {$count} relationship(s).");

            $this->selectedRelationships = [];
            $this->resetPage();
        } catch (\Exception $e) {
            $this->error('Failed to delete relationships: ' . $e->getMessage());
        }
    }

    public function getRelationships()
    {
        $query = Relationship::with(['from', 'to'])
            ->where('user_id', Auth::id());

        // Apply search filter
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('type', 'ilike', '%' . $this->search . '%')
                    ->orWhere('metadata', 'ilike', '%' . $this->search . '%')
                    ->orWhereHasMorph('from', [Event::class, EventObject::class, Block::class], function ($morphQuery) {
                        $morphQuery->where(function ($titleQuery) {
                            // For Event and EventObject which have title/action
                            if ($titleQuery->getModel() instanceof Event) {
                                $titleQuery->where('action', 'ilike', '%' . $this->search . '%');
                            } else {
                                $titleQuery->where('title', 'ilike', '%' . $this->search . '%');
                            }
                        });
                    })
                    ->orWhereHasMorph('to', [Event::class, EventObject::class, Block::class], function ($morphQuery) {
                        $morphQuery->where(function ($titleQuery) {
                            if ($titleQuery->getModel() instanceof Event) {
                                $titleQuery->where('action', 'ilike', '%' . $this->search . '%');
                            } else {
                                $titleQuery->where('title', 'ilike', '%' . $this->search . '%');
                            }
                        });
                    });
            });
        }

        // Apply type filter
        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        // Apply from_type filter
        if ($this->fromTypeFilter) {
            $query->where('from_type', $this->fromTypeFilter);
        }

        // Apply to_type filter
        if ($this->toTypeFilter) {
            $query->where('to_type', $this->toTypeFilter);
        }

        // Apply sorting
        $sortColumn = $this->sortBy['column'] ?? 'created_at';
        $sortDirection = $this->sortBy['direction'] ?? 'desc';
        $query->orderBy($sortColumn, $sortDirection);

        return $query->paginate($this->perPage);
    }

    public function getUniqueTypes()
    {
        return collect(RelationshipTypeRegistry::getTypes())
            ->sortBy('display_name')
            ->map(fn($config, $key) => [
                'key' => $key,
                'display_name' => $config['display_name'],
            ]);
    }

    public function getUniqueFromTypes()
    {
        return Relationship::where('user_id', Auth::id())
            ->distinct()
            ->pluck('from_type')
            ->filter()
            ->map(fn($type) => $this->formatModelType($type))
            ->sort()
            ->values();
    }

    public function getUniqueToTypes()
    {
        return Relationship::where('user_id', Auth::id())
            ->distinct()
            ->pluck('to_type')
            ->filter()
            ->map(fn($type) => $this->formatModelType($type))
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

    public function formatModelType(string $fullType): string
    {
        // Convert App\Models\EventObject to EventObject
        $parts = explode('\\', $fullType);

        return end($parts);
    }

    public function getModelTypeFromFormatted(string $formatted): string
    {
        // Convert back to full class name
        $mapping = [
            'Event' => Event::class,
            'EventObject' => EventObject::class,
            'Block' => Block::class,
        ];

        return $mapping[$formatted] ?? $formatted;
    }

    public function formatEntityTitle($relationship, string $direction): string
    {
        $entity = $direction === 'from' ? $relationship->from : $relationship->to;

        if (! $entity) {
            return '-';
        }

        $type = $this->formatModelType($direction === 'from' ? $relationship->from_type : $relationship->to_type);

        // Get title based on model type
        if ($entity instanceof Event) {
            $title = Str::title(str_replace('_', ' ', $entity->action));
        } elseif ($entity instanceof EventObject) {
            $title = $entity->title;
        } elseif ($entity instanceof Block) {
            $title = $entity->title ?? $entity->block_type;
        } else {
            $title = 'Unknown';
        }

        return Str::limit($title, 30);
    }


    public function getTypeDisplayName(string $type): string
    {
        return RelationshipTypeRegistry::getDisplayName($type) ?? Str::title(str_replace('_', ' ', $type));
    }

    public function isDirectional(string $type): bool
    {
        return RelationshipTypeRegistry::isDirectional($type);
    }
};

?>

<div>
    <x-header title="Relationships Admin" subtitle="Manage connections between events, objects, and blocks" separator>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @if (count($selectedRelationships) > 0)
                <button class="btn btn-error btn-sm" wire:click="bulkDelete"
                    onclick="return confirm('Are you sure you want to delete {{ count($selectedRelationships) }} relationship(s)? This action cannot be undone.')">
                    <x-icon name="fas-trash" class="w-4 h-4 mr-1" />
                    Delete Selected ({{ count($selectedRelationships) }})
                </button>
                @endif
            </div>
        </x-slot:actions>
    </x-header>

    <div class="space-y-4 lg:space-y-6">
        <!-- Desktop Filters -->
        <div class="hidden lg:block card bg-base-200 shadow">
            <div class="card-body">
                <div class="flex flex-row gap-4">
                    <div class="form-control flex-1">
                        <label class="label"><span class="label-text">Search</span></label>
                        <input type="text" class="input input-bordered w-full" placeholder="Search relationships..." wire:model.live.debounce.300ms="search" />
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Type</span></label>
                        <select class="select select-bordered" wire:model.live="typeFilter">
                            <option value="">All Types</option>
                            @foreach ($this->getUniqueTypes() as $type)
                            <option value="{{ $type['key'] }}">{{ $type['display_name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">From Type</span></label>
                        <select class="select select-bordered" wire:model.live="fromTypeFilter">
                            <option value="">All Types</option>
                            @foreach ($this->getUniqueFromTypes() as $fromType)
                            <option value="{{ $this->getModelTypeFromFormatted($fromType) }}">{{ $fromType }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">To Type</span></label>
                        <select class="select select-bordered" wire:model.live="toTypeFilter">
                            <option value="">All Types</option>
                            @foreach ($this->getUniqueToTypes() as $toType)
                            <option value="{{ $this->getModelTypeFromFormatted($toType) }}">{{ $toType }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($search || $typeFilter || $fromTypeFilter || $toTypeFilter)
                    <div class="form-control content-end">
                        <label class="label"><span class="label-text">&nbsp;</span></label>
                        <button class="btn btn-outline" wire:click="clearFilters">
                            <x-icon name="fas-xmark" class="w-4 h-4" />
                            Clear
                        </button>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Mobile Filters -->
        <div class="lg:hidden">
            <x-collapse separator class="bg-base-200">
                <x-slot:heading>
                    <div class="flex items-center gap-2">
                        <x-icon name="fas-filter" class="w-5 h-5" />
                        Filters
                        @if ($search || $typeFilter || $fromTypeFilter || $toTypeFilter)
                        <x-badge value="Active" class="badge-primary badge-xs" />
                        @endif
                    </div>
                </x-slot:heading>
                <x-slot:content>
                    <div class="flex flex-col gap-4">
                        <div class="form-control">
                            <label class="label"><span class="label-text">Search</span></label>
                            <input type="text" class="input input-bordered w-full" placeholder="Search relationships..." wire:model.live.debounce.300ms="search" />
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Type</span></label>
                            <select class="select select-bordered w-full" wire:model.live="typeFilter">
                                <option value="">All Types</option>
                                @foreach ($this->getUniqueTypes() as $type)
                                <option value="{{ $type['key'] }}">{{ $type['display_name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">From Type</span></label>
                            <select class="select select-bordered w-full" wire:model.live="fromTypeFilter">
                                <option value="">All Types</option>
                                @foreach ($this->getUniqueFromTypes() as $fromType)
                                <option value="{{ $this->getModelTypeFromFormatted($fromType) }}">{{ $fromType }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">To Type</span></label>
                            <select class="select select-bordered w-full" wire:model.live="toTypeFilter">
                                <option value="">All Types</option>
                                @foreach ($this->getUniqueToTypes() as $toType)
                                <option value="{{ $this->getModelTypeFromFormatted($toType) }}">{{ $toType }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if ($search || $typeFilter || $fromTypeFilter || $toTypeFilter)
                        <button class="btn btn-outline" wire:click="clearFilters">
                            <x-icon name="fas-xmark" class="w-4 h-4" />
                            Clear Filters
                        </button>
                        @endif
                    </div>
                </x-slot:content>
            </x-collapse>
        </div>

        <!-- Relationships Table -->
        <div class="card bg-base-200 shadow card-xs sm:card-md">
            <div class="card-body">
                <x-table
                    :headers="$this->headers()"
                    :rows="$this->getRelationships()"
                    :sort-by="$sortBy"
                    with-pagination
                    per-page="perPage"
                    :per-page-values="[10, 25, 50, 100]"
                    selectable
                    selectable-key="id"
                    wire:model.live="selectedRelationships"
                    striped
                    class="[&_table]:!static [&_td]:!static">
                    <x-slot:empty>
                        <div class="text-center py-12">
                            <x-icon name="fas-link" class="w-16 h-16 mx-auto mb-4 text-base-content/70" />
                            <h3 class="text-lg font-medium text-base-content mb-2">No relationships found</h3>
                            <p class="text-base-content/70">
                                @if ($search || $typeFilter || $fromTypeFilter || $toTypeFilter)
                                Try adjusting your filters or search terms
                                @else
                                No relationships have been created yet
                                @endif
                            </p>
                        </div>
                    </x-slot:empty>

                    @scope('cell_id', $relationship)
                    <span class="text-sm font-mono">{{ $this->truncateId($relationship->id) }}</span>
                    @endscope

                    @scope('cell_from', $relationship)
                    <div class="flex items-center gap-2">
                        <div class="flex flex-col">
                            <span class="text-sm">{{ $this->formatEntityTitle($relationship, 'from') }}</span>
                            <span class="text-xs text-base-content/70 sm:hidden">{{ $this->formatModelType($relationship->from_type) }}</span>
                        </div>
                    </div>
                    @endscope

                    @scope('cell_type', $relationship)
                    <div class="flex items-center gap-2">
                        <div class="flex flex-col">
                            <span class="text-sm">{{ $this->getTypeDisplayName($relationship->type) }}
                                @if ($this->isDirectional($relationship->type))
                                →
                                @else
                                ↔
                                @endif
                            </span>
                        </div>
                    </div>
                    @endscope

                    @scope('cell_to', $relationship)
                    <div class="flex items-center gap-2">
                        <div class="flex flex-col">
                            <span class="text-sm">{{ $this->formatEntityTitle($relationship, 'to') }}</span>
                            <span class="text-xs text-base-content/70 sm:hidden">{{ $this->formatModelType($relationship->to_type) }}</span>
                        </div>
                    </div>
                    @endscope

                    @scope('cell_value', $relationship)
                    <span class="text-sm">{{ $this->formatValue($relationship->value, $relationship->value_multiplier, $relationship->value_unit) }}</span>
                    @endscope

                    @scope('cell_created_at', $relationship)
                    <x-uk-date :date="$relationship->created_at" />
                    @endscope
                </x-table>
            </div>
        </div>
    </div>
</div>