<?php

use App\Services\TaskPipeline\TaskRegistry;
use App\Models\Event;
use App\Models\Block;
use App\Models\EventObject;
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
        $failures = [];
        $since = now()->subDay();

        // Check Events
        foreach (Event::where('updated_at', '>=', $since)->get() as $event) {
            $executions = $event->event_metadata['task_executions'] ?? [];
            foreach ($executions as $taskKey => $execution) {
                if (($execution['last_attempt']['status'] ?? null) === 'failed') {
                    $failures[] = [
                        'id' => $event->id . '-' . $taskKey,
                        'task_name' => TaskRegistry::getTask($taskKey)?->name ?? $taskKey,
                        'model_type' => 'Event',
                        'model_id' => $event->id,
                        'model_url' => route('events.show', $event), // Adjust route as needed
                        'error' => $execution['last_attempt']['error'] ?? 'Unknown error',
                        'failed_at' => $execution['last_attempt']['completed_at'] ?? null,
                    ];
                }
            }
        }

        return collect($failures)->sortByDesc('failed_at')->take(10)->values()->toArray();
    }

    protected function getTaskStatistics(): array
    {
        $pending = 0;
        $failed = 0;
        $success = 0;
        $since = now()->subDay();

        // Count from Events
        foreach (Event::where('updated_at', '>=', $since)->get() as $event) {
            $executions = $event->event_metadata['task_executions'] ?? [];
            foreach ($executions as $execution) {
                $status = $execution['last_attempt']['status'] ?? null;
                match($status) {
                    'pending', 'running' => $pending++,
                    'failed' => $failed++,
                    'success' => $success++,
                    default => null,
                };
            }
        }

        return [$pending, $failed, $success];
    }

    public function retryFailure(string $failureId): void
    {
        [$modelId, $taskKey] = explode('-', $failureId, 2);

        $event = Event::find($modelId);
        if ($event) {
            \App\Jobs\TaskPipeline\ProcessTaskPipelineJob::dispatch(
                model: $event,
                trigger: 'manual',
                taskFilter: [$taskKey],
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

    @if(session('message'))
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
                @foreach($tasks as $task)
                    <flux:row>
                        <flux:cell>
                            <div>
                                <div class="font-semibold">{{ $task->name }}</div>
                                <div class="text-xs text-zinc-500">{{ $task->description }}</div>
                            </div>
                        </flux:cell>

                        <flux:cell>
                            <div class="flex flex-wrap gap-1">
                                @foreach($task->appliesTo as $type)
                                    <flux:badge size="sm">{{ $type }}</flux:badge>
                                @endforeach
                            </div>
                        </flux:cell>

                        <flux:cell>
                            @if(!empty($task->dependencies))
                                <div class="text-xs">{{ count($task->dependencies) }} dependencies</div>
                            @else
                                <span class="text-zinc-400">None</span>
                            @endif
                        </flux:cell>

                        <flux:cell>
                            @if($task->registeredBy)
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
    @if(!empty($recentFailures))
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
                    @foreach($recentFailures as $failure)
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
