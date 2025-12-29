<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public bool $digestsEnabled = true;
    public string $scheduleTimezone = 'Europe/London';
    public array $scheduleTimesWeekday = ['06:00', '18:00'];
    public array $scheduleTimesWeekend = ['08:00', '19:00'];
    public array $enabledDomains = ['health', 'money', 'media', 'knowledge', 'online'];

    // UI state
    public string $activeTab = 'digest';
    public string $newWeekdayTime = '09:00';
    public string $newWeekendTime = '09:00';

    // Memory data
    public array $workingMemory = [];
    public array $feedbackStats = [];
    public array $patterns = [];
    public array $recentInsights = [];

    public array $availableDomains = [
        'health' => [
            'label' => 'Health & Fitness',
            'description' => 'Sleep, exercise, heart rate, and wellness metrics',
        ],
        'money' => [
            'label' => 'Money & Finance',
            'description' => 'Spending patterns, transactions, and financial insights',
        ],
        'media' => [
            'label' => 'Media & Entertainment',
            'description' => 'Music listening, movies, and media consumption',
        ],
        'knowledge' => [
            'label' => 'Knowledge & Learning',
            'description' => 'Articles, notes, and learning patterns',
        ],
        'online' => [
            'label' => 'Online & Productivity',
            'description' => 'Tasks, projects, and digital productivity',
        ],
    ];

    public array $timezones = [
        'UTC' => 'UTC',
        'Europe/London' => 'London',
        'Europe/Paris' => 'Paris',
        'America/New_York' => 'New York',
        'America/Chicago' => 'Chicago',
        'America/Los_Angeles' => 'Los Angeles',
        'America/Toronto' => 'Toronto',
        'Australia/Sydney' => 'Sydney',
        'Asia/Tokyo' => 'Tokyo',
    ];

    public array $timeOptions = [];

    public function mount(): void
    {
        $user = Auth::user();
        $settings = $user->settings['flint'] ?? [];

        $this->digestsEnabled = $settings['digests_enabled'] ?? true;
        $this->scheduleTimezone = $settings['schedule_timezone'] ?? 'Europe/London';
        $this->scheduleTimesWeekday = $settings['schedule_times_weekday'] ?? ['06:00', '18:00'];
        $this->scheduleTimesWeekend = $settings['schedule_times_weekend'] ?? ['08:00', '19:00'];
        $this->enabledDomains = $settings['enabled_domains'] ?? ['health', 'money', 'media', 'knowledge', 'online'];

        // Generate time options (every 15 minutes from 00:00 to 23:45)
        $this->timeOptions = collect(range(0, 23))
            ->flatMap(function ($hour) {
                return collect([0, 15, 30, 45])
                    ->mapWithKeys(function ($minute) use ($hour) {
                        $time = sprintf('%02d:%02d', $hour, $minute);
                        return [$time => $time];
                    });
            })
            ->toArray();

        // Load memory data if on memory tab
        if ($this->activeTab === 'memory') {
            $this->loadMemoryData();
        }
    }

    public function loadMemoryData(): void
    {
        $user = Auth::user();
        $workingMemoryService = app(\App\Services\AgentWorkingMemoryService::class);
        $memoryService = app(\App\Services\AgentMemoryService::class);

        // Load working memory data
        $this->feedbackStats = $workingMemoryService->getFeedbackStatistics($user->id);
        $this->workingMemory = [
            'domain_insights' => $workingMemoryService->getAllDomainInsights($user->id),
            'cross_domain' => $workingMemoryService->getCrossDomainObservations($user->id, 10),
            'urgent_flags' => $workingMemoryService->getUnresolvedUrgentFlags($user->id),
            'actions' => $workingMemoryService->getPrioritizedActions($user->id),
            'last_execution' => [
                'pre_digest' => $workingMemoryService->getLastExecutionTime($user->id, 'pre_digest_refresh'),
                'digest' => $workingMemoryService->getLastExecutionTime($user->id, 'digest_generation'),
                'patterns' => $workingMemoryService->getLastExecutionTime($user->id, 'pattern_detection'),
            ],
        ];

        // Load long-term memory data
        $this->patterns = $memoryService->getPatterns($user->id, 90);
        $this->recentInsights = $memoryService->getAllInsightBlocks($user->id, 7);
    }

    public function updatedActiveTab(): void
    {
        if ($this->activeTab === 'memory') {
            $this->loadMemoryData();
        }
    }

    public function addWeekdayTime(): void
    {
        if (!in_array($this->newWeekdayTime, $this->scheduleTimesWeekday)) {
            $this->scheduleTimesWeekday[] = $this->newWeekdayTime;
            sort($this->scheduleTimesWeekday);
        }
    }

    public function removeWeekdayTime(string $time): void
    {
        $this->scheduleTimesWeekday = array_values(
            array_filter($this->scheduleTimesWeekday, fn($t) => $t !== $time)
        );
    }

    public function addWeekendTime(): void
    {
        if (!in_array($this->newWeekendTime, $this->scheduleTimesWeekend)) {
            $this->scheduleTimesWeekend[] = $this->newWeekendTime;
            sort($this->scheduleTimesWeekend);
        }
    }

    public function removeWeekendTime(string $time): void
    {
        $this->scheduleTimesWeekend = array_values(
            array_filter($this->scheduleTimesWeekend, fn($t) => $t !== $time)
        );
    }

    public function toggleDomain(string $domain): void
    {
        if (in_array($domain, $this->enabledDomains)) {
            $this->enabledDomains = array_values(
                array_filter($this->enabledDomains, fn($d) => $d !== $domain)
            );
        } else {
            $this->enabledDomains[] = $domain;
        }
    }

    public function save(): void
    {
        $user = Auth::user();

        $settings = $user->settings;
        $settings['flint'] = [
            'digests_enabled' => $this->digestsEnabled,
            'schedule_timezone' => $this->scheduleTimezone,
            'schedule_times_weekday' => $this->scheduleTimesWeekday,
            'schedule_times_weekend' => $this->scheduleTimesWeekend,
            'enabled_domains' => $this->enabledDomains,
        ];

        $user->settings = $settings;
        $user->save();

        $this->success('Flint settings saved successfully!');
    }
}; ?>

<div>
    <x-header title="{{ __('Flint Settings') }}" subtitle="{{ __('Configure your AI assistant preferences') }}" separator />

    {{-- Tabs --}}
    <x-tabs wire:model="activeTab">
        <x-tab name="digest" label="Digest" icon="o-newspaper">
            <div class="space-y-4 lg:space-y-6">
                {{-- Placeholder for Digest content --}}
                <div class="card bg-base-200 shadow">
                    <div class="card-body text-center py-12">
                        <x-icon name="o-newspaper" class="w-16 h-16 mx-auto text-base-content/30 mb-4" />
                        <h3 class="text-lg font-semibold mb-2">{{ __('Latest Digest') }}</h3>
                        <p class="text-sm text-base-content/60">
                            {{ __('Your most recent AI-generated digest will appear here.') }}
                        </p>
                    </div>
                </div>
            </div>
        </x-tab>

        <x-tab name="coach" label="Coach" icon="fas.dumbbell">
            <div class="space-y-4 lg:space-y-6">
                {{-- Coach Status Card --}}
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">{{ __('Hevy Fitness Coach') }}</h3>
                        <p class="text-sm text-base-content/70 mb-4">
                            {{ __('Automatically analyzes your workouts and updates your Hevy routine with progressive overload recommendations.') }}
                        </p>

                        @php
                        $hevyIntegration = \App\Models\Integration::where('user_id', Auth::id())
                            ->where('service', 'hevy')
                            ->where('instance_type', 'workouts')
                            ->first();

                        $coachEnabled = $hevyIntegration && ($hevyIntegration->configuration['coach_enabled'] ?? false);

                        $lastAnalysis = null;
                        $recommendationCount = 0;

                        if ($hevyIntegration) {
                            $lastAnalysis = \App\Models\Event::where('integration_id', $hevyIntegration->id)
                                ->where('action', 'had_coach_recommendation')
                                ->latest('time')
                                ->first();

                            if ($lastAnalysis) {
                                $recommendationCount = \App\Models\Block::whereHas('event', function($q) use ($hevyIntegration) {
                                    $q->where('integration_id', $hevyIntegration->id)
                                      ->where('action', 'had_coach_recommendation');
                                })
                                ->where('block_type', 'coach_recommendation')
                                ->where('created_at', '>=', now()->subDays(7))
                                ->count();
                            }
                        }
                        @endphp

                        @if (!$hevyIntegration)
                        <div class="alert alert-info">
                            <x-icon name="o-information-circle" class="w-5 h-5" />
                            <div>
                                <div class="font-medium">{{ __('No Hevy Integration Found') }}</div>
                                <div class="text-sm">{{ __('Connect your Hevy account to use the fitness coach.') }}</div>
                            </div>
                        </div>
                        @else
                        {{-- Stats --}}
                        <div class="stats stats-vertical lg:stats-horizontal shadow mt-4 mb-4">
                            <div class="stat bg-base-100">
                                <div class="stat-title">{{ __('Last Analysis') }}</div>
                                <div class="stat-value text-sm">
                                    {{ $lastAnalysis ? $lastAnalysis->time->diffForHumans() : __('Never') }}
                                </div>
                            </div>

                            <div class="stat bg-base-100">
                                <div class="stat-title">{{ __('Recommendations') }}</div>
                                <div class="stat-value text-sm">
                                    {{ $recommendationCount }}
                                </div>
                                <div class="stat-desc">{{ __('Last 7 days') }}</div>
                            </div>

                            <div class="stat bg-base-100">
                                <div class="stat-title">{{ __('Coach Status') }}</div>
                                <div class="stat-value text-sm">
                                    @if ($coachEnabled)
                                        <span class="text-success">{{ __('Enabled') }}</span>
                                    @else
                                        <span class="text-base-content/50">{{ __('Disabled') }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Quick Actions --}}
                        <div class="card-actions justify-end">
                            <a href="{{ route('integrations.show', $hevyIntegration) }}" class="btn btn-outline btn-sm">
                                <x-icon name="o-cog-6-tooth" class="w-4 h-4" />
                                {{ __('Configure') }}
                            </a>
                            @if ($coachEnabled)
                            <button
                                class="btn btn-primary btn-sm"
                                onclick="alert('Manual trigger coming soon! This will dispatch the analyze effect.')"
                            >
                                <x-icon name="fas.chart-line" class="w-4 h-4" />
                                {{ __('Analyze Now') }}
                            </button>
                            <button
                                class="btn btn-success btn-sm"
                                onclick="alert('Manual trigger coming soon! This will dispatch the auto-coach effect.')"
                            >
                                <x-icon name="fas.robot" class="w-4 h-4" />
                                {{ __('Auto-Coach') }}
                            </button>
                            @endif
                        </div>
                        @endif
                    </div>
                </div>

                {{-- Recent Recommendations --}}
                @if ($hevyIntegration && $recommendationCount > 0)
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">{{ __('Recent Recommendations') }}</h3>
                        <p class="text-sm text-base-content/70 mb-4">
                            {{ __('Latest progression recommendations from your fitness coach') }}
                        </p>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            @php
                            $recentBlocks = \App\Models\Block::whereHas('event', function($q) use ($hevyIntegration) {
                                $q->where('integration_id', $hevyIntegration->id)
                                  ->where('action', 'had_coach_recommendation');
                            })
                            ->where('block_type', 'coach_recommendation')
                            ->where('created_at', '>=', now()->subDays(7))
                            ->latest()
                            ->limit(6)
                            ->get();
                            @endphp

                            @foreach ($recentBlocks as $block)
                                <x-block-card :block="$block" />
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </x-tab>

        <x-tab name="memory" label="Memory" icon="o-cpu-chip">
            @include('livewire.flint.memory')
        </x-tab>

        <x-tab name="settings" label="Settings" icon="o-cog-6-tooth">
    <div class="space-y-4 lg:space-y-6">
        {{-- General Settings --}}
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <h3 class="text-lg font-semibold mb-4">{{ __('General Settings') }}</h3>
                <p class="text-sm text-base-content/70 mb-4">
                    {{ __('Configure how Flint analyzes your data and delivers insights. Agents run 15 minutes before each scheduled digest.') }}
                </p>

                <div class="space-y-3">
                    <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                        <div>
                            <div class="font-medium text-sm">{{ __('Enable Daily Digests') }}</div>
                            <div class="text-xs text-base-content/60">{{ __('Receive scheduled digest notifications with AI-generated insights') }}</div>
                        </div>
                        <input
                            type="checkbox"
                            class="toggle toggle-primary"
                            wire:model.live="digestsEnabled"
                        />
                    </div>
                </div>
            </div>
        </div>

        {{-- Digest Schedule --}}
        @if ($digestsEnabled)
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <h3 class="text-lg font-semibold mb-4">{{ __('Digest Schedule') }}</h3>
                <p class="text-sm text-base-content/70 mb-4">
                    {{ __('Choose when you want to receive daily digests') }}
                </p>

                <div class="space-y-4">
                    {{-- Timezone --}}
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">{{ __('Timezone') }}</span>
                        </label>
                        <select wire:model.live="scheduleTimezone" class="select select-bordered w-full">
                            @foreach ($timezones as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Weekday Times --}}
                    <div>
                        <label class="label">
                            <span class="label-text">{{ __('Weekday Times (Mon-Fri)') }}</span>
                        </label>
                        <div class="space-y-2 mb-3">
                            @foreach ($scheduleTimesWeekday as $time)
                                <div class="flex items-center justify-between p-2 bg-base-100 rounded-lg">
                                    <span class="text-sm">{{ $time }}</span>
                                    <button
                                        type="button"
                                        wire:click="removeWeekdayTime('{{ $time }}')"
                                        class="btn btn-ghost btn-xs"
                                    >
                                        <x-icon name="fas.trash" class="w-3 h-3" />
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex gap-2">
                            <select wire:model="newWeekdayTime" class="select select-bordered flex-1">
                                @foreach ($timeOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <button wire:click="addWeekdayTime" class="btn btn-outline btn-sm">
                                {{ __('Add') }}
                            </button>
                        </div>
                    </div>

                    {{-- Weekend Times --}}
                    <div>
                        <label class="label">
                            <span class="label-text">{{ __('Weekend Times (Sat-Sun)') }}</span>
                        </label>
                        <div class="space-y-2 mb-3">
                            @foreach ($scheduleTimesWeekend as $time)
                                <div class="flex items-center justify-between p-2 bg-base-100 rounded-lg">
                                    <span class="text-sm">{{ $time }}</span>
                                    <button
                                        type="button"
                                        wire:click="removeWeekendTime('{{ $time }}')"
                                        class="btn btn-ghost btn-xs"
                                    >
                                        <x-icon name="fas.trash" class="w-3 h-3" />
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex gap-2">
                            <select wire:model="newWeekendTime" class="select select-bordered flex-1">
                                @foreach ($timeOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <button wire:click="addWeekendTime" class="btn btn-outline btn-sm">
                                {{ __('Add') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- Analysis Domains --}}
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <h3 class="text-lg font-semibold mb-4">{{ __('Analysis Domains') }}</h3>
                <p class="text-sm text-base-content/70 mb-4">
                    {{ __('Choose which domains Flint should analyze') }}
                </p>

                <div class="space-y-3">
                    @foreach ($availableDomains as $key => $domain)
                        <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                            <div>
                                <div class="font-medium text-sm">{{ $domain['label'] }}</div>
                                <div class="text-xs text-base-content/60">{{ $domain['description'] }}</div>
                            </div>
                            <input
                                type="checkbox"
                                class="toggle toggle-primary"
                                wire:model="enabledDomains"
                                value="{{ $key }}"
                            />
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Save Button --}}
        <div class="flex justify-end">
            <x-button
                label="{{ __('Save Settings') }}"
                wire:click="save"
                class="btn-primary"
                spinner="save"
            />
        </div>
    </div>
        </x-tab>
    </x-tabs>
</div>
