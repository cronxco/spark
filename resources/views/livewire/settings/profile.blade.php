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

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
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
}; ?>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <x-card title="{{ __('Profile Settings') }}" shadow>
            <div class="space-y-6">
                <!-- Name Section -->
                <div class="p-4 bg-base-200 rounded-lg">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h4 class="text-lg font-medium">{{ __('Name') }}</h4>
                            <p class="text-sm text-base-content/70">{{ __('Update your display name') }}</p>
                        </div>
                        <div class="flex items-center space-x-2">
                            <x-input
                                wire:model="name"
                                placeholder="Enter your name"
                                class="w-64"
                                required
                                autofocus
                                autocomplete="name"
                            />
                            <x-button
                                label="{{ __('Update Name') }}"
                                wire:click="updateProfileInformation"
                                class="btn-primary"
                                spinner="updateProfileInformation"
                            />
                        </div>
                    </div>
                </div>

                <!-- Email Section -->
                <div class="p-4 bg-base-200 rounded-lg">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h4 class="text-lg font-medium">{{ __('Email Address') }}</h4>
                            <p class="text-sm text-base-content/70">{{ __('Update your email address') }}</p>
                        </div>
                        <div class="flex items-center space-x-2">
                            <x-input
                                wire:model="email"
                                type="email"
                                placeholder="Enter your email"
                                class="w-64"
                                required
                                autocomplete="email"
                            />
                            <x-button
                                label="{{ __('Update Email') }}"
                                wire:click="updateProfileInformation"
                                class="btn-primary"
                                spinner="updateProfileInformation"
                            />
                        </div>
                    </div>
                </div>

                @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && !auth()->user()->hasVerifiedEmail())
                    <div class="p-4 bg-warning/10 border border-warning/20 rounded-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h4 class="text-lg font-medium text-warning">{{ __('Email Verification') }}</h4>
                                <p class="text-sm text-base-content/70">{{ __('Your email address is unverified.') }}</p>
                            </div>
                            <x-button
                                label="{{ __('Resend Verification') }}"
                                wire:click="resendVerificationNotification"
                                class="btn-warning"
                            />
                        </div>
                    </div>
                @endif

                <div class="pt-6 border-t border-base-300">
                    <livewire:settings.delete-user-form />
                </div>
            </div>
        </x-card>
    </div>
</div>
