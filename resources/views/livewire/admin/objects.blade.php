<?php

use App\Models\EventObject;
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
    public string $conceptFilter = '';
    public string $typeFilter = '';
    public array $selectedObjects = [];
    public int $perPage = 25;
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    protected $queryString = [
        'search' => ['except' => ''],
        'conceptFilter' => ['except' => '', 'as' => 'concept'],
        'typeFilter' => ['except' => '', 'as' => 'type'],
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

    public function updatedConceptFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'conceptFilter', 'typeFilter']);
        $this->resetPage();
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => 'ID', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'title', 'label' => 'Title', 'sortable' => true],
            ['key' => 'concept', 'label' => 'Concept', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'type', 'label' => 'Type', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'events', 'label' => 'Events', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'created_at', 'label' => 'Time', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
        ];
    }

    public function bulkDelete(): void
    {
        if (empty($this->selectedObjects)) {
            $this->error('No objects selected for deletion.');

            return;
        }

        try {
            $count = DB::transaction(function () {
                AdminTenant::recordAction('objects_bulk_delete', ['ids' => array_values($this->selectedObjects)]);

                // Delete objects (soft delete)
                // $selectedObjects is a public Livewire property; re-resolve it
                // through the owning-user predicate before mutating.
                return AdminTenant::scope(EventObject::query())
                    ->whereIn('id', $this->selectedObjects)
                    ->delete();
            });

            $this->success("Successfully deleted {$count} object(s).");

            $this->selectedObjects = [];
            $this->resetPage();
        } catch (Exception $e) {
            $this->error('Failed to delete objects: ' . $e->getMessage());
        }
    }

    public function getObjects()
    {
        $query = AdminTenant::scope(EventObject::query())
            ->withCount(['actorEvents', 'targetEvents']);

        // Apply search filter
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('title', 'ilike', '%' . $this->search . '%')
                    ->orWhere('content', 'ilike', '%' . $this->search . '%')
                    ->orWhere('concept', 'ilike', '%' . $this->search . '%')
                    ->orWhere('type', 'ilike', '%' . $this->search . '%');
            });
        }

        // Apply concept filter
        if ($this->conceptFilter) {
            $query->where('concept', $this->conceptFilter);
        }

        // Apply type filter
        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        // Apply sorting
        [$sortColumn, $sortDirection] = $this->resolvedSort('created_at');
        $query->orderBy($sortColumn, $sortDirection);

        return $query->paginate($this->resolvedPerPage());
    }

    public function getUniqueConcepts()
    {
        return AdminTenant::scope(EventObject::query())
            ->distinct()
            ->pluck('concept')
            ->filter()
            ->sort()
            ->values();
    }

    public function getUniqueTypes()
    {
        return AdminTenant::scope(EventObject::query())
            ->distinct()
            ->pluck('type')
            ->filter()
            ->sort()
            ->values();
    }

    public function formatConcept(string $concept): string
    {
        return Str::title(str_replace('_', ' ', $concept));
    }

    public function formatType(string $type): string
    {
        return Str::title(str_replace('_', ' ', $type));
    }

    public function truncateId(string $id): string
    {
        return Str::limit($id, 8, '');
    }
};

?>

<div>
    <x-header title="Data" separator>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @if (count($selectedObjects) > 0)
                <button class="btn btn-error btn-sm" wire:click="bulkDelete"
                    wire:confirm="Are you sure you want to delete {{ count($selectedObjects) }} object(s)? This action cannot be undone.">
                    <x-icon name="fas.trash" class="w-4 h-4 mr-1" />
                    Delete Selected ({{ count($selectedObjects) }})
                </button>
                @endif
            </div>
        </x-slot:actions>
    </x-header>

    <x-admin.data-tabs active="objects" />

    <x-admin.filter-bar
        search-placeholder="Search objects..."
        :active="$search || $conceptFilter || $typeFilter"
        :filters="[
            ['model' => 'conceptFilter', 'label' => 'Concept', 'placeholder' => 'All concepts', 'options' => $this->getUniqueConcepts()->mapWithKeys(fn ($v) => [$v => $this->formatConcept($v)])],
            ['model' => 'typeFilter', 'label' => 'Type', 'placeholder' => 'All types', 'options' => $this->getUniqueTypes()->mapWithKeys(fn ($v) => [$v => $this->formatType($v)])],
        ]" />

    <!-- Objects Table -->
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <x-table
                :headers="$this->headers()"
                :rows="$this->getObjects()"
                :sort-by="$sortBy"
                with-pagination
                per-page="perPage"
                :per-page-values="[10, 25, 50, 100]"
                selectable
                selectable-key="id"
                wire:model.live="selectedObjects"
                class="[&_table]:!static [&_td]:!static">

                @scope('cell_id', $object)
                <x-object-ref :object="$object" :showType="false" :text="'<span class=\'font-mono text-xs\'>' . $this->truncateId($object->id) . '</span>'" />
                @endscope

                @scope('cell_title', $object)
                <div>
                    <x-uk-date :date="$object->time" class="sm:hidden" />
                    <div class="font-medium">{{ $object->title }}</div>
                    @if ($object->url)
                    <div class="text-sm text-base-content/70 text-clip">
                        <a href="{{ $object->url }}" target="_blank" class="link">
                            {{ get_domain_from_url($object->url) }}
                        </a>
                    </div>
                    @endif
                </div>
                @endscope

                @scope('cell_concept', $object)
                <span class="text-sm">{{ $this->formatConcept($object->concept) }}</span>
                @endscope

                @scope('cell_type', $object)
                <span class="text-sm">{{ $this->formatType($object->type) }}</span>
                @endscope

                @scope('cell_events', $object)
                <span class="text-sm text-base-content/70">{{ $object->actor_events_count + $object->target_events_count }}</span>
                @endscope

                @scope('cell_created_at', $object)
                <x-uk-date :date="$object->time" />
                @endscope

                <x-slot:empty>
                    <div class="text-center py-12">
                        <x-icon name="o-cube-transparent" class="w-16 h-16 mx-auto mb-4 text-base-content/70" />
                        <p class="font-medium text-base-content mb-2">No objects found</p>
                        @if ($search || $conceptFilter || $typeFilter)
                        @endif
                    </div>
                </x-slot:empty>
            </x-table>
        </div>
    </div>
</div>
