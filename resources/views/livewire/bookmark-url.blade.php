<div>
    <x-modal wire:model="showModal" title="Bookmark URL" subtitle="Add a URL to monitor and fetch content">
        <x-form wire:submit="save">
            <!-- URL Input -->
            <x-input
                label="URL"
                wire:model="url"
                placeholder="https://example.com/article"
                icon="fas.link"
                hint="The URL to bookmark"
                required
            />

            <!-- Fetch Mode -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Fetch Mode</span>
                </label>
                <div class="space-y-2">
                    <label class="flex items-center gap-3 p-3 rounded-lg border border-base-300 hover:bg-base-200 cursor-pointer transition-colors">
                        <input
                            type="radio"
                            wire:model="fetchMode"
                            value="recurring"
                            class="radio radio-primary"
                        />
                        <div class="flex-1">
                            <div class="font-medium">Subscribe</div>
                            <div class="text-sm text-base-content/60">Fetch content on every scheduled update</div>
                        </div>
                    </label>
                    <label class="flex items-center gap-3 p-3 rounded-lg border border-base-300 hover:bg-base-200 cursor-pointer transition-colors">
                        <input
                            type="radio"
                            wire:model="fetchMode"
                            value="once"
                            class="radio radio-primary"
                        />
                        <div class="flex-1">
                            <div class="font-medium">Bookmark</div>
                            <div class="text-sm text-base-content/60">Fetch content only once</div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Enabled Toggle -->
            <div class="form-control">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input
                        type="checkbox"
                        wire:model="enabled"
                        class="toggle toggle-primary"
                    />
                    <div class="flex-1">
                        <div class="label-text font-medium">Enable fetching</div>
                    </div>
                </label>
            </div>

            <x-slot:actions>
                <x-button label="Cancel" wire:click="cancel" class="btn-outline"/>
                <x-button
                    label="Save"
                    class="btn-success"
                    type="submit"
                    spinner="save"
                    icon="o-bookmark-square"
                />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
