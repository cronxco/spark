<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public string $password = '';
    public bool $showDeleteModal = false;

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div class="space-y-6">
    <div class="space-y-2">
        <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('Delete account') }}</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Delete your account and all of its resources') }}</p>
    </div>

    <x-button
        label="{{ __('Delete account') }}"
        class="btn-error"
        wire:click="$set('showDeleteModal', true)"
    />

    <x-modal wire:model="showDeleteModal" title="{{ __('Are you sure you want to delete your account?') }}" subtitle="{{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}" separator>
        <x-form wire:submit="deleteUser">
            <x-input wire:model="password" :label="__('Password')" type="password" required />

            <x-slot:actions>
                <x-button
                    label="{{ __('Cancel') }}"
                    class="btn-outline"
                    wire:click="$set('showDeleteModal', false)"
                />
                <x-button label="{{ __('Delete account') }}" type="submit" class="btn-error" spinner="deleteUser" />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
