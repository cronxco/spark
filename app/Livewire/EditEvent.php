<?php

namespace App\Livewire;

use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class EditEvent extends Component
{
    public Event $event;

    public ?string $action = null;

    public ?float $value = null;

    public ?float $value_multiplier = null;

    public ?string $value_unit = null;

    public ?string $time = null;

    public function mount(Event $event): void
    {
        // Ensure user owns this event through integration
        $integration = $event->integration;
        if (! $integration || $integration->user_id !== Auth::id()) {
            abort(403);
        }

        $this->event = $event;
        $this->action = $event->action;
        $this->value = $event->value;
        $this->value_multiplier = $event->value_multiplier;
        $this->value_unit = $event->value_unit;
        $this->time = $event->time?->format('Y-m-d\TH:i');
    }

    public function save(): void
    {
        $this->validate([
            'action' => 'required|string|max:255',
            'value' => 'nullable|numeric',
            'value_multiplier' => 'nullable|numeric',
            'value_unit' => 'nullable|string|max:50',
            'time' => 'nullable|date',
        ]);

        $this->event->update([
            'action' => $this->action,
            'value' => $this->value,
            'value_multiplier' => $this->value_multiplier,
            'value_unit' => $this->value_unit,
            'time' => $this->time ? Carbon::parse($this->time) : $this->event->time,
        ]);

        $this->dispatch('event-updated');
        $this->dispatch('close-modal');
    }

    public function render()
    {
        return view('livewire.edit-event');
    }
}
