<?php

use App\Services\Flint\FlintRunDispatcher;
use App\Services\Notifications\NotificationArchiver;
use App\Services\Notifications\NotificationFeedService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component
{
    public array $feed = [];

    public function mount(): void
    {
        $this->refreshFeed();
    }

    public function refreshFeed(): void
    {
        $this->feed = app(NotificationFeedService::class)->feed(Auth::user(), limit: 6);
    }

    #[On('run-flint-routine')]
    public function runFlintRoutine(?string $skill = null, ?string $routine = null, string $period = 'morning'): void
    {
        try {
            app(FlintRunDispatcher::class)->dispatch(Auth::user(), skill: $skill, routine: $routine, period: $period);
        } catch (InvalidArgumentException) {
            return;
        }

        $this->refreshFeed();
    }

    public function markAsRead(string $notificationId): void
    {
        Auth::user()->notifications()->whereNull('archived_at')->find($notificationId)?->markAsRead();
        $this->refreshFeed();
    }

    public function markAllAsRead(): void
    {
        Auth::user()->unreadNotifications()->whereNull('archived_at')->update(['read_at' => now()]);
        $this->refreshFeed();
    }

    public function archive(string $notificationId): void
    {
        $notification = Auth::user()->notifications()->whereNull('archived_at')->find($notificationId);
        if ($notification) {
            app(NotificationArchiver::class)->archive($notification, 'manual');
        }
        $this->refreshFeed();
    }
}; ?>

@php
    $counts = $feed['counts'] ?? ['unread' => 0, 'unresolved_attention' => 0, 'active_activity' => 0];
    $items = $feed['data'] ?? [];
    $badgeCount = $counts['unresolved_attention'] ?: ($counts['active_activity'] ?: $counts['unread']);
@endphp

<div
    x-data="{ open: false }"
    @click.outside="open = false"
    class="relative"
    @if ($counts['active_activity'] > 0) wire:poll.3s="refreshFeed" @else wire:poll.30s="refreshFeed" @endif
>
    <a href="{{ route('notifications.index') }}" class="btn btn-ghost btn-sm sm:hidden" aria-label="Open notifications">
        <div class="indicator">
            @if ($badgeCount > 0)
                <span class="indicator-item badge badge-xs {{ $counts['unresolved_attention'] ? 'badge-error' : 'badge-info' }}">{{ min($badgeCount, 99) }}</span>
            @endif
            <x-icon :name="$counts['active_activity'] ? 'o-arrow-path' : 'o-bell'" @class(['size-5', 'animate-spin' => $counts['active_activity']]) />
        </div>
    </a>

    <button
        type="button"
        @click="open = !open"
        :aria-expanded="open"
        class="btn btn-ghost btn-sm hidden sm:flex"
        aria-label="Open notifications"
    >
        <div class="indicator">
            @if ($badgeCount > 0)
                <span class="indicator-item badge badge-xs {{ $counts['unresolved_attention'] ? 'badge-error' : 'badge-info' }}">{{ min($badgeCount, 99) }}</span>
            @endif
            <x-icon :name="$counts['active_activity'] ? 'o-arrow-path' : 'o-bell'" @class(['size-5', 'animate-spin' => $counts['active_activity']]) />
        </div>
    </button>

    <section
        x-cloak
        x-show="open"
        x-transition.origin.top.right
        class="absolute right-0 top-full z-[100] mt-3 hidden w-[26rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-box border border-base-300 bg-base-100 shadow-xl sm:block"
        aria-label="Recent notifications"
    >
        <header class="flex items-center justify-between border-b border-base-200 px-4 py-3">
            <div>
                <h2 class="font-semibold">Notifications</h2>
                <p class="text-xs text-base-content/55">
                    @if ($counts['unresolved_attention'])
                        {{ $counts['unresolved_attention'] }} need{{ $counts['unresolved_attention'] === 1 ? 's' : '' }} attention
                    @elseif ($counts['active_activity'])
                        {{ $counts['active_activity'] }} active
                    @elseif ($counts['unread'])
                        {{ $counts['unread'] }} unread
                    @else
                        You’re all caught up
                    @endif
                </p>
            </div>
            @if ($counts['unread'])
                <button wire:click="markAllAsRead" class="btn btn-ghost btn-xs">Mark all read</button>
            @endif
        </header>

        @if (count($items))
            <div class="max-h-[32rem] overflow-y-auto">
                @foreach ($items as $item)
                    @php
                        $isActivity = $item['kind'] === 'activity';
                        $icon = match (true) {
                            $isActivity && $item['state'] === 'active' => 'o-arrow-path',
                            in_array($item['severity'], ['critical', 'error'], true) => 'o-exclamation-circle',
                            $item['severity'] === 'warning' => 'o-exclamation-triangle',
                            $item['severity'] === 'success' => 'o-check-circle',
                            default => 'o-bell',
                        };
                        $iconTone = match ($item['severity']) {
                            'critical', 'error' => 'text-error',
                            'warning' => 'text-warning',
                            'success' => 'text-success',
                            default => 'text-info',
                        };
                    @endphp
                    <article @class(['flex gap-3 border-b border-base-200 p-4 last:border-0', 'bg-base-200/30' => ! $item['is_read'] && ! $isActivity])>
                        <x-icon :name="$icon" class="mt-0.5 size-5 shrink-0 {{ $iconTone }} {{ $isActivity && $item['state'] === 'active' ? 'animate-spin' : '' }}" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <h3 class="truncate text-sm font-semibold">{{ $item['title'] }}</h3>
                                <time class="shrink-0 text-[0.7rem] text-base-content/45">{{ \Carbon\Carbon::parse($item['updated_at'] ?? $item['occurred_at'])->diffForHumans(short: true) }}</time>
                            </div>
                            @if ($item['body'])
                                <p class="mt-0.5 line-clamp-2 text-xs leading-5 text-base-content/65">{{ $item['body'] }}</p>
                            @endif
                            @if ($item['progress'])
                                <progress class="progress progress-primary mt-2 h-1.5 w-full" value="{{ $item['progress']['current'] }}" max="{{ $item['progress']['total'] }}" aria-label="Progress"></progress>
                            @endif
                            @if (! $isActivity)
                                <div class="mt-2 flex gap-1">
                                    @if (! $item['is_read'])
                                        <button wire:click="markAsRead('{{ $item['id'] }}')" class="btn btn-ghost btn-xs">Mark read</button>
                                    @endif
                                    <button wire:click="archive('{{ $item['id'] }}')" class="btn btn-ghost btn-xs text-base-content/55" aria-label="Archive {{ $item['title'] }}">Archive</button>
                                </div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            <div class="px-6 py-10 text-center">
                <x-icon name="o-check-circle" class="mx-auto size-8 text-success" />
                <p class="mt-2 text-sm font-medium">Nothing needs your attention</p>
            </div>
        @endif

        <footer class="border-t border-base-200 p-2">
            <a href="{{ route('notifications.index') }}" class="btn btn-ghost btn-sm w-full">View all notifications <x-icon name="o-arrow-right" class="size-4" /></a>
        </footer>
    </section>
</div>
