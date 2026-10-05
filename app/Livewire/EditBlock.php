<?php

namespace App\Livewire;

use App\Models\Block;
use App\Services\SourceFieldGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class EditBlock extends Component
{
    public Block $block;

    public ?string $title = null;

    public ?string $block_type = null;

    public ?float $value = null;

    public ?float $value_multiplier = null;

    public ?string $value_unit = null;

    public ?string $time = null;

    public ?string $url = null;

    /**
     * Whether the title, type and URL can be edited here. They can't when the
     * block comes from an integration.
     */
    public bool $sourceFieldsEditable = true;

    public function mount(Block $block, SourceFieldGuard $sourceFields): void
    {
        // Ensure user owns this block through event->integration
        $event = $block->event;
        $integration = $event?->integration;
        if (! $integration || $integration->user_id !== Auth::id()) {
            abort(403);
        }

        $this->block = $block;
        $this->title = $block->title;
        $this->block_type = $block->block_type;
        $this->value = $block->value;
        $this->value_multiplier = $block->value_multiplier;
        $this->value_unit = $block->value_unit;
        $this->time = $block->time?->format('Y-m-d\TH:i');
        $this->url = $block->url;
        $this->sourceFieldsEditable = $sourceFields->sourceFieldsEditable($block);
    }

    public function save(SourceFieldGuard $sourceFields): void
    {
        $this->validate([
            'title' => 'nullable|string|max:255',
            'block_type' => 'nullable|string|max:100',
            'value' => 'nullable|numeric',
            'value_multiplier' => 'nullable|numeric',
            'value_unit' => 'nullable|string|max:50',
            'time' => 'nullable|date',
            'url' => 'nullable|url|max:500',
        ]);

        $attributes = [
            'title' => $this->title,
            'block_type' => $this->block_type,
            'value' => $this->value,
            'value_multiplier' => $this->value_multiplier,
            'value_unit' => $this->value_unit,
            'time' => $this->time ? Carbon::parse($this->time) : $this->block->time,
            'url' => $this->url,
        ];

        try {
            $sourceFields->assertEditable($this->block, $attributes);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        $this->block->update($attributes);

        $this->dispatch('block-updated');
        $this->dispatch('close-modal');
    }

    public function render()
    {
        return view('livewire.edit-block');
    }
}
