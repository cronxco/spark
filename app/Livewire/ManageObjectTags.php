<?php

namespace App\Livewire;

use App\Models\EventObject;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Spatie\Tags\Tag;

class ManageObjectTags extends Component
{
    public EventObject $object;
    public bool $showCreateTagModal = false;

    protected $listeners = [
        'tag-created' => 'handleTagCreated',
    ];

    public function mount(EventObject $object): void
    {
        // Ensure user owns this object
        if ($object->user_id !== Auth::id()) {
            abort(403);
        }

        $this->object = $object->load(['tags']);
    }

    public function addTag(string $value, ?string $type = null): void
    {
        $name = trim((string) $value);
        if ($name === '') {
            return;
        }

        if (str_starts_with($name, 'tag-whitelist-') || str_starts_with($name, 'tag-initial-')) {
            return;
        }

        // If type not explicitly provided, infer from value prefix
        $detectedType = $type !== null ? trim($type) : null;
        if ($detectedType === null) {
            if (preg_match('/^([A-Za-z0-9-]+)[_:](.+)$/', $name, $m) === 1) {
                $detectedType = strtolower($m[1]);
                $name = trim($m[2]);
            }
        } else {
            if (preg_match('/^' . preg_quote($detectedType, '/') . '[_:](.+)$/i', $name, $m) === 1) {
                $name = trim($m[1]);
            }
        }

        // Default free-form tags to 'spark' unless they are emoji-only
        if ($detectedType === null) {
            $detectedType = preg_match('/^\\p{Extended_Pictographic}(?:[\\x{FE0F}\\x{FE0E}])?(?:\\x{200D}\\p{Extended_Pictographic}(?:[\\x{FE0F}\\x{FE0E}])?)*$/u', $name) === 1
                ? 'emoji'
                : 'spark';
        }

        $tag = Tag::findOrCreate($name, $detectedType);
        if (($tag->type ?? null) !== $detectedType) {
            $tag->type = $detectedType;
            $tag->save();
        }

        $this->object->attachTag($tag);
        $this->object->refresh()->loadMissing('tags');
        Log::info('Tag added to object', ['object_id' => (string) $this->object->id, 'tag' => $name, 'type' => $detectedType]);

        $this->dispatch('tags-updated');
    }

    public function removeTag(string $value, ?string $type = null): void
    {
        $name = trim((string) $value);
        if ($name === '') {
            return;
        }

        if (str_starts_with($name, 'tag-whitelist-') || str_starts_with($name, 'tag-initial-')) {
            return;
        }

        $detectedType = $type !== null ? trim($type) : null;
        if ($detectedType === null) {
            if (preg_match('/^([A-Za-z0-9-]+)[_:](.+)$/', $name, $m) === 1) {
                $detectedType = strtolower($m[1]);
                $name = trim($m[2]);
            }
        } else {
            if (preg_match('/^' . preg_quote($detectedType, '/') . '[_:](.+)$/i', $name, $m) === 1) {
                $name = trim($m[1]);
            }
        }

        if ($detectedType === null) {
            $detectedType = preg_match('/^\\p{Extended_Pictographic}(?:[\\x{FE0F}\\x{FE0E}])?(?:\\x{200D}\\p{Extended_Pictographic}(?:[\\x{FE0F}\\x{FE0E}])?)*$/u', $name) === 1
                ? 'emoji'
                : 'spark';
        }

        $this->object->detachTag($name, $detectedType);
        $this->object->refresh()->loadMissing('tags');
        Log::info('Tag removed from object', ['object_id' => (string) $this->object->id, 'tag' => $name, 'type' => $detectedType]);

        $this->dispatch('tags-updated');
    }

    public function openCreateTagModal(): void
    {
        $this->showCreateTagModal = true;
    }

    public function handleTagCreated(): void
    {
        $this->object->refresh()->loadMissing('tags');
        $this->showCreateTagModal = false;
    }

    public function render()
    {
        return view('livewire.manage-object-tags');
    }
}
