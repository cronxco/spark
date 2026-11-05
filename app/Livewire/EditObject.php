<?php

namespace App\Livewire;

use App\Models\EventObject;
use App\Services\SourceFieldGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class EditObject extends Component
{
    public EventObject $object;

    public ?string $title = null;

    public ?string $type = null;

    public ?string $concept = null;

    public ?string $url = null;

    /**
     * Whether the title, type, concept and URL can be edited here. They can't
     * when the object comes from an integration or is locked.
     */
    public bool $sourceFieldsEditable = true;

    public function mount(EventObject $object, SourceFieldGuard $sourceFields): void
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
        $this->sourceFieldsEditable = $sourceFields->sourceFieldsEditable($object);
    }

    public function save(SourceFieldGuard $sourceFields): void
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'type' => 'nullable|string|max:100',
            'concept' => 'nullable|string|max:100',
            'url' => 'nullable|url|max:500',
        ]);

        $attributes = [
            'title' => $this->title,
            'type' => $this->type,
            'concept' => $this->concept,
            'url' => $this->url,
        ];

        try {
            $sourceFields->assertEditable($this->object, $attributes);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->object->update($attributes);

        $this->dispatch('object-updated');
        $this->dispatch('close-modal');
    }

    public function render()
    {
        return view('livewire.edit-object');
    }
}
