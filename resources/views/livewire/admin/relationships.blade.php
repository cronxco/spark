<?php

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Relationship;
use App\Services\RelationshipTypeRegistry;
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
            $count = DB::transaction(function () {
                AdminTenant::recordAction('relationships_bulk_delete', ['ids' => array_values($this->selectedRelationships)]);

                // $selectedRelationships is a public Livewire property; re-resolve
                // it through the same ownership predicate the listing uses.
                return AdminTenant::scope(Relationship::query())
                    ->whereIn('id', $this->selectedRelationships)
                    ->delete();
            });

            $this->success("Successfully deleted {$count} relationship(s).");

            $this->selectedRelationships = [];
            $this->resetPage();
        } catch (Exception $e) {
            $this->error('Failed to delete relationships: ' . $e->getMessage());
        }
    }

    public function getRelationships()
    {
        $query = Relationship::with(['from', 'to'])
            ->tap(AdminTenant::scoped());

        // Apply search filter
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('type', 'ilike', '%' . $this->search . '%')
                    ->orWhereRaw('metadata::text ilike ?', ['%' . $this->search . '%'])
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
        [$sortColumn, $sortDirection] = $this->resolvedSort('created_at');
        $query->orderBy($sortColumn, $sortDirection);

        return $query->paginate($this->resolvedPerPage());
    }

    public function getUniqueTypes()
    {
        return collect(RelationshipTypeRegistry::getTypes())
            ->sortBy('display_name')
            ->map(fn ($config, $key) => [
                'key' => $key,
                'display_name' => $config['display_name'],
            ]);
    }

    public function getUniqueFromTypes()
    {
        return AdminTenant::scope(Relationship::query())
            ->distinct()
            ->pluck('from_type')
            ->filter()
            ->map(fn ($type) => $this->formatModelType($type))
            ->sort()
            ->values();
    }

    public function getUniqueToTypes()
    {
        return AdminTenant::scope(Relationship::query())
            ->distinct()
            ->pluck('to_type')
            ->filter()
            ->map(fn ($type) => $this->formatModelType($type))
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
    <x-header title="Data" separator>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @if (count($selectedRelationships) > 0)
                <button class="btn btn-error btn-sm" wire:click="bulkDelete"
                    wire:confirm="Are you sure you want to delete {{ count($selectedRelationships) }} relationship(s)? This action cannot be undone.">
                    <x-icon name="fas.trash" class="w-4 h-4 mr-1" />
                    Delete Selected ({{ count($selectedRelationships) }})
                </button>
                @endif
            </div>
        </x-slot:actions>
    </x-header>

    <x-admin.data-tabs active="relationships" />

    <div class="space-y-4 lg:space-y-6">
        <x-admin.filter-bar
            search-placeholder="Search relationships..."
            :active="$search || $typeFilter || $fromTypeFilter || $toTypeFilter"
            :filters="[
                ['model' => 'typeFilter', 'label' => 'Type', 'placeholder' => 'All types', 'options' => $this->getUniqueTypes()->mapWithKeys(fn ($v) => [$v['key'] => $v['display_name']])],
                ['model' => 'fromTypeFilter', 'label' => 'From', 'placeholder' => 'Any source', 'options' => $this->getUniqueFromTypes()->mapWithKeys(fn ($v) => [$this->getModelTypeFromFormatted($v) => $v])],
                ['model' => 'toTypeFilter', 'label' => 'To', 'placeholder' => 'Any target', 'options' => $this->getUniqueToTypes()->mapWithKeys(fn ($v) => [$this->getModelTypeFromFormatted($v) => $v])],
            ]" />

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
                            <x-icon name="fas.link" class="w-16 h-16 mx-auto mb-4 text-base-content/70" />
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
                        @if ($relationship->from instanceof App\Models\Event)
                            <x-event-ref :event="$relationship->from" :showService="false" />
                        @elseif ($relationship->from instanceof App\Models\EventObject)
                            <x-object-ref :object="$relationship->from" :showType="false" />
                        @elseif ($relationship->from instanceof App\Models\Block)
                            <x-block-ref :block="$relationship->from" :showType="false" />
                        @else
                            <span class="text-sm">{{ $this->formatEntityTitle($relationship, 'from') }}</span>
                        @endif
                        <span class="text-xs text-base-content/70 sm:hidden">{{ $this->formatModelType($relationship->from_type) }}</span>
                    </div>
                    @endscope

                    @scope('cell_type', $relationship)
                    <div class="flex items-center gap-2">
                        <div class="flex flex-col">
                            <span class="text-sm">{{ $this->getTypeDisplayName($relationship->type) }}
                                @if ($this->isDirectional($relationship->type))
                                <x-icon name="fas.arrow-right" class="w-3 h-3 inline" />
                                @else
                                <x-icon name="fas.arrows-left-right" class="w-3 h-3 inline" />
                                @endif
                            </span>
                        </div>
                    </div>
                    @endscope

                    @scope('cell_to', $relationship)
                    <div class="flex items-center gap-2">
                        @if ($relationship->to instanceof App\Models\Event)
                            <x-event-ref :event="$relationship->to" :showService="false" />
                        @elseif ($relationship->to instanceof App\Models\EventObject)
                            <x-object-ref :object="$relationship->to" :showType="false" />
                        @elseif ($relationship->to instanceof App\Models\Block)
                            <x-block-ref :block="$relationship->to" :showType="false" />
                        @else
                            <span class="text-sm">{{ $this->formatEntityTitle($relationship, 'to') }}</span>
                        @endif
                        <span class="text-xs text-base-content/70 sm:hidden">{{ $this->formatModelType($relationship->to_type) }}</span>
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
