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

    // Digest data
    public ?array $latestDigest = null;
    public array $digestArchive = [];
    public bool $showArchive = false;
    public ?string $expandedArchiveDigestId = null;

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

        // Load data based on active tab
        if ($this->activeTab === 'memory') {
            $this->loadMemoryData();
        } elseif ($this->activeTab === 'digest') {
            $this->loadDigestData();
        }
    }

    public function loadDigestData(): void
    {
        $user = Auth::user();

        // Load latest digest
        $latestDigestBlock = \App\Models\Block::where('block_type', 'flint_digest')
            ->whereHas('event', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->where('service', 'flint');
            })
            ->with(['event'])
            ->latest('time')
            ->first();

        if ($latestDigestBlock) {
            $this->latestDigest = [
                'id' => $latestDigestBlock->id,
                'time' => $latestDigestBlock->time,
                'metadata' => $latestDigestBlock->metadata,
            ];
        }

        // Load archive (past 30 days, excluding latest)
        $this->digestArchive = \App\Models\Block::where('block_type', 'flint_digest')
            ->whereHas('event', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->where('service', 'flint');
            })
            ->when($latestDigestBlock, fn($query) => $query->where('id', '!=', $latestDigestBlock->id))
            ->where('time', '>=', now()->subDays(30))
            ->with(['event'])
            ->latest('time')
            ->get()
            ->map(function ($block) {
                return [
                    'id' => $block->id,
                    'time' => $block->time,
                    'metadata' => $block->metadata,
                ];
            })
            ->toArray();
    }

    public function toggleArchive(): void
    {
        $this->showArchive = !$this->showArchive;
        if ($this->showArchive && empty($this->digestArchive) && empty($this->latestDigest)) {
            $this->loadDigestData();
        }
    }

    public function expandArchiveDigest(string $digestId): void
    {
        $this->expandedArchiveDigestId = $this->expandedArchiveDigestId === $digestId ? null : $digestId;
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
        } elseif ($this->activeTab === 'digest') {
            $this->loadDigestData();
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
                @if ($latestDigest)
                    {{-- Latest Digest --}}
                    <div class="card bg-base-200 shadow">
                        <div class="card-body p-6 space-y-6">
                            {{-- Header --}}
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-2">
                                        <x-icon name="fas.newspaper" class="w-5 h-5 text-primary" />
                                        <span class="text-xs font-medium text-base-content/60 uppercase tracking-wide">
                                            {{ $latestDigest['time']->format('l, F j, Y') }}
                                        </span>
                                    </div>
                                    <h2 class="text-2xl font-bold text-base-content">
                                        {{ $latestDigest['metadata']['headline'] ?? 'Daily Digest' }}
                                    </h2>
                                    <p class="text-sm text-base-content/60 mt-1">
                                        Generated {{ $latestDigest['time']->diffForHumans() }}
                                    </p>
                                </div>
                                <div class="badge badge-primary badge-lg">
                                    {{ $latestDigest['metadata']['metrics']['total_insights'] ?? 0 }} insights
                                </div>
                            </div>

                            {{-- Top Insights --}}
                            @if (!empty($latestDigest['metadata']['top_insights']))
                            <div>
                                <h3 class="text-sm font-semibold text-base-content/80 uppercase tracking-wide mb-3 flex items-center gap-2">
                                    <x-icon name="fas.lightbulb" class="w-4 h-4 text-warning" />
                                    Key Insights
                                </h3>
                                <div class="space-y-3">
                                    @foreach ($latestDigest['metadata']['top_insights'] as $index => $insight)
                                        <div class="card bg-base-100 shadow-sm">
                                            <div class="card-body p-4">
                                                <div class="flex items-start gap-3">
                                                    <div class="text-2xl mt-1">{{ $insight['icon'] ?? '💡' }}</div>
                                                    <div class="flex-1 space-y-1">
                                                        <h4 class="font-semibold text-base">{{ $insight['title'] ?? 'Insight ' . ($index + 1) }}</h4>
                                                        <p class="text-sm text-base-content/80 leading-relaxed">{{ $insight['description'] ?? '' }}</p>
                                                        @if (!empty($insight['action_needed']) && $insight['action_needed'] !== 'None')
                                                            <div class="flex items-center gap-2 mt-2 text-xs text-primary">
                                                                <x-icon name="fas.arrow-right" class="w-3 h-3" />
                                                                <span class="font-medium">{{ $insight['action_needed'] }}</span>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            @endif

                            {{-- Wins --}}
                            @if (!empty($latestDigest['metadata']['wins']))
                            <div>
                                <h3 class="text-sm font-semibold text-success uppercase tracking-wide mb-3 flex items-center gap-2">
                                    <x-icon name="fas.check-circle" class="w-4 h-4" />
                                    Wins
                                </h3>
                                <div class="space-y-2">
                                    @foreach ($latestDigest['metadata']['wins'] as $win)
                                        <div class="flex items-start gap-2 p-3 bg-success/10 rounded-lg">
                                            <x-icon name="fas.check" class="w-4 h-4 text-success mt-0.5 flex-shrink-0" />
                                            <span class="text-sm text-base-content/90">{{ $win }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            @endif

                            {{-- Watch Points --}}
                            @if (!empty($latestDigest['metadata']['watch_points']))
                            <div>
                                <h3 class="text-sm font-semibold text-warning uppercase tracking-wide mb-3 flex items-center gap-2">
                                    <x-icon name="fas.exclamation-triangle" class="w-4 h-4" />
                                    Watch Points
                                </h3>
                                <div class="space-y-2">
                                    @foreach ($latestDigest['metadata']['watch_points'] as $watchPoint)
                                        <div class="flex items-start gap-2 p-3 bg-warning/10 rounded-lg">
                                            <x-icon name="fas.exclamation-circle" class="w-4 h-4 text-warning mt-0.5 flex-shrink-0" />
                                            <span class="text-sm text-base-content/90">{{ $watchPoint }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            @endif

                            {{-- Tomorrow Focus --}}
                            @if (!empty($latestDigest['metadata']['tomorrow_focus']))
                            <div>
                                <h3 class="text-sm font-semibold text-info uppercase tracking-wide mb-3 flex items-center gap-2">
                                    <x-icon name="fas.calendar" class="w-4 h-4" />
                                    Tomorrow's Focus
                                </h3>
                                <div class="space-y-2">
                                    @foreach ($latestDigest['metadata']['tomorrow_focus'] as $focus)
                                        <div class="flex items-start gap-2 p-3 bg-info/10 rounded-lg">
                                            <x-icon name="fas.arrow-right" class="w-4 h-4 text-info mt-0.5 flex-shrink-0" />
                                            <span class="text-sm text-base-content/90">{{ $focus }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            @endif

                            {{-- Footer Stats --}}
                            <div class="pt-4 border-t border-base-300">
                                <div class="flex items-center justify-between text-xs text-base-content/50">
                                    <span>
                                        {{ $latestDigest['metadata']['metrics']['total_insights'] ?? 0 }} insights analyzed •
                                        {{ $latestDigest['metadata']['metrics']['cross_domain_connections'] ?? 0 }} patterns detected •
                                        {{ $latestDigest['metadata']['metrics']['recommended_actions'] ?? 0 }} actions recommended
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Archive Toggle --}}
                    <div class="flex justify-center">
                        <button
                            wire:click="toggleArchive"
                            class="btn btn-outline btn-sm"
                        >
                            <x-icon name="fas.clock" class="w-4 h-4" />
                            {{ $showArchive ? 'Hide Archive' : 'View Past Digests' }}
                            @if (!empty($digestArchive))
                                <span class="badge badge-sm">{{ count($digestArchive) }}</span>
                            @endif
                        </button>
                    </div>

                    {{-- Archive --}}
                    @if ($showArchive)
                        <div class="space-y-3">
                            <h3 class="text-lg font-semibold">Past 30 Days</h3>
                            @forelse ($digestArchive as $digest)
                                <div class="card bg-base-200 shadow">
                                    <div class="card-body p-4">
                                        <div class="flex items-start justify-between gap-4">
                                            <div class="flex-1">
                                                <div class="flex items-center gap-2 mb-1">
                                                    <span class="text-xs font-medium text-base-content/60">
                                                        {{ $digest['time']->format('M j, Y g:i A') }}
                                                    </span>
                                                    <span class="badge badge-xs badge-ghost">
                                                        {{ $digest['metadata']['metrics']['total_insights'] ?? 0 }} insights
                                                    </span>
                                                </div>
                                                <h4 class="font-medium text-sm">{{ $digest['metadata']['headline'] ?? 'Daily Digest' }}</h4>

                                                {{-- Expandable content --}}
                                                @if ($expandedArchiveDigestId === $digest['id'])
                                                    <div class="mt-3 space-y-3 text-sm">
                                                        @if (!empty($digest['metadata']['top_insights']))
                                                            <div class="space-y-2">
                                                                @foreach (array_slice($digest['metadata']['top_insights'], 0, 2) as $insight)
                                                                    <div class="flex items-start gap-2">
                                                                        <span>{{ $insight['icon'] ?? '💡' }}</span>
                                                                        <span class="text-base-content/80">{{ $insight['title'] ?? '' }}</span>
                                                                    </div>
                                                                @endforeach
                                                            </div>
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                            <button
                                                wire:click="expandArchiveDigest('{{ $digest['id'] }}')"
                                                class="btn btn-ghost btn-xs"
                                            >
                                                <x-icon name="fas.chevron-{{ $expandedArchiveDigestId === $digest['id'] ? 'up' : 'down' }}" class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="text-center py-8 text-base-content/50 text-sm">
                                    No archive digests found
                                </div>
                            @endforelse
                        </div>
                    @endif
                @else
                    {{-- Empty State --}}
                    <div class="card bg-base-200 shadow">
                        <div class="card-body text-center py-12">
                            <x-icon name="fas.newspaper" class="w-16 h-16 mx-auto text-base-content/30 mb-4" />
                            <h3 class="text-lg font-semibold mb-2">{{ __('No Digests Yet') }}</h3>
                            <p class="text-sm text-base-content/60 mb-4">
                                {{ __('Your AI-generated digests will appear here once Flint starts analyzing your data.') }}
                            </p>
                            <p class="text-xs text-base-content/50">
                                {{ __('Digests are generated based on your schedule in Settings.') }}
                            </p>
                        </div>
                    </div>
                @endif
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
                            <a href="{{ route('integrations.details', $hevyIntegration) }}" class="btn btn-outline btn-sm">
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
