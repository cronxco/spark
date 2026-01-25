<?php

use App\Models\ActionProgress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use function Livewire\Volt\layout;
use function Livewire\Volt\state;

layout('components.layouts.app');

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $typeFilter = 'all'; // all, notifications, progress
    public string $statusFilter = 'all'; // all, unread, active
    public string $timeRange = 'all'; // all, today, week, month

    public function mount(): void
    {
        //
    }

    public function getItemsProperty()
    {
        $user = Auth::user();
        $items = collect();

        // Fetch notifications if filter allows
        if ($this->typeFilter === 'all' || $this->typeFilter === 'notifications') {
            $notifications = $user->notifications();

            // Apply status filter for notifications
            if ($this->statusFilter === 'unread') {
                $notifications = $notifications->whereNull('read_at');
            }

            // Apply time range filter
            $notifications = $this->applyTimeRangeFilter($notifications);

            // Apply search
            if ($this->search) {
                $notifications = $notifications->where('data', 'like', '%' . $this->search . '%');
            }

            $notificationItems = $notifications->get()->map(function ($notification) {
                return (object) [
                    'type' => 'notification',
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? 'Notification',
                    'message' => $notification->data['message'] ?? '',
                    'time' => $notification->created_at,
                    'read_at' => $notification->read_at,
                    'model' => $notification,
                ];
            });

            $items = $items->merge($notificationItems);
        }

        // Fetch action progress if filter allows
        if ($this->typeFilter === 'all' || $this->typeFilter === 'progress') {
            $progress = ActionProgress::where('user_id', $user->id);

            // Apply status filter for progress
            if ($this->statusFilter === 'active') {
                $progress = $progress->whereNull('completed_at')->whereNull('failed_at');
            }

            // Apply time range filter
            $progress = $this->applyTimeRangeFilter($progress);

            // Apply search
            if ($this->search) {
                $progress = $progress->where('message', 'like', '%' . $this->search . '%');
            }

            $progressItems = $progress->get()->map(function ($item) {
                return (object) [
                    'type' => 'progress',
                    'id' => $item->id,
                    'title' => ucfirst($item->action_type),
                    'message' => $item->message,
                    'time' => $item->updated_at,
                    'progress' => $item->progress,
                    'total' => $item->total,
                    'completed_at' => $item->completed_at,
                    'failed_at' => $item->failed_at,
                    'model' => $item,
                ];
            });

            $items = $items->merge($progressItems);
        }

        // Sort by time, newest first
        $items = $items->sortByDesc('time')->values();

        // Paginate
        $perPage = 25;
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $currentItems = $items->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $currentItems,
            $items->count(),
            $perPage,
            $currentPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    protected function applyTimeRangeFilter($query)
    {
        return match ($this->timeRange) {
            'today' => $query->where('created_at', '>=', now()->startOfDay()),
            'week' => $query->where('created_at', '>=', now()->subWeek()),
            'month' => $query->where('created_at', '>=', now()->subMonth()),
            default => $query,
        };
    }

    public function markAsRead($notificationId): void
    {
        $notification = Auth::user()->notifications()->find($notificationId);
        if ($notification) {
            $notification->markAsRead();
        }
    }

    public function deleteNotification($notificationId): void
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
        ActionProgress::where('user_id', Auth::user()->id)
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

    public function clearFilters(): void
    {
        $this->search = '';
        $this->typeFilter = 'all';
        $this->statusFilter = 'all';
        $this->timeRange = 'all';
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingTimeRange(): void
    {
        $this->resetPage();
    }
}; ?>

<div>
    <x-header
        title="Notifications"
        subtitle="View your notifications and active progress"
        separator
    />

    <div class="flex flex-col gap-6">
        {{-- Filters --}}
        <div class="flex flex-wrap gap-4 items-center justify-between">
            <div class="flex gap-4 items-center flex-wrap">
                {{-- Search --}}
                <div class="relative">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search..."
                        class="px-4 py-2 border border-neutral-300 dark:border-neutral-700 rounded-lg bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                </div>

                {{-- Type Filter --}}
                <select
                    wire:model.live="typeFilter"
                    class="px-4 py-2 border border-neutral-300 dark:border-neutral-700 rounded-lg bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option value="all">Type: All</option>
                    <option value="notifications">Type: Notifications</option>
                    <option value="progress">Type: Progress</option>
                </select>

                {{-- Status Filter --}}
                <select
                    wire:model.live="statusFilter"
                    class="px-4 py-2 border border-neutral-300 dark:border-neutral-700 rounded-lg bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option value="all">Status: All</option>
                    <option value="unread">Status: Unread</option>
                    <option value="active">Status: Active</option>
                </select>

                {{-- Time Range Filter --}}
                <select
                    wire:model.live="timeRange"
                    class="px-4 py-2 border border-neutral-300 dark:border-neutral-700 rounded-lg bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option value="all">Time: All</option>
                    <option value="today">Time: Today</option>
                    <option value="week">Time: This Week</option>
                    <option value="month">Time: This Month</option>
                </select>

                @if ($search || $typeFilter !== 'all' || $statusFilter !== 'all' || $timeRange !== 'all')
                    <button
                        wire:click="clearFilters"
                        class="px-4 py-2 text-neutral-600 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-neutral-100"
                    >
                        Clear Filters
                    </button>
                @endif
            </div>

            <div class="flex gap-2">
                <button
                    wire:click="markAllAsRead"
                    class="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                >
                    Mark All Read
                </button>
                <button
                    wire:click="clearCompleted"
                    class="px-4 py-2 text-sm bg-neutral-600 text-white rounded-lg hover:bg-neutral-700"
                >
                    Clear Completed
                </button>
            </div>
        </div>

        {{-- Items List --}}
        @if ($this->items->count() > 0)
            <div class="space-y-4">
                @foreach ($this->items as $item)
                    <div class="p-4 bg-white dark:bg-neutral-800 border border-neutral-200 dark:border-neutral-700 rounded-lg">
                        <div class="flex justify-between items-start">
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <h3 class="font-semibold text-neutral-900 dark:text-neutral-100">
                                        {{ $item->title }}
                                    </h3>
                                    @if ($item->type === 'notification' && !$item->read_at)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                            Unread
                                        </span>
                                    @endif
                                    @if ($item->type === 'progress' && !$item->completed_at && !$item->failed_at)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                            Active
                                        </span>
                                    @endif
                                </div>
                                <p class="text-sm text-neutral-600 dark:text-neutral-400 mt-1">
                                    {{ $item->message }}
                                </p>
                                @if ($item->type === 'progress' && isset($item->progress) && isset($item->total))
                                    <div class="mt-2">
                                        <div class="flex justify-between text-xs text-neutral-600 dark:text-neutral-400 mb-1">
                                            <span>Progress</span>
                                            <span>{{ $item->progress }}/{{ $item->total }}</span>
                                        </div>
                                        <div class="w-full bg-neutral-200 dark:bg-neutral-700 rounded-full h-2">
                                            <div
                                                class="bg-blue-600 h-2 rounded-full"
                                                style="width: {{ $item->total > 0 ? ($item->progress / $item->total * 100) : 0 }}%"
                                            ></div>
                                        </div>
                                    </div>
                                @endif
                                <p class="text-xs text-neutral-500 dark:text-neutral-500 mt-2">
                                    {{ $item->time->diffForHumans() }}
                                </p>
                            </div>
                            <div class="flex gap-2 ml-4">
                                @if ($item->type === 'notification' && !$item->read_at)
                                    <button
                                        wire:click="markAsRead('{{ $item->id }}')"
                                        class="text-sm text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300"
                                    >
                                        Mark Read
                                    </button>
                                @endif
                                @if ($item->type === 'notification')
                                    <button
                                        wire:click="deleteNotification('{{ $item->id }}')"
                                        class="text-sm text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300"
                                    >
                                        Delete
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Pagination --}}
            <div class="mt-6">
                {{ $this->items->links() }}
            </div>
        @else
            {{-- Empty State --}}
            <div class="text-center py-12">
                <div class="text-neutral-400 dark:text-neutral-600 mb-2">
                    <svg class="mx-auto h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-neutral-900 dark:text-neutral-100 mb-1">
                    No Notifications
                </h3>
                <p class="text-sm text-neutral-600 dark:text-neutral-400">
                    You're all caught up! There's nothing to see here.
                </p>
            </div>
        @endif
    </div>
</div>
