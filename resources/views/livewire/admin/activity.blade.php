<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Spatie\Activitylog\Models\Activity;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    use Toast, WithPagination;

    public string $search = '';
    public string $logNameFilter = '';
    public string $eventFilter = '';
    public string $subjectTypeFilter = '';
    public int $perPage = 25;
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    // Modal state
    public bool $showModal = false;
    public ?Activity $selectedActivity = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'logNameFilter' => ['except' => ''],
        'eventFilter' => ['except' => ''],
        'subjectTypeFilter' => ['except' => ''],
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

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => 'ID', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'log_name', 'label' => 'Log Name', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'event', 'label' => 'Event', 'sortable' => true],
            ['key' => 'subject_type', 'label' => 'Subject', 'sortable' => true],
            ['key' => 'changes', 'label' => 'Changes', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'causer', 'label' => 'Causer', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'created_at', 'label' => 'Time', 'sortable' => true],
        ];
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

        // Apply sorting
        $sortColumn = $this->sortBy['column'] ?? 'created_at';
        $sortDirection = $this->sortBy['direction'] ?? 'desc';
        $query->orderBy($sortColumn, $sortDirection);

        return $query->paginate($this->perPage);
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
        if (! $event) {
            return '-';
        }

        return Str::title(str_replace('_', ' ', $event));
    }

    public function prettifySubjectType(?string $subjectType): string
    {
        if (! $subjectType) {
            return '-';
        }

        return Str::afterLast($subjectType, '\\');
    }

    public function getSubjectTitle($activity): string
    {
        if (! $activity->subject) {
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
        if (! $activity->subject) {
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
        if (! $properties) {
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
        if (! $properties) {
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

    <div class="space-y-4 lg:space-y-6">
        <!-- Desktop Filters -->
        <div class="hidden lg:block card bg-base-200 shadow">
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
                            placeholder="Search activity..."
                            wire:model.live.debounce.300ms="search" />
                    </div>

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

                    <!-- Clear Filters Button -->
                    @if ($search || $logNameFilter || $eventFilter || $subjectTypeFilter)
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
        <div class="lg:hidden">
            <x-collapse separator class="bg-base-200">
                <x-slot:heading>
                    <div class="flex items-center gap-2">
                        <x-icon name="fas.filter" class="w-5 h-5" />
                        Filters
                        @if ($search || $logNameFilter || $eventFilter || $subjectTypeFilter)
                        <x-badge value="Active" class="badge-primary badge-xs" />
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
                                placeholder="Search activity..."
                                wire:model.live.debounce.300ms="search" />
                        </div>

                        <!-- Log Name Filter -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Log Name</span>
                            </label>
                            <select class="select select-bordered w-full" wire:model.live="logNameFilter">
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
                            <select class="select select-bordered w-full" wire:model.live="eventFilter">
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
                            <select class="select select-bordered w-full" wire:model.live="subjectTypeFilter">
                                <option value="">All Types</option>
                                @foreach ($this->getUniqueSubjectTypes() as $subjectType)
                                <option value="{{ $subjectType }}">{{ $this->prettifySubjectType($subjectType) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Clear Filters Button -->
                        @if ($search || $logNameFilter || $eventFilter || $subjectTypeFilter)
                        <button class="btn btn-outline" wire:click="clearFilters">
                            <x-icon name="fas.xmark" class="w-4 h-4" />
                            Clear Filters
                        </button>
                        @endif
                    </div>
                </x-slot:content>
            </x-collapse>
        </div>

        <!-- Activities Table -->
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <x-table
                    :headers="$this->headers()"
                    :rows="$this->getActivities()"
                    :sort-by="$sortBy"
                    with-pagination
                    per-page="perPage"
                    :per-page-values="[10, 25, 50, 100]"
                    striped
                    class="[&_table]:!static [&_td]:!static">
                    <x-slot:empty>
                        <div class="text-center py-12">
                            <x-icon name="fas.list" class="w-16 h-16 mx-auto mb-4 text-base-content/70" />
                            <h3 class="text-lg font-medium text-base-content mb-2">No activities found</h3>
                            <p class="text-base-content/70">
                                @if ($search || $logNameFilter || $eventFilter || $subjectTypeFilter)
                                Try adjusting your filters or search terms
                                @else
                                No activity has been logged yet
                                @endif
                            </p>
                        </div>
                    </x-slot:empty>

                    @scope('cell_id', $activity)
                    <button
                        wire:click="showActivityDetails('{{ $activity->id }}')"
                        class="font-mono text-sm btn-ghost">
                        {{ $this->truncateId($activity->id) }}
                    </button>
                    @endscope

                    @scope('cell_log_name', $activity)
                    <button
                        wire:click="showActivityDetails('{{ $activity->id }}')"
                        class="text-sm btn-ghost">
                        {{ $activity->log_name ?? 'default' }}
                    </button>
                    @endscope

                    @scope('cell_event', $activity)
                    <button
                        wire:click="showActivityDetails('{{ $activity->id }}')"
                        class="text-sm btn-ghost">
                        {{ $this->prettifyEvent($activity->event) }}
                    </button>
                    @endscope

                    @scope('cell_subject_type', $activity)
                    @if ($this->getSubjectUrl($activity))
                    <a href="{{ $this->getSubjectUrl($activity) }}" class="link font-medium">
                        {{ Str::limit($this->getSubjectTitle($activity), 30) }}
                    </a>
                    @else
                    <span class="text-base-content/70">{{ Str::limit($this->getSubjectTitle($activity), 30) }}</span>
                    @endif
                    <div class="text-xs text-base-content/70">{{ $this->prettifySubjectType($activity->subject_type) }}</div>
                    @endscope

                    @scope('cell_changes', $activity)
                    <span class="text-xs">{{ Str::limit($this->formatProperties($activity->properties), 40) }}</span>
                    @endscope

                    @scope('cell_causer', $activity)
                    @if ($activity->causer)
                    <span class="text-sm">{{ $activity->causer->name ?? $activity->causer->email ?? 'System' }}</span>
                    @else
                    <span class="text-base-content/70">System</span>
                    @endif
                    @endscope

                    @scope('cell_created_at', $activity)
                    <x-uk-date :date="$activity->created_at" />
                    @endscope
                </x-table>
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
                    <x-icon name="fas.xmark" class="w-4 h-4" />
                </button>
            </div>

            <div class="space-y-6">
                <!-- Basic Info -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="card bg-base-200">
                        <div class="card-body">
                            <h4 class="font-semibold text-sm text-base-content/70 uppercase tracking-wide">Basic Information</h4>
                            <div class="space-y-2 mt-2">
                                <div class="flex justify-between">
                                    <span class="text-sm font-medium">ID:</span>
                                    <span class="text-sm font-mono">{{ $selectedActivity->id }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-sm font-medium">Log Name:</span>
                                    <span class="text-sm">{{ $selectedActivity->log_name ?? 'default' }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-sm font-medium">Event:</span>
                                    <span class="text-sm">{{ $this->prettifyEvent($selectedActivity->event) }}</span>
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
                            <h4 class="font-semibold text-sm text-base-content/70 uppercase tracking-wide">Subject & Causer</h4>
                            <div class="space-y-2 mt-2">
                                <div>
                                    <span class="text-sm font-medium">Subject:</span>
                                    <div class="mt-1">
                                        @if ($this->getSubjectUrl($selectedActivity))
                                        <a href="{{ $this->getSubjectUrl($selectedActivity) }}" class="link font-medium" target="_blank">
                                            {{ $this->getSubjectTitle($selectedActivity) }}
                                        </a>
                                        @else
                                        <span class="text-base-content/70">{{ $this->getSubjectTitle($selectedActivity) }}</span>
                                        @endif
                                        <div class="text-xs text-base-content/70">{{ $this->prettifySubjectType($selectedActivity->subject_type) }}</div>
                                        @if ($selectedActivity->subject_id)
                                        <div class="text-xs text-base-content/70 font-mono">ID: {{ $selectedActivity->subject_id }}</div>
                                        @endif
                                    </div>
                                </div>
                                <div>
                                    <span class="text-sm font-medium">Caused by:</span>
                                    <div class="mt-1">
                                        @if ($selectedActivity->causer)
                                        <span class="text-sm">{{ $selectedActivity->causer->name ?? $selectedActivity->causer->email ?? 'Unknown User' }}</span>
                                        @if ($selectedActivity->causer_id)
                                        <div class="text-xs text-base-content/70 font-mono">ID: {{ $selectedActivity->causer_id }}</div>
                                        @endif
                                        @else
                                        <span class="text-base-content/70">System</span>
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
                        <h4 class="font-semibold text-sm text-base-content/70 uppercase tracking-wide flex items-center gap-2">
                            <x-icon name="fas.rotate" class="w-4 h-4" />
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
                        <h4 class="font-semibold text-sm text-base-content/70 uppercase tracking-wide flex items-center gap-2">
                            <x-icon name="fas.comment" class="w-4 h-4" />
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
                        <h4 class="font-semibold text-sm text-base-content/70 uppercase tracking-wide flex items-center gap-2">
                            <x-icon name="fas.file-lines" class="w-4 h-4" />
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