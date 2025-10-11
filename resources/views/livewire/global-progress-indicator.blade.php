<?php

use App\Models\ActionProgress;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Volt\Component;

new class extends Component {
    public Collection $activeProgresses;
    public Collection $recentlyCompleted;
    public Collection $recentHistory;
    public Collection $unreadNotifications;
    public bool $showHistory = false;
    public bool $showNotifications = false;
    public bool $showMobileModal = false;
    public array $expandedUpdates = [];

    public function mount(): void
    {
        $this->checkProgress();
        $this->loadNotifications();
    }

    public function toggleUpdates(string $progressId): void
    {
        if (in_array($progressId, $this->expandedUpdates)) {
            $this->expandedUpdates = array_values(array_diff($this->expandedUpdates, [$progressId]));
        } else {
            $this->expandedUpdates[] = $progressId;
        }
    }

    public function checkProgress(): void
    {
        $now = now();

        // Active operations (in progress)
        $this->activeProgresses = ActionProgress::where('user_id', Auth::id())
            ->whereNull('completed_at')
            ->whereNull('failed_at')
            ->where('created_at', '>', $now->copy()->subHour())
            ->latest()
            ->get();

        // Recently completed (last 1 minute)
        $this->recentlyCompleted = ActionProgress::where('user_id', Auth::id())
            ->where(function ($query) {
                $query->whereNotNull('completed_at')
                    ->orWhereNotNull('failed_at');
            })
            ->where(function ($query) use ($now) {
                $query->where('completed_at', '>', $now->copy()->subMinute())
                    ->orWhere('failed_at', '>', $now->copy()->subMinute());
            })
            ->latest('updated_at')
            ->get();

        // Recent history (1-5 minutes ago)
        $this->recentHistory = ActionProgress::where('user_id', Auth::id())
            ->where(function ($query) {
                $query->whereNotNull('completed_at')
                    ->orWhereNotNull('failed_at');
            })
            ->where(function ($query) use ($now) {
                $query->where(function ($q) use ($now) {
                    $q->where('completed_at', '<=', $now->copy()->subMinute())
                        ->where('completed_at', '>', $now->copy()->subMinutes(5));
                })->orWhere(function ($q) use ($now) {
                    $q->where('failed_at', '<=', $now->copy()->subMinute())
                        ->where('failed_at', '>', $now->copy()->subMinutes(5));
                });
            })
            ->latest('updated_at')
            ->get();

        $this->loadNotifications();
    }

    public function loadNotifications(): void
    {
        $this->unreadNotifications = Auth::user()->unreadNotifications()
            ->latest()
            ->limit(10)
            ->get();
    }

    public function markNotificationAsRead(string $notificationId): void
    {
        $notification = Auth::user()->notifications()->find($notificationId);
        if ($notification) {
            $notification->markAsRead();
        }
        $this->loadNotifications();
    }

    public function markAllNotificationsAsRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();
        $this->loadNotifications();
    }

    public function deleteNotification(string $notificationId): void
    {
        $notification = Auth::user()->notifications()->find($notificationId);
        if ($notification) {
            $notification->delete();
        }
        $this->loadNotifications();
    }

    public function toggleHistory(): void
    {
        $this->showHistory = !$this->showHistory;
    }

    public function openMobileModal(): void
    {
        $this->showMobileModal = true;
    }

    public function getActionIcon(string $actionType): string
    {
        return match ($actionType) {
            'migration' => 'o-arrow-path',
            'deletion' => 'o-trash',
            'sync' => 'o-arrow-path-rounded-square',
            'backup' => 'o-archive-box',
            'export' => 'o-arrow-down-tray',
            'import' => 'o-arrow-up-tray',
            'bulk_operation' => 'o-queue-list',
            'report' => 'o-document-chart-bar',
            'maintenance' => 'o-wrench-screwdriver',
            default => 'o-cog-6-tooth',
        };
    }

    public function getRelativeTime($timestamp): string
    {
        if (!$timestamp) {
            return '';
        }

        $seconds = abs(now()->diffInSeconds($timestamp, false));

        if ($seconds < 60) {
            return (int) $seconds . 's ago';
        }

        $minutes = (int) floor($seconds / 60);
        return $minutes . 'm ago';
    }

    public function getRunningDuration($startTime): string
    {
        if (!$startTime) {
            return '';
        }

        $seconds = abs(now()->diffInSeconds($startTime, false));

        if ($seconds < 60) {
            return (int) $seconds . 's';
        }

        if ($seconds < 3600) {
            $minutes = (int) floor($seconds / 60);
            $secs = (int) ($seconds % 60);
            return $minutes . 'm ' . $secs . 's';
        }

        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }
};
?>

<div>
    {{-- Always show the notification bell, with polling when there are active operations --}}
    <div class="dropdown sm:dropdown-end"
        @if ($activeProgresses->isNotEmpty()) wire:poll.3s="checkProgress" @elseif ($unreadNotifications->isNotEmpty()) wire:poll.30s="loadNotifications" @endif>

        <button class="btn btn-ghost btn-sm gap-2 sm:hidden" wire:click="openMobileModal">
            <div class="indicator">
                @if ($activeProgresses->isNotEmpty())
                <span class="indicator-item badge badge-primary badge-xs">
                    {{ $activeProgresses->count() }}
                </span>
                <span class="loading loading-spinner loading-xs"></span>
                @elseif ($unreadNotifications->isNotEmpty())
                <span class="indicator-item badge badge-info badge-xs">
                    {{ $unreadNotifications->count() }}
                </span>
                <x-icon name="o-bell" class="w-4 h-4" />
                @elseif ($recentlyCompleted->isNotEmpty())
                @if ($recentlyCompleted->where('failed_at', '!=', null)->isNotEmpty())
                <span class="indicator-item badge badge-error badge-xs">
                    {{ $recentlyCompleted->count() }}
                </span>
                <x-icon name="o-exclamation-circle" class="w-4 h-4" />
                @else
                <span class="indicator-item badge badge-success badge-xs">
                    {{ $recentlyCompleted->count() }}
                </span>
                <x-icon name="o-check-circle" class="w-4 h-4" />
                @endif
                @elseif ($recentHistory->isNotEmpty())
                <span class="indicator-item badge badge-ghost badge-xs">
                    {{ $recentHistory->count() }}
                </span>
                <x-icon name="o-clock" class="w-4 h-4" />
                @else
                {{-- Show bell icon when nothing is happening --}}
                <x-icon name="o-bell" class="w-4 h-4" />
                @endif
            </div>
        </button>

        <label tabindex="0" class="btn btn-ghost btn-sm gap-2 hidden sm:flex">
            <div class="indicator">
                @if ($activeProgresses->isNotEmpty())
                <span class="indicator-item badge badge-primary badge-xs">
                    {{ $activeProgresses->count() }}
                </span>
                <span class="loading loading-spinner loading-xs"></span>
                @elseif ($unreadNotifications->isNotEmpty())
                <span class="indicator-item badge badge-info badge-xs">
                    {{ $unreadNotifications->count() }}
                </span>
                <x-icon name="o-bell" class="w-4 h-4" />
                @elseif ($recentlyCompleted->isNotEmpty())
                @if ($recentlyCompleted->where('failed_at', '!=', null)->isNotEmpty())
                <span class="indicator-item badge badge-error badge-xs">
                    {{ $recentlyCompleted->count() }}
                </span>
                <x-icon name="o-exclamation-circle" class="w-4 h-4" />
                @else
                <span class="indicator-item badge badge-success badge-xs">
                    {{ $recentlyCompleted->count() }}
                </span>
                <x-icon name="o-check-circle" class="w-4 h-4" />
                @endif
                @elseif ($recentHistory->isNotEmpty())
                <span class="indicator-item badge badge-ghost badge-xs">
                    {{ $recentHistory->count() }}
                </span>
                <x-icon name="o-clock" class="w-4 h-4" />
                @else
                {{-- Show bell icon when nothing is happening --}}
                <x-icon name="o-bell" class="w-4 h-4" />
                @endif
            </div>
        </label>

        @if ($activeProgresses->isNotEmpty() || $recentlyCompleted->isNotEmpty() || $recentHistory->isNotEmpty() || $unreadNotifications->isNotEmpty())

        {{-- Desktop dropdown --}}
        <div tabindex="0" class="hidden sm:block dropdown-content z-[100] card card-compact w-[28rem] p-0 shadow-lg bg-base-200 mt-3 max-h-[80vh] overflow-y-auto">
            <div class="card-body">
                <h3 class="font-semibold text-sm mb-3">Activity</h3>

                {{-- Active Operations --}}
                @if ($activeProgresses->isNotEmpty())
                <div class="space-y-3 mb-4">
                    @foreach ($activeProgresses as $progress)
                    <div class="card bg-base-100 border border-primary/20">
                        <div class="card-body p-3">
                            <div class="flex items-start gap-2">
                                <x-icon :name="$this->getActionIcon($progress->action_type)"
                                    class="w-4 h-4 mt-0.5 animate-spin text-primary" />
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="font-semibold text-sm">
                                            {{ ucfirst(str_replace('_', ' ', $progress->action_type)) }}
                                        </div>
                                        <div class="text-xs text-base-content/50 font-mono">
                                            {{ $this->getRunningDuration($progress->created_at) }}
                                        </div>
                                    </div>
                                    <div class="text-xs text-base-content/70 truncate">
                                        {{ $progress->message }}
                                    </div>

                                    <div class="flex items-center gap-2 mt-2">
                                        <progress class="progress progress-primary flex-1 h-1.5"
                                            value="{{ $progress->progress }}"
                                            max="{{ $progress->total }}"></progress>
                                        <span class="text-xs font-mono text-base-content/70 min-w-[3rem] text-right">
                                            {{ $progress->progress }}%
                                        </span>
                                    </div>

                                    @if ($progress->step)
                                    <div class="text-xs text-base-content/60 mt-1">
                                        <span class="opacity-70">Step:</span> {{ $progress->step }}
                                    </div>
                                    @endif

                                    @if ($progress->details && count($progress->details) > 0)
                                    <div class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs mt-2 pt-2 border-t border-base-300">
                                        @foreach ($progress->details as $key => $value)
                                        @if (is_numeric($value))
                                        <div class="flex justify-between">
                                            <span class="text-base-content/60">{{ ucfirst($key) }}:</span>
                                            <span class="font-semibold">{{ number_format($value) }}</span>
                                        </div>
                                        @endif
                                        @endforeach
                                    </div>
                                    @endif

                                    @if ($progress->updates && count($progress->updates) > 0)
                                    <div class="mt-2 pt-2 border-t border-base-300">
                                        <button wire:click="toggleUpdates('{{ $progress->id }}')"
                                            class="flex items-center gap-1 text-xs text-base-content/60 hover:text-base-content">
                                            <x-icon name="o-chevron-{{ in_array($progress->id, $expandedUpdates) ? 'up' : 'down' }}" class="w-3 h-3" />
                                            <span>{{ count($progress->updates) }} update{{ count($progress->updates) !== 1 ? 's' : '' }}</span>
                                        </button>

                                        @if (in_array($progress->id, $expandedUpdates))
                                        <div class="mt-2 space-y-1 max-h-32 overflow-y-auto">
                                            @foreach (array_reverse($progress->updates) as $update)
                                            <div class="text-xs text-base-content/60 pl-2 border-l-2 border-base-300">
                                                <div class="flex items-start justify-between gap-2">
                                                    <span>{{ $update['message'] ?? '' }}</span>
                                                    @if (isset($update['timestamp']))
                                                    <span class="text-base-content/40 font-mono text-[10px]">
                                                        {{ $this->getRelativeTime($update['timestamp']) }}
                                                    </span>
                                                    @endif
                                                </div>
                                            </div>
                                            @endforeach
                                        </div>
                                        @endif
                                    </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif

                {{-- Notifications --}}
                @if ($unreadNotifications->isNotEmpty())
                @if ($activeProgresses->isNotEmpty() || $recentlyCompleted->isNotEmpty() || $recentHistory->isNotEmpty())
                <div class="divider my-2 text-xs">Notifications</div>
                @endif

                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold">Unread Notifications</span>
                    <button wire:click="markAllNotificationsAsRead" class="text-xs text-base-content/60 hover:text-base-content">
                        Mark all as read
                    </button>
                </div>

                <div class="space-y-2">
                    @foreach ($unreadNotifications as $notification)
                    @php
                    $data = $notification->data;
                    $iconName = $data['icon'] ?? 'o-bell';
                    $color = $data['color'] ?? 'primary';
                    $title = $data['title'] ?? 'Notification';
                    $message = $data['message'] ?? '';
                    $actionUrl = $data['action_url'] ?? null;
                    @endphp

                    <div class="card bg-base-100 border border-{{ $color }}/20">
                        <div class="card-body p-3">
                            <div class="flex items-start gap-2">
                                <x-icon :name="$iconName" class="w-4 h-4 mt-0.5 text-{{ $color }}" />

                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="font-semibold text-sm">
                                            {{ $title }}
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <span class="text-xs text-base-content/50">
                                                {{ $this->getRelativeTime($notification->created_at) }}
                                            </span>
                                            <button wire:click="deleteNotification('{{ $notification->id }}')"
                                                class="btn btn-ghost btn-xs">
                                                <x-icon name="o-x-mark" class="w-3 h-3" />
                                            </button>
                                        </div>
                                    </div>

                                    <div class="text-xs text-base-content/70">
                                        {{ $message }}
                                    </div>

                                    <div class="flex items-center gap-2 mt-2">
                                        @if ($actionUrl)
                                        <a href="{{ $actionUrl }}"
                                            wire:click="markNotificationAsRead('{{ $notification->id }}')"
                                            class="btn btn-xs btn-{{ $color }}">
                                            View
                                        </a>
                                        @endif
                                        <button wire:click="markNotificationAsRead('{{ $notification->id }}')"
                                            class="btn btn-xs btn-ghost">
                                            Mark as read
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
        @else
        {{-- Recently Completed (last 1 minute) --}}
        @if ($recentlyCompleted->isNotEmpty())
        @if ($activeProgresses->isNotEmpty())
        <div class="divider my-2 text-xs">Recently Completed</div>
        @endif

        <div class="space-y-2 mb-4">
            @foreach ($recentlyCompleted as $progress)
            <div class="card bg-base-100 border @if ($progress->isFailed()) border-error/20 @else border-success/20 @endif">
                <div class="card-body p-3">
                    <div class="flex items-start gap-2">
                        @if ($progress->isFailed())
                        <x-icon name="o-x-circle" class="w-4 h-4 mt-0.5 text-error" />
                        @else
                        <x-icon name="o-check-circle" class="w-4 h-4 mt-0.5 text-success" />
                        @endif

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between gap-2">
                                <div class="font-semibold text-sm">
                                    {{ ucfirst(str_replace('_', ' ', $progress->action_type)) }}
                                </div>
                                <div class="text-xs text-base-content/50">
                                    {{ $this->getRelativeTime($progress->completed_at ?? $progress->failed_at) }}
                                </div>
                            </div>

                            <div class="text-xs @if ($progress->isFailed()) text-error @else text-success @endif">
                                @if ($progress->isFailed())
                                Failed: {{ $progress->error_message ?? $progress->message }}
                                @else
                                {{ $progress->message }}
                                @endif
                            </div>

                            @if ($progress->details && count($progress->details) > 0)
                            <div class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs mt-2 pt-2 border-t border-base-300">
                                @foreach ($progress->details as $key => $value)
                                @if (is_numeric($value))
                                <div class="flex justify-between">
                                    <span class="text-base-content/60">{{ ucfirst($key) }}:</span>
                                    <span class="font-semibold">{{ number_format($value) }}</span>
                                </div>
                                @endif
                                @endforeach
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
        @endif

        {{-- Recent History (1-5 minutes ago) - Collapsible --}}
        @if ($recentHistory->isNotEmpty())
        <div x-data="{ open: @entangle('showHistory') }">
            <button @click="open = !open"
                class="flex items-center justify-between w-full text-xs text-base-content/70 hover:text-base-content py-2 px-1">
                <span>Recent History ({{ $recentHistory->count() }})</span>
                <x-icon name="o-chevron-down" class="w-3 h-3 transition-transform" ::class="open && 'rotate-180'" />
            </button>

            <div x-show="open"
                x-collapse
                class="space-y-2">
                @foreach ($recentHistory as $progress)
                <div class="card bg-base-100/50 border border-base-300/50">
                    <div class="card-body p-2">
                        <div class="flex items-start gap-2">
                            @if ($progress->isFailed())
                            <x-icon name="o-x-circle" class="w-3.5 h-3.5 mt-0.5 text-error/70" />
                            @else
                            <x-icon name="o-check-circle" class="w-3.5 h-3.5 mt-0.5 text-success/70" />
                            @endif

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="text-xs font-medium">
                                        {{ ucfirst(str_replace('_', ' ', $progress->action_type)) }}
                                    </div>
                                    <div class="text-xs text-base-content/40">
                                        {{ $this->getRelativeTime($progress->completed_at ?? $progress->failed_at) }}
                                    </div>
                                </div>

                                <div class="text-xs text-base-content/60 truncate">
                                    @if ($progress->isFailed())
                                    {{ $progress->error_message ?? $progress->message }}
                                    @else
                                    {{ $progress->message }}
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endif
        {{-- Show empty state when dropdown is opened with no items --}}
        <div tabindex="0" class="hidden sm:block dropdown-content z-[100] card card-compact w-[28rem] p-0 shadow-lg bg-base-200 mt-3">
            <div class="card-body">
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <x-icon name="o-bell-slash" class="w-12 h-12 text-base-content/30 mb-3" />
                    <p class="text-sm text-base-content/60">No notifications</p>
                    <p class="text-xs text-base-content/40 mt-1">You're all caught up!</p>
                </div>
            </div>
        </div>
        @endif
    </div>

    {{-- Mobile drawer --}}
    <x-drawer wire:model="showMobileModal" class="sm:hidden w-full max-w-lg" title="Task Progress" right with-close-button>
        <div class="p-4">
            @if ($activeProgresses->isNotEmpty() || $recentlyCompleted->isNotEmpty() || $recentHistory->isNotEmpty() || $unreadNotifications->isNotEmpty())

            {{-- Active Operations --}}
            @if ($activeProgresses->isNotEmpty())
            <div class="space-y-3 mb-4">
                @foreach ($activeProgresses as $progress)
                <div class="card bg-base-100 border border-primary/20">
                    <div class="card-body p-3">
                        <div class="flex items-start gap-2">
                            <x-icon :name="$this->getActionIcon($progress->action_type)"
                                class="w-4 h-4 mt-0.5 animate-spin text-primary" />
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="font-semibold text-sm">
                                        {{ ucfirst(str_replace('_', ' ', $progress->action_type)) }}
                                    </div>
                                    <div class="text-xs text-base-content/50 font-mono">
                                        {{ $this->getRunningDuration($progress->created_at) }}
                                    </div>
                                </div>
                                <div class="text-xs text-base-content/70 truncate">
                                    {{ $progress->message }}
                                </div>

                                <div class="flex items-center gap-2 mt-2">
                                    <progress class="progress progress-primary flex-1 h-1.5"
                                        value="{{ $progress->progress }}"
                                        max="{{ $progress->total }}"></progress>
                                    <span class="text-xs font-mono text-base-content/70 min-w-[3rem] text-right">
                                        {{ $progress->progress }}%
                                    </span>
                                </div>

                                @if ($progress->step)
                                <div class="text-xs text-base-content/60 mt-1">
                                    <span class="opacity-70">Step:</span> {{ $progress->step }}
                                </div>
                                @endif

                                @if ($progress->details && count($progress->details) > 0)
                                <div class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs mt-2 pt-2 border-t border-base-300">
                                    @foreach ($progress->details as $key => $value)
                                    @if (is_numeric($value))
                                    <div class="flex justify-between">
                                        <span class="text-base-content/60">{{ ucfirst($key) }}:</span>
                                        <span class="font-semibold">{{ number_format($value) }}</span>
                                    </div>
                                    @endif
                                    @endforeach
                                </div>
                                @endif

                                @if ($progress->updates && count($progress->updates) > 0)
                                <div class="mt-2 pt-2 border-t border-base-300">
                                    <button wire:click="toggleUpdates('{{ $progress->id }}')"
                                        class="flex items-center gap-1 text-xs text-base-content/60 hover:text-base-content">
                                        <x-icon name="o-chevron-{{ in_array($progress->id, $expandedUpdates) ? 'up' : 'down' }}" class="w-3 h-3" />
                                        <span>{{ count($progress->updates) }} update{{ count($progress->updates) !== 1 ? 's' : '' }}</span>
                                    </button>

                                    @if (in_array($progress->id, $expandedUpdates))
                                    <div class="mt-2 space-y-1 max-h-32 overflow-y-auto">
                                        @foreach (array_reverse($progress->updates) as $update)
                                        <div class="text-xs text-base-content/60 pl-2 border-l-2 border-base-300">
                                            <div class="flex items-start justify-between gap-2">
                                                <span>{{ $update['message'] ?? '' }}</span>
                                                @if (isset($update['timestamp']))
                                                <span class="text-base-content/40 font-mono text-[10px]">
                                                    {{ $this->getRelativeTime($update['timestamp']) }}
                                                </span>
                                                @endif
                                            </div>
                                        </div>
                                        @endforeach
                                    </div>
                                    @endif
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif

            {{-- Notifications --}}
            @if ($unreadNotifications->isNotEmpty())
            @if ($activeProgresses->isNotEmpty() || $recentlyCompleted->isNotEmpty() || $recentHistory->isNotEmpty())
            <div class="divider my-2 text-xs">Notifications</div>
            @endif

            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold">Unread Notifications</span>
                <button wire:click="markAllNotificationsAsRead" class="text-xs text-base-content/60 hover:text-base-content">
                    Mark all as read
                </button>
            </div>

            <div class="space-y-2">
                @foreach ($unreadNotifications as $notification)
                @php
                $data = $notification->data;
                $iconName = $data['icon'] ?? 'o-bell';
                $color = $data['color'] ?? 'primary';
                $title = $data['title'] ?? 'Notification';
                $message = $data['message'] ?? '';
                $actionUrl = $data['action_url'] ?? null;
                @endphp

                <div class="card bg-base-100 border border-{{ $color }}/20">
                    <div class="card-body p-3">
                        <div class="flex items-start gap-2">
                            <x-icon :name="$iconName" class="w-4 h-4 mt-0.5 text-{{ $color }}" />

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="font-semibold text-sm">
                                        {{ $title }}
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <span class="text-xs text-base-content/50">
                                            {{ $this->getRelativeTime($notification->created_at) }}
                                        </span>
                                        <button wire:click="deleteNotification('{{ $notification->id }}')"
                                            class="btn btn-ghost btn-xs">
                                            <x-icon name="o-x-mark" class="w-3 h-3" />
                                        </button>
                                    </div>
                                </div>

                                <div class="text-xs text-base-content/70">
                                    {{ $message }}
                                </div>

                                <div class="flex items-center gap-2 mt-2">
                                    @if ($actionUrl)
                                    <a href="{{ $actionUrl }}"
                                        wire:click="markNotificationAsRead('{{ $notification->id }}')"
                                        class="btn btn-xs btn-{{ $color }}">
                                        View
                                    </a>
                                    @endif
                                    <button wire:click="markNotificationAsRead('{{ $notification->id }}')"
                                        class="btn btn-xs btn-ghost">
                                        Mark as read
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif

            {{-- Recently Completed (last 1 minute) --}}
            @if ($recentlyCompleted->isNotEmpty())
            @if ($activeProgresses->isNotEmpty())
            <div class="divider my-2 text-xs">Recently Completed</div>
            @endif

            <div class="space-y-2 mb-4">
                @foreach ($recentlyCompleted as $progress)
                <div class="card bg-base-100 border @if ($progress->isFailed()) border-error/20 @else border-success/20 @endif">
                    <div class="card-body p-3">
                        <div class="flex items-start gap-2">
                            @if ($progress->isFailed())
                            <x-icon name="o-x-circle" class="w-4 h-4 mt-0.5 text-error" />
                            @else
                            <x-icon name="o-check-circle" class="w-4 h-4 mt-0.5 text-success" />
                            @endif

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="font-semibold text-sm">
                                        {{ ucfirst(str_replace('_', ' ', $progress->action_type)) }}
                                    </div>
                                    <div class="text-xs text-base-content/50">
                                        {{ $this->getRelativeTime($progress->completed_at ?? $progress->failed_at) }}
                                    </div>
                                </div>

                                <div class="text-xs @if ($progress->isFailed()) text-error @else text-success @endif">
                                    @if ($progress->isFailed())
                                    Failed: {{ $progress->error_message ?? $progress->message }}
                                    @else
                                    {{ $progress->message }}
                                    @endif
                                </div>

                                @if ($progress->details && count($progress->details) > 0)
                                <div class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs mt-2 pt-2 border-t border-base-300">
                                    @foreach ($progress->details as $key => $value)
                                    @if (is_numeric($value))
                                    <div class="flex justify-between">
                                        <span class="text-base-content/60">{{ ucfirst($key) }}:</span>
                                        <span class="font-semibold">{{ number_format($value) }}</span>
                                    </div>
                                    @endif
                                    @endforeach
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif

            {{-- Recent History (1-5 minutes ago) - Collapsible --}}
            @if ($recentHistory->isNotEmpty())
            <div x-data="{ open: @entangle('showHistory') }">
                <button @click="open = !open"
                    class="flex items-center justify-between w-full text-xs text-base-content/70 hover:text-base-content py-2 px-1">
                    <span>Recent History ({{ $recentHistory->count() }})</span>
                    <x-icon name="o-chevron-down" class="w-3 h-3 transition-transform" ::class="open && 'rotate-180'" />
                </button>

                <div x-show="open"
                    x-collapse
                    class="space-y-2">
                    @foreach ($recentHistory as $progress)
                    <div class="card bg-base-100/50 border border-base-300/50">
                        <div class="card-body p-2">
                            <div class="flex items-start gap-2">
                                @if ($progress->isFailed())
                                <x-icon name="o-x-circle" class="w-3.5 h-3.5 mt-0.5 text-error/70" />
                                @else
                                <x-icon name="o-check-circle" class="w-3.5 h-3.5 mt-0.5 text-success/70" />
                                @endif

                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="text-xs font-medium">
                                            {{ ucfirst(str_replace('_', ' ', $progress->action_type)) }}
                                        </div>
                                        <div class="text-xs text-base-content/40">
                                            {{ $this->getRelativeTime($progress->completed_at ?? $progress->failed_at) }}
                                        </div>
                                    </div>

                                    <div class="text-xs text-base-content/60 truncate">
                                        @if ($progress->isFailed())
                                        {{ $progress->error_message ?? $progress->message }}
                                        @else
                                        {{ $progress->message }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
            @else
            {{-- Empty state --}}
            <div class="flex flex-col items-center justify-center py-8 text-center">
                <x-icon name="o-bell-slash" class="w-12 h-12 text-base-content/30 mb-3" />
                <p class="text-sm text-base-content/60">No notifications</p>
                <p class="text-xs text-base-content/40 mt-1">You're all caught up!</p>
            </div>
            @endif
        </div>
    </x-drawer>
</div>