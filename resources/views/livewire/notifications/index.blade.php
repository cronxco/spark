<?php

use Livewire\Volt\Component;
use App\Models\ActionProgress;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;

new class extends Component {
    public string $searchQuery = '';
    public string $typeFilter = 'all';
    public string $statusFilter = 'all';
    public string $timeRangeFilter = '24h';
    public int $page = 1;
    public int $perPage = 25;
    public array $expandedUpdates = [];

    public function mount(): void
    {
        //
    }

    public function updatedSearchQuery(): void
    {
        $this->page = 1;
    }

    public function updatedTypeFilter(): void
    {
        $this->page = 1;
    }

    public function updatedStatusFilter(): void
    {
        $this->page = 1;
    }

    public function updatedTimeRangeFilter(): void
    {
        $this->page = 1;
    }

    public function toggleUpdates(string $progressId): void
    {
        if (in_array($progressId, $this->expandedUpdates)) {
            $this->expandedUpdates = array_values(array_diff($this->expandedUpdates, [$progressId]));
        } else {
            $this->expandedUpdates[] = $progressId;
        }
    }

    public function getFeed(): array
    {
        $user = Auth::user();

        if (!$user) {
            return [
                'items' => collect([]),
                'total' => 0,
                'hasMore' => false,
                'currentPage' => 1,
                'totalPages' => 1,
            ];
        }

        $now = now();

        // Get notifications if type filter allows
        $notifications = collect([]);
        if ($this->typeFilter === 'all' || $this->typeFilter === 'notifications') {
            $notificationsQuery = $user->notifications();

            // Apply search filter
            if ($this->searchQuery !== '') {
                $searchTerm = '%' . $this->searchQuery . '%';
                $notificationsQuery->where(function ($q) use ($searchTerm) {
                    $q->whereRaw("LOWER(data->>'title') LIKE ?", [strtolower($searchTerm)])
                        ->orWhereRaw("LOWER(data->>'message') LIKE ?", [strtolower($searchTerm)]);
                });
            }

            // Apply status filter
            if ($this->statusFilter === 'unread') {
                $notificationsQuery->whereNull('read_at');
            }

            // Apply time range filter
            $notificationsQuery = $this->applyTimeRangeFilter($notificationsQuery, 'created_at');

            $notifications = $notificationsQuery
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn($n) => [
                    'id' => $n->id,
                    'type' => 'notification',
                    'item' => $n,
                    'timestamp' => $n->created_at,
                    'sort_key' => $n->created_at->timestamp,
                ]);
        }

        // Get action progress if type filter allows
        $progress = collect([]);
        if ($this->typeFilter === 'all' || $this->typeFilter === 'progress') {
            $progressQuery = ActionProgress::where('user_id', $user->id);

            // Apply search filter
            if ($this->searchQuery !== '') {
                $searchTerm = '%' . $this->searchQuery . '%';
                $progressQuery->where(function ($q) use ($searchTerm) {
                    $q->where('action_type', 'like', $searchTerm)
                        ->orWhere('message', 'like', $searchTerm);
                });
            }

            // Apply status filter
            if ($this->statusFilter === 'active') {
                $progressQuery->whereNull('completed_at')->whereNull('failed_at');
            } elseif ($this->statusFilter === 'completed') {
                $progressQuery->whereNotNull('completed_at');
            } elseif ($this->statusFilter === 'failed') {
                $progressQuery->whereNotNull('failed_at');
            }

            // Apply time range filter
            $progressQuery = $this->applyTimeRangeFilter($progressQuery, 'updated_at');

            $progress = $progressQuery
                ->latest('updated_at')
                ->limit(100)
                ->get()
                ->map(fn($p) => [
                    'id' => 'progress-' . $p->id,
                    'type' => 'progress',
                    'item' => $p,
                    'timestamp' => $p->updated_at,
                    'sort_key' => $p->updated_at->timestamp,
                ]);
        }

        // Merge and sort chronologically
        $merged = $notifications->concat($progress)->sortByDesc('sort_key')->values();

        // Paginate manually
        $offset = ($this->page - 1) * $this->perPage;
        return [
            'items' => $merged->slice($offset, $this->perPage)->values(),
            'total' => $merged->count(),
            'hasMore' => $merged->count() > ($offset + $this->perPage),
            'currentPage' => $this->page,
            'totalPages' => (int) ceil($merged->count() / $this->perPage),
        ];
    }

    protected function applyTimeRangeFilter($query, string $column)
    {
        $now = now();

        return match ($this->timeRangeFilter) {
            '1h' => $query->where($column, '>', $now->copy()->subHour()),
            '24h' => $query->where($column, '>', $now->copy()->subDay()),
            '7d' => $query->where($column, '>', $now->copy()->subDays(7)),
            default => $query,
        };
    }

    public function hasActiveProgress(): bool
    {
        return ActionProgress::where('user_id', Auth::id())
            ->whereNull('completed_at')
            ->whereNull('failed_at')
            ->exists();
    }

    public function hasUnreadNotifications(): bool
    {
        $user = Auth::user();
        return $user ? $user->unreadNotifications()->exists() : false;
    }

    public function markNotificationAsRead(string $notificationId): void
    {
        $notification = Auth::user()->notifications()->find($notificationId);
        if ($notification) {
            $notification->markAsRead();
        }
    }

    public function deleteNotification(string $notificationId): void
    {
        $notification = Auth::user()->notifications()->find($notificationId);
        if ($notification) {
            $notification->delete();
        }
    }

    public function markAllAsRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();
    }

    public function clearCompleted(): void
    {
        $cutoff = now()->subDay();
        ActionProgress::where('user_id', Auth::id())
            ->where(function ($query) {
                $query->whereNotNull('completed_at')
                    ->orWhereNotNull('failed_at');
            })
            ->where(function ($query) use ($cutoff) {
                $query->where('completed_at', '<', $cutoff)
                    ->orWhere('failed_at', '<', $cutoff);
            })
            ->delete();
    }

    public function previousPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
        }
    }

    public function nextPage(): void
    {
        $feed = $this->getFeed();
        if ($feed['hasMore']) {
            $this->page++;
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['searchQuery', 'typeFilter', 'statusFilter', 'timeRangeFilter', 'page']);
    }

    public function getActionIcon(string $actionType): string
    {
        return match ($actionType) {
            'migration' => 'fas.rotate',
            'deletion' => 'fas.trash',
            'sync' => 'fas.repeat',
            'backup' => 'fas.box-archive',
            'export' => 'fas.download',
            'import' => 'fas.upload',
            'bulk_operation' => 'o-queue-list',
            'report' => 'o-document-chart-bar',
            'maintenance' => 'o-wrench-screwdriver',
            default => 'fas.gear',
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

        if ($seconds < 3600) {
            $minutes = (int) floor($seconds / 60);
            return $minutes . 'm ago';
        }

        if ($seconds < 86400) {
            $hours = (int) floor($seconds / 3600);
            return $hours . 'h ago';
        }

        $days = (int) floor($seconds / 86400);
        return $days . 'd ago';
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

<div @if ($this->hasActiveProgress()) wire:poll.5s @elseif ($this->hasUnreadNotifications()) wire:poll.30s @endif>
    <x-header title="Notifications" subtitle="View and manage all your notifications and activities" separator>
        <x-slot:actions>
            <button wire:click="markAllAsRead" class="btn btn-ghost btn-sm">
                <x-icon name="fas.check-double" class="w-4 h-4" />
                Mark All Read
            </button>
            <button wire:click="clearCompleted" class="btn btn-ghost btn-sm">
                <x-icon name="fas.broom" class="w-4 h-4" />
                Clear Completed
            </button>
        </x-slot:actions>
    </x-header>

    <!-- Filters -->
    <div class="hidden lg:block card bg-base-200 shadow mb-6">
        <div class="card-body">
            <div class="grid grid-cols-4 gap-4">
                <!-- Search -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Search</span>
                    </label>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="searchQuery"
                        placeholder="Search notifications..."
                        class="input input-bordered w-full" />
                </div>

                <!-- Type Filter -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Type</span>
                    </label>
                    <select wire:model.live="typeFilter" class="select select-bordered w-full">
                        <option value="all">All</option>
                        <option value="notifications">Notifications</option>
                        <option value="progress">Progress</option>
                    </select>
                </div>

                <!-- Status Filter -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Status</span>
                    </label>
                    <select wire:model.live="statusFilter" class="select select-bordered w-full">
                        <option value="all">All</option>
                        <option value="active">Active</option>
                        <option value="completed">Completed</option>
                        <option value="failed">Failed</option>
                        <option value="unread">Unread</option>
                    </select>
                </div>

                <!-- Time Range Filter -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Time Range</span>
                    </label>
                    <select wire:model.live="timeRangeFilter" class="select select-bordered w-full">
                        <option value="1h">Last Hour</option>
                        <option value="24h">Last 24 Hours</option>
                        <option value="7d">Last 7 Days</option>
                        <option value="all">All Time</option>
                    </select>
                </div>
            </div>

            <!-- Clear Filters -->
            @if ($searchQuery !== '' || $typeFilter !== 'all' || $statusFilter !== 'all' || $timeRangeFilter !== '24h')
            <div class="flex justify-end mt-4">
                <button wire:click="clearFilters" class="btn btn-outline btn-sm">
                    <x-icon name="fas.xmark" class="w-4 h-4" />
                    Clear Filters
                </button>
            </div>
            @endif
        </div>
    </div>

    <!-- Mobile Filters -->
    <div class="lg:hidden mb-4">
        <x-collapse separator class="bg-base-200">
            <x-slot:heading>
                <div class="flex items-center gap-2">
                    <x-icon name="fas.filter" class="w-5 h-5" />
                    Filters
                    @if ($searchQuery !== '' || $typeFilter !== 'all' || $statusFilter !== 'all' || $timeRangeFilter !== '24h')
                    <x-badge value="Active" class="badge-success badge-xs" />
                    @endif
                </div>
            </x-slot:heading>
            <x-slot:content>
                <div class="flex flex-col gap-4">
                    <!-- Search -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Search</span>
                        </label>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="searchQuery"
                            placeholder="Search notifications..."
                            class="input input-bordered w-full" />
                    </div>

                    <!-- Type Filter -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Type</span>
                        </label>
                        <select wire:model.live="typeFilter" class="select select-bordered w-full">
                            <option value="all">All</option>
                            <option value="notifications">Notifications</option>
                            <option value="progress">Progress</option>
                        </select>
                    </div>

                    <!-- Status Filter -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Status</span>
                        </label>
                        <select wire:model.live="statusFilter" class="select select-bordered w-full">
                            <option value="all">All</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="failed">Failed</option>
                            <option value="unread">Unread</option>
                        </select>
                    </div>

                    <!-- Time Range Filter -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Time Range</span>
                        </label>
                        <select wire:model.live="timeRangeFilter" class="select select-bordered w-full">
                            <option value="1h">Last Hour</option>
                            <option value="24h">Last 24 Hours</option>
                            <option value="7d">Last 7 Days</option>
                            <option value="all">All Time</option>
                        </select>
                    </div>

                    <!-- Clear Filters -->
                    @if ($searchQuery !== '' || $typeFilter !== 'all' || $statusFilter !== 'all' || $timeRangeFilter !== '24h')
                    <button wire:click="clearFilters" class="btn btn-outline">
                        <x-icon name="fas.xmark" class="w-4 h-4" />
                        Clear Filters
                    </button>
                    @endif
                </div>
            </x-slot:content>
        </x-collapse>
    </div>

    @php
    $feed = $this->getFeed();
    $items = $feed['items'];
    @endphp

    @if ($items->isEmpty())
    <!-- Empty State -->
    <x-card>
        <div class="text-center py-8">
            @if ($searchQuery !== '' || $typeFilter !== 'all' || $statusFilter !== 'all' || $timeRangeFilter !== '24h')
            <x-icon name="fas.magnifying-glass" class="w-12 h-12 text-base-content/40 mx-auto mb-4" />
            <h3 class="text-lg font-semibold text-base-content mb-2">No Results Found</h3>
            <p class="text-base-content/70">
                Try adjusting your filters
            </p>
            @else
            <x-icon name="fas.bell-slash" class="w-12 h-12 text-base-content/40 mx-auto mb-4" />
            <h3 class="text-lg font-semibold text-base-content mb-2">No Notifications</h3>
            <p class="text-base-content/70">
                You're all caught up!
            </p>
            @endif
        </div>
    </x-card>
    @else
    <!-- Feed -->
    <div class="space-y-3">
        @foreach ($items as $feedItem)
            @if ($feedItem['type'] === 'notification')
                @php
                $notification = $feedItem['item'];
                $data = $notification->data;
                $iconName = $data['icon'] ?? 'fas.bell';
                $color = $data['color'] ?? 'primary';
                $title = $data['title'] ?? 'Notification';
                $message = $data['message'] ?? '';
                $actionUrl = $data['action_url'] ?? null;
                @endphp

                <div class="card bg-base-100 border border-{{ $color }}/20 @if ($notification->read_at) opacity-60 @endif">
                    <div class="card-body p-4">
                        <div class="flex items-start gap-3">
                            <x-icon :name="$iconName" class="w-5 h-5 mt-0.5 text-{{ $color }}" />

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="font-semibold text-base">
                                        {{ $title }}
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-base-content/50">
                                            {{ $this->getRelativeTime($notification->created_at) }}
                                        </span>
                                        <button wire:click="deleteNotification('{{ $notification->id }}')"
                                            class="btn btn-ghost btn-xs">
                                            <x-icon name="fas.trash" class="w-3 h-3" />
                                        </button>
                                    </div>
                                </div>

                                <div class="text-sm text-base-content/70 mt-1">
                                    {{ $message }}
                                </div>

                                <div class="flex items-center gap-2 mt-3">
                                    @if ($actionUrl)
                                    <a href="{{ $actionUrl }}"
                                        wire:click="markNotificationAsRead('{{ $notification->id }}')"
                                        class="btn btn-sm btn-{{ $color }}">
                                        <x-icon name="fas.arrow-right" class="w-3 h-3" />
                                        View
                                    </a>
                                    @endif
                                    @if (!$notification->read_at)
                                    <button wire:click="markNotificationAsRead('{{ $notification->id }}')"
                                        class="btn btn-sm btn-ghost">
                                        <x-icon name="fas.check" class="w-3 h-3" />
                                        Mark as Read
                                    </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            @elseif ($feedItem['type'] === 'progress')
                @php
                $progress = $feedItem['item'];
                $isActive = !$progress->completed_at && !$progress->failed_at;
                $isFailed = $progress->failed_at !== null;
                $isCompleted = $progress->completed_at !== null;
                @endphp

                <div class="card bg-base-100 border @if ($isActive) border-primary/20 @elseif ($isFailed) border-error/20 @else border-success/20 @endif">
                    <div class="card-body p-4">
                        <div class="flex items-start gap-3">
                            @if ($isActive)
                            <x-icon :name="$this->getActionIcon($progress->action_type)"
                                class="w-5 h-5 mt-0.5 animate-spin text-primary" />
                            @elseif ($isFailed)
                            <x-icon name="fas.circle-xmark" class="w-5 h-5 mt-0.5 text-error" />
                            @else
                            <x-icon name="fas.circle-check" class="w-5 h-5 mt-0.5 text-success" />
                            @endif

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="font-semibold text-base">
                                        {{ ucfirst(str_replace('_', ' ', $progress->action_type)) }}
                                    </div>
                                    <div class="text-xs text-base-content/50 font-mono">
                                        @if ($isActive)
                                        {{ $this->getRunningDuration($progress->created_at) }}
                                        @else
                                        {{ $this->getRelativeTime($progress->completed_at ?? $progress->failed_at) }}
                                        @endif
                                    </div>
                                </div>

                                <div class="text-sm mt-1 @if ($isFailed) text-error @elseif ($isCompleted) text-success @else text-base-content/70 @endif">
                                    @if ($isFailed)
                                    Failed: {{ $progress->error_message ?? $progress->message }}
                                    @else
                                    {{ $progress->message }}
                                    @endif
                                </div>

                                @if ($isActive)
                                <div class="flex items-center gap-2 mt-3">
                                    <progress class="progress progress-primary flex-1 h-2"
                                        value="{{ $progress->progress }}"
                                        max="{{ $progress->total }}"></progress>
                                    <span class="text-xs font-mono text-base-content/70 min-w-[3rem] text-right">
                                        {{ $progress->progress }}%
                                    </span>
                                </div>

                                @if ($progress->step)
                                <div class="text-xs text-base-content/60 mt-2">
                                    <span class="opacity-70">Step:</span> {{ $progress->step }}
                                </div>
                                @endif
                                @endif

                                @if ($progress->details && count($progress->details) > 0)
                                <div class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs mt-3 pt-3 border-t border-base-300">
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
                                <div class="mt-3 pt-3 border-t border-base-300">
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
            @endif
        @endforeach
    </div>

    <!-- Pagination -->
    @if ($feed['totalPages'] > 1)
    <div class="flex items-center justify-between mt-6">
        <div class="text-sm text-base-content/60">
            Page {{ $feed['currentPage'] }} of {{ $feed['totalPages'] }}
            ({{ $feed['total'] }} total items)
        </div>
        <div class="flex gap-2">
            <button
                wire:click="previousPage"
                @if ($page <= 1) disabled @endif
                class="btn btn-sm"
                @if ($page <= 1) disabled @endif>
                <x-icon name="fas.chevron-left" class="w-3 h-3" />
                Previous
            </button>
            <button
                wire:click="nextPage"
                @if (!$feed['hasMore']) disabled @endif
                class="btn btn-sm"
                @if (!$feed['hasMore']) disabled @endif>
                Next
                <x-icon name="fas.chevron-right" class="w-3 h-3" />
            </button>
        </div>
    </div>
    @endif
    @endif
</div>
