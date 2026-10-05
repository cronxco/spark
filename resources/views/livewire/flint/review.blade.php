<?php

use App\Services\Flint\FlintReviewService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component
{
    use Toast;

    /** @var array<string, string> The candidate transaction picked for each receipt suggestion. */
    public array $chosen = [];

    #[Computed]
    public function items(): array
    {
        return app(FlintReviewService::class)->items(Auth::user());
    }

    public function act(string $kind, string $id, string $action): void
    {
        try {
            app(FlintReviewService::class)->act(Auth::user(), $kind, $id, $action, [
                'transaction_id' => $this->chosen[$id] ?? null,
            ]);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            unset($this->items);
            $this->error('That item is no longer available. The list has been refreshed.');

            return;
        }

        unset($this->chosen[$id]);
        unset($this->items);
        $this->success(match ($action) {
            'confirm' => 'Confirmed.',
            'keep' => 'Kept.',
            'undo' => 'Undone.',
            default => 'Dismissed.',
        });
    }
}; ?>

@php
    $needsDecision = collect($this->items)->filter(fn ($item) => in_array($item['kind'], ['receipt_suggestion', 'link_suggestion'], true));
    $automatic = collect($this->items)->reject(fn ($item) => in_array($item['kind'], ['receipt_suggestion', 'link_suggestion'], true));
@endphp

<div class="space-y-6">
    <p class="text-sm text-base-content/70">
        Decide on suggestions first. Spark's automatic links remain available to undo for
        {{ FlintReviewService::AUTO_DECISION_DAYS }} days.
    </p>

    @foreach ([['Needs your decision', $needsDecision], ['Linked by Spark', $automatic]] as [$heading, $group])
        @if ($group->isNotEmpty())
            <section class="space-y-3" aria-label="{{ $heading }}">
                <h3 class="text-lg font-semibold">{{ $heading }} <span class="text-base-content/60">({{ $group->count() }})</span></h3>

                @foreach ($group as $item)
                    <div wire:key="review-{{ $item['kind'] }}-{{ $item['id'] }}" class="card bg-base-200 shadow-sm">
                        <div class="card-body p-4 gap-3">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                <span class="font-medium">{{ match ($item['kind']) {
                                    'receipt_suggestion' => 'Receipt suggestion',
                                    'receipt_auto_match' => 'Receipt linked automatically',
                                    'link_suggestion' => 'Link suggestion',
                                    default => 'Linked automatically',
                                } }}</span>
                                @if ($item['confidence'] !== null)
                                    <span class="text-base-content/70">{{ round($item['confidence'] * 100) }}% match score</span>
                                @endif
                                @if ($item['created_at'] && ! in_array($item['kind'], ['receipt_suggestion'], true))
                                    <span class="ml-auto text-base-content/60">{{ $heading === 'Linked by Spark' ? 'Linked' : 'Suggested' }} <x-user-time :time="$item['created_at']" format="j M Y, H:i" /></span>
                                @endif
                            </div>

                            <p class="text-sm text-base-content/70">{{ $item['summary'] }}</p>
                            <div class="grid gap-2 md:grid-cols-2">
                                <x-flint-review-event-card :event="$item['subject']" :label="$item['kind'] === 'receipt_suggestion' || $item['kind'] === 'receipt_auto_match' ? 'Receipt' : 'First transaction'" />
                                @if (isset($item['linked']))
                                    <x-flint-review-event-card :event="$item['linked']" :label="$item['kind'] === 'receipt_auto_match' ? 'Transaction' : 'Linked transaction'" />
                                @endif
                            </div>

                            @if ($item['kind'] === 'receipt_suggestion')
                                @if (count($item['candidates']) > 0)
                                    <fieldset class="space-y-2">
                                        <legend class="mb-2 text-sm font-medium">Choose a transaction</legend>
                                        @foreach ($item['candidates'] as $candidate)
                                            <div wire:key="candidate-{{ $item['id'] }}-{{ $candidate['id'] }}" class="flex items-start gap-3">
                                                <input type="radio" class="radio radio-sm mt-3" name="candidate-{{ $item['id'] }}"
                                                    aria-label="Select {{ $candidate['title'] ?: 'transaction' }}"
                                                    wire:model.live="chosen.{{ $item['id'] }}" value="{{ $candidate['id'] }}" />
                                                <div class="min-w-0 flex-1">
                                                    <x-flint-review-event-card :event="$candidate" :label="'Candidate · '.round($candidate['confidence'] * 100).'% match score'" />
                                                </div>
                                            </div>
                                        @endforeach
                                    </fieldset>
                                @else
                                    <p class="text-sm text-base-content/70">No suggested transactions are available now. You can dismiss this suggestion.</p>
                                @endif
                            @elseif ($item['relationship_type'] ?? null)
                                <p class="text-xs text-base-content/60">Relationship: {{ str_replace('_', ' ', $item['relationship_type']) }}</p>
                            @endif

                            <div class="flex flex-wrap gap-2">
                                @foreach ($item['actions'] as $action)
                                    <button type="button"
                                        wire:click="act('{{ $item['kind'] }}', '{{ $item['id'] }}', '{{ $action }}')"
                                        @if ($action === 'undo') wire:confirm="Undo this automatic link? The two events will be unlinked." @endif
                                        @if ($action === 'confirm' && $item['kind'] === 'receipt_suggestion' && ! in_array($this->chosen[$item['id']] ?? null, array_column($item['candidates'], 'id'), true)) disabled @endif
                                        wire:loading.attr="disabled"
                                        class="btn btn-sm {{ $action === 'confirm' ? 'btn-primary' : 'btn-ghost' }}">
                                        {{ match ($action) { 'confirm' => 'Confirm', 'undo' => 'Undo link', default => 'Dismiss' } }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </section>
        @endif
    @endforeach

    @if ($needsDecision->isEmpty() && $automatic->isEmpty())
        <div class="card bg-base-200"><div class="card-body items-center text-center text-base-content/60">
            <x-icon name="o-check-circle" class="w-8 h-8" />
            <p>Nothing to review.</p>
        </div></div>
    @endif
</div>
