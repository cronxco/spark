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

<div>
    <x-header title="{{ __('Update Password') }}" subtitle="{{ __('Ensure your account is using a strong password') }}" separator />

    <div class="space-y-4 lg:space-y-6">
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold mb-4">{{ __('Change Password') }}</h3>

            <!-- Current Password -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text">{{ __('Current Password') }}</span>
                </label>
                <input
                    wire:model="current_password"
                    type="password"
                    placeholder="Enter current password"
                    class="input input-bordered w-full"
                    required
                    autocomplete="current-password"
                />
                <label class="label">
                    <span class="label-text-alt">{{ __('Enter your current password to verify your identity') }}</span>
                </label>
            </div>

            <!-- New Password -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text">{{ __('New Password') }}</span>
                </label>
                <input
                    wire:model="password"
                    type="password"
                    placeholder="Enter new password"
                    class="input input-bordered w-full"
                    required
                    autocomplete="new-password"
                />
                <label class="label">
                    <span class="label-text-alt">{{ __('Enter your new password') }}</span>
                </label>
            </div>

            <!-- Confirm Password -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text">{{ __('Confirm Password') }}</span>
                </label>
                <input
                    wire:model="password_confirmation"
                    type="password"
                    placeholder="Confirm new password"
                    class="input input-bordered w-full"
                    required
                    autocomplete="new-password"
                />
                <label class="label">
                    <span class="label-text-alt">{{ __('Confirm your new password') }}</span>
                </label>
            </div>

            <div class="flex justify-end mt-4">
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
</div>
