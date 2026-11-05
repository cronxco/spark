<div>
    <form wire:submit.prevent="save">
        <div class="grid grid-cols-1 gap-6">
            <!-- Tag Type Selection -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Tag Type *</span>
                </label>
                <select wire:model.live="type" class="select select-bordered w-full @error('type') select-error @enderror">
                    <option value="">Select a tag type</option>
                    @foreach ($this->getExistingTypes() as $typeKey => $typeLabel)
                    <option value="{{ $typeKey }}">{{ $typeLabel }}</option>
                    @endforeach
                    <option value="custom">+ Create New</option>
                </select>
                @error('type')
                <label class="label">
                    <span class="label-text-alt text-error">{{ $message }}</span>
                </label>
                @enderror
            </div>

            <!-- Custom Type Input (shown when "Custom Type" is selected) -->
            @if ($type === 'custom')
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Custom Type Name *</span>
                </label>
                <input
                    type="text"
                    wire:model="customType"
                    placeholder="e.g. Project Name, Location"
                    class="input input-bordered w-full @error('customType') input-error @enderror" />
                @error('customType')
                <label class="label">
                    <span class="label-text-alt text-error">{{ $message }}</span>
                </label>
                @enderror
            </div>
            @endif

            <!-- Tag Name -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Tag Name *</span>
                </label>
                <input
                    type="text"
                    wire:model="name"
                    placeholder="Enter tag name..."
                    class="input input-bordered w-full @error('name') input-error @enderror" />
                @error('name')
                <label class="label">
                    <span class="label-text-alt text-error">{{ $message }}</span>
                </label>
                @enderror
            </div>
        </div>

        <!-- Form Actions -->
        <div class="flex gap-3 mt-8">
            <button type="submit" class="btn btn-primary">
                <x-icon name="fas.plus" class="w-4 h-4" />
                Create Tag
            </button>
            <button type="button" wire:click="$set('showModal', false)" class="btn btn-ghost">
                Cancel
            </button>
        </div>
    </form>
</div>