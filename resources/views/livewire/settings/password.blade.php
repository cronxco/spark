<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');
            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->success('Password updated successfully!');
        $this->dispatch('password-updated');
    }
}; ?>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <x-card title="{{ __('Update Password') }}" shadow>
            <div class="space-y-6">
                <!-- Current Password Section -->
                <div class="p-4 bg-base-200 rounded-lg">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h4 class="text-lg font-medium">{{ __('Current Password') }}</h4>
                            <p class="text-sm text-base-content/70">{{ __('Enter your current password to verify your identity') }}</p>
                        </div>
                        <div class="flex items-center space-x-2">
                            <x-input
                                wire:model="current_password"
                                type="password"
                                placeholder="Enter current password"
                                class="w-64"
                                required
                                autocomplete="current-password"
                            />
                        </div>
                    </div>
                </div>

                <!-- New Password Section -->
                <div class="p-4 bg-base-200 rounded-lg">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h4 class="text-lg font-medium">{{ __('New Password') }}</h4>
                            <p class="text-sm text-base-content/70">{{ __('Enter your new password') }}</p>
                        </div>
                        <div class="flex items-center space-x-2">
                            <x-input
                                wire:model="password"
                                type="password"
                                placeholder="Enter new password"
                                class="w-64"
                                required
                                autocomplete="new-password"
                            />
                        </div>
                    </div>
                </div>

                <!-- Confirm Password Section -->
                <div class="p-4 bg-base-200 rounded-lg">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h4 class="text-lg font-medium">{{ __('Confirm Password') }}</h4>
                            <p class="text-sm text-base-content/70">{{ __('Confirm your new password') }}</p>
                        </div>
                        <div class="flex items-center space-x-2">
                            <x-input
                                wire:model="password_confirmation"
                                type="password"
                                placeholder="Confirm new password"
                                class="w-64"
                                required
                                autocomplete="new-password"
                            />
                            <x-button
                                label="{{ __('Update Password') }}"
                                wire:click="updatePassword"
                                class="btn-primary"
                                spinner="updatePassword"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>
</div>
