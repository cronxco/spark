<?php

namespace App\Livewire;

use App\Models\Block;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AnswerFlintQuestion extends Component
{
    public Block $block;

    public string $answer = '';

    public ?string $answer_note = null;

    public bool $answered = false;

    public function mount(Block $block): void
    {
        $event = $block->event;
        $integration = $event?->integration;

        if (! $integration || $integration->user_id !== Auth::id()) {
            abort(403);
        }

        $this->block = $block;

        $meta = $block->metadata ?? [];
        $this->answered = ! is_null($meta['answer'] ?? null);
        $this->answer = $meta['answer'] ?? '';
        $this->answer_note = $meta['answer_note'] ?? null;
    }

    public function submit(): void
    {
        $this->validate([
            'answer' => ['required', 'string', 'max:1000'],
            'answer_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->block->metadata = array_merge($this->block->metadata ?? [], [
            'answer' => $this->answer,
            'answer_note' => $this->answer_note ?: null,
            'answered_at' => now()->toIso8601String(),
        ]);

        $this->block->save();

        $this->answered = true;
    }

    public function render()
    {
        return view('livewire.answer-flint-question');
    }
}
