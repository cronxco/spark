<div>
    <div class="max-w-2xl mx-auto">
        <!-- Header -->
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-base-content">Add Financial Account</h1>
            <p class="text-base-content/70">Create a new financial account to track manually</p>
        </div>

        <!-- Form -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <form wire:submit="save">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Account Name -->
                        <div class="form-control md:col-span-2">
                            <label class="label">
                                <span class="label-text">Account Name *</span>
                            </label>
                            <input
                                type="text"
                                wire:model="name"
                                placeholder="e.g. Main Current Account, ISA, Mortgage"
                                class="input input-bordered w-full @error('name') input-error @enderror"
                            />
                            @error('name')
                                <label class="label">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                        </div>

                        <!-- Account Type -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Account Type *</span>
                            </label>
                            <select wire:model="accountType" class="select select-bordered @error('accountType') select-error @enderror">
                                <option value="">Select account type</option>
                                <option value="current_account">Current Account</option>
                                <option value="savings_account">Savings Account</option>
                                <option value="mortgage">Mortgage</option>
                                <option value="investment_account">Investment Account</option>
                                <option value="credit_card">Credit Card</option>
                                <option value="loan">Loan</option>
                                <option value="pension">Pension</option>
                                <option value="other">Other</option>
                            </select>
                            @error('accountType')
                                <label class="label">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                        </div>

                        <!-- Provider -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Provider *</span>
                            </label>
                            <input
                                type="text"
                                wire:model="provider"
                                placeholder="e.g. Barclays, Santander, HSBC"
                                class="input input-bordered w-full @error('provider') input-error @enderror"
                            />
                            @error('provider')
                                <label class="label">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                        </div>

                        <!-- Account Number -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Account Number</span>
                            </label>
                            <input
                                type="text"
                                wire:model="accountNumber"
                                placeholder="Account number or identifier"
                                class="input input-bordered w-full @error('accountNumber') input-error @enderror"
                            />
                            @error('accountNumber')
                                <label class="label">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                        </div>

                        <!-- Sort Code -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Sort Code</span>
                            </label>
                            <input
                                type="text"
                                wire:model="sortCode"
                                placeholder="e.g. 12-34-56"
                                class="input input-bordered w-full @error('sortCode') input-error @enderror"
                            />
                            @error('sortCode')
                                <label class="label">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                        </div>

                        <!-- Currency -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Currency *</span>
                            </label>
                            <select wire:model="currency" class="select select-bordered @error('currency') select-error @enderror">
                                <option value="GBP">British Pound (£)</option>
                                <option value="USD">US Dollar ($)</option>
                                <option value="EUR">Euro (€)</option>
                            </select>
                            @error('currency')
                                <label class="label">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                        </div>

                        <!-- Interest Rate -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Interest Rate (%)</span>
                            </label>
                            <input
                                type="number"
                                wire:model="interestRate"
                                placeholder="e.g. 2.5"
                                step="0.01"
                                min="0"
                                max="100"
                                class="input input-bordered w-full @error('interestRate') input-error @enderror"
                            />
                            @error('interestRate')
                                <label class="label">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                        </div>

                        <!-- Start Date -->
                        <div class="form-control md:col-span-2">
                            <label class="label">
                                <span class="label-text">Start Date</span>
                            </label>
                            <input
                                type="date"
                                wire:model="startDate"
                                class="input input-bordered w-full @error('startDate') input-error @enderror"
                            />
                            @error('startDate')
                                <label class="label">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="flex gap-3 mt-8">
                        <button type="submit" class="btn btn-primary">
                            <x-icon name="o-plus" class="w-4 h-4" />
                            Create Account
                        </button>
                        <a href="{{ route('money') }}" class="btn btn-outline">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Toast notifications -->
    <x-toast position="toast-top toast-end" />
</div>