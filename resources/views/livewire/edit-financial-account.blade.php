<div class="modal-box max-w-2xl">
    <h3 class="font-bold text-lg mb-4">Edit Account: {{ $account->title }}</h3>

    <form wire:submit="updateMetadata">
        <div class="space-y-4">
            @foreach ($editableFields as $fieldName => $fieldConfig)
                <div class="form-control">
                    <label class="label">
                        <span class="label-text font-medium">{{ $fieldConfig['label'] }}</span>
                        @if (isset($fieldConfig['description']))
                            <span class="label-text-alt text-base-content/70">{{ $fieldConfig['description'] }}</span>
                        @endif
                    </label>

                    @if ($fieldConfig['type'] === 'select')
                        <select
                            wire:model="metadata.{{ $fieldName }}"
                            class="select select-bordered w-full"
                            @if (isset($fieldConfig['required']) && $fieldConfig['required']) required @endif
                        >
                            <option value="">Select {{ $fieldConfig['label'] }}</option>
                            @foreach ($fieldConfig['options'] as $value => $label)
                                <option value="{{ $value }}" {{ ($metadata[$fieldName] ?? '') === $value ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    @elseif ($fieldConfig['type'] === 'number')
                        <input
                            type="number"
                            wire:model="metadata.{{ $fieldName }}"
                            class="input input-bordered w-full"
                            @if (isset($fieldConfig['step'])) step="{{ $fieldConfig['step'] }}" @endif
                            @if (isset($fieldConfig['min'])) min="{{ $fieldConfig['min'] }}" @endif
                            @if (isset($fieldConfig['max'])) max="{{ $fieldConfig['max'] }}" @endif
                            @if (isset($fieldConfig['required']) && $fieldConfig['required']) required @endif
                        />
                    @elseif ($fieldConfig['type'] === 'date')
                        <input
                            type="date"
                            wire:model="metadata.{{ $fieldName }}"
                            class="input input-bordered w-full"
                            @if (isset($fieldConfig['required']) && $fieldConfig['required']) required @endif
                        />
                    @else
                        <input
                            type="text"
                            wire:model="metadata.{{ $fieldName }}"
                            class="input input-bordered w-full"
                            @if (isset($fieldConfig['required']) && $fieldConfig['required']) required @endif
                        />
                    @endif
                </div>
            @endforeach
        </div>

        <div class="modal-action">
            <button type="button" class="btn btn-outline" wire:click="$dispatch('closeModal')">
                Cancel
            </button>
            <button type="submit" class="btn btn-primary">
                Update Account
            </button>
        </div>
    </form>
</div>
