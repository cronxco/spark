<?php

use App\Models\EventObject;
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
    public string $conceptFilter = '';
    public string $typeFilter = '';
    public array $selectedObjects = [];
    public int $perPage = 25;
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    protected $queryString = [
        'search' => ['except' => ''],
        'conceptFilter' => ['except' => ''],
        'typeFilter' => ['except' => ''],
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
            DB::transaction(function () {
                // Delete objects (soft delete)
                EventObject::whereIn('id', $this->selectedObjects)->delete();
            });

            $count = count($this->selectedObjects);
            $this->success("Successfully deleted {$count} object(s).");

            $this->selectedObjects = [];
            $this->resetPage();
        } catch (\Exception $e) {
            $this->error('Failed to delete objects: ' . $e->getMessage());
        }
    }

    public function getObjects()
    {
        $query = EventObject::where('user_id', Auth::id())
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
        $sortColumn = $this->sortBy['column'] ?? 'created_at';
        $sortDirection = $this->sortBy['direction'] ?? 'desc';
        $query->orderBy($sortColumn, $sortDirection);

        return $query->paginate($this->perPage);
    }

    public function getUniqueConcepts()
    {
        return EventObject::where('user_id', Auth::id())
            ->distinct()
            ->pluck('concept')
            ->filter()
            ->sort()
            ->values();
    }

    public function getUniqueTypes()
    {
        return EventObject::where('user_id', Auth::id())
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
    <x-header title="Objects Admin" subtitle="Manage and monitor your objects data" separator>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @if (count($selectedObjects) > 0)
                <button class="btn btn-error btn-sm" wire:click="bulkDelete"
                    onclick="return confirm('Are you sure you want to delete {{ count($selectedObjects) }} object(s)? This action cannot be undone.')">
                    <x-icon name="fas.trash" class="w-4 h-4 mr-1" />
                    Delete Selected ({{ count($selectedObjects) }})
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
                        placeholder="Search objects..."
                        wire:model.live.debounce.300ms="search" />
                </div>

                <!-- Concept Filter -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Concept</span>
                    </label>
                    <select class="select select-bordered w-full" wire:model.live="conceptFilter">
                        <option value="">All Concepts</option>
                        @foreach ($this->getUniqueConcepts() as $concept)
                        <option value="{{ $concept }}">{{ $this->formatConcept($concept) }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Type Filter -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Type</span>
                    </label>
                    <select class="select select-bordered w-full" wire:model.live="typeFilter">
                        <option value="">All Types</option>
                        @foreach ($this->getUniqueTypes() as $type)
                        <option value="{{ $type }}">{{ $this->formatType($type) }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Clear Filters Button -->
                @if ($search || $conceptFilter || $typeFilter)
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
                    @if ($search || $conceptFilter || $typeFilter)
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
                            placeholder="Search objects..."
                            wire:model.live.debounce.300ms="search" />
                    </div>

                    <!-- Concept Filter -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Concept</span>
                        </label>
                        <select class="select select-bordered w-full" wire:model.live="conceptFilter">
                            <option value="">All Concepts</option>
                            @foreach ($this->getUniqueConcepts() as $concept)
                            <option value="{{ $concept }}">{{ $this->formatConcept($concept) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Type Filter -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Type</span>
                        </label>
                        <select class="select select-bordered w-full" wire:model.live="typeFilter">
                            <option value="">All Types</option>
                            @foreach ($this->getUniqueTypes() as $type)
                            <option value="{{ $type }}">{{ $this->formatType($type) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Clear Filters Button -->
                    @if ($search || $conceptFilter || $typeFilter)
                    <button class="btn btn-outline" wire:click="clearFilters">
                        <x-icon name="fas.xmark" class="w-4 h-4" />
                        Clear Filters
                    </button>
                    @endif
                </div>
            </x-slot:content>
        </x-collapse>
    </div>

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
                        <p class="text-sm text-base-content/70">Try adjusting your search or filters</p>
                        @endif
                    </div>
                </x-slot:empty>
            </x-table>
        </div>
    </div>
</div>