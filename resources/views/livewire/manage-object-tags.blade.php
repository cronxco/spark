<div>
    <div class="space-y-4">
        <!-- Tag Input -->
        <div class="form-control">
            <label class="label">
                <span class="label-text">Tags</span>
                <button type="button" wire:click="openCreateTagModal" class="btn btn-xs btn-ghost btn-circle" title="Create new tag">
                    <x-icon name="fas.plus" class="w-3 h-3" />
                </button>
            </label>
            <div wire:key="object-tags-modal-{{ $object->id }}" wire:ignore>
                <input
                    id="tag-input-modal-{{ $object->id }}"
                    data-tagify
                    data-initial="tag-initial-modal-{{ $object->id }}"
                    data-suggestions-id="tag-suggestions-modal-{{ $object->id }}"
                    aria-label="Tags"
                    class="input input-bordered w-full"
                    placeholder="Add tags..."
                />
                <script type="application/json" id="tag-initial-modal-{{ $object->id }}">
                    {!! json_encode($object->tags->map(fn($tag) => ['value' => (string) $tag->name, 'type' => $tag->type ? (string) $tag->type : null])->values()->all()) !!}
                </script>
                <script type="application/json" id="tag-suggestions-modal-{{ $object->id }}">
                    {!! json_encode(\Spatie\Tags\Tag::query()->select(['name', 'type'])->get()->map(fn($tag) => ['value' => (string) $tag->name, 'type' => $tag->type ? (string) $tag->type : null])->values()->all()) !!}
                </script>
            </div>
            <label class="label">
                <span class="label-text-alt">Type to search or create new tags.<br />Use "type:name" format for custom types.</span>
            </label>
        </div>

        <!-- Current Tags Display -->
        @if ($object->tags->isNotEmpty())
        <div class="form-control">
            <label class="label">
                <span class="label-text">Current Tags</span>
            </label>
            <div class="flex flex-wrap gap-2">
                @foreach ($object->tags as $tag)
                <x-tag-ref :tag="$tag" />
                @endforeach
            </div>
        </div>
        @endif
    </div>

    <div class="flex gap-3 mt-6">
        <x-button label="Done" class="btn-primary" @click="$wire.dispatch('close-modal')" />
    </div>

    <!-- Create Tag Modal -->
    <x-modal wire:model="showCreateTagModal" title="Create New Tag" subtitle="Define a new tag with a specific type" separator>
        <livewire:create-tag :key="'create-tag-object-modal-' . $object->id" @tag-created="handleTagCreated" />
    </x-modal>
</div>
