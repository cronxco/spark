<?php

namespace App\Livewire;

use App\Models\EventObject;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class EditObject extends Component
{
    public EventObject $object;

    public ?string $title = null;
    public ?string $type = null;
    public ?string $concept = null;
    public ?string $url = null;

    public function mount(EventObject $object): void
    {
        // Ensure user owns this object
        if ($object->user_id !== Auth::id()) {
            abort(403);
        }

        $this->object = $object;
        $this->title = $object->title;
        $this->type = $object->type;
        $this->concept = $object->concept;
        $this->url = $object->url;
    }

    public function save(): void
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'type' => 'nullable|string|max:100',
            'concept' => 'nullable|string|max:100',
            'url' => 'nullable|url|max:500',
        ]);

        $this->object->update([
            'title' => $this->title,
            'type' => $this->type,
            'concept' => $this->concept,
            'url' => $this->url,
        ]);

        $this->dispatch('object-updated');
        $this->dispatch('close-modal');
    }

    public function render()
    {
        return view('livewire.edit-object');
    }
}
