<?php

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use App\Models\TaskExecution;
use App\Services\TaskPipeline\TaskRegistry;
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

    public string $taskSearch = '';
    public string $appliesToFilter = '';
    public string $sourceFilter = '';
    public string $failureWindow = '24h';
    public int $perPage = 25;

    protected $queryString = [
        'taskSearch' => ['except' => ''],
        'appliesToFilter' => ['except' => ''],
        'sourceFilter' => ['except' => ''],
        'failureWindow' => ['except' => '24h'],
        'perPage' => ['except' => 25],
        'page' => ['except' => 1],
    ];

    public function updatedFailureWindow(): void
    {
        $this->resetPage();
    }

    public function clearTaskFilters(): void
    {
        $this->reset(['taskSearch', 'appliesToFilter', 'sourceFilter']);
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

    protected function failureWindowStart(): \Carbon\Carbon
    {
        return match ($this->failureWindow) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDay(),
        };
    }

    public function getRecentFailuresProperty()
    {
        return TaskExecution::query()
            ->where('status', 'failed')
            ->where('updated_at', '>=', $this->failureWindowStart())
            ->latest('updated_at')
            ->paginate($this->perPage);
    }

    public function getStatsProperty(): array
    {
        $counts = TaskExecution::query()
            ->where('updated_at', '>=', $this->failureWindowStart())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $pending = (int) ($counts['pending'] ?? 0) + (int) ($counts['running'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $success = (int) ($counts['success'] ?? 0);
        $total = $failed + $success;

        return [
            'totalTasks' => $this->allTasks->count(),
            'pluginTasks' => $this->allTasks->filter(fn ($task) => $task->registeredBy !== null)->count(),
            'pending' => $pending,
            'failed' => $failed,
            'success' => $success,
            'failureRate' => $total > 0 ? round($failed / $total * 100, 1) : 0,
            'successRate' => $total > 0 ? round($success / $total * 100, 1) : 0,
        ];
    }

    public function taskName(string $key): string
    {
        return TaskRegistry::getTask($key)?->name ?? $key;
    }

    public function failureModelUrl(TaskExecution $execution): ?string
    {
        return $execution->entity_type === 'event' ? route('events.show', $execution->entity_id) : null;
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

<div>
    <x-header title="Task Pipeline" subtitle="Registered tasks and recent execution failures" separator />

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
                <div class="stat-value text-primary">{{ $this->stats['pending'] }}</div>
                <div class="stat-desc">In queue</div>
            </div>

            <div class="stat">
                <div class="stat-title">Failed</div>
                <div class="stat-value text-error">{{ $this->stats['failed'] }}</div>
                <div class="stat-desc">{{ $this->stats['failureRate'] }}% failure rate</div>
            </div>

            <div class="stat">
                <div class="stat-title">Success</div>
                <div class="stat-value text-success">{{ $this->stats['success'] }}</div>
                <div class="stat-desc">{{ $this->stats['successRate'] }}% success rate</div>
            </div>
        </div>

        {{-- Registered Tasks --}}
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

        {{-- Recent Failures --}}
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                    <h3 class="card-title">Recent Failures</h3>

                    <select class="select select-bordered select-sm" wire:model.live="failureWindow">
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
                        @if ($url = $this->failureModelUrl($failure))
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
                        <button
                            wire:click="retryFailure('{{ $failure->id }}')"
                            wire:loading.attr="disabled"
                            wire:target="retryFailure('{{ $failure->id }}')"
                            class="btn btn-ghost btn-sm">
                            <span wire:loading wire:target="retryFailure('{{ $failure->id }}')" class="loading loading-spinner loading-xs"></span>
                            Retry
                        </button>
                    @endscope
                </x-table>
            </div>
        </div>
    </div>
</div>
