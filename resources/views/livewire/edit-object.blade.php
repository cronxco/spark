<div>
    <x-form wire:submit="save">
        <x-input
            label="Title"
            wire:model="title"
            placeholder="Object title"
            hint="Display name for this object"
        />

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <x-input
                label="Type"
                wire:model="type"
                placeholder="e.g., account, playlist, device"
                hint="Object type classification"
            />

            <x-input
                label="Concept"
                wire:model="concept"
                placeholder="e.g., bank_account, music_playlist"
                hint="Conceptual category"
            />
        </div>

        <x-input
            label="URL"
            wire:model="url"
            type="url"
            placeholder="https://..."
            hint="External link to this object"
        />

        <x-slot:actions>
            <x-button label="Cancel" @click="$wire.dispatch('close-modal')" />
            <x-button label="Save Changes" class="btn-primary" type="submit" spinner="save" />
        </x-slot:actions>
    </x-form>
</div>
