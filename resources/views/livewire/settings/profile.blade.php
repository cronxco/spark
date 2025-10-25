<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public string $name = '';
    public string $email = '';
    public bool $debugLoggingEnabled = false;
    public string $timezone = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->debugLoggingEnabled = Auth::user()->hasDebugLoggingEnabled();
        $this->timezone = Auth::user()->getTimezone();
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id)
            ],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->success('Profile updated successfully!');
        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));
            return;
        }

        $user->sendEmailVerificationNotification();

        $this->success('A new verification link has been sent to your email address.');
    }

    /**
     * Toggle debug logging setting
     */
    public function toggleDebugLogging(): void
    {
        $user = Auth::user();

        if ($this->debugLoggingEnabled) {
            $user->enableDebugLogging();
            $this->success('Debug logging enabled. API calls and detailed logs will now be recorded.');
        } else {
            $user->disableDebugLogging();
            $this->success('Debug logging disabled. Only important events will be logged.');
        }
    }

    /**
     * Update timezone preference
     */
    public function updateTimezone(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'timezone' => ['required', 'string', 'timezone:all'],
        ]);

        $user->setTimezone($validated['timezone']);

        $this->success('Timezone updated successfully!');
    }

    /**
     * Get list of common timezones for dropdown
     */
    public function getTimezoneOptions(): array
    {
        return [
            'UTC' => 'UTC',
            'America/New_York' => 'Eastern Time (US & Canada)',
            'America/Chicago' => 'Central Time (US & Canada)',
            'America/Denver' => 'Mountain Time (US & Canada)',
            'America/Los_Angeles' => 'Pacific Time (US & Canada)',
            'America/Phoenix' => 'Arizona',
            'America/Anchorage' => 'Alaska',
            'Pacific/Honolulu' => 'Hawaii',
            'Europe/London' => 'London',
            'Europe/Paris' => 'Paris',
            'Europe/Berlin' => 'Berlin',
            'Europe/Madrid' => 'Madrid',
            'Europe/Rome' => 'Rome',
            'Europe/Amsterdam' => 'Amsterdam',
            'Europe/Brussels' => 'Brussels',
            'Europe/Vienna' => 'Vienna',
            'Europe/Stockholm' => 'Stockholm',
            'Europe/Copenhagen' => 'Copenhagen',
            'Europe/Helsinki' => 'Helsinki',
            'Europe/Oslo' => 'Oslo',
            'Europe/Warsaw' => 'Warsaw',
            'Europe/Athens' => 'Athens',
            'Europe/Bucharest' => 'Bucharest',
            'Europe/Istanbul' => 'Istanbul',
            'Asia/Dubai' => 'Dubai',
            'Asia/Kolkata' => 'Mumbai, Kolkata',
            'Asia/Singapore' => 'Singapore',
            'Asia/Hong_Kong' => 'Hong Kong',
            'Asia/Tokyo' => 'Tokyo',
            'Asia/Seoul' => 'Seoul',
            'Asia/Shanghai' => 'Beijing, Shanghai',
            'Australia/Sydney' => 'Sydney',
            'Australia/Melbourne' => 'Melbourne',
            'Australia/Brisbane' => 'Brisbane',
            'Australia/Perth' => 'Perth',
            'Pacific/Auckland' => 'Auckland',
        ];
    }
}; ?>

<div>
    <x-header title="{{ __('Profile Settings') }}" subtitle="{{ __('Manage your account profile and preferences') }}" separator />

    <div class="space-y-4 lg:space-y-6">
    <!-- Profile Information Card -->
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold mb-4">{{ __('Profile Information') }}</h3>

            <!-- Name Field -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text">{{ __('Name') }}</span>
                </label>
                <input
                    wire:model="name"
                    type="text"
                    placeholder="Enter your name"
                    class="input input-bordered w-full"
                    required
                    autofocus
                    autocomplete="name"
                />
                <label class="label">
                    <span class="label-text-alt">{{ __('Update your display name') }}</span>
                </label>
            </div>

            <!-- Email Field -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text">{{ __('Email Address') }}</span>
                </label>
                <input
                    wire:model="email"
                    type="email"
                    placeholder="Enter your email"
                    class="input input-bordered w-full"
                    required
                    autocomplete="email"
                />
                <label class="label">
                    <span class="label-text-alt">{{ __('Update your email address') }}</span>
                </label>
            </div>

            <div class="flex justify-end mt-4">
                <x-button
                    label="{{ __('Update Profile') }}"
                    wire:click="updateProfileInformation"
                    class="btn-primary"
                    spinner="updateProfileInformation"
                />
            </div>
        </div>
    </div>

    <!-- Email Verification Card -->
    @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && !auth()->user()->hasVerifiedEmail())
        <div class="card bg-warning/10 border border-warning/20">
            <div class="card-body">
                <h3 class="text-lg font-semibold text-warning mb-2">{{ __('Email Verification') }}</h3>
                <p class="text-sm text-base-content/70 mb-4">{{ __('Your email address is unverified.') }}</p>

                <div class="flex justify-end">
                    <x-button
                        label="{{ __('Resend Verification') }}"
                        wire:click="resendVerificationNotification"
                        class="btn-warning"
                    />
                </div>
            </div>
        </div>
    @endif

    <!-- Timezone Card -->
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold mb-4">{{ __('Timezone') }}</h3>
            <p class="text-sm text-base-content/70 mb-4">
                {{ __('Set your local timezone for accurate time displays and card scheduling') }}
            </p>

            <div class="form-control">
                <label class="label">
                    <span class="label-text">{{ __('Your Timezone') }}</span>
                </label>
                <select
                    wire:model="timezone"
                    class="select select-bordered w-full"
                >
                    @foreach ($this->getTimezoneOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <label class="label">
                    <span class="label-text-alt">{{ __('Cards and time-based features will use this timezone') }}</span>
                </label>
            </div>

            <div class="flex justify-end mt-4">
                <x-button
                    label="{{ __('Update Timezone') }}"
                    wire:click="updateTimezone"
                    class="btn-primary"
                    spinner="updateTimezone"
                />
            </div>
        </div>
    </div>

    <!-- Debug Logging Card -->
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold mb-4">{{ __('Debug Logging') }}</h3>
            <p class="text-sm text-base-content/70 mb-4">
                {{ __('Enable detailed logging for integration API calls and debugging') }}
            </p>

            <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                <div>
                    <div class="font-medium text-sm">{{ __('Enable Debug Logging') }}</div>
                    <div class="text-xs text-base-content/60">
                        When enabled, all API requests and responses will be logged to help troubleshoot integration issues.
                        These logs are automatically deleted after 7 days.
                    </div>
                </div>
                <input
                    type="checkbox"
                    class="toggle toggle-primary"
                    wire:model.live="debugLoggingEnabled"
                    wire:change="toggleDebugLogging"
                />
            </div>
        </div>
    </div>

        <!-- Delete Account Section -->
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <livewire:settings.delete-user-form />
            </div>
        </div>
    </div>
</div>
