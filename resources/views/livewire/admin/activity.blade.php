<?php

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Place;
use App\Models\Relationship;
use App\Models\User;
use App\Support\AdminTenant;
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

    /**
     * Activity rows belonging to the tenant the admin pages are scoped to:
     * things the tenant did, or changes to records the tenant owns.
     */
    private function tenantActivities()
    {
        $tenantId = AdminTenant::id();

        if ($tenantId === null) {
            return Activity::query();
        }

        return Activity::query()->where(function ($q) use ($tenantId) {
            $q->where(fn ($c) => $c->where('causer_type', User::class)->where('causer_id', $tenantId))
                ->orWhere(fn ($c) => $c->where('subject_type', User::class)->where('subject_id', $tenantId))
                ->orWhere(fn ($c) => $c->where('subject_type', Event::class)
                    ->whereIn('subject_id', Event::withTrashed()->forUser($tenantId)->select('events.id')))
                ->orWhere(fn ($c) => $c->where('subject_type', Block::class)
                    ->whereIn('subject_id', Block::withTrashed()->whereHas('event.integration', fn ($i) => $i->where('user_id', $tenantId))->select('blocks.id')))
                ->orWhere(fn ($c) => $c->where('subject_type', EventObject::class)
                    ->whereIn('subject_id', EventObject::withTrashed()->where('user_id', $tenantId)->select('id')))
                ->orWhere(fn ($c) => $c->where('subject_type', Integration::class)
                    ->whereIn('subject_id', Integration::withTrashed()->where('user_id', $tenantId)->select('id')))
                ->orWhere(fn ($c) => $c->where('subject_type', IntegrationGroup::class)
                    ->whereIn('subject_id', IntegrationGroup::withTrashed()->where('user_id', $tenantId)->select('id')))
                ->orWhere(fn ($c) => $c->where('subject_type', Relationship::class)
                    ->whereIn('subject_id', Relationship::where('user_id', $tenantId)->select('id')))
                ->orWhere(fn ($c) => $c->where('subject_type', Place::class)
                    ->whereIn('subject_id', Place::where('user_id', $tenantId)->select('id')));
        });
    }

    public function getActivities()
    {
        $query = $this->tenantActivities()->with(['subject', 'causer']);

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
        $sortableColumns = collect($this->headers())->where('sortable', true)->pluck('key')->all();
        $sortColumn = in_array($this->sortBy['column'] ?? null, $sortableColumns, true) ? $this->sortBy['column'] : 'created_at';
        $sortDirection = ($this->sortBy['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortColumn, $sortDirection);

        $perPage = in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 25;

        return $query->paginate($perPage);
    }

    public function getUniqueLogNames()
    {
        return Cache::remember('activity_log_unique_log_names_' . AdminTenant::id(), 3600, function () {
            return $this->tenantActivities()->distinct()->pluck('log_name')->filter()->sort()->values()->toArray();
        });
    }

    public function getUniqueEvents()
    {
        return Cache::remember('activity_log_unique_events_' . AdminTenant::id(), 3600, function () {
            return $this->tenantActivities()->distinct()->pluck('event')->filter()->sort()->values()->toArray();
        });
    }

    public function getUniqueSubjectTypes()
    {
        return Cache::remember('activity_log_unique_subject_types_' . AdminTenant::id(), 3600, function () {
            return $this->tenantActivities()->distinct()->pluck('subject_type')->filter()->sort()->values()->toArray();
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
        $this->selectedActivity = $this->tenantActivities()->with(['subject', 'causer'])->find($activityId);
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
    <x-header title="Activity Log" separator>
    </x-header>

    <div class="space-y-4 lg:space-y-6">
        <x-admin.filter-bar
            search-placeholder="Search activity..."
            :active="$search || $logNameFilter || $eventFilter || $subjectTypeFilter"
            :filters="[
                ['model' => 'logNameFilter', 'label' => 'Log', 'placeholder' => 'All logs', 'options' => collect($this->getUniqueLogNames())->mapWithKeys(fn ($v) => [$v => $v])],
                ['model' => 'eventFilter', 'label' => 'Event', 'placeholder' => 'All events', 'options' => collect($this->getUniqueEvents())->mapWithKeys(fn ($v) => [$v => $this->prettifyEvent($v)])],
                ['model' => 'subjectTypeFilter', 'label' => 'Subject', 'placeholder' => 'All subjects', 'options' => collect($this->getUniqueSubjectTypes())->mapWithKeys(fn ($v) => [$v => $this->prettifySubjectType($v)])],
            ]" />

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
                    @if ($activity->subject)
                        @if ($activity->subject instanceof \App\Models\Event)
                            <x-event-ref :event="$activity->subject" :showService="false" />
                        @elseif ($activity->subject instanceof \App\Models\EventObject)
                            <x-object-ref :object="$activity->subject" />
                        @elseif ($activity->subject instanceof \App\Models\Block)
                            <x-block-ref :block="$activity->subject" :showType="false" />
                        @else
                            <span class="text-base-content/70">{{ Str::limit($this->getSubjectTitle($activity), 30) }}</span>
                            <div class="text-xs text-base-content/70">{{ $this->prettifySubjectType($activity->subject_type) }}</div>
                        @endif
                    @else
                        <span class="text-base-content/70 italic">Deleted</span>
                        <div class="text-xs text-base-content/70">{{ $this->prettifySubjectType($activity->subject_type) }}</div>
                    @endif
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
                                        @if ($selectedActivity->subject)
                                            @if ($selectedActivity->subject instanceof \App\Models\Event)
                                                <x-event-ref :event="$selectedActivity->subject" />
                                            @elseif ($selectedActivity->subject instanceof \App\Models\EventObject)
                                                <x-object-ref :object="$selectedActivity->subject" :showType="true" />
                                            @elseif ($selectedActivity->subject instanceof \App\Models\Block)
                                                <x-block-ref :block="$selectedActivity->subject" />
                                            @else
                                                <span class="text-base-content/70">{{ $this->getSubjectTitle($selectedActivity) }}</span>
                                            @endif
                                        @else
                                            <span class="text-base-content/70 italic">Deleted</span>
                                        @endif
                                        <div class="text-xs text-base-content/70 mt-1">{{ $this->prettifySubjectType($selectedActivity->subject_type) }}</div>
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