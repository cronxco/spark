<?php

use function Livewire\Volt\{state, computed, on};
use App\Models\Event;
use Carbon\Carbon;

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

$event = computed(function () {
    if (!$this->eventId) {
        return null;
    }
    
    return Event::with([
        'actor', 
        'target', 
        'integration', 
        'blocks', 
        'tags',
        'actor.tags',
        'target.tags'
    ])
    ->where('id', $this->eventId)
    ->whereHas('integration', function ($q) {
        $userId = optional(auth()->guard('web')->user())->id;
        if ($userId) {
            $q->where('user_id', $userId);
        } else {
            $q->whereRaw('1 = 0');
        }
    })
    ->first();
});

$viewEvent = function ($id) {
    $this->eventId = $id;
    $this->view = 'show';
};

$goBack = function () {
    $this->view = 'index';
    $this->eventId = null;
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
    @if($this->view === 'index')
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
                @if($this->events->isEmpty())
                    <x-card>
                        <div class="text-center py-8">
                            <x-icon name="o-calendar" class="w-12 h-12 text-base-300 mx-auto mb-4" />
                            <h3 class="text-lg font-semibold text-base-content mb-2">No events for this date</h3>
                            <p class="text-base-content/70">Try another day using the arrows or date picker.</p>
                        </div>
                    </x-card>
                @else
                    <div class="space-y-4">
                        @foreach($this->events as $event)
                            <x-card class="hover:shadow-md transition-shadow cursor-pointer" 
                                    wire:click="viewEvent('{{ $event->id }}')">
                                <div class="flex items-start gap-4">
                                    <!-- Event Icon -->
                                    <div class="flex-shrink-0">
                                        <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center">
                                            <x-icon name="o-bolt" class="w-5 h-5 text-primary" />
                                        </div>
                                    </div>

                                    <!-- Event Content -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-2">
                                            <h3 class="font-semibold text-base-content">
                                                {{ ucfirst($event->action) }}
                                            </h3>
                                            <x-badge :value="$event->service" class="badge-sm" />
                                            @if($event->domain)
                                                <x-badge :value="$event->domain" class="badge-sm badge-outline" />
                                            @endif
                                        </div>

                                        <!-- Actor and Target -->
                                        <div class="flex items-center gap-2 text-sm text-base-content/70 mb-2">
                                            @if($event->actor)
                                                <span class="font-medium">{{ $event->actor->title }}</span>
                                                <x-icon name="o-arrow-right" class="w-3 h-3" />
                                            @endif
                                            @if($event->target)
                                                <span class="font-medium">{{ $event->target->title }}</span>
                                            @endif
                                        </div>

                                        <!-- Event Details -->
                                        <div class="flex items-center gap-4 text-xs text-base-content/60">
                                            <div class="flex items-center gap-1">
                                                <x-icon name="o-clock" class="w-3 h-3" />
                                                {{ $event->time->format('g:i A') }}
                                            </div>
                                            @if($event->integration)
                                                <div class="flex items-center gap-1">
                                                    <x-icon name="o-link" class="w-3 h-3" />
                                                    {{ $event->integration->name }}
                                                </div>
                                            @endif
                                            @if($event->value)
                                                <div class="flex items-center gap-1">
                                                    <x-icon name="o-chart-bar" class="w-3 h-3" />
                                                    {{ $event->value }}
                                                    @if($event->value_unit)
                                                        {{ $event->value_unit }}
                                                    @endif
                                                </div>
                                            @endif
                                        </div>

                                        <!-- Tags -->
                                        @if($event->tags->isNotEmpty())
                                            <div class="flex flex-wrap gap-1 mt-2">
                                                @foreach($event->tags as $tag)
                                                    <x-badge :value="$tag->name" class="badge-xs" />
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    <!-- Arrow -->
                                    <div class="flex-shrink-0">
                                        <x-icon name="o-chevron-right" class="w-4 h-4 text-base-content/40" />
                                    </div>
                                </div>
                            </x-card>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @else
        <!-- Event Detail -->
        @if($this->event)
            <div class="space-y-6">
                <!-- Header -->
                <div class="flex items-center gap-4">
                    <x-button wire:click="goBack" class="btn-ghost">
                        <x-icon name="o-arrow-left" class="w-4 h-4" />
                        Back to Events
                    </x-button>
                    <div class="flex-1">
                        <h1 class="text-2xl font-bold text-base-content">
                            Event Details
                        </h1>
                    </div>
                </div>

                <!-- Event Overview Card -->
                <x-card>
                    <div class="flex items-start gap-4">
                        <!-- Event Icon -->
                        <div class="flex-shrink-0">
                            <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center">
                                <x-icon name="o-bolt" class="w-6 h-6 text-primary" />
                            </div>
                        </div>

                        <!-- Event Info -->
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-3">
                                <h2 class="text-xl font-semibold text-base-content">
                                    {{ ucfirst($this->event->action) }}
                                </h2>
                                <x-badge :value="$this->event->service" />
                                @if($this->event->domain)
                                    <x-badge :value="$this->event->domain" class="badge-outline" />
                                @endif
                            </div>

                            <!-- Actor and Target Flow -->
                            <div class="flex items-center gap-3 mb-4">
                                @if($this->event->actor)
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-full bg-secondary/10 flex items-center justify-center">
                                            <x-icon name="o-user" class="w-4 h-4 text-secondary" />
                                        </div>
                                        <span class="font-medium">{{ $this->event->actor->title }}</span>
                                    </div>
                                    <x-icon name="o-arrow-right" class="w-4 h-4 text-base-content/40" />
                                @endif
                                @if($this->event->target)
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-full bg-accent/10 flex items-center justify-center">
                                            <x-icon name="o-arrow-trending-up" class="w-4 h-4 text-accent" />
                                        </div>
                                        <span class="font-medium">{{ $this->event->target->title }}</span>
                                    </div>
                                @endif
                            </div>

                            <!-- Event Metadata -->
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-clock" class="w-4 h-4 text-base-content/60" />
                                    <span class="text-base-content/70">Time:</span>
                                    <span class="font-medium">{{ $this->event->time->format('F j, Y g:i A') }}</span>
                                </div>
                                @if($this->event->integration)
                                    <div class="flex items-center gap-2">
                                        <x-icon name="o-link" class="w-4 h-4 text-base-content/60" />
                                        <span class="text-base-content/70">Integration:</span>
                                        <span class="font-medium">{{ $this->event->integration->name }}</span>
                                    </div>
                                @endif
                                @if($this->event->value)
                                    <div class="flex items-center gap-2">
                                        <x-icon name="o-chart-bar" class="w-4 h-4 text-base-content/60" />
                                        <span class="text-base-content/70">Value:</span>
                                        <span class="font-medium">
                                            {{ $this->event->value }}
                                            @if($this->event->value_unit)
                                                {{ $this->event->value_unit }}
                                            @endif
                                        </span>
                                    </div>
                                @endif
                            </div>

                            <!-- Event Tags -->
                            @if($this->event->tags->isNotEmpty())
                                <div class="mt-4">
                                    <h4 class="text-sm font-medium text-base-content/70 mb-2">Event Tags</h4>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($this->event->tags as $tag)
                                            <x-badge :value="$tag->name" />
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </x-card>

                <!-- Actor Details -->
                @if($this->event->actor)
                    <x-card>
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="o-user" class="w-5 h-5 text-secondary" />
                            Actor Details
                        </h3>
                        <div class="space-y-4">
                            <div>
                                <h4 class="font-medium text-base-content">{{ $this->event->actor->title }}</h4>
                                @if($this->event->actor->content)
                                    <p class="text-sm text-base-content/70 mt-1">{{ $this->event->actor->content }}</p>
                                @endif
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                @if($this->event->actor->type)
                                    <div class="flex items-center gap-2">
                                        <x-icon name="o-tag" class="w-4 h-4 text-base-content/60" />
                                        <span class="text-base-content/70">Type:</span>
                                        <span class="font-medium">{{ $this->event->actor->type }}</span>
                                    </div>
                                @endif
                                @if($this->event->actor->concept)
                                    <div class="flex items-center gap-2">
                                        <x-icon name="o-sparkles" class="w-4 h-4 text-base-content/60" />
                                        <span class="text-base-content/70">Concept:</span>
                                        <span class="font-medium">{{ $this->event->actor->concept }}</span>
                                    </div>
                                @endif
                                @if($this->event->actor->url)
                                    <div class="flex items-center gap-2">
                                        <x-icon name="o-link" class="w-4 h-4 text-base-content/60" />
                                        <span class="text-base-content/70">URL:</span>
                                        <a href="{{ $this->event->actor->url }}" target="_blank" class="font-medium text-primary hover:underline">
                                            View
                                        </a>
                                    </div>
                                @endif
                            </div>

                            @if($this->event->actor->tags->isNotEmpty())
                                <div>
                                    <h4 class="text-sm font-medium text-base-content/70 mb-2">Actor Tags</h4>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($this->event->actor->tags as $tag)
                                            <x-badge :value="$tag->name" class="badge-secondary" />
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    </x-card>
                @endif

                <!-- Target Details -->
                @if($this->event->target)
                    <x-card>
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="o-arrow-trending-up" class="w-5 h-5 text-accent" />
                            Target Details
                        </h3>
                        <div class="space-y-4">
                            <div>
                                <h4 class="font-medium text-base-content">{{ $this->event->target->title }}</h4>
                                @if($this->event->target->content)
                                    <p class="text-sm text-base-content/70 mt-1">{{ $this->event->target->content }}</p>
                                @endif
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                @if($this->event->target->type)
                                    <div class="flex items-center gap-2">
                                        <x-icon name="o-tag" class="w-4 h-4 text-base-content/60" />
                                        <span class="text-base-content/70">Type:</span>
                                        <span class="font-medium">{{ $this->event->target->type }}</span>
                                    </div>
                                @endif
                                @if($this->event->target->concept)
                                    <div class="flex items-center gap-2">
                                        <x-icon name="o-sparkles" class="w-4 h-4 text-base-content/60" />
                                        <span class="text-base-content/70">Concept:</span>
                                        <span class="font-medium">{{ $this->event->target->concept }}</span>
                                    </div>
                                @endif
                                @if($this->event->target->url)
                                    <div class="flex items-center gap-2">
                                        <x-icon name="o-link" class="w-4 h-4 text-base-content/60" />
                                        <span class="text-base-content/70">URL:</span>
                                        <a href="{{ $this->event->target->url }}" target="_blank" class="font-medium text-primary hover:underline">
                                            View
                                        </a>
                                    </div>
                                @endif
                            </div>

                            @if($this->event->target->tags->isNotEmpty())
                                <div>
                                    <h4 class="text-sm font-medium text-base-content/70 mb-2">Target Tags</h4>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($this->event->target->tags as $tag)
                                            <x-badge :value="$tag->name" class="badge-accent" />
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    </x-card>
                @endif

                <!-- Blocks -->
                @if($this->event->blocks->isNotEmpty())
                    <x-card>
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="o-squares-2x2" class="w-5 h-5 text-info" />
                            Related Blocks ({{ $this->event->blocks->count() }})
                        </h3>
                        <div class="space-y-4">
                            @foreach($this->event->blocks as $block)
                                <div class="border border-base-300 rounded-lg p-4">
                                    <div class="flex items-start justify-between mb-2">
                                        <h4 class="font-medium text-base-content">{{ $block->title }}</h4>
                                        @if($block->value)
                                            <x-badge :value="$block->value . ($block->value_unit ? ' ' . $block->value_unit : '')" class="badge-info" />
                                        @endif
                                    </div>
                                    
                                    @if($block->content)
                                        <p class="text-sm text-base-content/70 mb-3">{{ $block->content }}</p>
                                    @endif

                                    <div class="flex items-center gap-4 text-xs text-base-content/60">
                                        @if($block->time)
                                            <div class="flex items-center gap-1">
                                                <x-icon name="o-clock" class="w-3 h-3" />
                                                {{ $block->time->format('g:i A') }}
                                            </div>
                                        @endif
                                        @if($block->url)
                                            <div class="flex items-center gap-1">
                                                <x-icon name="o-link" class="w-3 h-3" />
                                                <a href="{{ $block->url }}" target="_blank" class="text-primary hover:underline">
                                                    View
                                                </a>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-card>
                @endif

                <!-- Event Metadata -->
                @if($this->event->event_metadata && count($this->event->event_metadata) > 0)
                    <x-card>
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="o-cog-6-tooth" class="w-5 h-5 text-warning" />
                            Event Metadata
                        </h3>
                        <div class="bg-base-200 rounded-lg p-4">
                            <pre class="text-sm text-base-content/80 whitespace-pre-wrap">{{ json_encode($this->event->event_metadata, JSON_PRETTY_PRINT) }}</pre>
                        </div>
                    </x-card>
                @endif
            </div>
        @else
            <div class="text-center py-8">
                <x-icon name="o-exclamation-triangle" class="w-12 h-12 text-warning mx-auto mb-4" />
                <h3 class="text-lg font-semibold text-base-content mb-2">Event Not Found</h3>
                <p class="text-base-content/70">The requested event could not be found.</p>
                <x-button wire:click="goBack" class="mt-4">
                    Back to Events
                </x-button>
            </div>
        @endif
    @endif
</div>
