<?php

use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Illuminate\Support\Str;
use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component {
    use WithPagination, Toast;

    public string $search = '';
    public string $logNameFilter = '';
    public string $eventFilter = '';
    public string $subjectTypeFilter = '';
    public int $perPage = 25;

    protected $queryString = [
        'search' => ['except' => ''],
        'logNameFilter' => ['except' => ''],
        'eventFilter' => ['except' => ''],
        'subjectTypeFilter' => ['except' => ''],
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

    public function updatedLogNameFilter(): void
    {
        $this->resetPage();
    }

    public function updatedEventFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSubjectTypeFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'logNameFilter', 'eventFilter', 'subjectTypeFilter']);
        $this->resetPage();
    }

    public function getActivities()
    {
        $query = Activity::with(['subject', 'causer']);

        // Apply search filter
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('description', 'ilike', '%' . $this->search . '%')
                  ->orWhere('log_name', 'ilike', '%' . $this->search . '%')
                  ->orWhere('event', 'ilike', '%' . $this->search . '%')
                  ->orWhere('subject_type', 'ilike', '%' . $this->search . '%');
            });
        }

        // Apply log name filter
        if ($this->logNameFilter) {
            $query->where('log_name', $this->logNameFilter);
        }

        // Apply event filter
        if ($this->eventFilter) {
            $query->where('event', $this->eventFilter);
        }

        // Apply subject type filter
        if ($this->subjectTypeFilter) {
            $query->where('subject_type', $this->subjectTypeFilter);
        }

        return $query->orderBy('created_at', 'desc')->paginate($this->perPage);
    }

    public function getUniqueLogNames()
    {
        return Activity::distinct()->pluck('log_name')->filter()->sort()->values();
    }

    public function getUniqueEvents()
    {
        return Activity::distinct()->pluck('event')->filter()->sort()->values();
    }

    public function getUniqueSubjectTypes()
    {
        return Activity::distinct()->pluck('subject_type')->filter()->sort()->values();
    }

    public function truncateId(string $id): string
    {
        return Str::limit($id, 8, '');
    }

    public function prettifyEvent(?string $event): string
    {
        if (!$event) {
            return '-';
        }
        return Str::title(str_replace('_', ' ', $event));
    }

    public function prettifySubjectType(?string $subjectType): string
    {
        if (!$subjectType) {
            return '-';
        }
        return Str::afterLast($subjectType, '\\');
    }

    public function getSubjectTitle($activity): string
    {
        if (!$activity->subject) {
            return 'Deleted Object';
        }

        // Try to get a title from the subject based on its type
        if (method_exists($activity->subject, 'getTitleAttribute')) {
            return $activity->subject->getTitleAttribute();
        }

        if (isset($activity->subject->title)) {
            return $activity->subject->title;
        }

        if (isset($activity->subject->name)) {
            return $activity->subject->name;
        }

        return $this->prettifySubjectType($activity->subject_type);
    }

    public function getSubjectUrl($activity): ?string
    {
        if (!$activity->subject) {
            return null;
        }

        $subjectType = $this->prettifySubjectType($activity->subject_type);
        $subjectId = $activity->subject_id;

        switch ($subjectType) {
            case 'Event':
                return route('events.show', $subjectId);
            case 'EventObject':
                return route('objects.show', $subjectId);
            case 'Block':
                return route('blocks.show', $subjectId);
            default:
                return null;
        }
    }

    public function formatProperties($properties): string
    {
        if (!$properties) {
            return '-';
        }

        if (is_string($properties)) {
            $properties = json_decode($properties, true);
        }

        if (empty($properties)) {
            return '-';
        }

        // Show a summary of changed attributes
        $changes = [];
        if (isset($properties['attributes'])) {
            $changes[] = 'Updated: ' . implode(', ', array_keys($properties['attributes']));
        }
        if (isset($properties['old'])) {
            $changes[] = 'Changed from: ' . implode(', ', array_keys($properties['old']));
        }

        return implode(' | ', $changes) ?: 'No details';
    }
};

?>

<div>
    <x-header title="Activity Log" subtitle="View and monitor system activity" separator>
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
                                placeholder="Search by description, log name, event, or subject type..."
                                wire:model.live.debounce.300ms="search"
                            />
                            @if ($search)
                                <button class="btn btn-outline join-item" wire:click="clearFilters">
                                    <x-icon name="o-x-mark" class="w-4 h-4" />
                                </button>
                            @endif
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- Log Name Filter -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Log Name</span>
                            </label>
                            <select class="select select-bordered" wire:model.live="logNameFilter">
                                <option value="">All Log Names</option>
                                @foreach ($this->getUniqueLogNames() as $logName)
                                    <option value="{{ $logName }}">{{ $logName }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Event Filter -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Event</span>
                            </label>
                            <select class="select select-bordered" wire:model.live="eventFilter">
                                <option value="">All Events</option>
                                @foreach ($this->getUniqueEvents() as $event)
                                    <option value="{{ $event }}">{{ $this->prettifyEvent($event) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Subject Type Filter -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Subject Type</span>
                            </label>
                            <select class="select select-bordered" wire:model.live="subjectTypeFilter">
                                <option value="">All Types</option>
                                @foreach ($this->getUniqueSubjectTypes() as $subjectType)
                                    <option value="{{ $subjectType }}">{{ $this->prettifySubjectType($subjectType) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <!-- Clear Filters Button -->
                    @if ($logNameFilter || $eventFilter || $subjectTypeFilter)
                        <div class="flex justify-end">
                            <button class="btn btn-outline btn-sm" wire:click="clearFilters">
                                <x-icon name="o-x-mark" class="w-4 h-4 mr-1" />
                                Clear Filters
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Activities Table -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <div class="overflow-x-auto">
                    <table class="table table-zebra">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Log Name</th>
                                <th>Event</th>
                                <th>Description</th>
                                <th>Subject</th>
                                <th>Changes</th>
                                <th>Causer</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->getActivities() as $activity)
                                <tr>
                                    <td>
                                        <span class="font-mono text-xs" title="{{ $activity->id }}">
                                            {{ $this->truncateId($activity->id) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-outline">{{ $activity->log_name ?? 'default' }}</span>
                                    </td>
                                    <td>
                                        <span class="badge badge-neutral">{{ $this->prettifyEvent($activity->event) }}</span>
                                    </td>
                                    <td>
                                        <span class="text-sm">{{ Str::limit($activity->description, 50) }}</span>
                                    </td>
                                    <td>
                                        @if ($this->getSubjectUrl($activity))
                                            <a href="{{ $this->getSubjectUrl($activity) }}" class="link link-primary font-medium">
                                                {{ Str::limit($this->getSubjectTitle($activity), 30) }}
                                            </a>
                                        @else
                                            <span class="text-gray-500">{{ Str::limit($this->getSubjectTitle($activity), 30) }}</span>
                                        @endif
                                        <div class="text-xs text-gray-500">{{ $this->prettifySubjectType($activity->subject_type) }}</div>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ Str::limit($this->formatProperties($activity->properties), 40) }}</span>
                                    </td>
                                    <td>
                                        @if ($activity->causer)
                                            <span class="text-sm">{{ $activity->causer->name ?? $activity->causer->email ?? 'System' }}</span>
                                        @else
                                            <span class="text-gray-500">System</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="text-sm">{{ $activity->created_at->format('M j, Y g:i A') }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center py-8">
                                        <div class="text-gray-500">
                                            @if ($search || $logNameFilter || $eventFilter || $subjectTypeFilter)
                                                No activities found matching your criteria.
                                            @else
                                                No activities found.
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="mt-6">
                    {{ $this->getActivities()->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
