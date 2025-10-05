<?php

use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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

    // Modal state
    public bool $showModal = false;
    public ?Activity $selectedActivity = null;

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

        // Apply search filter - optimized for better index usage
        if ($this->search) {
            $searchTerm = strtolower(trim($this->search));
            $query->where(function ($q) use ($searchTerm) {
                // Use LOWER() functions to utilize the expression indexes
                $q->whereRaw('LOWER(description) LIKE ?', ['%' . $searchTerm . '%'])
                  ->orWhereRaw('LOWER(log_name) LIKE ?', ['%' . $searchTerm . '%'])
                  ->orWhereRaw('LOWER(event) LIKE ?', ['%' . $searchTerm . '%'])
                  ->orWhereRaw('LOWER(subject_type) LIKE ?', ['%' . $searchTerm . '%']);
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
        return Cache::remember('activity_log_unique_log_names', 3600, function () {
            return Activity::distinct()->pluck('log_name')->filter()->sort()->values()->toArray();
        });
    }

    public function getUniqueEvents()
    {
        return Cache::remember('activity_log_unique_events', 3600, function () {
            return Activity::distinct()->pluck('event')->filter()->sort()->values()->toArray();
        });
    }

    public function getUniqueSubjectTypes()
    {
        return Cache::remember('activity_log_unique_subject_types', 3600, function () {
            return Activity::distinct()->pluck('subject_type')->filter()->sort()->values()->toArray();
        });
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

    public function showActivityDetails(string $activityId): void
    {
        $this->selectedActivity = Activity::with(['subject', 'causer'])->find($activityId);
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->selectedActivity = null;
    }

    public function getFormattedProperties($properties): array
    {
        if (!$properties) {
            return [];
        }

        // If it's already an array, return it
        if (is_array($properties)) {
            return $properties;
        }

        // If it's a string, try to decode it
        if (is_string($properties)) {
            $decoded = json_decode($properties, true);
            return is_array($decoded) ? $decoded : [];
        }

        // If it's an object (like Laravel's cast attributes), convert to array
        if (is_object($properties)) {
            return json_decode(json_encode($properties), true) ?: [];
        }

        return [];
    }

    public function formatJson($data): string
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
                                        <button
                                            wire:click="showActivityDetails('{{ $activity->id }}')"
                                            class="font-mono text-xs link link-primary hover:link-accent"
                                            title="{{ $activity->id }} - Click to view details"
                                        >
                                            {{ $this->truncateId($activity->id) }}
                                        </button>
                                    </td>
                                    <td>
                                        <span class="badge badge-outline">{{ $activity->log_name ?? 'default' }}</span>
                                    </td>
                                    <td>
                                        <span class="badge badge-neutral">{{ $this->prettifyEvent($activity->event) }}</span>
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
                                    <td colspan="7" class="text-center py-8">
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

    <!-- Activity Details Modal -->
    @if ($showModal && $selectedActivity)
        <div class="modal modal-open">
            <div class="modal-box w-11/12 max-w-5xl">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-lg">Activity Details</h3>
                    <button class="btn btn-sm btn-circle" wire:click="closeModal">
                        <x-icon name="o-x-mark" class="w-4 h-4" />
                    </button>
                </div>

                <div class="space-y-6">
                    <!-- Basic Info -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="card bg-base-200">
                            <div class="card-body">
                                <h4 class="font-semibold text-sm text-gray-600 uppercase tracking-wide">Basic Information</h4>
                                <div class="space-y-2 mt-2">
                                    <div class="flex justify-between">
                                        <span class="text-sm font-medium">ID:</span>
                                        <span class="text-sm font-mono">{{ $selectedActivity->id }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-sm font-medium">Log Name:</span>
                                        <span class="badge badge-outline">{{ $selectedActivity->log_name ?? 'default' }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-sm font-medium">Event:</span>
                                        <span class="badge badge-neutral">{{ $this->prettifyEvent($selectedActivity->event) }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-sm font-medium">Created:</span>
                                        <span class="text-sm">{{ $selectedActivity->created_at->format('M j, Y g:i:s A') }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card bg-base-200">
                            <div class="card-body">
                                <h4 class="font-semibold text-sm text-gray-600 uppercase tracking-wide">Subject & Causer</h4>
                                <div class="space-y-2 mt-2">
                                    <div>
                                        <span class="text-sm font-medium">Subject:</span>
                                        <div class="mt-1">
                                            @if ($this->getSubjectUrl($selectedActivity))
                                                <a href="{{ $this->getSubjectUrl($selectedActivity) }}" class="link link-primary font-medium" target="_blank">
                                                    {{ $this->getSubjectTitle($selectedActivity) }}
                                                </a>
                                            @else
                                                <span class="text-gray-500">{{ $this->getSubjectTitle($selectedActivity) }}</span>
                                            @endif
                                            <div class="text-xs text-gray-500">{{ $this->prettifySubjectType($selectedActivity->subject_type) }}</div>
                                            @if ($selectedActivity->subject_id)
                                                <div class="text-xs text-gray-500 font-mono">ID: {{ $selectedActivity->subject_id }}</div>
                                            @endif
                                        </div>
                                    </div>
                                    <div>
                                        <span class="text-sm font-medium">Caused by:</span>
                                        <div class="mt-1">
                                            @if ($selectedActivity->causer)
                                                <span class="text-sm">{{ $selectedActivity->causer->name ?? $selectedActivity->causer->email ?? 'Unknown User' }}</span>
                                                @if ($selectedActivity->causer_id)
                                                    <div class="text-xs text-gray-500 font-mono">ID: {{ $selectedActivity->causer_id }}</div>
                                                @endif
                                            @else
                                                <span class="text-gray-500">System</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Changes -->
                    @if ($selectedActivity->properties)
                        @php
                            $properties = $this->getFormattedProperties($selectedActivity->properties);
                            $newData = $properties['attributes'] ?? [];
                            $oldData = $properties['old'] ?? [];
                            $comment = $properties['comment'] ?? null;
                        @endphp

                        @if (!empty($newData) || !empty($oldData))
                            <div class="card bg-base-200">
                                <div class="card-body">
                                    <h4 class="font-semibold text-sm text-gray-600 uppercase tracking-wide flex items-center gap-2">
                                        <x-icon name="o-arrow-path" class="w-4 h-4" />
                                        Changes
                                    </h4>
                                    <div class="mt-3">
                                        <x-change-details :new="$newData" :old="$oldData" />
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if ($comment)
                            <div class="card bg-base-200">
                                <div class="card-body">
                                    <h4 class="font-semibold text-sm text-gray-600 uppercase tracking-wide flex items-center gap-2">
                                        <x-icon name="o-chat-bubble-left" class="w-4 h-4" />
                                        Comment
                                    </h4>
                                    <div class="mt-2">
                                        <p class="text-sm whitespace-pre-wrap">{{ $comment }}</p>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if (!empty($properties) && (empty($newData) && empty($oldData) && !$comment))
                            <div class="card bg-base-200">
                                <div class="card-body">
                                    <h4 class="font-semibold text-sm text-gray-600 uppercase tracking-wide flex items-center gap-2">
                                        <x-icon name="o-document-text" class="w-4 h-4" />
                                        Raw Properties
                                    </h4>
                                    <div class="mt-2">
                                        <details class="bg-transparent p-0">
                                            <summary class="cursor-pointer list-none text-sm text-base-content/80 hover:text-base-content">View raw data</summary>
                                            <div class="mt-2">
                                                <pre class="text-xs bg-base-300 p-3 rounded overflow-x-auto">{{ $this->formatJson($properties) }}</pre>
                                            </div>
                                        </details>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endif
                </div>

                <div class="modal-action">
                    <button class="btn" wire:click="closeModal">Close</button>
                </div>
            </div>
        </div>
    @endif
</div>
