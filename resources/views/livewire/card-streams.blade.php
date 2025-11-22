<?php

use App\Cards\CardRegistry;
use App\Cards\Streams\StreamDefinition;
use Illuminate\Support\Facades\Cache;

use function Livewire\Volt\computed;
use function Livewire\Volt\layout;
use function Livewire\Volt\mount;
use function Livewire\Volt\on;
use function Livewire\Volt\state;

state([
    'streamId' => 'day',
    'date' => null,
    'currentIndex' => 0,
    'isOpen' => false,
    'cardData' => [],
    'prefetchedData' => [],
    'viewState' => [],
    'sessionCacheKey' => null,
]);

layout('components.layouts.app');

mount(function (?string $streamId = null, ?string $date = null) {
    $user = auth()->guard('web')->user();
    $this->streamId = $streamId ?? 'day';
    $this->date = $date ?? user_today($user)->format('Y-m-d');
    $this->currentIndex = 0;
    $this->isOpen = false;
});

$streamDefinition = computed(function () {
    return StreamDefinition::find($this->streamId);
});

$cards = computed(function () {
    $user = auth()->guard('web')->user();
    if (! $user) {
        return collect();
    }

    // If stream is open and we have a cache key, use cached cards
    if ($this->isOpen && $this->sessionCacheKey) {
        $cached = Cache::get($this->sessionCacheKey);
        if ($cached) {
            return $cached;
        }
    }

    // Otherwise, get fresh cards
    return CardRegistry::getEligibleCards($this->streamId, $user, $this->date);
});

$currentCard = computed(function () {
    return $this->cards->get($this->currentIndex);
});

$cardsMeta = computed(function () {
    return $this->cards->map(function ($card) {
        return [
            'id' => $card->getId(),
            'requiresInteraction' => $card->requiresInteraction(),
        ];
    })->toArray();
});

$cardViewData = computed(function () {
    if (! $this->currentCard) {
        return [];
    }

    $cardId = $this->currentCard->getId();

    // Return cached data if available
    if (isset($this->cardData[$cardId])) {
        return $this->cardData[$cardId];
    }

    // Load data for current card
    $user = auth()->guard('web')->user();
    if (! $user) {
        return [];
    }

    $data = $this->currentCard->getData($user, $this->date);
    $this->cardData[$cardId] = $data;

    // Prefetch next card data in background
    $this->prefetchNextCard();

    return $data;
});

$prefetchNextCard = function (): void {
    $nextIndex = $this->currentIndex + 1;
    if ($nextIndex >= $this->cards->count()) {
        return;
    }

    $nextCard = $this->cards->get($nextIndex);
    if (! $nextCard) {
        return;
    }

    $cardId = $nextCard->getId();
    if (isset($this->prefetchedData[$cardId]) || isset($this->cardData[$cardId])) {
        return; // Already prefetched or loaded
    }

    $user = auth()->guard('web')->user();
    if (! $user) {
        return;
    }

    $this->prefetchedData[$cardId] = $nextCard->getData($user, $this->date);
};

$open = function (?string $streamId = null, ?string $date = null): void {
    if ($streamId) {
        $this->streamId = $streamId;
    }
    if ($date) {
        $this->date = $date;
    }
    $this->currentIndex = 0;
    $this->cardData = [];
    $this->prefetchedData = [];

    // Get and filter cards BEFORE opening
    $user = auth()->guard('web')->user();
    if (! $user) {
        return;
    }

    $allCards = CardRegistry::getEligibleCards($this->streamId, $user, $this->date);

    // Filter cards based on view state
    $filtered = $allCards->filter(function ($card) {
        $cardId = $card->getId();
        $cardState = $this->viewState[$cardId] ?? null;

        if (! $cardState) {
            return true;
        }

        if (! ($cardState['viewed'] ?? false)) {
            return true;
        }

        if ($card->requiresInteraction() && ! ($cardState['interacted'] ?? false)) {
            return true;
        }

        return false;
    })->values();

    if ($filtered->isEmpty()) {
        return;
    }

    // Cache the filtered cards for this session
    $this->sessionCacheKey = 'card_stream_' . $user->id . '_' . $this->streamId . '_' . $this->date . '_' . uniqid();
    Cache::put($this->sessionCacheKey, $filtered, now()->addMinutes(5));

    $this->isOpen = true;

    // Dispatch event to Alpine.js to skip to first visible card
    // Pass the cards metadata so Alpine doesn't need to fetch it
    $this->dispatch('stream-opened', cardsMeta: $this->cardsMeta);
};

$close = function (): void {
    // Clear cache
    if ($this->sessionCacheKey) {
        Cache::forget($this->sessionCacheKey);
        $this->sessionCacheKey = null;
    }

    $this->isOpen = false;
    $this->currentIndex = 0;
    $this->cardData = [];
    $this->prefetchedData = [];
    $this->viewState = [];

    // Dispatch event to re-check FAB visibility
    $this->dispatch('card-stream-closed');
};

$nextCard = function (): void {
    if ($this->currentIndex < $this->cards->count() - 1) {
        $this->currentIndex++;

        // Move prefetched data to cardData if available
        $cardId = $this->currentCard?->getId();
        if ($cardId && isset($this->prefetchedData[$cardId])) {
            $this->cardData[$cardId] = $this->prefetchedData[$cardId];
            unset($this->prefetchedData[$cardId]);
        }
    } else {
        // Reached end of stream
        $this->close();
    }
};

$previousCard = function (): void {
    if ($this->currentIndex > 0) {
        $this->currentIndex--;
    }
};

$goToCard = function (int $index): void {
    if ($index >= 0 && $index < $this->cards->count()) {
        $this->currentIndex = $index;

        // Move prefetched data to cardData if available
        $cardId = $this->currentCard?->getId();
        if ($cardId && isset($this->prefetchedData[$cardId])) {
            $this->cardData[$cardId] = $this->prefetchedData[$cardId];
            unset($this->prefetchedData[$cardId]);
        }
    }
};

// Listen for check-in saved event to mark as interacted
on(['checkin-saved' => function () {
    // Dispatch event to mark card as interacted
    $currentCardId = $this->currentCard?->getId();
    if ($currentCardId) {
        $this->dispatch('card-interacted-server', cardId: $currentCardId);
    }
}]);

// Listen for external open event
on(['open-card-stream' => function (string $streamId, string $date) {
    $this->open($streamId, $date);
}]);

?>

<div x-data="{
    livewireReady: false,
    userId: '{{ auth()->id() }}',
    currentDate: '{{ $date }}',
    streamId: '{{ $streamId }}',
    currentSessionCards: new Set(),

    getStorageKey() {
        return `spark_card_views_${this.userId}_${this.currentDate}`;
    },

    getCardState() {
        if (!this.userId) return {};

        try {
            const stored = localStorage.getItem(this.getStorageKey());
            return stored ? JSON.parse(stored) : {};
        } catch (e) {
            console.error('Failed to parse card state:', e);
            return {};
        }
    },

    saveCardState(state) {
        if (!this.userId) return;

        try {
            localStorage.setItem(this.getStorageKey(), JSON.stringify(state));
        } catch (e) {
            console.error('Failed to save card state:', e);
        }
    },

    markCardViewed(cardId) {
        // Add to current session set for navigation
        this.currentSessionCards.add(cardId);

        const state = this.getCardState();

        if (!state[this.streamId]) {
            state[this.streamId] = {};
        }

        if (!state[this.streamId][cardId]) {
            state[this.streamId][cardId] = {
                viewed: true,
                interacted: false,
                timestamp: new Date().toISOString()
            };
        } else {
            state[this.streamId][cardId].viewed = true;
        }

        this.saveCardState(state);

        console.log('Card marked as viewed:', cardId, state);
    },

    markCardInteracted(cardId) {
        const state = this.getCardState();

        if (!state[this.streamId]) {
            state[this.streamId] = {};
        }

        if (!state[this.streamId][cardId]) {
            state[this.streamId][cardId] = {
                viewed: true,
                interacted: true,
                timestamp: new Date().toISOString()
            };
        } else {
            state[this.streamId][cardId].interacted = true;
            state[this.streamId][cardId].timestamp = new Date().toISOString();
        }

        this.saveCardState(state);
    },

    isCardViewed(cardId) {
        const state = this.getCardState();
        return state[this.streamId]?.[cardId]?.viewed ?? false;
    },

    isCardInteracted(cardId) {
        const state = this.getCardState();
        return state[this.streamId]?.[cardId]?.interacted ?? false;
    },

    checkMidnightReset() {
        // Note: Using browser's local date is correct here
        // The server passes currentDate in user's timezone format (Y-m-d)
        const now = new Date();
        const today = now.toISOString().split('T')[0];

        // If current date in component doesn't match today, clear old data
        if (this.currentDate !== today) {
            const key = this.getStorageKey();
            localStorage.removeItem(key);
        }
    },

    purgeOldEntries() {
        if (!this.userId) return;

        const now = new Date();
        const sevenDaysAgo = new Date(now.getTime() - (7 * 24 * 60 * 60 * 1000));
        const prefix = `spark_card_views_${this.userId}_`;

        try {
            const keysToRemove = [];

            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key && key.startsWith(prefix)) {
                    // Extract date from key: spark_card_views_{userId}_{date}
                    const parts = key.split('_');
                    const dateStr = parts[parts.length - 1];
                    const entryDate = new Date(dateStr);

                    if (entryDate < sevenDaysAgo) {
                        keysToRemove.push(key);
                    }
                }
            }

            keysToRemove.forEach(key => localStorage.removeItem(key));
        } catch (e) {
            console.error('Failed to purge old entries:', e);
        }
    },

    shouldShowCard(cardId, requiresInteraction) {
        // If card was already shown in this session, include it for navigation
        if (this.currentSessionCards.has(cardId)) {
            return true;
        }

        const state = this.getCardState();
        const cardState = state[this.streamId]?.[cardId];

        if (!cardState || !cardState.viewed) {
            return true; // Not viewed yet, show it
        }

        // If viewed and requires interaction and not completed, show it
        if (requiresInteraction && !cardState.interacted) {
            return true;
        }

        // Otherwise hide (already viewed and completed or non-interactive)
        return false;
    },

    findNextVisibleCard(startIndex, cardsMeta) {
        for (let i = startIndex; i < cardsMeta.length; i++) {
            if (this.shouldShowCard(cardsMeta[i].id, cardsMeta[i].requiresInteraction)) {
                return i;
            }
        }
        return null;
    },

    findPreviousVisibleCard(startIndex, cardsMeta) {
        for (let i = startIndex; i >= 0; i--) {
            if (this.shouldShowCard(cardsMeta[i].id, cardsMeta[i].requiresInteraction)) {
                return i;
            }
        }
        return null;
    },

    skipToNextVisible() {
        // Just call the regular Livewire nextCard method
        // No filtering during navigation - that only happens on stream open
        this.$wire.call('nextCard');
    },

    skipToPreviousVisible() {
        // Just call the regular Livewire previousCard method
        this.$wire.call('previousCard');
    },

    handleOpen(event) {
        // Clear session tracking when stream opens
        this.currentSessionCards.clear();

        // Get current view state and pass to Livewire for filtering
        const state = this.getCardState();
        const streamState = state[this.streamId] || {};

        console.log('=== Stream Opening ===');
        console.log('View state being sent to Livewire:', streamState);

        // Set the view state in Livewire so it can filter cards
        this.$wire.viewState = streamState;

        // Wait for Livewire to update, then reset to first card
        this.$nextTick(() => {
            this.$wire.call('goToCard', 0);
            console.log('Stream opened, starting at first visible card');
        });
    },

    init() {
        this.$nextTick(() => {
            this.livewireReady = true;
        });

        // Purge old entries on init
        this.purgeOldEntries();

        // Check for midnight reset every minute
        setInterval(() => this.checkMidnightReset(), 60000);

        // Listen for server-side card-interacted event
        Livewire.on('card-interacted-server', (event) => {
            if (event.cardId) {
                this.markCardInteracted(event.cardId);
            }
        });

        // Add global debug helper
        window.debugCardViews = () => {
            const state = this.getCardState();
            console.log('=== Card View State ===');
            console.log('Storage Key:', this.getStorageKey());
            console.log('Current Session Cards:', Array.from(this.currentSessionCards));
            console.log('Full State:', state);
            console.log('Stream ID:', this.streamId);
            console.log('Cards in this stream:', state[this.streamId] || 'None');
            return state;
        };

        console.log('Card tracking initialized. Run debugCardViews() to see current state.');
    }
}"
     x-init="init()"
     @card-viewed.window="markCardViewed($event.detail.cardId)"
     @card-interacted.window="markCardInteracted($event.detail.cardId)"
     @next-card.window="skipToNextVisible()"
     @previous-card.window="skipToPreviousVisible()"
     @stream-opened="handleOpen($event)">
    @if ($isOpen)
    <!-- Mobile: Full-screen overlay -->
    <div
        class="md:hidden fixed inset-0 bg-base-100 z-50 flex flex-col"
        x-data="{
            touchStartX: 0,
            touchEndX: 0,
            handleSwipe() {
                const diff = this.touchEndX - this.touchStartX;
                if (diff < -50) $dispatch('next-card');
                if (diff > 50) $dispatch('previous-card');
            }
        }"
        @touchstart="touchStartX = $event.changedTouches[0].screenX"
        @touchend="touchEndX = $event.changedTouches[0].screenX; handleSwipe()"
        @keydown.escape.window="$wire.close()"
        @keydown.left.window="$dispatch('previous-card')"
        @keydown.right.window="$dispatch('next-card')">

        <!-- Progress bars -->
        <div class="flex gap-1 p-4 pb-2">
            @foreach ($this->cards as $index => $card)
            <div class="flex-1 h-1 bg-base-300 rounded-full overflow-hidden cursor-pointer"
                wire:click="goToCard({{ $index }})">
                <div class="h-full bg-primary transition-all duration-300 {{ $index < $currentIndex ? 'w-full' : ($index === $currentIndex ? 'w-full' : 'w-0') }}"></div>
            </div>
            @endforeach
        </div>

        <!-- Close button -->
        <div class="absolute top-4 right-4 z-10">
            <button wire:click="close" class="btn btn-circle btn-ghost btn-sm">
                <x-icon name="fas.xmark" class="w-5 h-5" />
            </button>
        </div>

        <!-- Card content -->
        <div class="flex-1 overflow-hidden"
            x-data="{
                show: true,
                cardId: '{{ $this->currentCard?->getId() }}',
                markAsViewed() {
                    if (this.cardId) {
                        $dispatch('card-viewed', { cardId: this.cardId });
                    }
                }
            }"
            x-init="$nextTick(() => markAsViewed())"
            x-show="show"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-x-8"
            x-transition:enter-end="opacity-100 translate-x-0"
            wire:key="card-{{ $currentIndex }}-{{ $this->currentCard?->getId() }}">
            @if ($this->currentCard)
            @include($this->currentCard->getViewPath(), $this->cardViewData)
            @endif
        </div>

        <!-- Navigation hints -->
        <div class="flex justify-between items-center p-4 text-base-content/40 text-sm">
            <div class="flex items-center gap-2">
                @if ($currentIndex > 0)
                <button @click="$dispatch('previous-card')" class="flex items-center gap-1">
                    <x-icon name="fas.chevron-left" class="w-4 h-4" />
                    <span>Previous</span>
                </button>
                @endif
            </div>
            <div>
                {{ $currentIndex + 1 }} / {{ $this->cards->count() }}
            </div>
            <div class="flex items-center gap-2">
                @if ($currentIndex < $this->cards->count() - 1)
                <button @click="$dispatch('next-card')" class="flex items-center gap-1">
                    <span>Next</span>
                    <x-icon name="fas.chevron-right" class="w-4 h-4" />
                </button>
                @else
                <button wire:click="close" class="flex items-center gap-1">
                    <span>Done</span>
                    <x-icon name="fas.check" class="w-4 h-4" />
                </button>
                @endif
            </div>
        </div>
    </div>

    <!-- Desktop: Modal with portrait aspect ratio -->
    <div class="hidden md:block">
        <input type="checkbox" class="modal-toggle" checked />
        <div class="modal modal-open" role="dialog">
            <div
                class="modal-box w-full max-w-md h-[85vh] max-h-[800px] p-0 relative"
                x-data="{
                    touchStartX: 0,
                    touchEndX: 0,
                    handleSwipe() {
                        const diff = this.touchEndX - this.touchStartX;
                        if (diff < -50) $dispatch('next-card');
                        if (diff > 50) $dispatch('previous-card');
                    }
                }"
                @touchstart="touchStartX = $event.changedTouches[0].screenX"
                @touchend="touchEndX = $event.changedTouches[0].screenX; handleSwipe()"
                @keydown.escape.window="$wire.close()"
                @keydown.left.window="$dispatch('previous-card')"
                @keydown.right.window="$dispatch('next-card')">

                <!-- Progress bars -->
                <div class="flex gap-1 p-4 pb-2">
                    @foreach ($this->cards as $index => $card)
                    <div class="flex-1 h-1 bg-base-300 rounded-full overflow-hidden cursor-pointer"
                        wire:click="goToCard({{ $index }})">
                        <div class="h-full bg-primary transition-all duration-300 {{ $index < $currentIndex ? 'w-full' : ($index === $currentIndex ? 'w-full' : 'w-0') }}"></div>
                    </div>
                    @endforeach
                </div>

                <!-- Close button -->
                <div class="absolute top-4 right-4 z-10">
                    <button wire:click="close" class="btn btn-circle btn-ghost btn-sm">
                        <x-icon name="fas.xmark" class="w-5 h-5" />
                    </button>
                </div>

                <!-- Card content -->
                <div class="h-[calc(85vh-4rem)] max-h-[736px] overflow-hidden"
                    x-data="{
                        show: true,
                        cardId: '{{ $this->currentCard?->getId() }}',
                        markAsViewed() {
                            if (this.cardId) {
                                $dispatch('card-viewed', { cardId: this.cardId });
                            }
                        }
                    }"
                    x-init="$nextTick(() => markAsViewed())"
                    x-show="show"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 translate-x-8"
                    x-transition:enter-end="opacity-100 translate-x-0"
                    wire:key="card-{{ $currentIndex }}-{{ $this->currentCard?->getId() }}">
                    @if ($this->currentCard)
                    @include($this->currentCard->getViewPath(), $this->cardViewData)
                    @endif
                </div>

                <!-- Navigation hints -->
                <div class="flex justify-between items-center p-4 text-base-content/40 text-sm border-t border-base-300">
                    <div class="flex items-center gap-2">
                        @if ($currentIndex > 0)
                        <button @click="$dispatch('previous-card')" class="flex items-center gap-1 hover:text-base-content">
                            <x-icon name="fas.chevron-left" class="w-4 h-4" />
                            <span>Previous</span>
                        </button>
                        @endif
                    </div>
                    <div>
                        {{ $currentIndex + 1 }} / {{ $this->cards->count() }}
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($currentIndex < $this->cards->count() - 1)
                        <button @click="$dispatch('next-card')" class="flex items-center gap-1 hover:text-base-content">
                            <span>Next</span>
                            <x-icon name="fas.chevron-right" class="w-4 h-4" />
                        </button>
                        @else
                        <button wire:click="close" class="flex items-center gap-1 hover:text-base-content">
                            <span>Done</span>
                            <x-icon name="fas.check" class="w-4 h-4" />
                        </button>
                        @endif
                    </div>
                </div>
            </div>
            <div class="modal-backdrop" wire:click="close">
                <button>close</button>
            </div>
        </div>
    </div>
    @endif
</div>
