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

        // Filter out not_applicable if toggle is off
        $visibleTasks = $allTasks->filter(function($task) use ($executions) {
            if ($this->showNotApplicable) {
                return true;
            }

            $status = $executions[$task->key]['last_attempt']['status'] ?? 'not_run';
            return $status !== 'not_applicable';
        });

        return [
            'allTasks' => $visibleTasks,
            'executions' => $executions,
            'totalTasks' => $allTasks->count(),
            'hiddenCount' => $allTasks->count() - $visibleTasks->count(),
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

        session()->flash('message', 'Task queued for re-run');
    }

    public function rerunAllTasks(): void
    {
        ProcessTaskPipelineJob::dispatch(
            model: $this->model,
            trigger: 'manual',
            force: true,
        )->onQueue('tasks');

        $this->dispatch('tasks-rerun-initiated');

        session()->flash('message', 'All tasks queued for re-run');
    }

    protected function getTaskExecutions(): array
    {
        $field = $this->model instanceof \App\Models\Event ? 'event_metadata' : 'metadata';
        $metadata = $this->model->$field ?? [];
        return $metadata['task_executions'] ?? [];
    }
}; ?>

<div class="space-y-4">
    <div class="flex items-center justify-between">
        <flux:heading size="lg">Task Executions</flux:heading>

        <div class="flex gap-2 items-center">
            @if ($hiddenCount > 0)
                <flux:badge color="zinc" size="sm">
                    {{ $hiddenCount }} hidden
                </flux:badge>
            @endif

            <flux:switch wire:model.live="showNotApplicable" label="Show N/A" />

            <flux:button wire:click="rerunAllTasks" size="sm" variant="ghost" icon="arrow-path">
                Re-run All
            </flux:button>
        </div>
    </div>

    @if (session('message'))
        <flux:banner variant="success">
            {{ session('message') }}
        </flux:banner>
    @endif

    <div class="space-y-3">
        @forelse ($allTasks as $task)
            @php
            $execution = $executions[$task->key] ?? null;
            $lastAttempt = $execution['last_attempt'] ?? null;
            $lastSuccess = $execution['last_success'] ?? null;
            $status = $lastAttempt['status'] ?? 'not_run';
            @endphp

            <flux:card>
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1 space-y-2">
                        <div class="flex items-center gap-2">
                            <flux:heading size="sm">{{ $task->name }}</flux:heading>

                            @if ($status === 'success')
                                <flux:badge color="green" size="sm" icon="check-circle">
                                    Success
                                </flux:badge>
                            @elseif ($status === 'failed')
                                <flux:badge color="red" size="sm" icon="x-circle">
                                    Failed
                                </flux:badge>
                            @elseif ($status === 'running')
                                <flux:badge color="amber" size="sm" icon="arrow-path">
                                    Running
                                </flux:badge>
                            @elseif ($status === 'pending')
                                <flux:badge color="blue" size="sm" icon="clock">
                                    Pending
                                </flux:badge>
                            @elseif ($status === 'not_applicable')
                                <flux:badge color="zinc" size="sm" icon="minus-circle">
                                    Not Applicable
                                </flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm" variant="outline">
                                    Not Run
                                </flux:badge>
                            @endif
                        </div>

                        <flux:subheading>{{ $task->description }}</flux:subheading>

                        @if ($lastAttempt)
                            <div class="text-xs text-zinc-500 dark:text-zinc-400 space-y-1">
                                <div class="flex items-center gap-4">
                                    <span>Last run: {{ \Carbon\Carbon::parse($lastAttempt['started_at'])->diffForHumans() }}</span>
                                    @if (isset($lastAttempt['attempts']) && $lastAttempt['attempts'] > 1)
                                        <span>Attempts: {{ $lastAttempt['attempts'] }}</span>
                                    @endif
                                    <span>Trigger: {{ $lastAttempt['triggered_by'] ?? 'unknown' }}</span>
                                </div>

                                @if ($status === 'failed' && isset($lastAttempt['error']))
                                    <flux:banner variant="danger" size="sm">
                                        {{ $lastAttempt['error'] }}
                                    </flux:banner>
                                @endif
                            </div>
                        @endif

                        @if (!empty($task->dependencies))
                            <div class="text-xs text-zinc-400">
                                Depends on: {{ collect($task->dependencies)->map(fn($key) => TaskRegistry::getTask($key)?->name ?? $key)->join(', ') }}
                            </div>
                        @endif
                    </div>

                    <flux:button
                        wire:click="rerunTask('{{ $task->key }}')"
                        size="sm"
                        variant="ghost"
                        icon="arrow-path"
                        :disabled="in_array($status, ['running', 'pending'])"
                    >
                        Re-run
                    </flux:button>
                </div>
            </flux:card>
        @empty
            <flux:card>
                <div class="text-center text-zinc-500 py-8">
                    No tasks available for this item
                </div>
            </flux:card>
        @endforelse
    </div>
</div>
