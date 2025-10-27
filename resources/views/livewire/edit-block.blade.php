<div>
    <x-form wire:submit="save">
        <x-input
            label="Title"
            wire:model="title"
            placeholder="Block title"
            hint="Display name for this block"
        />

        <x-input
            label="Block Type"
            wire:model="block_type"
            placeholder="e.g., daily_summary, workout_detail"
            hint="Type classification for this block"
        />

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <x-input
                label="Value"
                wire:model="value"
                type="number"
                step="0.01"
                placeholder="0.00"
                hint="Numeric value"
            />

            <x-input
                label="Value Unit"
                wire:model="value_unit"
                placeholder="e.g., GBP, minutes, steps"
                hint="Unit of measurement"
            />
        </div>

        <x-input
            label="Value Multiplier"
            wire:model="value_multiplier"
            type="number"
            step="0.01"
            placeholder="1.00"
            hint="Multiplier applied to the value"
        />

        <x-input
            label="Time"
            wire:model="time"
            type="datetime-local"
            hint="Time associated with this block"
        />

        <x-input
            label="URL"
            wire:model="url"
            type="url"
            placeholder="https://..."
            hint="External link"
        />

        <x-slot:actions>
            <x-button label="Cancel" @click="$wire.dispatch('close-modal')" />
            <x-button label="Save Changes" class="btn-primary" type="submit" spinner="save" />
        </x-slot:actions>
    </x-form>
</div>
