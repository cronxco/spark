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
    public bool $selectAll = false;
    public int $perPage = 25;

    protected $queryString = [
        'search' => ['except' => ''],
        'conceptFilter' => ['except' => ''],
        'typeFilter' => ['except' => ''],
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

    public function toggleSelectAll(): void
    {
        if ($this->selectAll) {
            $this->selectedObjects = $this->getObjects()->pluck('id')->toArray();
        } else {
            $this->selectedObjects = [];
        }
    }

    public function toggleObjectSelection(string $objectId): void
    {
        if (in_array($objectId, $this->selectedObjects)) {
            $this->selectedObjects = array_filter($this->selectedObjects, fn ($id) => $id !== $objectId);
        } else {
            $this->selectedObjects[] = $objectId;
        }

        // Update select all checkbox state
        $this->selectAll = count($this->selectedObjects) === $this->getObjects()->count();
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
            $this->selectAll = false;
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

        return $query->orderBy('time', 'desc')->paginate($this->perPage);
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
                        <x-icon name="o-trash" class="w-4 h-4 mr-1" />
                        Delete Selected ({{ count($selectedObjects) }})
                    </button>
                @endif
            </div>
        </x-slot:actions>
    </x-header>

    <div class="space-y-4 lg:space-y-6">
        <!-- Search and Filters -->
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Search -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Search</span>
                        </label>
                        <input
                            type="text"
                            class="input input-bordered w-full"
                            placeholder="Search objects..."
                            wire:model.live.debounce.300ms="search"
                        />
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
                </div>

                <!-- Clear Filters Button -->
                @if ($search || $conceptFilter || $typeFilter)
                    <div class="flex justify-end mt-4">
                        <button class="btn btn-outline btn-sm" wire:click="clearFilters">
                            <x-icon name="o-x-mark" class="w-4 h-4" />
                            Clear Filters
                        </button>
                    </div>
                @endif
            </div>
        </div>

        <!-- Objects Table -->
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>
                                    <x-checkbox
                                        wire:model.live="selectAll"
                                        wire:click="toggleSelectAll"
                                    />
                                </th>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Concept</th>
                                <th>Type</th>
                                <th>Events</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->getObjects() as $object)
                                <tr class="hover">
                                    <td>
                                        <x-checkbox
                                            wire:model.live="selectedObjects"
                                            value="{{ $object->id }}"
                                            wire:click="toggleObjectSelection('{{ $object->id }}')"
                                        />
                                    </td>
                                    <td>
                                        <a href="{{ route('objects.show', $object->id) }}" class="link font-mono text-xs" title="{{ $object->id }}">
                                            {{ $this->truncateId($object->id) }}
                                        </a>
                                    </td>
                                    <td>
                                        <div class="font-medium">{{ $object->title }}</div>
                                        @if ($object->url)
                                            <div class="text-sm text-base-content/70">
                                                <a href="{{ $object->url }}" target="_blank" class="link">
                                                    <x-icon name="o-link" class="w-3 h-3 inline mr-1" />
                                                    Link
                                                </a>
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="text-sm">{{ $this->formatConcept($object->concept) }}</span>
                                    </td>
                                    <td>
                                        <span class="text-sm">{{ $this->formatType($object->type) }}</span>
                                    </td>
                                    <td>
                                        <span class="text-sm text-base-content/70">{{ $object->actor_events_count + $object->target_events_count }}</span>
                                    </td>
                                    <td>
                                        <div class="text-sm">
                                            {{ $object->time->format('M j, Y') }}
                                        </div>
                                        <div class="text-xs text-base-content/70">
                                            {{ $object->time->format('g:i A') }}
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-12">
                                        <div>
                                            <x-icon name="o-document-text" class="w-16 h-16 mx-auto mb-4 text-base-content/70" />
                                            <p class="font-medium text-base-content mb-2">No objects found</p>
                                            @if ($search || $conceptFilter || $typeFilter)
                                                <p class="text-sm text-base-content/70">Try adjusting your search or filters</p>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                @if ($this->getObjects()->hasPages())
                    <div class="p-4 border-t">
                        {{ $this->getObjects()->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
