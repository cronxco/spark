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
    public string $activeTab = 'settings';
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

        // Generate time options (every hour from 00:00 to 23:00)
        $this->timeOptions = collect(range(0, 23))
            ->mapWithKeys(fn($hour) => [
                sprintf('%02d:00', $hour) => sprintf('%02d:00', $hour)
            ])
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

        <x-tab name="memory" label="Memory" icon="o-cpu-chip">
    <div class="space-y-4 lg:space-y-6">
        {{-- Memory Overview --}}
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <h3 class="text-lg font-semibold mb-4">{{ __('Memory Overview') }}</h3>
                <p class="text-sm text-base-content/70 mb-4">
                    {{ __('View what Flint remembers about your data and how it learns from your feedback.') }}
                </p>

                {{-- Last Execution Times --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="stat bg-base-100 rounded-lg">
                        <div class="stat-title">{{ __('Last Digest') }}</div>
                        <div class="stat-value text-lg">
                            @if($workingMemory['last_execution']['digest'] ?? null)
                                {{ \Carbon\Carbon::parse($workingMemory['last_execution']['digest'])->diffForHumans() }}
                            @else
                                {{ __('Never') }}
                            @endif
                        </div>
                    </div>
                    <div class="stat bg-base-100 rounded-lg">
                        <div class="stat-title">{{ __('Patterns Detected') }}</div>
                        <div class="stat-value text-lg">{{ count($patterns) }}</div>
                        <div class="stat-desc">{{ __('Last 90 days') }}</div>
                    </div>
                    <div class="stat bg-base-100 rounded-lg">
                        <div class="stat-title">{{ __('Feedback Given') }}</div>
                        <div class="stat-value text-lg">{{ $feedbackStats['total_feedback_count'] ?? 0 }}</div>
                        <div class="stat-desc">
                            @if(($feedbackStats['rating_average'] ?? 0) > 0)
                                {{ __('Avg rating:') }} {{ number_format($feedbackStats['rating_average'], 1) }}/5
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Domain Insights --}}
        @if(!empty($workingMemory['domain_insights']))
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <h3 class="text-lg font-semibold mb-4">{{ __('Current Domain Insights') }}</h3>
                <p class="text-sm text-base-content/70 mb-4">
                    {{ __('Active insights from each domain agent in working memory.') }}
                </p>

                <div class="space-y-4">
                    @foreach($workingMemory['domain_insights'] as $domain => $insight)
                        @if($insight)
                        <div class="collapse collapse-arrow bg-base-100">
                            <input type="checkbox" />
                            <div class="collapse-title font-medium flex items-center gap-2">
                                <span class="badge badge-primary badge-sm">{{ ucfirst($domain) }}</span>
                                @if(isset($insight['last_updated']))
                                    <span class="text-xs text-base-content/60">
                                        {{ __('Updated') }} {{ \Carbon\Carbon::parse($insight['last_updated'])->diffForHumans() }}
                                    </span>
                                @endif
                            </div>
                            <div class="collapse-content">
                                @if(!empty($insight['insights']))
                                    <div class="space-y-2 mb-3">
                                        <div class="text-sm font-semibold">{{ __('Insights:') }}</div>
                                        @foreach($insight['insights'] as $item)
                                            <div class="alert alert-info p-2">
                                                <div class="flex-1">
                                                    <div class="text-sm font-medium">{{ $item['title'] ?? 'Insight' }}</div>
                                                    <div class="text-xs">{{ $item['description'] ?? '' }}</div>
                                                    @if(isset($item['confidence']))
                                                        <div class="badge badge-xs mt-1">{{ __('Confidence:') }} {{ round($item['confidence'] * 100) }}%</div>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                @if(!empty($insight['suggestions']))
                                    <div class="space-y-2">
                                        <div class="text-sm font-semibold">{{ __('Suggestions:') }}</div>
                                        @foreach($insight['suggestions'] as $suggestion)
                                            <div class="alert alert-success p-2">
                                                <div class="flex-1">
                                                    <div class="text-sm font-medium">{{ $suggestion['title'] ?? 'Suggestion' }}</div>
                                                    <div class="text-xs">{{ $suggestion['description'] ?? '' }}</div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        {{-- Prioritized Actions --}}
        @if(!empty($workingMemory['actions']))
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <h3 class="text-lg font-semibold mb-4">{{ __('Prioritized Actions') }}</h3>
                <div class="space-y-2">
                    @foreach($workingMemory['actions'] as $action)
                        <div class="alert {{ $action['priority'] === 'high' ? 'alert-warning' : 'alert-info' }} p-3">
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="text-sm font-medium">{{ $action['title'] ?? 'Action' }}</span>
                                    <span class="badge badge-sm">{{ ucfirst($action['priority'] ?? 'medium') }}</span>
                                </div>
                                <div class="text-xs">{{ $action['description'] ?? '' }}</div>
                                @if(!empty($action['source_domains']))
                                    <div class="flex gap-1 mt-1">
                                        @foreach($action['source_domains'] as $domain)
                                            <span class="badge badge-xs">{{ ucfirst($domain) }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        {{-- Detected Patterns --}}
        @if(!empty($patterns))
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <h3 class="text-lg font-semibold mb-4">{{ __('Detected Patterns') }}</h3>
                <p class="text-sm text-base-content/70 mb-4">
                    {{ __('Long-term patterns discovered by analyzing your historical data.') }}
                </p>
                <div class="space-y-2">
                    @foreach(array_slice($patterns, 0, 10) as $pattern)
                        <div class="collapse collapse-arrow bg-base-100">
                            <input type="checkbox" />
                            <div class="collapse-title font-medium text-sm">
                                {{ $pattern['title'] ?? 'Pattern' }}
                                @if(isset($pattern['metadata']['confidence']))
                                    <span class="badge badge-xs ml-2">{{ round($pattern['metadata']['confidence'] * 100) }}%</span>
                                @endif
                            </div>
                            <div class="collapse-content">
                                <div class="text-xs text-base-content/70">
                                    {{ $pattern['metadata']['description'] ?? '' }}
                                </div>
                                @if(!empty($pattern['metadata']['pattern_type']))
                                    <div class="badge badge-sm mt-2">{{ ucfirst($pattern['metadata']['pattern_type']) }}</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        {{-- Empty State --}}
        @if(empty($workingMemory['domain_insights']) && empty($workingMemory['actions']) && empty($patterns))
        <div class="card bg-base-200 shadow">
            <div class="card-body text-center py-12">
                <x-icon name="o-cpu-chip" class="w-16 h-16 mx-auto text-base-content/30 mb-4" />
                <h3 class="text-lg font-semibold mb-2">{{ __('No Memory Data Yet') }}</h3>
                <p class="text-sm text-base-content/60">
                    {{ __('Flint will start building memory as it analyzes your data and generates digests.') }}
                </p>
            </div>
        </div>
        @endif
    </div>
        </x-tab>
    </x-tabs>
</div>
