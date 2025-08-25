<div>
    <div class="max-w-2xl mx-auto">
        <!-- Header -->
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-base-content">Add Balance Update</h1>
            <p class="text-base-content/70">Record a new balance for one of your financial accounts</p>
        </div>

        <!-- Form -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <form wire:submit="save">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Account Selection -->
                        <div class="form-control md:col-span-2">
                            <label class="label">
                                <span class="label-text">Account *</span>
                            </label>
                            <select wire:model="accountId" class="select select-bordered w-full @error('accountId') select-error @enderror">
                                <option value="">Select an account</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}">
                                        {{ $account->name }} - {{ $account->provider }} ({{ $account->currency_symbol }}{{ number_format($account->current_balance ?? 0, 2) }})
                                    </option>
                                @endforeach
                            </select>
                            @error('accountId')
                                <label class="label">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                        </div>

                        <!-- Balance -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Balance *</span>
                            </label>
                            <input 
                                type="number" 
                                wire:model="balance" 
                                placeholder="0.00"
                                step="0.01"
                                class="input input-bordered w-full @error('balance') input-error @enderror"
                            />
                            @error('balance')
                                <label class="label">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                        </div>

                        <!-- Date -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Date *</span>
                            </label>
                            <input 
                                type="date" 
                                wire:model="date" 
                                class="input input-bordered w-full @error('date') input-error @enderror"
                            />
                            @error('date')
                                <label class="label">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                        </div>

                        <!-- Notes -->
                        <div class="form-control md:col-span-2">
                            <label class="label">
                                <span class="label-text">Notes</span>
                            </label>
                            <textarea 
                                wire:model="notes" 
                                placeholder="Optional notes about this balance update..."
                                rows="3"
                                class="textarea textarea-bordered w-full @error('notes') textarea-error @enderror"
                            ></textarea>
                            @error('notes')
                                <label class="label">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="flex gap-3 mt-8">
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="o-currency-pound" class="w-4 h-4" />
                            Add Balance Update
                        </button>
                        <a href="{{ route('financial-accounts') }}" class="btn btn-outline">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Help Text -->
        <div class="card bg-base-200 shadow-sm mt-6">
            <div class="card-body">
                <h3 class="card-title text-base-content">
                    <x-icon name="o-information-circle" class="w-5 h-5" />
                    Tips for Balance Updates
                </h3>
                <ul class="list-disc list-inside space-y-2 text-sm text-base-content/70">
                    <li>Update your balances regularly to keep track of your financial progress</li>
                    <li>For mortgages and loans, use negative balances to represent outstanding debt</li>
                    <li>Include notes for significant changes or important context</li>
                    <li>You can update balances as frequently as needed</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Toast notifications -->
    <x-toast position="toast-top toast-end" />
</div>