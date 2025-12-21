<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public bool $digestsEnabled = true;
    public bool $continuousAnalysisEnabled = true;
    public string $scheduleTimezone = 'Europe/London';
    public array $scheduleTimesWeekday = ['06:00', '18:00'];
    public array $scheduleTimesWeekend = ['08:00', '19:00'];
    public array $enabledDomains = ['health', 'money', 'media', 'knowledge', 'online'];

    // UI state
    public string $newWeekdayTime = '09:00';
    public string $newWeekendTime = '09:00';

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
        $this->continuousAnalysisEnabled = $settings['continuous_analysis_enabled'] ?? true;
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
            'continuous_analysis_enabled' => $this->continuousAnalysisEnabled,
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

    <div class="space-y-4 lg:space-y-6">
        {{-- General Settings --}}
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <h3 class="text-lg font-semibold mb-4">{{ __('General Settings') }}</h3>
                <p class="text-sm text-base-content/70 mb-4">
                    {{ __('Configure how Flint analyzes your data and delivers insights') }}
                </p>

                <div class="space-y-3">
                    <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                        <div>
                            <div class="font-medium text-sm">{{ __('Enable Daily Digests') }}</div>
                            <div class="text-xs text-base-content/60">{{ __('Receive scheduled digest notifications') }}</div>
                        </div>
                        <input
                            type="checkbox"
                            class="toggle toggle-primary"
                            wire:model.live="digestsEnabled"
                        />
                    </div>

                    <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                        <div>
                            <div class="font-medium text-sm">{{ __('Enable Continuous Analysis') }}</div>
                            <div class="text-xs text-base-content/60">{{ __('Run background analysis every 15 minutes') }}</div>
                        </div>
                        <input
                            type="checkbox"
                            class="toggle toggle-primary"
                            wire:model.live="continuousAnalysisEnabled"
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
                                {{ in_array($key, $enabledDomains) ? 'checked' : '' }}
                                wire:click="toggleDomain('{{ $key }}')"
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
</div>
