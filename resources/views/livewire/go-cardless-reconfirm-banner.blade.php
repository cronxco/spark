<div>
    @php
        $bankName = $group->auth_metadata['gocardless_institution_name'] ?? 'Bank';
    @endphp

    <flux:card class="mb-6 border-l-4 border-orange-500">
        <div class="flex items-start gap-4">
            <div class="flex-shrink-0">
                <flux:icon.exclamation-triangle variant="solid" class="size-6 text-orange-500"/>
            </div>

            <div class="flex-1">
                <flux:heading size="lg" class="mb-2">
                    {{ $bankName }} Connection Expired
                </flux:heading>

                <flux:text class="mb-4 text-zinc-600 dark:text-zinc-400">
                    Your {{ $bankName }} connection needs to be renewed. This is required every 90 days for security. Your transaction history will not be affected.
                </flux:text>

                @error('general')
                    <flux:error class="mb-4">
                        {{ $message }}
                    </flux:error>
                @enderror

                <div class="flex gap-3">
                    <flux:button
                        wire:click="attemptReconfirmation"
                        wire:loading.attr="disabled"
                        variant="primary"
                    >
                        <span wire:loading.remove wire:target="attemptReconfirmation,createNewEua">
                            Reconnect {{ $bankName }}
                        </span>
                        <span wire:loading wire:target="attemptReconfirmation,createNewEua">
                            Loading...
                        </span>
                    </flux:button>

                    <flux:button
                        wire:click="createNewEua"
                        wire:loading.attr="disabled"
                        variant="ghost"
                    >
                        Connect Different Account
                    </flux:button>
                </div>
            </div>
        </div>
    </flux:card>
</div>
