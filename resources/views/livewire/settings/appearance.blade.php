<?php

use Livewire\Volt\Component;

new class extends Component {
    public string $appearance = 'light';
}; ?>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <x-card title="{{ __('Appearance') }}" shadow>
            <div class="space-y-6">
                <!-- Theme Section -->
                <div class="p-4 bg-base-200 rounded-lg">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h4 class="text-lg font-medium">{{ __('Theme') }}</h4>
                            <p class="text-sm text-base-content/70">{{ __('Choose your preferred theme') }}</p>
                        </div>
                        <div class="flex items-center space-x-4">
                            <x-radio label="{{ __('Light') }}" value="light" wire:model="appearance">
                                <x-icon name="o-sun" class="w-5 h-5" />
                            </x-radio>

                            <x-radio label="{{ __('Dark') }}" value="dark" wire:model="appearance">
                                <x-icon name="o-moon" class="w-5 h-5" />
                            </x-radio>

                            <x-radio label="{{ __('System') }}" value="system" wire:model="appearance">
                                <x-icon name="o-computer-desktop" class="w-5 h-5" />
                            </x-radio>
                        </div>
                    </div>
                </div>
            </div>
        </x-card>
    </div>
</div>
