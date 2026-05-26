<?php

use App\Services\TaskPipeline\TaskRegistry;
use App\Models\TaskExecution;
use App\Models\Event;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        $tasks = TaskRegistry::getAllTasks();
        $pluginTasks = collect($tasks)->filter(fn($task) => $task->registeredBy !== null);

        // Get recent failures from the last 24 hours
        $recentFailures = $this->getRecentFailures();

        // Get task statistics
        [$pendingTasks, $failedTasks, $successTasks] = $this->getTaskStatistics();

        return [
            'totalTasks' => count($tasks),
            'pluginTasks' => $pluginTasks->count(),
            'tasks' => $tasks,
            'recentFailures' => $recentFailures,
            'pendingTasks' => $pendingTasks,
            'failedTasks' => $failedTasks,
            'successTasks' => $successTasks,
            'failureRate' => $successTasks + $failedTasks > 0
                ? round(($failedTasks / ($successTasks + $failedTasks)) * 100, 1)
                : 0,
            'successRate' => $successTasks + $failedTasks > 0
                ? round(($successTasks / ($successTasks + $failedTasks)) * 100, 1)
                : 0,
        ];
    }

    protected function getRecentFailures(): array
    {
        return TaskExecution::query()
            ->where('status', 'failed')
            ->where('updated_at', '>=', now()->subDay())
            ->latest('updated_at')
            ->take(10)
            ->get()
            ->map(fn(TaskExecution $execution) => [
                'id' => $execution->id,
                'task_name' => $execution->task_name ?? TaskRegistry::getTask($execution->task_key)?->name ?? $execution->task_key,
                'model_type' => ucfirst($execution->entity_type),
                'model_id' => $execution->entity_id,
                'model_url' => $execution->entity_type === 'event' ? route('events.show', $execution->entity_id) : '#',
                'error' => $execution->error ?? 'Unknown error',
                'failed_at' => $execution->completed_at?->toIso8601String(),
            ])
            ->values()
            ->toArray();
    }

    protected function getTaskStatistics(): array
    {
        $since = now()->subDay();

        $counts = TaskExecution::query()
            ->where('updated_at', '>=', $since)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            (int) ($counts['pending'] ?? 0) + (int) ($counts['running'] ?? 0),
            (int) ($counts['failed'] ?? 0),
            (int) ($counts['success'] ?? 0),
        ];
    }

    public function retryFailure(string $failureId): void
    {
        $execution = TaskExecution::find($failureId);
        if (! $execution || $execution->entity_type !== 'event') {
            return;
        }

        $event = Event::find($execution->entity_id);
        if ($event) {
            App\Jobs\TaskPipeline\ProcessTaskPipelineJob::dispatch(
                model: $event,
                trigger: 'manual',
                taskFilter: [$execution->task_key],
                force: true,
            )->onQueue('tasks');

            session()->flash('message', 'Task queued for retry');
        }
    }
}; ?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Task Pipeline</flux:heading>

        <flux:button href="{{ route('admin.task-pipeline.registry') }}" variant="ghost">
            View Registry
        </flux:button>
    </div>

    @if (session('message'))
        <flux:banner variant="success">
            {{ session('message') }}
        </flux:banner>
    @endif

    {{-- Stats Overview --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <flux:card>
            <div class="space-y-2">
                <flux:subheading>Registered Tasks</flux:subheading>
                <div class="text-3xl font-bold">{{ $totalTasks }}</div>
                <div class="text-sm text-zinc-500">{{ $pluginTasks }} from plugins</div>
            </div>
        </flux:card>

        <flux:card>
            <div class="space-y-2">
                <flux:subheading>Tasks Pending</flux:subheading>
                <div class="text-3xl font-bold text-blue-600">{{ $pendingTasks }}</div>
                <div class="text-sm text-zinc-500">In queue</div>
            </div>
        </flux:card>

        <flux:card>
            <div class="space-y-2">
                <flux:subheading>Failed (24h)</flux:subheading>
                <div class="text-3xl font-bold text-red-600">{{ $failedTasks }}</div>
                <div class="text-sm text-zinc-500">{{ $failureRate }}% failure rate</div>
            </div>
        </flux:card>

        <flux:card>
            <div class="space-y-2">
                <flux:subheading>Success (24h)</flux:subheading>
                <div class="text-3xl font-bold text-green-600">{{ $successTasks }}</div>
                <div class="text-sm text-zinc-500">{{ $successRate }}% success rate</div>
            </div>
        </flux:card>
    </div>

    {{-- Task Registry Table --}}
    <flux:card>
        <flux:heading size="lg">Registered Tasks</flux:heading>

        <flux:table class="mt-4">
            <flux:columns>
                <flux:column>Task</flux:column>
                <flux:column>Applies To</flux:column>
                <flux:column>Dependencies</flux:column>
                <flux:column>Source</flux:column>
            </flux:columns>

            <flux:rows>
                @foreach ($tasks as $task)
                    <flux:row>
                        <flux:cell>
                            <div>
                                <div class="font-semibold">{{ $task->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $task->description }}</div>
                            </div>
                        </flux:cell>

                        <flux:cell>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($task->appliesTo as $type)
                                    <flux:badge size="sm">{{ $type }}</flux:badge>
                                @endforeach
                            </div>
                        </flux:cell>

                        <flux:cell>
                            @if (!empty($task->dependencies))
                                <div class="text-xs">{{ count($task->dependencies) }} dependencies</div>
                            @else
                                <span class="text-zinc-400">None</span>
                            @endif
                        </flux:cell>

                        <flux:cell>
                            @if ($task->registeredBy)
                                <flux:badge variant="outline" size="sm">Plugin</flux:badge>
                            @else
                                <span class="text-zinc-500">Core</span>
                            @endif
                        </flux:cell>
                    </flux:row>
                @endforeach
            </flux:rows>
        </flux:table>
    </flux:card>

    {{-- Recent Failures --}}
    @if (!empty($recentFailures))
        <flux:card>
            <flux:heading size="lg">Recent Failures</flux:heading>

            <flux:table class="mt-4">
                <flux:columns>
                    <flux:column>Task</flux:column>
                    <flux:column>Model</flux:column>
                    <flux:column>Error</flux:column>
                    <flux:column>When</flux:column>
                    <flux:column>Actions</flux:column>
                </flux:columns>

                <flux:rows>
                    @foreach ($recentFailures as $failure)
                        <flux:row>
                            <flux:cell>{{ $failure['task_name'] }}</flux:cell>

                            <flux:cell>
                                <a href="{{ $failure['model_url'] }}" class="text-blue-600 hover:underline">
                                    {{ $failure['model_type'] }} #{{ substr($failure['model_id'], 0, 8) }}
                                </a>
                            </flux:cell>

                            <flux:cell>
                                <div class="text-xs max-w-xs truncate" title="{{ $failure['error'] }}">
                                    {{ $failure['error'] }}
                                </div>
                            </flux:cell>

                            <flux:cell>
                                {{ $failure['failed_at'] ? \Carbon\Carbon::parse($failure['failed_at'])->diffForHumans() : 'Unknown' }}
                            </flux:cell>

                            <flux:cell>
                                <flux:button
                                    wire:click="retryFailure('{{ $failure['id'] }}')"
                                    size="sm"
                                    variant="ghost"
                                >
                                    Retry
                                </flux:button>
                            </flux:cell>
                        </flux:row>
                    @endforeach
                </flux:rows>
            </flux:table>
        </flux:card>
    @endif
</div>
