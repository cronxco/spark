<?php

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use App\Models\TaskExecution;
use App\Services\TaskPipeline\TaskRegistry;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    use Toast, WithPagination;

    public string $activeTab = 'registry';

    public string $taskSearch = '';
    public string $appliesToFilter = '';
    public string $sourceFilter = '';

    public string $activeStatusFilter = '';

    public string $execStatusFilter = '';
    public string $execTaskFilter = '';
    public string $execEntityTypeFilter = '';
    public string $execSearch = '';

    public string $window = '24h';
    public int $perPage = 25;

    public bool $showExecutionModal = false;
    public ?string $selectedExecutionId = null;

    public int $stuckAfterMinutes = 15;

    protected $queryString = [
        'activeTab' => ['except' => 'registry'],
        'taskSearch' => ['except' => ''],
        'appliesToFilter' => ['except' => ''],
        'sourceFilter' => ['except' => ''],
        'activeStatusFilter' => ['except' => ''],
        'execStatusFilter' => ['except' => ''],
        'execTaskFilter' => ['except' => ''],
        'execEntityTypeFilter' => ['except' => ''],
        'execSearch' => ['except' => ''],
        'window' => ['except' => '24h'],
        'perPage' => ['except' => 25],
        'executionsPage' => ['except' => 1],
        'failuresPage' => ['except' => 1],
    ];

    public function updatedWindow(): void
    {
        $this->resetPage('executionsPage');
        $this->resetPage('failuresPage');
    }

    public function updatedExecStatusFilter(): void
    {
        $this->resetPage('executionsPage');
    }

    public function updatedExecTaskFilter(): void
    {
        $this->resetPage('executionsPage');
    }

    public function updatedExecEntityTypeFilter(): void
    {
        $this->resetPage('executionsPage');
    }

    public function clearTaskFilters(): void
    {
        $this->reset(['taskSearch', 'appliesToFilter', 'sourceFilter']);
    }

    public function clearExecutionFilters(): void
    {
        $this->reset(['execStatusFilter', 'execTaskFilter', 'execEntityTypeFilter', 'execSearch']);
        $this->resetPage('executionsPage');
    }

    public function taskHeaders(): array
    {
        return [
            ['key' => 'name', 'label' => 'Task', 'sortable' => false],
            ['key' => 'applies_to', 'label' => 'Applies To', 'sortable' => false],
            ['key' => 'dependencies', 'label' => 'Dependencies', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'source', 'label' => 'Source', 'sortable' => false],
        ];
    }

    public function activeHeaders(): array
    {
        return [
            ['key' => 'task_name', 'label' => 'Task', 'sortable' => false],
            ['key' => 'model', 'label' => 'Model', 'sortable' => false],
            ['key' => 'status', 'label' => 'Status', 'sortable' => false],
            ['key' => 'attempts', 'label' => 'Attempts', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'updated_at', 'label' => 'Updated', 'sortable' => false],
            ['key' => 'actions', 'label' => '', 'sortable' => false],
        ];
    }

    public function executionHeaders(): array
    {
        return [
            ['key' => 'task_name', 'label' => 'Task', 'sortable' => false],
            ['key' => 'model', 'label' => 'Model', 'sortable' => false],
            ['key' => 'status', 'label' => 'Status', 'sortable' => false],
            ['key' => 'triggered_by', 'label' => 'Trigger', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'updated_at', 'label' => 'Updated', 'sortable' => false],
            ['key' => 'actions', 'label' => '', 'sortable' => false],
        ];
    }

    public function failureHeaders(): array
    {
        return [
            ['key' => 'task_name', 'label' => 'Task', 'sortable' => false],
            ['key' => 'model', 'label' => 'Model', 'sortable' => false],
            ['key' => 'error', 'label' => 'Error', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'failed_at', 'label' => 'When', 'sortable' => false],
            ['key' => 'actions', 'label' => 'Actions', 'sortable' => false],
        ];
    }

    public function getAllTasksProperty(): Collection
    {
        return collect(TaskRegistry::getAllTasks());
    }

    public function getFilteredTasksProperty(): Collection
    {
        return $this->allTasks
            ->filter(function ($task) {
                if ($this->taskSearch) {
                    $needle = strtolower($this->taskSearch);
                    if (! str_contains(strtolower($task->name), $needle) && ! str_contains(strtolower($task->key), $needle)) {
                        return false;
                    }
                }

                if ($this->appliesToFilter && ! in_array($this->appliesToFilter, $task->appliesTo)) {
                    return false;
                }

                if ($this->sourceFilter === 'core' && $task->registeredBy) {
                    return false;
                }

                if ($this->sourceFilter === 'plugin' && ! $task->registeredBy) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    public function getUniqueAppliesToProperty(): array
    {
        return $this->allTasks->pluck('appliesTo')->flatten()->unique()->sort()->values()->toArray();
    }

    protected function windowStart(): \Carbon\Carbon
    {
        return match ($this->window) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDay(),
        };
    }

    protected function stuckThreshold(): \Carbon\Carbon
    {
        return now()->subMinutes($this->stuckAfterMinutes);
    }

    public function isStuck(TaskExecution $execution): bool
    {
        return in_array($execution->status, ['pending', 'running'], true)
            && $execution->updated_at->lt($this->stuckThreshold());
    }

    public function getActiveExecutionsProperty(): Collection
    {
        return TaskExecution::query()
            ->whereIn('status', ['pending', 'running', 'waiting', 'blocked'])
            ->when($this->activeStatusFilter, fn ($q) => $q->where('status', $this->activeStatusFilter))
            ->latest('updated_at')
            ->limit(100)
            ->get();
    }

    public function getRecentExecutionsProperty(): LengthAwarePaginator
    {
        return TaskExecution::query()
            ->where('updated_at', '>=', $this->windowStart())
            ->when($this->execStatusFilter, fn ($q) => $q->where('status', $this->execStatusFilter))
            ->when($this->execTaskFilter, fn ($q) => $q->where('task_key', $this->execTaskFilter))
            ->when($this->execEntityTypeFilter, fn ($q) => $q->where('entity_type', $this->execEntityTypeFilter))
            ->when($this->execSearch, function ($q) {
                $needle = strtolower($this->execSearch);
                $q->where(function ($sub) use ($needle) {
                    $sub->whereRaw('LOWER(task_key) LIKE ?', ["%{$needle}%"])
                        ->orWhereRaw('LOWER(task_name) LIKE ?', ["%{$needle}%"]);
                });
            })
            ->latest('updated_at')
            ->paginate($this->perPage, ['*'], 'executionsPage');
    }

    public function getRecentFailuresProperty(): LengthAwarePaginator
    {
        return TaskExecution::query()
            ->where('status', 'failed')
            ->where('updated_at', '>=', $this->windowStart())
            ->latest('updated_at')
            ->paginate($this->perPage, ['*'], 'failuresPage');
    }

    public function getStatsProperty(): array
    {
        $liveCounts = TaskExecution::query()
            ->whereIn('status', ['pending', 'running', 'waiting', 'blocked'])
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $pending = (int) ($liveCounts['pending'] ?? 0);
        $running = (int) ($liveCounts['running'] ?? 0);
        $waiting = (int) ($liveCounts['waiting'] ?? 0);
        $blocked = (int) ($liveCounts['blocked'] ?? 0);

        $stuck = TaskExecution::query()
            ->whereIn('status', ['pending', 'running'])
            ->where('updated_at', '<', $this->stuckThreshold())
            ->count();

        $windowCounts = TaskExecution::query()
            ->whereIn('status', ['success', 'failed'])
            ->where('updated_at', '>=', $this->windowStart())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $success = (int) ($windowCounts['success'] ?? 0);
        $failed = (int) ($windowCounts['failed'] ?? 0);
        $total = $success + $failed;

        return [
            'totalTasks' => $this->allTasks->count(),
            'pluginTasks' => $this->allTasks->filter(fn ($task) => $task->registeredBy !== null)->count(),
            'pending' => $pending,
            'running' => $running,
            'waiting' => $waiting,
            'blocked' => $blocked,
            'stuck' => $stuck,
            'activeCount' => $pending + $running + $waiting + $blocked,
            'success' => $success,
            'failed' => $failed,
            'failureRate' => $total > 0 ? round($failed / $total * 100, 1) : 0,
            'successRate' => $total > 0 ? round($success / $total * 100, 1) : 0,
        ];
    }

    public function statusOptions(): array
    {
        return [
            'pending' => 'Pending',
            'running' => 'Running',
            'waiting' => 'Waiting',
            'blocked' => 'Blocked',
            'success' => 'Success',
            'failed' => 'Failed',
            'not_applicable' => 'Not Applicable',
        ];
    }

    public function activeStatusOptions(): array
    {
        return collect($this->statusOptions())
            ->only(['pending', 'running', 'waiting', 'blocked'])
            ->toArray();
    }

    public function taskName(?string $key): string
    {
        if (! $key) {
            return 'Unknown';
        }

        return TaskRegistry::getTask($key)?->name ?? $key;
    }

    public function executionModelUrl(TaskExecution $execution): ?string
    {
        return match ($execution->entity_type) {
            'event' => route('events.show', $execution->entity_id),
            'object' => route('objects.show', $execution->entity_id),
            'block' => route('blocks.show', $execution->entity_id),
            default => null,
        };
    }

    public function showExecutionDetails(string $executionId): void
    {
        $this->selectedExecutionId = $executionId;
        $this->showExecutionModal = true;
    }

    public function closeExecutionModal(): void
    {
        $this->showExecutionModal = false;
        $this->selectedExecutionId = null;
    }

    public function getSelectedExecutionProperty(): ?TaskExecution
    {
        return $this->selectedExecutionId ? TaskExecution::find($this->selectedExecutionId) : null;
    }

    public function retryFailure(string $failureId): void
    {
        $execution = TaskExecution::find($failureId);

        if (! $execution) {
            $this->error('That task execution could not be found.');

            return;
        }

        if ($execution->entity_type !== 'event') {
            $this->error('Only event-backed tasks can be retried from here.');

            return;
        }

        $event = Event::find($execution->entity_id);

        if (! $event) {
            $this->error('The underlying event no longer exists.');

            return;
        }

        ProcessTaskPipelineJob::dispatch(
            model: $event,
            trigger: 'manual',
            taskFilter: [$execution->task_key],
            force: true,
        )->onQueue('tasks');

        $this->success('Task queued for retry.');
    }
}; ?>

<div @if ($this->stats['activeCount'] > 0) wire:poll.5s @endif>
    <x-header title="Task Pipeline" subtitle="Registered tasks, live status, and execution history" separator>
        <x-slot:actions>
            <button class="btn btn-ghost btn-sm gap-2" wire:click="$refresh">
                <x-icon name="fas.rotate" class="w-4 h-4" />
                Refresh
            </button>
        </x-slot:actions>
    </x-header>

    <div class="space-y-4 lg:space-y-6">
        {{-- Stats Overview --}}
        <div class="stats stats-vertical lg:stats-horizontal shadow w-full">
            <div class="stat">
                <div class="stat-title">Registered Tasks</div>
                <div class="stat-value">{{ $this->stats['totalTasks'] }}</div>
                <div class="stat-desc">{{ $this->stats['pluginTasks'] }} from plugins</div>
            </div>

            <div class="stat">
                <div class="stat-title">Pending</div>
                <div class="stat-value text-info">{{ $this->stats['pending'] }}</div>
                <div class="stat-desc">In queue</div>
            </div>

            <div class="stat">
                <div class="stat-title">Running</div>
                <div class="stat-value text-warning">{{ $this->stats['running'] }}</div>
                <div class="stat-desc">In progress</div>
            </div>

            <div class="stat">
                <div class="stat-title">Waiting / Blocked</div>
                <div class="stat-value">{{ $this->stats['waiting'] + $this->stats['blocked'] }}</div>
                <div class="stat-desc">{{ $this->stats['waiting'] }} waiting, {{ $this->stats['blocked'] }} blocked</div>
            </div>

            <div class="stat">
                <div class="stat-title">Stuck</div>
                <div class="stat-value {{ $this->stats['stuck'] > 0 ? 'text-error' : '' }}">{{ $this->stats['stuck'] }}</div>
                <div class="stat-desc">Running/pending &gt;{{ $stuckAfterMinutes }}m</div>
            </div>

            <div class="stat">
                <div class="stat-title">Failed ({{ $window }})</div>
                <div class="stat-value text-error">{{ $this->stats['failed'] }}</div>
                <div class="stat-desc">{{ $this->stats['failureRate'] }}% failure rate</div>
            </div>

            <div class="stat">
                <div class="stat-title">Success ({{ $window }})</div>
                <div class="stat-value text-success">{{ $this->stats['success'] }}</div>
                <div class="stat-desc">{{ $this->stats['successRate'] }}% success rate</div>
            </div>
        </div>

        <x-tabs wire:model="activeTab">
            {{-- Registry Tab --}}
            <x-tab name="registry" label="Registry" icon="fas.list-check">
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                            <h3 class="card-title">Registered Tasks</h3>

                            <div class="flex flex-wrap items-center gap-2">
                                <input
                                    type="text"
                                    class="input input-bordered input-sm"
                                    placeholder="Search tasks..."
                                    wire:model.live.debounce.300ms="taskSearch" />

                                <select class="select select-bordered select-sm" wire:model.live="appliesToFilter">
                                    <option value="">All Types</option>
                                    @foreach ($this->uniqueAppliesTo as $type)
                                        <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                                    @endforeach
                                </select>

                                <select class="select select-bordered select-sm" wire:model.live="sourceFilter">
                                    <option value="">Core &amp; Plugins</option>
                                    <option value="core">Core Only</option>
                                    <option value="plugin">Plugins Only</option>
                                </select>

                                @if ($taskSearch || $appliesToFilter || $sourceFilter)
                                    <button class="btn btn-outline btn-sm" wire:click="clearTaskFilters">
                                        <x-icon name="fas.xmark" class="w-4 h-4" />
                                        Clear
                                    </button>
                                @endif
                            </div>
                        </div>

                        <x-table
                            :headers="$this->taskHeaders()"
                            :rows="$this->filteredTasks"
                            striped
                            class="[&_table]:!static [&_td]:!static">
                            <x-slot:empty>
                                <div class="text-center py-12">
                                    <x-icon name="fas.diagram-project" class="w-16 h-16 mx-auto mb-4 text-base-content/70" />
                                    <h3 class="text-lg font-medium text-base-content mb-2">No tasks found</h3>
                                    <p class="text-base-content/70">
                                        @if ($taskSearch || $appliesToFilter || $sourceFilter)
                                            Try adjusting your filters or search terms
                                        @else
                                            No tasks are registered yet
                                        @endif
                                    </p>
                                </div>
                            </x-slot:empty>

                            @scope('cell_name', $task)
                                <div class="font-semibold">{{ $task->name }}</div>
                                <div class="text-xs text-base-content/70">{{ $task->description }}</div>
                            @endscope

                            @scope('cell_applies_to', $task)
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($task->appliesTo as $type)
                                        <span class="badge badge-sm">{{ $type }}</span>
                                    @endforeach
                                </div>
                            @endscope

                            @scope('cell_dependencies', $task)
                                @if (! empty($task->dependencies))
                                    <span class="text-xs">{{ count($task->dependencies) }} dependencies</span>
                                @else
                                    <span class="text-base-content/50">None</span>
                                @endif
                            @endscope

                            @scope('cell_source', $task)
                                @if ($task->registeredBy)
                                    <x-badge value="Plugin" class="badge-outline badge-sm" />
                                @else
                                    <span class="text-base-content/70">Core</span>
                                @endif
                            @endscope
                        </x-table>
                    </div>
                </div>
            </x-tab>

            {{-- Active Tab --}}
            <x-tab name="active" label="Active" icon="fas.bolt">
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                            <h3 class="card-title">Pending, Running, Waiting &amp; Blocked</h3>

                            <select class="select select-bordered select-sm" wire:model.live="activeStatusFilter">
                                <option value="">All Active Statuses</option>
                                @foreach ($this->activeStatusOptions() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <x-table
                            :headers="$this->activeHeaders()"
                            :rows="$this->activeExecutions"
                            striped
                            class="[&_table]:!static [&_td]:!static">
                            <x-slot:empty>
                                <div class="text-center py-12">
                                    <x-icon name="fas.circle-check" class="w-16 h-16 mx-auto mb-4 text-base-content/70" />
                                    <h3 class="text-lg font-medium text-base-content mb-2">Nothing active</h3>
                                    <p class="text-base-content/70">No tasks are pending, running, waiting, or blocked right now.</p>
                                </div>
                            </x-slot:empty>

                            @scope('cell_task_name', $execution)
                                <span class="text-sm">{{ $this->taskName($execution->task_key) }}</span>
                            @endscope

                            @scope('cell_model', $execution)
                                @if ($url = $this->executionModelUrl($execution))
                                    <a href="{{ $url }}" class="link link-primary text-sm">
                                        {{ ucfirst($execution->entity_type) }} #{{ Str::limit($execution->entity_id, 8, '') }}
                                    </a>
                                @else
                                    <span class="text-sm">{{ ucfirst($execution->entity_type) }} #{{ Str::limit($execution->entity_id, 8, '') }}</span>
                                @endif
                            @endscope

                            @scope('cell_status', $execution)
                                <div class="flex items-center gap-2">
                                    <x-task-status-badge :status="$execution->status" />
                                    @if ($this->isStuck($execution))
                                        <span class="badge badge-error badge-outline badge-xs gap-1" title="No update in over {{ $stuckAfterMinutes }} minutes">
                                            <x-icon name="fas.triangle-exclamation" class="w-3 h-3" />
                                            Stuck
                                        </span>
                                    @endif
                                </div>
                            @endscope

                            @scope('cell_attempts', $execution)
                                <span class="text-xs">{{ $execution->attempts }}</span>
                            @endscope

                            @scope('cell_updated_at', $execution)
                                <span class="text-sm">{{ $execution->updated_at->diffForHumans() }}</span>
                            @endscope

                            @scope('cell_actions', $execution)
                                <button
                                    wire:click="showExecutionDetails('{{ $execution->id }}')"
                                    class="btn btn-ghost btn-sm">
                                    View
                                </button>
                            @endscope
                        </x-table>
                    </div>
                </div>
            </x-tab>

            {{-- Recent Executions Tab --}}
            <x-tab name="executions" label="Recent Executions" icon="fas.clock-rotate-left">
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                            <h3 class="card-title">Recent Executions</h3>

                            <div class="flex flex-wrap items-center gap-2">
                                <input
                                    type="text"
                                    class="input input-bordered input-sm"
                                    placeholder="Search task..."
                                    wire:model.live.debounce.300ms="execSearch" />

                                <select class="select select-bordered select-sm" wire:model.live="execStatusFilter">
                                    <option value="">All Statuses</option>
                                    @foreach ($this->statusOptions() as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>

                                <select class="select select-bordered select-sm" wire:model.live="execTaskFilter">
                                    <option value="">All Tasks</option>
                                    @foreach ($this->allTasks->sortBy('name') as $task)
                                        <option value="{{ $task->key }}">{{ $task->name }}</option>
                                    @endforeach
                                </select>

                                <select class="select select-bordered select-sm" wire:model.live="execEntityTypeFilter">
                                    <option value="">All Types</option>
                                    @foreach ($this->uniqueAppliesTo as $type)
                                        <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                                    @endforeach
                                </select>

                                <select class="select select-bordered select-sm" wire:model.live="window">
                                    <option value="24h">Last 24 hours</option>
                                    <option value="7d">Last 7 days</option>
                                    <option value="30d">Last 30 days</option>
                                </select>

                                @if ($execStatusFilter || $execTaskFilter || $execEntityTypeFilter || $execSearch)
                                    <button class="btn btn-outline btn-sm" wire:click="clearExecutionFilters">
                                        <x-icon name="fas.xmark" class="w-4 h-4" />
                                        Clear
                                    </button>
                                @endif
                            </div>
                        </div>

                        <x-table
                            :headers="$this->executionHeaders()"
                            :rows="$this->recentExecutions"
                            with-pagination
                            per-page="perPage"
                            :per-page-values="[10, 25, 50, 100]"
                            striped
                            class="[&_table]:!static [&_td]:!static">
                            <x-slot:empty>
                                <div class="text-center py-12">
                                    <x-icon name="fas.clock-rotate-left" class="w-16 h-16 mx-auto mb-4 text-base-content/70" />
                                    <h3 class="text-lg font-medium text-base-content mb-2">No executions found</h3>
                                    <p class="text-base-content/70">
                                        @if ($execStatusFilter || $execTaskFilter || $execEntityTypeFilter || $execSearch)
                                            Try adjusting your filters or search terms
                                        @else
                                            Nothing has run in this window
                                        @endif
                                    </p>
                                </div>
                            </x-slot:empty>

                            @scope('cell_task_name', $execution)
                                <span class="text-sm">{{ $this->taskName($execution->task_key) }}</span>
                            @endscope

                            @scope('cell_model', $execution)
                                @if ($url = $this->executionModelUrl($execution))
                                    <a href="{{ $url }}" class="link link-primary text-sm">
                                        {{ ucfirst($execution->entity_type) }} #{{ Str::limit($execution->entity_id, 8, '') }}
                                    </a>
                                @else
                                    <span class="text-sm">{{ ucfirst($execution->entity_type) }} #{{ Str::limit($execution->entity_id, 8, '') }}</span>
                                @endif
                            @endscope

                            @scope('cell_status', $execution)
                                <x-task-status-badge :status="$execution->status" />
                            @endscope

                            @scope('cell_triggered_by', $execution)
                                <span class="text-sm">{{ $execution->triggered_by ?? 'Unknown' }}</span>
                            @endscope

                            @scope('cell_updated_at', $execution)
                                <span class="text-sm">{{ $execution->updated_at->diffForHumans() }}</span>
                            @endscope

                            @scope('cell_actions', $execution)
                                <button
                                    wire:click="showExecutionDetails('{{ $execution->id }}')"
                                    class="btn btn-ghost btn-sm">
                                    View
                                </button>
                            @endscope
                        </x-table>
                    </div>
                </div>
            </x-tab>

            {{-- Failures Tab --}}
            <x-tab name="failures" label="Failures" icon="fas.triangle-exclamation">
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                            <h3 class="card-title">Recent Failures</h3>

                            <select class="select select-bordered select-sm" wire:model.live="window">
                                <option value="24h">Last 24 hours</option>
                                <option value="7d">Last 7 days</option>
                                <option value="30d">Last 30 days</option>
                            </select>
                        </div>

                        <x-table
                            :headers="$this->failureHeaders()"
                            :rows="$this->recentFailures"
                            with-pagination
                            per-page="perPage"
                            :per-page-values="[10, 25, 50, 100]"
                            striped
                            class="[&_table]:!static [&_td]:!static">
                            <x-slot:empty>
                                <div class="text-center py-12">
                                    <x-icon name="fas.circle-check" class="w-16 h-16 mx-auto mb-4 text-base-content/70" />
                                    <h3 class="text-lg font-medium text-base-content mb-2">No failures</h3>
                                    <p class="text-base-content/70">Nothing has failed in this window.</p>
                                </div>
                            </x-slot:empty>

                            @scope('cell_task_name', $failure)
                                <span class="text-sm">{{ $this->taskName($failure->task_key) }}</span>
                            @endscope

                            @scope('cell_model', $failure)
                                @if ($url = $this->executionModelUrl($failure))
                                    <a href="{{ $url }}" class="link link-primary text-sm">
                                        {{ ucfirst($failure->entity_type) }} #{{ Str::limit($failure->entity_id, 8, '') }}
                                    </a>
                                @else
                                    <span class="text-sm">{{ ucfirst($failure->entity_type) }} #{{ Str::limit($failure->entity_id, 8, '') }}</span>
                                @endif
                            @endscope

                            @scope('cell_error', $failure)
                                <div class="text-xs max-w-xs truncate" title="{{ $failure->error }}">
                                    {{ $failure->error ?? 'Unknown error' }}
                                </div>
                            @endscope

                            @scope('cell_failed_at', $failure)
                                <span class="text-sm">{{ $failure->completed_at?->diffForHumans() ?? 'Unknown' }}</span>
                            @endscope

                            @scope('cell_actions', $failure)
                                <div class="flex items-center gap-1">
                                    <button
                                        wire:click="showExecutionDetails('{{ $failure->id }}')"
                                        class="btn btn-ghost btn-sm">
                                        View
                                    </button>
                                    <button
                                        wire:click="retryFailure('{{ $failure->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="retryFailure('{{ $failure->id }}')"
                                        class="btn btn-ghost btn-sm">
                                        <span wire:loading wire:target="retryFailure('{{ $failure->id }}')" class="loading loading-spinner loading-xs"></span>
                                        Retry
                                    </button>
                                </div>
                            @endscope
                        </x-table>
                    </div>
                </div>
            </x-tab>
        </x-tabs>
    </div>

    {{-- Execution Details Modal --}}
    @if ($showExecutionModal && $this->selectedExecution)
        @php $execution = $this->selectedExecution; @endphp
        <div class="modal modal-open">
            <div class="modal-box w-11/12 max-w-3xl">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-lg">Execution Details</h3>
                    <button class="btn btn-sm btn-circle" wire:click="closeExecutionModal">
                        <x-icon name="fas.xmark" class="w-4 h-4" />
                    </button>
                </div>

                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <x-task-status-badge :status="$execution->status" />
                        <span class="font-semibold">{{ $this->taskName($execution->task_key) }}</span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="card bg-base-200">
                            <div class="card-body p-4">
                                <h4 class="font-semibold text-sm text-base-content/70 uppercase tracking-wide">Execution</h4>
                                <div class="space-y-2 mt-2 text-sm">
                                    <div class="flex justify-between gap-2">
                                        <span class="font-medium">Entity:</span>
                                        <span class="font-mono">{{ ucfirst($execution->entity_type) }} #{{ Str::limit($execution->entity_id, 8, '') }}</span>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <span class="font-medium">Attempts:</span>
                                        <span>{{ $execution->attempts }}</span>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <span class="font-medium">Triggered by:</span>
                                        <span>{{ $execution->triggered_by ?? 'Unknown' }}</span>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <span class="font-medium">Started:</span>
                                        <span>{{ $execution->started_at?->format('M j, Y g:i:s A') ?? '—' }}</span>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <span class="font-medium">Completed:</span>
                                        <span>{{ $execution->completed_at?->format('M j, Y g:i:s A') ?? '—' }}</span>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <span class="font-medium">Updated:</span>
                                        <span>{{ $execution->updated_at->diffForHumans() }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card bg-base-200">
                            <div class="card-body p-4">
                                <h4 class="font-semibold text-sm text-base-content/70 uppercase tracking-wide">Queue</h4>
                                <div class="space-y-2 mt-2 text-sm">
                                    <div class="flex justify-between gap-2">
                                        <span class="font-medium">Queue:</span>
                                        <span>{{ $execution->queue ?? '—' }}</span>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <span class="font-medium">Connection:</span>
                                        <span>{{ $execution->queue_connection ?? '—' }}</span>
                                    </div>
                                    <div class="flex justify-between gap-2">
                                        <span class="font-medium">Job ID:</span>
                                        <span class="font-mono text-xs">{{ $execution->job_id ?? '—' }}</span>
                                    </div>
                                    @if ($execution->waiting_for)
                                        <div class="flex justify-between gap-2">
                                            <span class="font-medium">Waiting for:</span>
                                            <span>{{ $this->taskName($execution->waiting_for) }}</span>
                                        </div>
                                    @endif
                                    @if ($execution->blocked_by)
                                        <div class="flex justify-between gap-2">
                                            <span class="font-medium">Blocked by:</span>
                                            <span>{{ $this->taskName($execution->blocked_by) }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    @if ($execution->error)
                        <div class="card bg-base-200">
                            <div class="card-body p-4">
                                <h4 class="font-semibold text-sm text-base-content/70 uppercase tracking-wide flex items-center gap-2">
                                    <x-icon name="fas.triangle-exclamation" class="w-4 h-4" />
                                    Error
                                </h4>
                                <pre class="text-xs bg-base-300 p-3 rounded overflow-x-auto mt-2 whitespace-pre-wrap">{{ $execution->error }}</pre>
                            </div>
                        </div>
                    @endif

                    @if (! empty($execution->changed_fields))
                        <div class="card bg-base-200">
                            <div class="card-body p-4">
                                <h4 class="font-semibold text-sm text-base-content/70 uppercase tracking-wide">Changed Fields</h4>
                                <div class="flex flex-wrap gap-1 mt-2">
                                    @foreach ($execution->changed_fields as $field)
                                        <span class="badge badge-sm">{{ $field }}</span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif

                    @if (! empty($execution->history))
                        <div class="card bg-base-200">
                            <div class="card-body p-4">
                                <h4 class="font-semibold text-sm text-base-content/70 uppercase tracking-wide flex items-center gap-2">
                                    <x-icon name="fas.clock-rotate-left" class="w-4 h-4" />
                                    History
                                </h4>
                                <div class="space-y-2 mt-2">
                                    @foreach (array_reverse($execution->history) as $entry)
                                        <div class="flex items-start gap-2 text-xs border-l-2 border-base-300 pl-2 py-1">
                                            <x-task-status-badge :status="$entry['status'] ?? 'unknown'" />
                                            <div class="flex-1">
                                                <div class="text-base-content/70">{{ $entry['completed_at'] ?? $entry['started_at'] ?? '' }}</div>
                                                @if (! empty($entry['error']))
                                                    <div class="text-error">{{ $entry['error'] }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif

                    @if (! empty($execution->last_success))
                        <div class="card bg-base-200">
                            <div class="card-body p-4">
                                <h4 class="font-semibold text-sm text-base-content/70 uppercase tracking-wide">Last Success</h4>
                                <details class="bg-transparent p-0 mt-2">
                                    <summary class="cursor-pointer list-none text-sm text-base-content/80 hover:text-base-content">View raw data</summary>
                                    <pre class="text-xs bg-base-300 p-3 rounded overflow-x-auto mt-2">{{ json_encode($execution->last_success, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="modal-action">
                    @if ($execution->status === 'failed' && $execution->entity_type === 'event')
                        <button class="btn btn-ghost" wire:click="retryFailure('{{ $execution->id }}')">
                            Retry
                        </button>
                    @endif
                    <button class="btn" wire:click="closeExecutionModal">Close</button>
                </div>
            </div>
        </div>
    @endif
</div>
