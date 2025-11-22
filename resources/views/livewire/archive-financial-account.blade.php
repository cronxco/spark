<div>
    <form wire:submit="archive">
        <div class="space-y-4">
            <!-- Warning Alert -->
            <x-alert icon="fas-triangle-exclamation" class="alert-warning">
                <span class="font-semibold">Warning:</span> Archiving this account will create a final balance event with a zero balance. This action cannot be easily undone.
            </x-alert>

            <!-- Archive Date Input -->
            <div class="form-control w-full">
                <label class="label">
                    <span class="label-text font-medium">Archive Date</span>
                </label>
                <input
                    type="date"
                    wire:model="archiveDate"
                    max="{{ now()->toDateString() }}"
                    class="input input-bordered w-full @error('archiveDate') input-error @enderror"
                    required />
                @error('archiveDate')
                <label class="label">
                    <span class="label-text-alt text-error">{{ $message }}</span>
                </label>
                @enderror
                <label class="label">
                    <span class="label-text-alt">The date when this account was closed.</span>
                </label>
            </div>

            <!-- Account Summary -->
            <div class="card bg-base-300">
                <div class="card-body p-4">
                    <h4 class="font-semibold text-sm mb-2">Account to Archive:</h4>
                    <div class="text-sm space-y-1">
                        <div><span class="text-base-content/70">Name:</span> <span class="font-medium">{{ $account->metadata['name'] ?? $account->title }}</span></div>
                        <div><span class="text-base-content/70">Provider:</span> <span class="font-medium">{{ $account->metadata['provider'] ?? '-' }}</span></div>
                        <div><span class="text-base-content/70">Type:</span> <span class="font-medium">{{ $account->metadata['account_type'] ?? '-' }}</span></div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex justify-end gap-2 pt-4">
                <x-button
                    type="button"
                    wire:click="$dispatch('close-modal')"
                    class="btn-ghost">
                    Cancel
                </x-button>
                <x-button
                    type="submit"
                    class="btn-error">
                    <x-icon name="fas-box-archive" class="w-4 h-4" />
                    Archive
                </x-button>
            </div>
        </div>
    </form>
</div>