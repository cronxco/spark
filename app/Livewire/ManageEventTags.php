<?php

namespace App\Livewire;

use App\Models\Event;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Spatie\Tags\Tag;

class ManageEventTags extends Component
{
    public Event $event;

    public bool $showCreateTagModal = false;

    protected $listeners = [
        'tag-created' => 'handleTagCreated',
    ];

    public function mount(Event $event): void
    {
        // Ensure user owns this event through integration
        $integration = $event->integration;
        if (! $integration || $integration->user_id !== Auth::id()) {
            abort(403);
        }

        $this->event = $event->load(['tags']);
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

        // If type not explicitly provided, infer from value prefix (e.g., type:label or type_label)
        $detectedType = $type !== null ? trim($type) : null;
        if ($detectedType === null) {
            if (preg_match('/^([A-Za-z0-9-]+)[_:](.+)$/', $name, $m) === 1) {
                $detectedType = strtolower($m[1]);
                $name = trim($m[2]);
            }
        } else {
            // Strip matching prefix from the visible value if present
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
        // Ensure type persisted in case library returned an existing tag without the type set
        if (($tag->type ?? null) !== $detectedType) {
            $tag->type = $detectedType;
            $tag->save();
        }

        $this->event->attachTag($tag);
        $this->event->refresh()->loadMissing('tags');
        Log::info('Tag added to event', ['event_id' => (string) $this->event->id, 'tag' => $name, 'type' => $detectedType, 'tags_now' => $this->event->tags->pluck('name')->all()]);

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

        // If type not explicitly provided, infer from value prefix (e.g., type:label or type_label)
        $detectedType = $type !== null ? trim($type) : null;
        if ($detectedType === null) {
            if (preg_match('/^([A-Za-z0-9-]+)[_:](.+)$/', $name, $m) === 1) {
                $detectedType = strtolower($m[1]);
                $name = trim($m[2]);
            }
        } else {
            // Strip matching prefix from the visible value if present
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

        $this->event->detachTag($name, $detectedType);
        $this->event->refresh()->loadMissing('tags');
        Log::info('Tag removed from event', ['event_id' => (string) $this->event->id, 'tag' => $name, 'type' => $detectedType, 'tags_now' => $this->event->tags->pluck('name')->all()]);

        $this->dispatch('tags-updated');
    }

    public function openCreateTagModal(): void
    {
        $this->showCreateTagModal = true;
    }

    public function handleTagCreated(): void
    {
        $this->event->refresh()->loadMissing('tags');
        $this->showCreateTagModal = false;
    }

    public function render()
    {
        return view('livewire.manage-event-tags');
    }
}
