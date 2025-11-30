<?php

use App\Services\TaskPipeline\TaskRegistry;
use Livewire\Volt\Component;
use Illuminate\Database\Eloquent\Model;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;

new class extends Component {
    public Model $model;
    public bool $showNotApplicable = false;

    public function mount(Model $model): void
    {
        $this->model = $model;
    }

    public function with(): array
    {
        $executions = $this->getTaskExecutions();
        $allTasks = TaskRegistry::getTasksForModel($this->model, 'manual');

        // Categorize tasks
        $executedTasks = collect();
        $shouldHaveRunTasks = collect();
        $notApplicableTasks = collect();

        foreach ($allTasks as $task) {
            $status = $executions[$task->key]['last_attempt']['status'] ?? 'not_run';

            if (in_array($status, ['success', 'failed', 'running', 'pending'])) {
                $executedTasks->push($task);
            } elseif ($status === 'not_run' || !isset($executions[$task->key])) {
                $shouldHaveRunTasks->push($task);
            } elseif ($status === 'not_applicable') {
                $notApplicableTasks->push($task);
            }
        }

        return [
            'executedTasks' => $executedTasks,
            'shouldHaveRunTasks' => $shouldHaveRunTasks,
            'notApplicableTasks' => $notApplicableTasks,
            'executions' => $executions,
            'totalTasks' => $allTasks->count(),
            'hiddenCount' => $notApplicableTasks->count(),
        ];
    }

    public function rerunTask(string $taskKey): void
    {
        ProcessTaskPipelineJob::dispatch(
            model: $this->model,
            trigger: 'manual',
            taskFilter: [$taskKey],
            force: true,
        )->onQueue('tasks');

        $this->dispatch('task-rerun-initiated', taskKey: $taskKey);
        $this->dispatch('mary-toast', message: 'Task queued for re-run', type: 'info');
    }

    public function rerunAllTasks(): void
    {
        ProcessTaskPipelineJob::dispatch(
            model: $this->model,
            trigger: 'manual',
            force: true,
        )->onQueue('tasks');

        $this->dispatch('tasks-rerun-initiated');
        $this->dispatch('mary-toast', message: 'All tasks queued for re-run', type: 'info');
    }

    protected function getTaskExecutions(): array
    {
        // Determine which field to use based on model type
        $field = match (get_class($this->model)) {
            \App\Models\Event::class => 'event_metadata',
            \App\Models\Integration::class => 'configuration',
            default => 'metadata', // EventObject, Block
        };

        $metadata = $this->model->$field ?? [];
        return $metadata['task_executions'] ?? [];
    }
}; ?>

<div class="space-y-3">
    {{-- Should Have Run Section (Warning) --}}
    @if ($shouldHaveRunTasks->isNotEmpty())
        <div>
            <div class="text-xs font-semibold uppercase tracking-wider text-warning mb-2 flex items-center gap-2">
                <x-icon name="fas.triangle-exclamation" class="w-3 h-3" />
                Should Have Run ({{ $shouldHaveRunTasks->count() }})
            </div>
            <div class="space-y-1">
                @foreach ($shouldHaveRunTasks as $task)
                    @php
                        $execution = $executions[$task->key] ?? null;
                        $lastAttempt = $execution['last_attempt'] ?? null;
                    @endphp

                    <div class="flex items-start gap-2 p-2 rounded hover:bg-base-200 transition-colors">
                        {{-- Status Badge --}}
                        <div class="flex-shrink-0 pt-0.5">
                            <x-badge class="badge-xs badge-outline badge-warning gap-1">
                                <x-icon name="fas.circle" class="w-3 h-3" />
                            </x-badge>
                        </div>

                        {{-- Task Info --}}
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium">{{ $task->name }}</div>
                            <div class="text-xs text-base-content/60">{{ $task->description }}</div>
                            @if (!empty($task->dependencies))
                                <div class="text-xs text-base-content/40 mt-1">
                                    Depends on: {{ collect($task->dependencies)->map(fn($key) => TaskRegistry::getTask($key)?->name ?? $key)->join(', ') }}
                                </div>
                            @endif
                        </div>

                        {{-- Re-run Button --}}
                        <button
                            wire:click="rerunTask('{{ $task->key }}')"
                            class="btn btn-ghost btn-xs flex-shrink-0"
                            title="Re-run this task">
                            <x-icon name="fas.rotate" class="w-3 h-3" />
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Executed Tasks Section --}}
    @if ($executedTasks->isNotEmpty())
        <div>
            <div class="text-xs font-semibold uppercase tracking-wider text-base-content/70 mb-2">
                Executed ({{ $executedTasks->count() }})
            </div>
            <div class="space-y-1">
                @foreach ($executedTasks as $task)
                    @php
                        $execution = $executions[$task->key] ?? null;
                        $lastAttempt = $execution['last_attempt'] ?? null;
                        $lastSuccess = $execution['last_success'] ?? null;
                        $status = $lastAttempt['status'] ?? 'not_run';
                    @endphp

                    <div class="flex items-start gap-2 p-2 rounded hover:bg-base-200 transition-colors">
                        {{-- Status Badge --}}
                        <div class="flex-shrink-0 pt-0.5">
                            @if ($status === 'success')
                                <x-badge class="badge-xs badge-success gap-1">
                                    <x-icon name="fas.check-circle" class="w-3 h-3" />
                                </x-badge>
                            @elseif ($status === 'failed')
                                <x-badge class="badge-xs badge-error gap-1">
                                    <x-icon name="fas.x-circle" class="w-3 h-3" />
                                </x-badge>
                            @elseif ($status === 'running')
                                <x-badge class="badge-xs badge-warning gap-1">
                                    <x-icon name="fas.spinner" class="w-3 h-3 animate-spin" />
                                </x-badge>
                            @elseif ($status === 'pending')
                                <x-badge class="badge-xs badge-info gap-1">
                                    <x-icon name="fas.clock" class="w-3 h-3" />
                                </x-badge>
                            @endif
                        </div>

                        {{-- Task Info --}}
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium">{{ $task->name }}</div>
                            @if ($lastAttempt)
                                <div class="text-xs text-base-content/60">
                                    {{ \Carbon\Carbon::parse($lastAttempt['started_at'])->diffForHumans() }}
                                    @if (isset($lastAttempt['attempts']) && $lastAttempt['attempts'] > 1)
                                        · {{ $lastAttempt['attempts'] }} attempts
                                    @endif
                                    @if (isset($lastAttempt['triggered_by']))
                                        · Triggered by: {{ $lastAttempt['triggered_by'] }}
                                    @endif
                                </div>
                            @endif
                            @if ($status === 'failed' && isset($lastAttempt['error']))
                                <div class="text-xs text-error mt-1 truncate" title="{{ $lastAttempt['error'] }}">
                                    {{ $lastAttempt['error'] }}
                                </div>
                            @endif
                            @if (!empty($task->dependencies))
                                <div class="text-xs text-base-content/40 mt-1">
                                    Depends on: {{ collect($task->dependencies)->map(fn($key) => TaskRegistry::getTask($key)?->name ?? $key)->join(', ') }}
                                </div>
                            @endif
                        </div>

                        {{-- Re-run Button --}}
                        <button
                            wire:click="rerunTask('{{ $task->key }}')"
                            class="btn btn-ghost btn-xs flex-shrink-0"
                            title="Re-run this task"
                            @if (in_array($status, ['running', 'pending'])) disabled @endif>
                            <x-icon name="fas.rotate" class="w-3 h-3" />
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Not Applicable Section (Hidden by Default) --}}
    @if ($showNotApplicable && $notApplicableTasks->isNotEmpty())
        <div>
            <div class="text-xs font-semibold uppercase tracking-wider text-base-content/40 mb-2">
                Not Applicable ({{ $notApplicableTasks->count() }})
            </div>
            <div class="space-y-1">
                @foreach ($notApplicableTasks as $task)
                    <div class="flex items-start gap-2 p-2 rounded hover:bg-base-200 transition-colors">
                        {{-- Status Badge --}}
                        <div class="flex-shrink-0 pt-0.5">
                            <x-badge class="badge-xs badge-ghost gap-1">
                                <x-icon name="fas.minus-circle" class="w-3 h-3" />
                            </x-badge>
                        </div>

                        {{-- Task Info --}}
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-base-content/60">{{ $task->name }}</div>
                            <div class="text-xs text-base-content/40">{{ $task->description }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Toggle for N/A Tasks --}}
    @if ($notApplicableTasks->isNotEmpty())
        <div class="pt-3 border-t border-base-200">
            <label class="flex items-center gap-2 cursor-pointer">
                <input
                    type="checkbox"
                    wire:model.live="showNotApplicable"
                    class="checkbox checkbox-xs" />
                <span class="text-xs text-base-content/60">
                    Show {{ $notApplicableTasks->count() }} not applicable tasks
                </span>
            </label>
        </div>
    @endif

    {{-- Empty State --}}
    @if ($executedTasks->isEmpty() && $shouldHaveRunTasks->isEmpty() && $notApplicableTasks->isEmpty())
        <x-empty-state
            icon="fas.list-check"
            message="No tasks configured for this item"
            :actionEvent="null" />
    @endif
</div>
