<?php

use App\Jobs\Outline\GenerateDayNotes;
use App\Models\{ActionProgress, Integration, IntegrationGroup};
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component {
    use Toast;

    public ?IntegrationGroup $group = null;

    public ?Integration $taskIntegration = null;

    public array $yearStatuses = [];

    public function mount(): void
    {
        // Find Outline IntegrationGroup (shared credentials)
        $this->group = IntegrationGroup::where('service', 'outline')
            ->where('user_id', Auth::id())
            ->first();

        if ($this->group) {
            // Get or create task integration for job dispatching
            $this->taskIntegration = Integration::where('integration_group_id', $this->group->id)
                ->where('instance_type', 'task')
                ->first();

            // Load generation status from ActionProgress
            $this->loadYearStatuses();
        }
    }

    protected function loadYearStatuses(): void
    {
        $years = [2026, 2027, 2028];

        foreach ($years as $year) {
            $progress = ActionProgress::where('user_id', Auth::id())
                ->where('action_type', 'outline_generate_daynotes')
                ->where('details->year', $year)
                ->latest()
                ->first();

            $status = 'not_started';
            if ($progress) {
                if ($progress->isCompleted()) {
                    $status = 'completed';
                } elseif ($progress->isFailed()) {
                    $status = 'failed';
                } elseif ($progress->isInProgress()) {
                    $status = 'in_progress';
                }
            }

            $this->yearStatuses[$year] = [
                'status' => $status,
                'progress' => $progress,
            ];
        }
    }

    public function generateYear(int $year): void
    {
        if (! $this->taskIntegration) {
            $this->error('No task integration found. Please configure Outline integration.');

            return;
        }

        // Create progress tracker
        $progress = ActionProgress::createProgress(
            (string) Auth::id(),
            'outline_generate_daynotes',
            "year_{$year}",
            'starting',
            "Generating day notes for {$year}",
            0,
            100,
            [
                'year' => $year,
                'integration_group_id' => $this->group->id,
                'total_months' => 12,
                'completed_months' => 0,
                'total_days' => $this->getDaysInYear($year),
                'completed_days' => 0,
            ]
        );

        // Dispatch job with progress ID
        GenerateDayNotes::dispatch($this->taskIntegration, $year, (string) $progress->id)
            ->onQueue('pull');

        $this->success("Started generating day notes for {$year}");
        $this->loadYearStatuses();
    }

    protected function getDaysInYear(int $year): int
    {
        return Carbon::create($year, 12, 31)->dayOfYear;
    }

    public function refreshStatus(): void
    {
        $this->loadYearStatuses();
        $this->success('Status refreshed');
    }
}; ?>

<div>
    <x-header title="Outline Day Notes" subtitle="Generate and manage day note documents" separator>
        <x-slot:actions>
            @if ($group)
                <a href="{{ $group->auth_metadata['api_url'] ?? config('services.outline.url') }}"
                   target="_blank"
                   class="btn btn-sm btn-outline">
                    <x-icon name="fas.arrow-up-right-from-square" class="w-4 h-4 mr-1" />
                    Open Outline
                </a>
            @endif
            <button wire:click="refreshStatus" class="btn btn-sm btn-outline">
                <x-icon name="o-arrow-path" class="w-4 h-4 mr-1" />
                Refresh
            </button>
        </x-slot:actions>
    </x-header>

    @if (!$group)
        <div class="alert alert-warning">
            <x-icon name="o-exclamation-triangle" class="w-6 h-6" />
            <div>
                <h3 class="font-bold">Outline integration not configured</h3>
                <p class="text-sm">Please configure your Outline integration first.</p>
            </div>
            <a href="{{ route('integrations.index') }}" class="btn btn-sm">
                Go to Integrations
            </a>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @foreach ([2026, 2027, 2028] as $year)
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="card-title">{{ $year }}</h3>

                        @php
                            $status = $yearStatuses[$year]['status'] ?? 'not_started';
                            $progress = $yearStatuses[$year]['progress'] ?? null;
                        @endphp

                        @if ($status === 'completed')
                            <div class="badge badge-success gap-1">
                                <x-icon name="o-check-circle" class="w-3 h-3" />
                                Generated
                            </div>
                            @if ($progress)
                                <p class="text-xs text-base-content/70">
                                    {{ $progress->updated_at->diffForHumans() }}
                                </p>
                            @endif
                        @elseif ($status === 'in_progress')
                            <div class="badge badge-warning gap-1">
                                <span class="loading loading-spinner loading-xs"></span>
                                In Progress
                            </div>
                            @if ($progress && isset($progress->details['completed_months']))
                                <progress
                                    class="progress progress-warning mt-2"
                                    value="{{ $progress->details['completed_months'] }}"
                                    max="12">
                                </progress>
                                <p class="text-xs mt-1">
                                    Month {{ $progress->details['completed_months'] }} of 12
                                    <span class="text-base-content/50">
                                        ({{ $progress->details['completed_days'] ?? 0 }}/{{ $progress->details['total_days'] ?? 0 }} days)
                                    </span>
                                </p>
                            @endif
                        @elseif ($status === 'failed')
                            <div class="badge badge-error gap-1">
                                <x-icon name="o-x-circle" class="w-3 h-3" />
                                Failed
                            </div>
                            @if ($progress)
                                <p class="text-xs text-error">
                                    {{ $progress->updated_at->diffForHumans() }}
                                </p>
                            @endif
                        @else
                            <div class="badge badge-ghost">Not Generated</div>
                        @endif

                        <button
                            wire:click="generateYear({{ $year }})"
                            wire:loading.attr="disabled"
                            class="btn btn-primary btn-sm mt-2"
                            @if ($status === 'in_progress') disabled @endif>
                            <span wire:loading.remove wire:target="generateYear({{ $year }})">
                                @if ($status === 'completed')
                                    Regenerate
                                @elseif ($status === 'failed')
                                    Retry
                                @else
                                    Generate
                                @endif
                            </span>
                            <span wire:loading wire:target="generateYear({{ $year }})"
                                  class="loading loading-spinner loading-sm"></span>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        @if (!$taskIntegration)
            <div class="alert alert-warning mt-4">
                <x-icon name="o-exclamation-triangle" class="w-6 h-6" />
                <div>
                    <h3 class="font-bold">No task integration found</h3>
                    <p class="text-sm">You must create an Outline task integration before you can generate day notes. Please configure your Outline integration with instance type "task".</p>
                </div>
                <a href="{{ route('integrations.index') }}" class="btn btn-sm">
                    Configure Integration
                </a>
            </div>
        @endif
    @endif
</div>
