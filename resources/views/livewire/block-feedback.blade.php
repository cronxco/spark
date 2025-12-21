<?php

use App\Models\Block;
use App\Services\AgentWorkingMemoryService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public Block $block;
    public ?int $userRating = null;
    public bool $isDismissed = false;
    public bool $showFeedback = false;

    public function mount(): void
    {
        $this->loadFeedback();
    }

    public function loadFeedback(): void
    {
        $userId = Auth::id();
        $feedback = $this->block->metadata['user_feedback'] ?? [];

        $this->userRating = $feedback[$userId]['rating'] ?? null;
        $this->isDismissed = $feedback[$userId]['dismissed'] ?? false;
    }

    public function rate(int $rating): void
    {
        $userId = Auth::id();

        $metadata = $this->block->metadata;
        $metadata['user_feedback'] = $metadata['user_feedback'] ?? [];
        $metadata['user_feedback'][$userId] = [
            'rating' => $rating,
            'dismissed' => false,
            'rated_at' => now()->toIso8601String(),
        ];

        $this->block->metadata = $metadata;
        $this->block->save();

        $this->userRating = $rating;
        $this->isDismissed = false;

        // Store in working memory for agents to learn from
        $this->storeInWorkingMemory($rating, false);

        $this->success('Thank you for your feedback!');
    }

    public function dismiss(): void
    {
        $userId = Auth::id();

        $metadata = $this->block->metadata;
        $metadata['user_feedback'] = $metadata['user_feedback'] ?? [];
        $metadata['user_feedback'][$userId] = [
            'rating' => null,
            'dismissed' => true,
            'dismissed_at' => now()->toIso8601String(),
        ];

        $this->block->metadata = $metadata;
        $this->block->save();

        $this->userRating = null;
        $this->isDismissed = true;

        // Store in working memory for agents to learn from
        $this->storeInWorkingMemory(null, true);

        $this->info('Insight dismissed');
    }

    public function undoDismiss(): void
    {
        $userId = Auth::id();

        $metadata = $this->block->metadata;
        if (isset($metadata['user_feedback'][$userId])) {
            unset($metadata['user_feedback'][$userId]);
        }

        $this->block->metadata = $metadata;
        $this->block->save();

        $this->userRating = null;
        $this->isDismissed = false;

        $this->success('Insight restored');
    }

    protected function storeInWorkingMemory(?int $rating, bool $dismissed): void
    {
        $workingMemory = app(AgentWorkingMemoryService::class);
        $user = Auth::user();

        $workingMemory->storeUserFeedback($user->id, [
            'insight_id' => $this->block->id,
            'block_type' => $this->block->block_type,
            'rating' => $rating,
            'dismissed' => $dismissed,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    #[On('block-updated')]
    public function refreshBlock(): void
    {
        $this->block->refresh();
        $this->loadFeedback();
    }
}; ?>

<div class="flex items-center gap-2">
    @if ($isDismissed)
        {{-- Dismissed state --}}
        <div class="flex items-center gap-2 opacity-50">
            <x-icon name="o-eye-slash" class="w-4 h-4" />
            <span class="text-xs">Dismissed</span>
            <button
                wire:click="undoDismiss"
                class="btn btn-ghost btn-xs"
                title="Undo dismiss"
            >
                <x-icon name="o-arrow-uturn-left" class="w-3 h-3" />
            </button>
        </div>
    @else
        {{-- Rating buttons --}}
        <div class="flex items-center gap-1">
            @foreach ([1, 2, 3, 4, 5] as $star)
                <button
                    wire:click="rate({{ $star }})"
                    class="btn btn-ghost btn-xs btn-circle {{ $userRating >= $star ? 'text-warning' : 'text-base-content/30' }}"
                    title="Rate {{ $star }} star{{ $star > 1 ? 's' : '' }}"
                >
                    <x-icon
                        :name="$userRating >= $star ? 's-star' : 'o-star'"
                        class="w-4 h-4"
                    />
                </button>
            @endforeach
        </div>

        {{-- Dismiss button --}}
        <button
            wire:click="dismiss"
            class="btn btn-ghost btn-xs"
            title="Not useful"
        >
            <x-icon name="o-x-mark" class="w-4 h-4" />
        </button>
    @endif
</div>
