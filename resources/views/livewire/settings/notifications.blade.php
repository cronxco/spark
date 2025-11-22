<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public array $emailEnabled = [];
    public bool $workHoursEnabled = false;
    public string $workHoursTimezone = 'UTC';
    public string $workHoursStart = '09:00';
    public string $workHoursEnd = '17:00';
    public string $delayedSendingMode = 'immediate';
    public string $digestTime = '09:00';

    public array $notificationTypes = [
        'integration_completed' => [
            'label' => 'Integration Completed',
            'description' => 'Notify when an integration finishes syncing successfully'
        ],
        'integration_failed' => [
            'label' => 'Integration Failed',
            'description' => 'Notify when an integration sync fails (always sent immediately)'
        ],
        'integration_authentication_failed' => [
            'label' => 'Authentication Required',
            'description' => 'Notify when an integration needs re-authorization (always sent immediately)'
        ],
        'migration_completed' => [
            'label' => 'Historical Data Import Complete',
            'description' => 'Notify when historical data migration finishes successfully'
        ],
        'migration_failed' => [
            'label' => 'Historical Data Import Failed',
            'description' => 'Notify when historical data migration fails (always sent immediately)'
        ],
        'data_export_ready' => [
            'label' => 'Data Export Ready',
            'description' => 'Notify when your data export is ready for download'
        ],
        'system_maintenance' => [
            'label' => 'System Maintenance',
            'description' => 'Notify about system maintenance and updates (always sent immediately)'
        ],
    ];

    public array $timezones = [
        'UTC' => 'UTC',
        'Europe/London' => 'London',
        'Europe/Paris' => 'Paris',
        'America/New_York' => 'New York',
        'America/Chicago' => 'Chicago',
        'America/Denver' => 'Denver',
        'America/Los_Angeles' => 'Los Angeles',
        'America/Toronto' => 'Toronto',
        'Australia/Sydney' => 'Sydney',
        'Asia/Tokyo' => 'Tokyo',
        'Asia/Singapore' => 'Singapore',
    ];

    public function mount(): void
    {
        $user = Auth::user();
        $preferences = $user->getNotificationPreferences();

        // Load email preferences
        foreach (array_keys($this->notificationTypes) as $type) {
            $this->emailEnabled[$type] = $preferences['email_enabled'][$type] ?? true;
        }

        // Load work hours
        $this->workHoursEnabled = $preferences['work_hours']['enabled'] ?? false;
        $this->workHoursTimezone = $preferences['work_hours']['timezone'] ?? 'UTC';
        $this->workHoursStart = $preferences['work_hours']['start'] ?? '09:00';
        $this->workHoursEnd = $preferences['work_hours']['end'] ?? '17:00';

        // Load delayed sending
        $this->delayedSendingMode = $preferences['delayed_sending']['mode'] ?? 'immediate';
        $this->digestTime = $preferences['delayed_sending']['digest_time'] ?? '09:00';
    }

    public function savePreferences(): void
    {
        $user = Auth::user();

        $user->updateNotificationPreferences([
            'email_enabled' => $this->emailEnabled,
            'work_hours' => [
                'enabled' => $this->workHoursEnabled,
                'timezone' => $this->workHoursTimezone,
                'start' => $this->workHoursStart,
                'end' => $this->workHoursEnd,
            ],
            'delayed_sending' => [
                'mode' => $this->delayedSendingMode,
                'digest_time' => $this->digestTime,
            ],
        ]);

        $this->success('Notification preferences saved successfully!');
    }
}; ?>

<div>
    <x-header title="{{ __('Notification Preferences') }}" subtitle="{{ __('Manage how you receive notifications') }}" separator />

    <div class="space-y-4 lg:space-y-6">
    <!-- Email Notifications Card -->
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold mb-4">{{ __('Email Notifications') }}</h3>
            <p class="text-sm text-base-content/70 mb-4">
                {{ __('Choose which notifications you want to receive via email') }}
            </p>

            <div class="space-y-3">
                @foreach ($notificationTypes as $type => $config)
                    <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                        <div>
                            <div class="font-medium text-sm">{{ $config['label'] }}</div>
                            <div class="text-xs text-base-content/60">{{ $config['description'] }}</div>
                        </div>
                        <input
                            type="checkbox"
                            class="toggle toggle-primary"
                            wire:model="emailEnabled.{{ $type }}"
                            @if (in_array($type, ['integration_failed', 'integration_authentication_failed', 'migration_failed', 'system_maintenance'])) disabled checked @endif
                        />
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Work Hours Card -->
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold mb-4">{{ __('Work Hours') }}</h3>
            <p class="text-sm text-base-content/70 mb-4">
                {{ __('Define your work hours for delayed email notifications') }}
            </p>

            <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                <div>
                    <div class="font-medium text-sm">{{ __('Enable Work Hours') }}</div>
                    <div class="text-xs text-base-content/60">{{ __('Restrict notifications to specific hours') }}</div>
                </div>
                <input
                    type="checkbox"
                    class="toggle toggle-primary"
                    wire:model.live="workHoursEnabled"
                />
            </div>

            @if ($workHoursEnabled)
                <div class="space-y-4 mt-4">
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">{{ __('Timezone') }}</span>
                        </label>
                        <select wire:model="workHoursTimezone" class="select select-bordered w-full">
                            @foreach ($timezones as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">{{ __('Start Time') }}</span>
                            </label>
                            <input
                                type="time"
                                wire:model="workHoursStart"
                                class="input input-bordered w-full"
                            />
                        </div>
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">{{ __('End Time') }}</span>
                            </label>
                            <input
                                type="time"
                                wire:model="workHoursEnd"
                                class="input input-bordered w-full"
                            />
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <!-- Delayed Sending Card -->
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold mb-4">{{ __('Email Delivery Timing') }}</h3>
            <p class="text-sm text-base-content/70 mb-4">
                {{ __('Control when non-urgent email notifications are sent') }}
            </p>

            <div class="space-y-4">
                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input
                            type="radio"
                            name="delayed_sending_mode"
                            class="radio radio-primary"
                            value="immediate"
                            wire:model="delayedSendingMode"
                        />
                        <div>
                            <span class="label-text font-medium">{{ __('Immediate') }}</span>
                            <p class="text-xs text-base-content/60">{{ __('Send all notifications immediately') }}</p>
                        </div>
                    </label>
                </div>

                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input
                            type="radio"
                            name="delayed_sending_mode"
                            class="radio radio-primary"
                            value="work_hours"
                            wire:model="delayedSendingMode"
                            @if (!$workHoursEnabled) disabled @endif
                        />
                        <div>
                            <span class="label-text font-medium">{{ __('During Work Hours Only') }}</span>
                            <p class="text-xs text-base-content/60">
                                {{ __('Delay non-urgent notifications until your work hours') }}
                                @if (!$workHoursEnabled)
                                    <span class="text-warning">({{ __('Enable work hours first') }})</span>
                                @endif
                            </p>
                        </div>
                    </label>
                </div>

                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input
                            type="radio"
                            name="delayed_sending_mode"
                            class="radio radio-primary"
                            value="daily_digest"
                            wire:model.live="delayedSendingMode"
                        />
                        <div class="flex-1">
                            <span class="label-text font-medium">{{ __('Daily Digest') }}</span>
                            <p class="text-xs text-base-content/60">{{ __('Group notifications into a single daily email') }}</p>

                            @if ($delayedSendingMode === 'daily_digest')
                                <div class="form-control mt-3">
                                    <label class="label">
                                        <span class="label-text text-xs">{{ __('Digest Time') }}</span>
                                    </label>
                                    <input
                                        type="time"
                                        wire:model="digestTime"
                                        class="input input-bordered input-sm w-32"
                                    />
                                </div>
                            @endif
                        </div>
                    </label>
                </div>
            </div>

            <div class="alert alert-info mt-4">
                <x-icon name="fas-circle-info" class="w-5 h-5" />
                <span class="text-xs">
                    {{ __('Priority notifications (failures, system maintenance) are always sent immediately regardless of these settings.') }}
                </span>
            </div>
        </div>
    </div>

        <!-- Save Button -->
        <div class="flex justify-end">
            <x-button
                label="{{ __('Save Preferences') }}"
                wire:click="savePreferences"
                class="btn-primary"
                spinner="savePreferences"
            />
        </div>
    </div>
</div>
