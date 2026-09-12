<?php

use App\Services\Notifications\NotificationArchiver;
use App\Services\Notifications\NotificationFeedService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    #[Url]
    public string $scope = 'active';

    #[Url]
    public string $stream = 'all';

    #[Url]
    public string $search = '';

    public ?string $cursor = null;

    /** @var array<int, string|null> */
    public array $previousCursors = [];

    public function getFeedProperty(): array
    {
        return app(NotificationFeedService::class)->feed(
            user: Auth::user(),
            scope: $this->scope,
            stream: $this->stream === 'all' ? null : $this->stream,
            search: trim($this->search) === '' ? null : trim($this->search),
            cursor: $this->cursor,
            limit: 25,
        );
    }

    public function updatedScope(): void
    {
        $this->resetCursor();
    }

    public function updatedStream(): void
    {
        $this->resetCursor();
    }

    public function updatedSearch(): void
    {
        $this->resetCursor();
    }

    public function nextPage(): void
    {
        if (! $this->feed['next_cursor']) {
            return;
        }

        $this->previousCursors[] = $this->cursor;
        $this->cursor = $this->feed['next_cursor'];
    }

    public function previousPage(): void
    {
        $this->cursor = array_pop($this->previousCursors);
    }

    public function markAsRead(string $notificationId): void
    {
        Auth::user()->notifications()->whereNull('archived_at')->find($notificationId)?->markAsRead();
    }

    public function markAsUnread(string $notificationId): void
    {
        Auth::user()->notifications()->whereNull('archived_at')->find($notificationId)?->markAsUnread();
    }

    public function archive(string $notificationId): void
    {
        $notification = Auth::user()->notifications()->whereNull('archived_at')->find($notificationId);
        if (! $notification) {
            return;
        }

        app(NotificationArchiver::class)->archive($notification, 'manual');
    }

    public function markAllAsRead(): void
    {
        Auth::user()->unreadNotifications()->whereNull('archived_at')->update(['read_at' => now()]);
    }

    private function resetCursor(): void
    {
        $this->cursor = null;
        $this->previousCursors = [];
    }
}; ?>

<div class="mx-auto w-full max-w-5xl pb-12">
    <x-header
        title="Notifications"
        subtitle="Updates, active work, and anything that needs your attention."
        separator
    >
        <x-slot:actions>
            @if ($this->feed['counts']['unread'] > 0 && $scope === 'active')
                <button wire:click="markAllAsRead" class="btn btn-ghost btn-sm">Mark all read</button>
            @endif
        </x-slot:actions>
    </x-header>

    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div role="tablist" aria-label="Notification scope" class="tabs tabs-box w-fit bg-base-200">
            <button type="button" role="tab" aria-selected="{{ $scope === 'active' ? 'true' : 'false' }}" wire:click="$set('scope', 'active')" @class(['tab', 'tab-active' => $scope === 'active'])>Inbox</button>
            <button type="button" role="tab" aria-selected="{{ $scope === 'history' ? 'true' : 'false' }}" wire:click="$set('scope', 'history')" @class(['tab', 'tab-active' => $scope === 'history'])>History</button>
        </div>

        <label class="input input-bordered flex w-full items-center gap-2 sm:max-w-xs">
            <x-icon name="o-magnifying-glass" class="size-4 opacity-60" />
            <input type="search" wire:model.live.debounce.300ms="search" class="grow" placeholder="Search notifications" aria-label="Search notifications" />
        </label>
    </div>

    <div class="mb-6 flex gap-2 overflow-x-auto pb-1" aria-label="Notification streams">
        @foreach ([
            'all' => ['All', null],
            'attention' => ['Attention', $this->feed['counts']['unresolved_attention']],
            'activity' => ['Activity', $this->feed['counts']['active_activity']],
            'updates' => ['Updates', null],
            'system' => ['System', null],
        ] as $value => [$label, $count])
            <button
                type="button"
                wire:click="$set('stream', '{{ $value }}')"
                @class(['btn btn-sm shrink-0', 'btn-neutral' => $stream === $value, 'btn-ghost' => $stream !== $value])
                aria-pressed="{{ $stream === $value ? 'true' : 'false' }}"
            >
                {{ $label }}
                @if ($count)
                    <span class="badge badge-sm {{ $value === 'attention' ? 'badge-error' : 'badge-info' }}">{{ $count }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <div wire:loading.delay class="mb-3 w-full">
        <progress class="progress progress-primary w-full" aria-label="Loading notifications"></progress>
    </div>

    @if (count($this->feed['data']) > 0)
        <div class="overflow-hidden rounded-box border border-base-300 bg-base-100 shadow-sm">
            @foreach ($this->feed['data'] as $item)
                @php
                    $isActivity = $item['kind'] === 'activity';
                    $isAttention = $item['stream'] === 'attention';
                    $icon = match (true) {
                        $isActivity && $item['state'] === 'active' => 'o-arrow-path',
                        $item['severity'] === 'critical', $item['severity'] === 'error' => 'o-exclamation-circle',
                        $item['severity'] === 'warning' => 'o-exclamation-triangle',
                        $item['severity'] === 'success' => 'o-check-circle',
                        $item['stream'] === 'updates' => 'o-sparkles',
                        default => 'o-bell',
                    };
                    $tone = match ($item['severity']) {
                        'critical', 'error' => 'text-error bg-error/10',
                        'warning' => 'text-warning bg-warning/10',
                        'success' => 'text-success bg-success/10',
                        default => 'text-info bg-info/10',
                    };
                    $destination = $item['destination'];
                @endphp

                <article @class([
                    'group flex gap-3 border-b border-base-200 p-4 last:border-b-0 sm:gap-4 sm:p-5',
                    'bg-base-200/35' => ! $item['is_read'] && ! $isActivity,
                ])>
                    <div class="grid size-10 shrink-0 place-items-center rounded-full {{ $tone }}" aria-hidden="true">
                        <x-icon :name="$icon" @class(['size-5', 'animate-spin' => $isActivity && $item['state'] === 'active']) />
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                            <div class="flex min-w-0 items-center gap-2">
                                <h2 class="truncate font-semibold text-base-content">{{ $item['title'] }}</h2>
                                @if (! $item['is_read'] && ! $isActivity)
                                    <span class="size-2 shrink-0 rounded-full bg-primary" aria-label="Unread"></span>
                                @endif
                                @if ($item['occurrence_count'] > 1)
                                    <span class="badge badge-ghost badge-sm">{{ $item['occurrence_count'] }} times</span>
                                @endif
                            </div>
                            <time class="shrink-0 text-xs text-base-content/55" datetime="{{ $item['occurred_at'] }}">
                                {{ \Carbon\Carbon::parse($item['updated_at'] ?? $item['occurred_at'])->diffForHumans() }}
                            </time>
                        </div>

                        @if ($item['body'])
                            <p class="mt-1 text-sm leading-6 text-base-content/70">{{ $item['body'] }}</p>
                        @endif

                        @if ($item['progress'])
                            @php $percent = min(100, round(($item['progress']['current'] / max(1, $item['progress']['total'])) * 100)); @endphp
                            <div class="mt-3 flex items-center gap-3">
                                <progress class="progress progress-primary h-2 flex-1" value="{{ $item['progress']['current'] }}" max="{{ $item['progress']['total'] }}" aria-label="{{ $percent }} percent complete"></progress>
                                <span class="text-xs tabular-nums text-base-content/60">{{ $percent }}%</span>
                            </div>
                        @endif

                        <div class="mt-3 flex flex-wrap items-center gap-1">
                            @if (is_string($destination) && str_starts_with($destination, 'http'))
                                <a href="{{ $destination }}" class="btn btn-primary btn-xs">{{ $item['primary_action']['label'] ?? 'View' }}</a>
                            @endif
                            @if (! $isActivity && $scope === 'active')
                                <button type="button" wire:click="{{ $item['is_read'] ? 'markAsUnread' : 'markAsRead' }}('{{ $item['id'] }}')" class="btn btn-ghost btn-xs">
                                    Mark {{ $item['is_read'] ? 'unread' : 'read' }}
                                </button>
                                <button type="button" wire:click="archive('{{ $item['id'] }}')" wire:confirm="Move this notification to history?" class="btn btn-ghost btn-xs text-base-content/60">Archive</button>
                            @endif
                            @if ($isAttention && $item['has_technical_detail'])
                                <span class="ml-auto text-xs text-base-content/45">Technical detail available</span>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        <nav class="mt-5 flex items-center justify-between" aria-label="Notification pages">
            <button wire:click="previousPage" class="btn btn-ghost btn-sm" @disabled(count($previousCursors) === 0)><x-icon name="o-chevron-left" class="size-4" /> Previous</button>
            <button wire:click="nextPage" class="btn btn-ghost btn-sm" @disabled(! $this->feed['has_more'])>Next <x-icon name="o-chevron-right" class="size-4" /></button>
        </nav>
    @else
        <div class="rounded-box border border-dashed border-base-300 bg-base-100 px-6 py-16 text-center">
            <div class="mx-auto mb-4 grid size-12 place-items-center rounded-full bg-base-200">
                <x-icon :name="$scope === 'history' ? 'o-archive-box' : 'o-check-circle'" class="size-6 text-base-content/55" />
            </div>
            <h2 class="font-semibold">{{ $scope === 'history' ? 'No notification history' : 'You’re all caught up' }}</h2>
            <p class="mt-1 text-sm text-base-content/60">{{ $search ? 'Try a different search or stream.' : 'New updates and active work will appear here.' }}</p>
        </div>
    @endif
</div>
