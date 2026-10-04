<div>
    <x-form wire:submit="save">
        @unless ($sourceFieldsEditable)
            <x-alert
                title="These fields come from the source"
                :description="$object->isLocked() ? 'This object is locked, so its title, type, concept and URL stay as they are.' : 'This object comes from an integration, so its title, type, concept and URL follow the source. Notes and tags can still be edited.'"
                icon="fas.lock"
            />
        @endunless

        <x-input
            label="Title"
            wire:model="title"
            :readonly="! $sourceFieldsEditable"
            placeholder="Object title"
            hint="Display name for this object"
        />

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <x-input
                label="Type"
                wire:model="type"
                :readonly="! $sourceFieldsEditable"
                placeholder="e.g., account, playlist, device"
                hint="Object type classification"
            />

            <x-input
                label="Concept"
                wire:model="concept"
                :readonly="! $sourceFieldsEditable"
                placeholder="e.g., bank_account, music_playlist"
                hint="Conceptual category"
            />
        </div>

        <x-input
            label="URL"
            wire:model="url"
            :readonly="! $sourceFieldsEditable"
            type="url"
            placeholder="https://..."
            hint="External link to this object"
        />

        <x-slot:actions>
            <x-button label="Cancel" @click="$wire.dispatch('close-modal')" />
            @if ($sourceFieldsEditable)
                <x-button label="Save Changes" class="btn-primary" type="submit" spinner="save" />
            @endif
        </x-slot:actions>
    </x-form>
</div>
