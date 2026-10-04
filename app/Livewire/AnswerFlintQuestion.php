<?php

namespace App\Livewire;

use App\Models\Block;
use App\Services\Api\ResourceVersion;
use App\Services\Flint\FlintQuestionActionService;
use App\Support\FlintQuestion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class AnswerFlintQuestion extends Component
{
    public Block $block;

    public string $answer = '';

    public ?string $answer_note = null;

    public bool $answered = false;

    public bool $skipped = false;

    public bool $editing = false;

    /**
     * The version of the question this page was rendered from, sent as the
     * If-Match of every write so an answer given on another device is never
     * silently replaced from a stale tab.
     */
    public string $version = '';

    public function mount(Block $block): void
    {
        $event = $block->event;
        $integration = $event?->integration;

        if (! $integration || $integration->user_id !== Auth::id()) {
            abort(403);
        }

        $this->block = $block;
        $this->hydrateFromBlock();
    }

    public function submit(): void
    {
        $this->validate([
            'answer' => ['required', 'string', 'max:1000'],
            'answer_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->act([
            'action' => $this->answered ? 'correct' : 'answer',
            'answer' => $this->answer,
            'context' => $this->answer_note,
        ]);
    }

    /** "Not relevant": retires an open question without answering it (ratified decision D12). */
    public function skip(): void
    {
        $this->act(['action' => 'skip']);
    }

    public function edit(): void
    {
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->hydrateFromBlock();
    }

    public function render()
    {
        return view('livewire.answer-flint-question');
    }

    /** @param array{action:string, answer?:string|null, context?:string|null} $input */
    private function act(array $input): void
    {
        $result = app(FlintQuestionActionService::class)->record(
            Auth::user(),
            (string) $this->block->id,
            $input,
            $this->version,
            (string) Str::uuid(),
        );

        if ($result['status'] >= 400) {
            $this->addError('answer', $result['status'] === 412
                ? __('This question changed elsewhere. Refresh the page to see the latest answer.')
                : ($result['message'] ?? __('The answer could not be saved.')));

            return;
        }

        $this->block = $this->block->fresh();
        $this->hydrateFromBlock();
    }

    private function hydrateFromBlock(): void
    {
        $meta = $this->block->metadata ?? [];
        $this->answered = FlintQuestion::isAnswered($this->block);
        $this->skipped = FlintQuestion::status($this->block) === 'skipped';
        $this->answer = $meta['answer'] ?? '';
        $this->answer_note = $meta['answer_note'] ?? null;
        $this->editing = false;
        $this->version = app(ResourceVersion::class)->etag($this->block);
    }
}
