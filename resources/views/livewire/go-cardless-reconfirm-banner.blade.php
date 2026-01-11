<div>
    @php
        $bankName = $group->auth_metadata['gocardless_institution_name'] ?? 'Bank';
    @endphp

    <div class="alert alert-warning mb-6 border-l-4 border-warning">
        <div class="flex items-start gap-4 w-full">
            <div class="flex-shrink-0">
                <x-icon name="fas.triangle-exclamation" class="w-6 h-6 text-warning" />
            </div>

            <div class="flex-1">
                <h3 class="text-lg font-semibold text-base-content mb-2">
                    {{ $bankName }} Connection Expired
                </h3>

                <p class="text-sm text-base-content/80 mb-4">
                    Your {{ $bankName }} connection needs to be renewed. This is required every 90 days for security. Your transaction history will not be affected.
                </p>

                @error('general')
                    <div class="alert alert-error mb-4">
                        <x-icon name="fas.circle-exclamation" class="w-5 h-5" />
                        <span>{{ $message }}</span>
                    </div>
                @enderror

                <div class="flex gap-3">
                    <button
                        wire:click="attemptReconfirmation"
                        wire:loading.attr="disabled"
                        class="btn btn-primary"
                    >
                        <span wire:loading.remove wire:target="attemptReconfirmation,createNewEua">
                            Reconnect {{ $bankName }}
                        </span>
                        <span wire:loading wire:target="attemptReconfirmation,createNewEua">
                            <span class="loading loading-spinner loading-sm"></span>
                            Loading...
                        </span>
                    </button>

                    <button
                        wire:click="createNewEua"
                        wire:loading.attr="disabled"
                        class="btn btn-ghost"
                    >
                        Connect Different Account
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
