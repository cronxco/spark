<?php

namespace App\Livewire;

use App\Models\Block;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
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

    public function mount(Block $block): void
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
    }

    public function save(): void
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

        $this->block->update([
            'title' => $this->title,
            'block_type' => $this->block_type,
            'value' => $this->value,
            'value_multiplier' => $this->value_multiplier,
            'value_unit' => $this->value_unit,
            'time' => $this->time ? Carbon::parse($this->time) : $this->block->time,
            'url' => $this->url,
        ]);

        $this->dispatch('block-updated');
        $this->dispatch('close-modal');
    }

    public function render()
    {
        return view('livewire.edit-block');
    }
}
