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
        }

        unset($this->items);
        $this->success(match ($action) {
            'confirm' => 'Confirmed.',
            'keep' => 'Kept.',
            'undo' => 'Undone.',
            default => 'Dismissed.',
        });
    }
}; ?>

<div class="space-y-4">
    <p class="text-sm text-base-content/70">
        Decisions Spark made by itself, and suggestions it wasn't sure enough to act on. Automatic decisions stay here for
        {{ \App\Services\Flint\FlintReviewService::AUTO_DECISION_DAYS }} days, or until you keep or undo them.
    </p>

    @forelse ($this->items as $item)
        <div wire:key="review-{{ $item['kind'] }}-{{ $item['id'] }}" class="card bg-base-200 shadow">
            <div class="card-body p-4 gap-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2 flex-wrap">
                        <div class="badge badge-outline badge-sm">
                            {{ match ($item['kind']) {
                                'receipt_suggestion' => 'Receipt suggestion',
                                'receipt_auto_match' => 'Receipt linked automatically',
                                'link_suggestion' => 'Link suggestion',
                                default => 'Linked automatically',
                            } }}
                        </div>
                        @if ($item['confidence'] !== null)
                            <div class="badge badge-ghost badge-sm">{{ round($item['confidence'] * 100) }}% confident</div>
                        @endif
                    </div>
                    @if ($item['created_at'])
                        <span class="text-xs text-base-content/50"><x-user-time :time="$item['created_at']" format="j M" /></span>
                    @endif
                </div>

                <div>
                    <div class="font-medium">{{ $item['title'] }}</div>
                    <div class="text-sm text-base-content/70">{{ $item['summary'] }}</div>
                </div>

                @if ($item['kind'] === 'receipt_suggestion')
                    <div class="space-y-1">
                        @foreach ($item['candidates'] as $candidate)
                            <label wire:key="candidate-{{ $item['id'] }}-{{ $candidate['id'] }}" class="flex items-center gap-2 text-sm">
                                <input type="radio" class="radio radio-sm" wire:model="chosen.{{ $item['id'] }}" value="{{ $candidate['id'] }}" />
                                <span>{{ $candidate['title'] ?? 'Transaction' }}</span>
                                <span class="text-base-content/60">{{ $candidate['amount'] }} {{ $candidate['unit'] }}</span>
                                <span class="text-base-content/50">{{ round($candidate['confidence'] * 100) }}%</span>
                            </label>
                        @endforeach
                    </div>
                @elseif (isset($item['linked']))
                    <div class="text-sm text-base-content/70">
                        {{ $item['subject']['title'] ?? 'Transaction' }}
                        <x-icon name="o-arrow-right" class="w-3 h-3 inline" />
                        {{ $item['linked']['title'] ?? 'Transaction' }}
                        <span class="text-base-content/50">({{ str_replace('_', ' ', $item['relationship_type']) }})</span>
                    </div>
                @endif

                <div class="flex flex-wrap gap-2">
                    @foreach ($item['actions'] as $action)
                        <button
                            type="button"
                            wire:click="act('{{ $item['kind'] }}', '{{ $item['id'] }}', '{{ $action }}')"
                            wire:loading.attr="disabled"
                            class="btn btn-sm {{ in_array($action, ['confirm', 'keep'], true) ? 'btn-primary' : 'btn-ghost' }}"
                        >
                            {{ match ($action) {
                                'confirm' => 'Confirm',
                                'keep' => 'Keep',
                                'undo' => 'Undo',
                                default => 'Dismiss',
                            } }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    @empty
        <div class="card bg-base-200">
            <div class="card-body items-center text-center text-base-content/60">
                <x-icon name="o-check-circle" class="w-8 h-8" />
                <p>Nothing to review.</p>
            </div>
        </div>
    @endforelse
</div>
