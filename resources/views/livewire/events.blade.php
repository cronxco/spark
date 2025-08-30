<?php

use function Livewire\Volt\{state, computed, on};
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Integrations\PluginRegistry;

state([
    'view' => 'index',
    'eventId' => null,
    'search' => '',
    // Selected date in Y-m-d format for native date input
    'date' => Carbon::today()->format('Y-m-d'),
]);

$events = computed(function () {
    try {
        $selectedDate = Carbon::parse($this->date);
    } catch (\Throwable $e) {
        $selectedDate = Carbon::today();
    }

    $query = Event::with(['actor', 'target', 'integration', 'tags'])
        ->whereHas('integration', function ($q) {
            $userId = optional(auth()->guard('web')->user())->id;
            if ($userId) {
                $q->where('user_id', $userId);
            } else {
                // Force empty result if no auth user
                $q->whereRaw('1 = 0');
            }
        })
        ->whereDate('time', $selectedDate)
        ->orderBy('time', 'desc');

    if ($this->search) {
        $query->where(function ($q) {
            $q->where('action', 'like', "%{$this->search}%")
              ->orWhere('domain', 'like', "%{$this->search}%")
              ->orWhere('service', 'like', "%{$this->search}%")
              ->orWhereHas('actor', function ($actorQuery) {
                  $actorQuery->where('title', 'like', "%{$this->search}%");
              })
              ->orWhereHas('target', function ($targetQuery) {
                  $targetQuery->where('title', 'like', "%{$this->search}%");
              });
        });
    }

    return $query->get();
});

$dateLabel = computed(function () {
    try {
        $date = Carbon::parse($this->date);
    } catch (\Throwable $e) {
        $date = Carbon::today();
    }

    if ($date->isToday()) {
        return 'Today';
    }

    if ($date->isYesterday()) {
        return 'Yesterday';
    }

    if ($date->isTomorrow()) {
        return 'Tomorrow';
    }

    return $date->format('M j, Y');
});

$formatAction = function ($action) {
    // Convert snake_case to title case
    $formatted = Str::headline($action);
    
    // Keep certain words lowercase for natural language flow
    $wordsToLowercase = ['To', 'For', 'From', 'In', 'On', 'At', 'By', 'With', 'Of', 'The', 'A', 'An'];
    foreach ($wordsToLowercase as $word) {
        $formatted = str_replace(" $word ", " " . strtolower($word) . " ", $formatted);
    }
    
    return $formatted;
};

$getEventIcon = function ($action, $service) {
    // Try to get icon from plugin configuration first
    $pluginClass = PluginRegistry::getPlugin($service);
    if ($pluginClass) {
        $actionTypes = $pluginClass::getActionTypes();
        if (isset($actionTypes[$action]) && isset($actionTypes[$action]['icon'])) {
            return $actionTypes[$action]['icon'];
        }
    }
    
    // Fallback to hardcoded icons if plugin doesn't have this action type
    $icons = [
        'create' => 'o-plus-circle',
        'update' => 'o-arrow-path',
        'delete' => 'o-trash',
        'move' => 'o-arrow-right',
        'copy' => 'o-document-duplicate',
        'share' => 'o-share',
        'like' => 'o-heart',
        'comment' => 'o-chat-bubble-left',
        'follow' => 'o-user-plus',
        'unfollow' => 'o-user-minus',
        'join' => 'o-user-group',
        'leave' => 'o-user-group',
        'start' => 'o-play',
        'stop' => 'o-stop',
        'pause' => 'o-pause',
        'resume' => 'o-play',
        'complete' => 'o-check-circle',
        'fail' => 'o-x-circle',
        'cancel' => 'o-x-mark',
        'approve' => 'o-check',
        'reject' => 'o-x-mark',
        'publish' => 'o-globe-alt',
        'unpublish' => 'o-eye-slash',
        'archive' => 'o-archive-box',
        'restore' => 'o-arrow-path',
        'login' => 'o-arrow-right-on-rectangle',
        'logout' => 'o-arrow-left-on-rectangle',
        'purchase' => 'o-shopping-cart',
        'refund' => 'o-arrow-path',
        'transfer' => 'o-arrow-right',
        'withdraw' => 'o-arrow-down',
        'deposit' => 'o-arrow-up',
        'listen' => 'o-musical-note',
        'watch' => 'o-video-camera',
        'read' => 'o-book-open',
        'write' => 'o-pencil',
        'send' => 'o-paper-airplane',
        'receive' => 'o-inbox',
        'download' => 'o-arrow-down-tray',
        'upload' => 'o-arrow-up-tray',
        'save' => 'o-bookmark',
        'bookmark' => 'o-bookmark',
        'favorite' => 'o-heart',
        'rate' => 'o-star',
        'review' => 'o-chat-bubble-left-ellipsis',
        'subscribe' => 'o-bell',
        'unsubscribe' => 'o-bell-slash',
        'block' => 'o-no-symbol',
        'unblock' => 'o-check-circle',
        'mute' => 'o-speaker-x-mark',
        'unmute' => 'o-speaker-wave',
        'pin' => 'o-map-pin',
        'unpin' => 'o-map-pin',
        'lock' => 'o-lock-closed',
        'unlock' => 'o-lock-open',
        'hide' => 'o-eye-slash',
        'show' => 'o-eye',
        'enable' => 'o-toggle-on',
        'disable' => 'o-toggle-off',
        'activate' => 'o-power',
        'deactivate' => 'o-power',
        'connect' => 'o-link',
        'disconnect' => 'o-link-slash',
        'sync' => 'o-arrow-path',
        'backup' => 'o-archive-box',
        'restore' => 'o-arrow-path',
        'export' => 'o-arrow-down-tray',
        'import' => 'o-arrow-up-tray',
        'install' => 'o-download',
        'uninstall' => 'o-trash',
        'upgrade' => 'o-arrow-trending-up',
        'downgrade' => 'o-arrow-trending-down',
        'pot' => 'o-arrow-right',
        'add' => 'o-plus',
        'remove' => 'o-minus',
        'increase' => 'o-arrow-trending-up',
        'decrease' => 'o-arrow-trending-down',
    ];

    return $icons[strtolower($action)] ?? 'o-bolt';
};

$getEventColor = function ($action) {
    $colors = [
        'create' => 'text-success',
        'update' => 'text-info',
        'delete' => 'text-error',
        'move' => 'text-warning',
        'copy' => 'text-info',
        'share' => 'text-primary',
        'like' => 'text-error',
        'comment' => 'text-info',
        'follow' => 'text-success',
        'unfollow' => 'text-warning',
        'join' => 'text-success',
        'leave' => 'text-warning',
        'start' => 'text-success',
        'stop' => 'text-error',
        'pause' => 'text-warning',
        'resume' => 'text-success',
        'complete' => 'text-success',
        'fail' => 'text-error',
        'cancel' => 'text-warning',
        'approve' => 'text-success',
        'reject' => 'text-error',
        'publish' => 'text-success',
        'unpublish' => 'text-warning',
        'archive' => 'text-neutral',
        'restore' => 'text-info',
        'login' => 'text-success',
        'logout' => 'text-warning',
        'purchase' => 'text-success',
        'refund' => 'text-info',
        'transfer' => 'text-warning',
        'withdraw' => 'text-error',
        'deposit' => 'text-success',
    ];

    return $colors[strtolower($action)] ?? 'text-primary';
};

$previousDay = function () {
    try {
        $current = Carbon::parse($this->date);
    } catch (\Throwable $e) {
        $current = Carbon::today();
    }
    $this->date = $current->copy()->subDay()->format('Y-m-d');
};

$nextDay = function () {
    try {
        $current = Carbon::parse($this->date);
    } catch (\Throwable $e) {
        $current = Carbon::today();
    }
    $this->date = $current->copy()->addDay()->format('Y-m-d');
};

?>

<div>
        <!-- Events Index -->
        <div>
            <x-header :title="'Events — ' . $this->dateLabel" separator>
                <x-slot:actions>
                    <div class="flex items-center gap-3">
                        <div class="join">
                            <x-button class="join-item btn-ghost btn-sm" wire:click="previousDay">
                                <x-icon name="o-chevron-left" class="w-4 h-4" />
                            </x-button>
                            <label class="join-item">
                                <input
                                    type="date"
                                    class="input input-sm"
                                    wire:model.live="date"
                                />
                            </label>
                            <x-button class="join-item btn-ghost btn-sm" wire:click="nextDay">
                                <x-icon name="o-chevron-right" class="w-4 h-4" />
                            </x-button>
                        </div>

                        <x-input
                            wire:model.live.debounce.300ms="search"
                            placeholder="Search events..."
                            class="w-64"
                        />
                    </div>
                </x-slot:actions>
            </x-header>

            <div class="space-y-6">
                @if ($this->events->isEmpty())
                    <x-card>
                        <div class="text-center py-8">
                            <x-icon name="o-calendar" class="w-12 h-12 text-base-300 mx-auto mb-4" />
                            <h3 class="text-lg font-semibold text-base-content mb-2">No events for this date</h3>
                            <p class="text-base-content/70">Try another day using the arrows or date picker.</p>
                        </div>
                    </x-card>
                @else
                <!-- Vertical Timeline View -->
                <div class="bg-base-100 rounded-lg p-6">
                    <ul class="timeline timeline-vertical">
                        @foreach ($this->events as $index => $event)
                            <li>
                                <div class="timeline-start">
                                <div class="flex items-start gap-4">
                                        <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0">
                                            <x-icon name="{{ $this->getEventIcon($event->action, $event->service) }}" 
                                                   class="w-5 h-5 {{ $this->getEventColor($event->action) }}" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="mb-2">
                                                <div class="font-semibold text-base-content text-lg leading-tight">
                                                    {{ $this->formatAction($event->action) }}
                                                    @if ($event->target)
                                                        to {{ $event->target->title }}
                                                    @elseif ($event->actor)
                                                        {{ $event->actor->title }}
                                                    @endif
                                                    @if ($event->value)
                                                        <span class="text-primary font-medium">
                                                            ({{ $event->formatted_value }}{{ $event->value_unit ? ' ' . $event->value_unit : '' }})
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="text-sm text-base-content/70 mb-3">
                                                {{ $event->time->format('g:i A') }}
                                                @if ($event->integration)
                                                    · {{ $event->integration->name }}
                                                @endif
                                    </div>

                                            <!-- Service and Domain Badges -->
                                        <div class="flex items-center gap-2 mb-2">
                                            <x-badge :value="$event->service" class="badge-sm" />
                                            @if ($event->domain)
                                                <x-badge :value="$event->domain" class="badge-sm badge-outline" />
                                            @endif
                                        </div>

                                        <!-- Tags -->
                                        @if ($event->tags->isNotEmpty())
                                                <div class="flex flex-wrap gap-1 mb-3">
                                                @foreach ($event->tags as $tag)
                                                    <x-badge :value="$tag->name" class="badge-xs" />
                                                @endforeach
                                            </div>
                                        @endif

                                            <!-- View Event Button -->
                            <div>
                                                <a href="{{ route('events.show', $event->id) }}" 
                                                   class="btn btn-sm btn-primary">
                                                    View Details
                                                    <x-icon name="o-chevron-right" class="w-3 h-3" />
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </li>
                            @endforeach
                    </ul>
                        </div>
                @endif
            </div>
            </div>
</div>
