<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Spatie\Tags\Tag;

class CreateTag extends Component
{
    public string $name = '';

    public string $type = '';

    public string $customType = '';

    public bool $showModal = false;

    protected $rules = [
        'name' => 'required|string|max:255',
        'type' => 'required|string',
        'customType' => 'required_if:type,custom|string|max:100',
    ];

    protected $messages = [
        'name.required' => 'Please enter a tag name',
        'type.required' => 'Please select a tag type',
        'customType.required_if' => 'Please enter a custom type name',
    ];

    public function mount(): void
    {
        //
    }

    public function save(): void
    {
        $this->validate();

        // Determine the final type
        $finalType = $this->type === 'custom' ? $this->convertToSnakeCase($this->customType) : $this->type;

        // Create or find the tag
        $tag = Tag::findOrCreate($this->name, $finalType);

        // Ensure type is set correctly
        if ($tag->type !== $finalType) {
            $tag->type = $finalType;
            $tag->save();
        }

        // Dispatch success event
        $this->dispatch('tag-created', name: $this->name, type: $finalType);

        // Reset form
        $this->reset(['name', 'type', 'customType']);

        // Close modal
        $this->showModal = false;
    }

    public function getExistingTypes(): array
    {
        // Get all distinct tag types from the database
        $types = Tag::distinct()
            ->pluck('type')
            ->filter()
            ->mapWithKeys(function ($type) {
                // Convert snake_case to Title Case for display
                $label = str($type)->replace('_', ' ')->title()->toString();

                return [$type => $label];
            })
            ->sortBy(fn ($label) => $label)
            ->toArray();

        return $types;
    }

    public function render(): View
    {
        return view('livewire.create-tag');
    }

    /**
     * Convert a string to snake_case
     */
    protected function convertToSnakeCase(string $value): string
    {
        return str($value)->snake()->toString();
    }
}
