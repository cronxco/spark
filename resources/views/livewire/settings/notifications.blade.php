<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public array $emailEnabled = [];
    public array $pushEnabled = [];
    public bool $pushGlobalEnabled = true;
    public bool $workHoursEnabled = false;
    public string $workHoursTimezone = 'UTC';
    public string $workHoursStart = '09:00';
    public string $workHoursEnd = '17:00';
    public string $delayedSendingMode = 'immediate';
    public string $digestTime = '09:00';
    public array $pushSubscriptions = [];

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

        // Load push preferences
        $this->pushGlobalEnabled = $preferences['push_enabled'] ?? true;
        foreach (array_keys($this->notificationTypes) as $type) {
            $this->pushEnabled[$type] = $preferences['push_types'][$type] ?? true;
        }

        // Load push subscriptions
        $this->loadPushSubscriptions();

        // Load work hours
        $this->workHoursEnabled = $preferences['work_hours']['enabled'] ?? false;
        $this->workHoursTimezone = $preferences['work_hours']['timezone'] ?? 'UTC';
        $this->workHoursStart = $preferences['work_hours']['start'] ?? '09:00';
        $this->workHoursEnd = $preferences['work_hours']['end'] ?? '17:00';

        // Load delayed sending
        $this->delayedSendingMode = $preferences['delayed_sending']['mode'] ?? 'immediate';
        $this->digestTime = $preferences['delayed_sending']['digest_time'] ?? '09:00';
    }

    public function loadPushSubscriptions(): void
    {
        $user = Auth::user();
        $this->pushSubscriptions = $user->pushSubscriptions()
            ->select('id', 'endpoint', 'created_at')
            ->get()
            ->map(function ($subscription) {
                $endpoint = $subscription->endpoint;
                $browser = 'Unknown';

                if (str_contains($endpoint, 'fcm.googleapis.com')) {
                    $browser = 'Chrome/Android';
                } elseif (str_contains($endpoint, 'mozilla.com')) {
                    $browser = 'Firefox';
                } elseif (str_contains($endpoint, 'windows.com')) {
                    $browser = 'Edge';
                } elseif (str_contains($endpoint, 'apple.com') || str_contains($endpoint, 'push.apple')) {
                    $browser = 'Safari/iOS';
                }

                return [
                    'id' => $subscription->id,
                    'browser' => $browser,
                    'created_at' => $subscription->created_at->diffForHumans(),
                ];
            })
            ->toArray();
    }

    public function removePushSubscription(int $id): void
    {
        $user = Auth::user();
        $deleted = $user->pushSubscriptions()->where('id', $id)->delete();

        if ($deleted) {
            $this->loadPushSubscriptions();
            $this->success('Device removed successfully');
        } else {
            $this->error('Failed to remove device');
        }
    }

    public function sendTestNotification(): void
    {
        $user = Auth::user();

        if (!$user->pushSubscriptions()->exists()) {
            $this->error('No devices registered for push notifications');
            return;
        }

        $user->notify(new \App\Notifications\TestPushNotification());
        $this->success('Test notification sent!');
    }

    public function savePreferences(): void
    {
        $user = Auth::user();

        $user->updateNotificationPreferences([
            'email_enabled' => $this->emailEnabled,
            'push_enabled' => $this->pushGlobalEnabled,
            'push_types' => $this->pushEnabled,
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

    <!-- Push Notifications Card -->
    <div class="card bg-base-200 shadow" x-data="pushNotifications()" x-init="init()">
        <div class="card-body">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-semibold">{{ __('Push Notifications') }}</h3>
                    <p class="text-sm text-base-content/70">
                        {{ __('Receive instant notifications on your device') }}
                    </p>
                </div>
                <input
                    type="checkbox"
                    class="toggle toggle-primary"
                    wire:model.live="pushGlobalEnabled"
                />
            </div>

            <!-- Browser/Device Status -->
            <div class="p-3 bg-base-100 rounded-lg mb-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <template x-if="!supported">
                            <div class="flex items-center gap-2 text-warning">
                                <x-icon name="fas.triangle-exclamation" class="w-4 h-4" />
                                <span class="text-sm">{{ __('Push notifications not supported in this browser') }}</span>
                            </div>
                        </template>
                        <template x-if="supported && isiOSSafari">
                            <div class="flex items-center gap-2 text-info">
                                <x-icon name="fas.mobile-screen" class="w-4 h-4" />
                                <span class="text-sm">{{ __('Add to Home Screen to enable push notifications') }}</span>
                            </div>
                        </template>
                        <template x-if="supported && !isiOSSafari && permission === 'denied'">
                            <div class="flex items-center gap-2 text-error">
                                <x-icon name="fas.ban" class="w-4 h-4" />
                                <span class="text-sm">{{ __('Notifications blocked. Enable in browser settings.') }}</span>
                            </div>
                        </template>
                        <template x-if="supported && !isiOSSafari && permission !== 'denied' && !subscribed">
                            <div class="flex items-center gap-2 text-base-content/70">
                                <x-icon name="fas.bell" class="w-4 h-4" />
                                <span class="text-sm">{{ __('This device is not registered') }}</span>
                            </div>
                        </template>
                        <template x-if="supported && !isiOSSafari && permission !== 'denied' && subscribed">
                            <div class="flex items-center gap-2 text-success">
                                <x-icon name="fas.circle-check" class="w-4 h-4" />
                                <span class="text-sm">{{ __('This device is registered') }}</span>
                            </div>
                        </template>
                    </div>
                    <template x-if="supported && !isiOSSafari && permission !== 'denied'">
                        <button
                            x-show="!subscribed"
                            @click="subscribe()"
                            :disabled="loading"
                            class="btn btn-primary btn-sm"
                        >
                            <span x-show="loading" class="loading loading-spinner loading-xs"></span>
                            <span x-show="!loading">{{ __('Enable') }}</span>
                        </button>
                    </template>
                    <template x-if="supported && !isiOSSafari && subscribed">
                        <button
                            @click="unsubscribe()"
                            :disabled="loading"
                            class="btn btn-ghost btn-sm"
                        >
                            <span x-show="loading" class="loading loading-spinner loading-xs"></span>
                            <span x-show="!loading">{{ __('Disable') }}</span>
                        </button>
                    </template>
                </div>

                <!-- iOS Installation Instructions -->
                <template x-if="supported && isiOSSafari">
                    <div class="mt-3 p-3 bg-info/10 rounded-lg">
                        <p class="text-xs text-base-content/70 mb-2">{{ __('To receive push notifications on iOS:') }}</p>
                        <ol class="text-xs text-base-content/70 list-decimal list-inside space-y-1">
                            <li>{{ __('Tap the Share button') }} <x-icon name="fas.arrow-up-from-bracket" class="w-3 h-3 inline" /></li>
                            <li>{{ __('Scroll down and tap "Add to Home Screen"') }}</li>
                            <li>{{ __('Open Spark from your home screen') }}</li>
                            <li>{{ __('Return here to enable notifications') }}</li>
                        </ol>
                    </div>
                </template>
            </div>

            <!-- Registered Devices -->
            @if (count($pushSubscriptions) > 0)
                <div class="mb-4">
                    <h4 class="text-sm font-medium mb-2">{{ __('Registered Devices') }}</h4>
                    <div class="space-y-2">
                        @foreach ($pushSubscriptions as $subscription)
                            <div class="flex items-center justify-between p-2 bg-base-100 rounded-lg">
                                <div class="flex items-center gap-2">
                                    <x-icon name="fas.mobile-screen" class="w-4 h-4 text-base-content/50" />
                                    <span class="text-sm">{{ $subscription['browser'] }}</span>
                                    <span class="text-xs text-base-content/50">{{ $subscription['created_at'] }}</span>
                                </div>
                                <button
                                    wire:click="removePushSubscription({{ $subscription['id'] }})"
                                    class="btn btn-ghost btn-xs text-error"
                                >
                                    <x-icon name="fas.trash" class="w-3 h-3" />
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Test Notification -->
            @if (count($pushSubscriptions) > 0)
                <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg mb-4">
                    <div>
                        <div class="font-medium text-sm">{{ __('Test Push Notification') }}</div>
                        <div class="text-xs text-base-content/60">{{ __('Send a test notification to all your devices') }}</div>
                    </div>
                    <button
                        wire:click="sendTestNotification"
                        class="btn btn-outline btn-sm"
                        wire:loading.attr="disabled"
                        wire:target="sendTestNotification"
                    >
                        <span wire:loading wire:target="sendTestNotification" class="loading loading-spinner loading-xs"></span>
                        <span wire:loading.remove wire:target="sendTestNotification">{{ __('Send Test') }}</span>
                    </button>
                </div>
            @endif

            <!-- Per-type Push Settings -->
            @if ($pushGlobalEnabled)
                <div class="space-y-3">
                    <h4 class="text-sm font-medium">{{ __('Notification Types') }}</h4>
                    @foreach ($notificationTypes as $type => $config)
                        <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                            <div>
                                <div class="font-medium text-sm">{{ $config['label'] }}</div>
                            </div>
                            <input
                                type="checkbox"
                                class="toggle toggle-primary toggle-sm"
                                wire:model="pushEnabled.{{ $type }}"
                                @if (in_array($type, ['integration_failed', 'integration_authentication_failed', 'migration_failed', 'system_maintenance'])) disabled checked @endif
                            />
                        </div>
                    @endforeach
                </div>
            @endif
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
                <x-icon name="fas.circle-info" class="w-5 h-5" />
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

@script
<script>
    function pushNotifications() {
        return {
            supported: false,
            subscribed: false,
            permission: 'default',
            isiOSSafari: false,
            loading: false,

            async init() {
                // Check support
                this.supported = 'serviceWorker' in navigator &&
                                 'PushManager' in window &&
                                 'Notification' in window;

                if (!this.supported) return;

                // Check if iOS Safari (not standalone)
                const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
                const isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
                                    window.navigator.standalone === true;
                this.isiOSSafari = isIOS && !isStandalone;

                // Get permission status
                this.permission = Notification.permission;

                // Check subscription status
                if (navigator.serviceWorker.controller) {
                    await this.checkSubscription();
                } else {
                    navigator.serviceWorker.ready.then(() => this.checkSubscription());
                }

                // Listen for push events
                window.addEventListener('push-subscribed', () => {
                    this.subscribed = true;
                    $wire.$refresh();
                });

                window.addEventListener('push-unsubscribed', () => {
                    this.subscribed = false;
                    $wire.$refresh();
                });
            },

            async checkSubscription() {
                try {
                    const registration = await navigator.serviceWorker.ready;
                    const subscription = await registration.pushManager.getSubscription();
                    this.subscribed = subscription !== null;
                } catch (e) {
                    console.error('Error checking subscription:', e);
                }
            },

            async subscribe() {
                if (!window.SparkPush) {
                    console.error('SparkPush not initialized');
                    return;
                }

                this.loading = true;
                try {
                    await window.SparkPush.subscribe();
                    this.subscribed = true;
                    this.permission = Notification.permission;
                    $wire.$refresh();
                } catch (e) {
                    console.error('Subscribe error:', e);
                    if (e.message.includes('permission')) {
                        this.permission = 'denied';
                    }
                } finally {
                    this.loading = false;
                }
            },

            async unsubscribe() {
                if (!window.SparkPush) {
                    console.error('SparkPush not initialized');
                    return;
                }

                this.loading = true;
                try {
                    await window.SparkPush.unsubscribe();
                    this.subscribed = false;
                    $wire.$refresh();
                } catch (e) {
                    console.error('Unsubscribe error:', e);
                } finally {
                    this.loading = false;
                }
            }
        };
    }
</script>
@endscript
