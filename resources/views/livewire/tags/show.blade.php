<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Spatie\Tags\Tag;
use App\Models\Event;
use App\Models\EventObject;

new class extends Component {
    use WithPagination;

    public Tag $tag;
    public string $filterType = 'all'; // 'all', 'events', 'objects'

    public function mount(string $type, string $slug, string $id): void
    {
        $this->tag = Tag::findOrFail($id);
    }

    public function setFilter(string $type): void
    {
        $this->filterType = $type;
        $this->resetPage();
    }

    public function getTaggedEvents()
    {
        return Event::withAnyTags([$this->tag])
            ->with(['actor', 'target', 'integration', 'tags'])
            ->whereHas('integration', function ($q) {
                $userId = optional(auth()->guard('web')->user())->id;
                if ($userId) {
                    $q->where('user_id', $userId);
                } else {
                    $q->whereRaw('1 = 0');
                }
            })
            ->orderBy('time', 'desc')
            ->paginate(20);
    }

    public function getTaggedObjects()
    {
        return EventObject::withAnyTags([$this->tag])
            ->with(['tags'])
            ->where('user_id', optional(auth()->guard('web')->user())->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);
    }

    public function getTagTypeLabel(string $type): string
    {
        return match($type) {
            'transaction_category' => 'Transaction Category',
            'transaction_type' => 'Transaction Type',
            'transaction_status' => 'Transaction Status',
            'transaction_scheme' => 'Transaction Scheme',
            'transaction_currency' => 'Currency',
            'balance_type' => 'Balance Type',
            'merchant_emoji' => 'Merchant Emoji',
            'merchant_country' => 'Merchant Country',
            'merchant_category' => 'Merchant Category',
            'person' => 'Person',
            'card_pan' => 'Card',
            'decline_reason' => 'Decline Reason',
            'music_artist' => 'Artist',
            'music_album' => 'Album',
            'spotify_context' => 'Spotify Context',
            'emoji' => 'Emoji',
            'spark' => 'Custom Tag',
            default => Str::headline($type),
        };
    }

    public function formatAction($action)
    {
        return format_action_title($action);
    }

    public function getEventCount()
    {
        return Event::withAnyTags([$this->tag])
            ->whereHas('integration', function ($q) {
                $userId = optional(auth()->guard('web')->user())->id;
                if ($userId) {
                    $q->where('user_id', $userId);
                } else {
                    $q->whereRaw('1 = 0');
                }
            })
            ->count();
    }

    public function getObjectCount()
    {
        return EventObject::withAnyTags([$this->tag])
            ->where('user_id', optional(auth()->guard('web')->user())->id)
            ->count();
    }
};
?>

<div>
    <div class="space-y-4 lg:space-y-6">
        <!-- Header -->
        <x-header :title="$tag->name" subtitle="{{ $this->getTagTypeLabel($tag->type ?? 'untyped') }}" separator>
            <x-slot:actions>
                <!-- Desktop: Full buttons -->
                <div class="hidden sm:flex gap-2">
                    <a href="{{ route('tags.index') }}" class="btn btn-outline">
                        <x-icon name="fas.arrow-left" class="w-4 h-4" />
                        All Tags
                    </a>
                </div>

                <!-- Mobile: Dropdown -->
                <div class="sm:hidden">
                    <x-dropdown>
                        <x-slot:trigger>
                            <x-button class="btn-ghost btn-sm">
                                <x-icon name="fas.ellipsis-vertical" class="w-5 h-5" />
                            </x-button>
                        </x-slot:trigger>
                        <x-menu-item title="All Tags" icon="fas.arrow-left" link="{{ route('tags.index') }}" />
                    </x-dropdown>
                </div>
            </x-slot:actions>
        </x-header>

        <!-- Hero Card -->
        <x-card>
            <div class="flex flex-col sm:flex-row items-start gap-4 lg:gap-6">
                <!-- Large tag icon -->
                <div class="flex-shrink-0 self-center sm:self-start">
                    <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-primary/10 flex items-center justify-center">
                        <x-icon name="fas.tag" class="w-6 h-6 sm:w-8 sm:h-8 text-primary" />
                    </div>
                </div>

                <!-- Main content -->
                <div class="flex-1 w-full">
                    <div class="mb-4 text-center sm:text-left">
                        <h2 class="text-xl sm:text-2xl lg:text-3xl font-bold text-base-content mb-2">
                            <x-tag-ref :tag="$tag" />
                        </h2>
                        <div class="text-sm text-base-content/70">
                            {{ $this->getTagTypeLabel($tag->type ?? 'untyped') }}
                        </div>
                    </div>

                    <!-- Stats -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="p-3 lg:p-4 rounded-lg bg-base-300/50 border border-base-300">
                            <div class="text-sm text-base-content/70 mb-1">Events</div>
                            <div class="text-2xl sm:text-3xl font-bold text-primary">
                                {{ $this->getEventCount() }}
                            </div>
                        </div>
                        <div class="p-3 lg:p-4 rounded-lg bg-base-300/50 border border-base-300">
                            <div class="text-sm text-base-content/70 mb-1">Objects</div>
                            <div class="text-2xl sm:text-3xl font-bold text-info">
                                {{ $this->getObjectCount() }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </x-card>

        <!-- Filter Tabs -->
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <div class="flex gap-2">
                    <button
                        wire:click="setFilter('all')"
                        class="btn btn-sm {{ $filterType === 'all' ? 'btn-primary' : 'btn-outline' }}">
                        All
                    </button>
                    <button
                        wire:click="setFilter('events')"
                        class="btn btn-sm {{ $filterType === 'events' ? 'btn-primary' : 'btn-outline' }}">
                        <x-icon name="fas.bolt" class="w-4 h-4" />
                        Events ({{ $this->getEventCount() }})
                    </button>
                    <button
                        wire:click="setFilter('objects')"
                        class="btn btn-sm {{ $filterType === 'objects' ? 'btn-primary' : 'btn-outline' }}">
                        <x-icon name="o-cube" class="w-4 h-4" />
                        Objects ({{ $this->getObjectCount() }})
                    </button>
                </div>
            </div>
        </div>

        @if ($filterType === 'all' || $filterType === 'events')
            <!-- Tagged Events -->
            @php $events = $this->getTaggedEvents(); @endphp
            @if ($events->isNotEmpty())
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="fas.bolt" class="w-5 h-5 text-primary" />
                            Tagged Events
                        </h3>
                        <div class="space-y-3">
                            @foreach ($events as $event)
                                <div class="border border-base-200 bg-base-100 rounded-lg p-3 hover:bg-base-50 transition-colors">
                                    <div class="flex items-start gap-3">
                                        <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0 mt-1">
                                            <x-icon name="fas.bolt" class="w-4 h-4 text-primary" />
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-start justify-between gap-2 mb-1">
                                                <div class="flex items-center flex-wrap gap-1">
                                                    @if ($event->actor)
                                                        <x-object-ref :object="$event->actor" />
                                                    @endif
                                                    <x-event-ref :event="$event" :showService="false" />
                                                    @if ($event->target)
                                                        <x-object-ref :object="$event->target" />
                                                    @endif
                                                </div>
                                                <div class="flex items-center gap-2 flex-shrink-0">
                                                    @if ($event->value)
                                                        <span class="text-sm font-semibold text-primary">
                                                            {!! format_event_value_display($event->formatted_value, $event->value_unit, $event->service, $event->action, 'action') !!}
                                                        </span>
                                                    @endif
                                                    <a href="{{ route('events.show', $event->id) }}"
                                                       wire:navigate
                                                       class="btn btn-ghost btn-xs btn-square"
                                                       title="View event details">
                                                        <x-icon name="fas.arrow-right" class="w-3 h-3" />
                                                    </a>
                                                </div>
                                            </div>
                                            <div class="text-sm text-base-content/70">
                                                {{ $event->time->format('M j, Y g:i A') }}
                                            </div>
                                            @if ($event->tags->isNotEmpty())
                                                <div class="flex flex-wrap gap-1 mt-2">
                                                    @foreach ($event->tags as $eventTag)
                                                        <x-tag-ref :tag="$eventTag" />
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-4">
                            {{ $events->links() }}
                        </div>
                    </div>
                </div>
            @elseif ($filterType === 'events')
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="text-center py-12">
                            <x-icon name="fas.bolt" class="w-16 h-16 text-base-content/70 mx-auto mb-4" />
                            <h3 class="text-lg font-medium text-base-content mb-2">No Events Found</h3>
                            <p class="text-base-content/70">No events are tagged with "{{ $tag->name }}"</p>
                        </div>
                    </div>
                </div>
            @endif
        @endif

        @if ($filterType === 'all' || $filterType === 'objects')
            <!-- Tagged Objects -->
            @php $objects = $this->getTaggedObjects(); @endphp
            @if ($objects->isNotEmpty())
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="o-cube" class="w-5 h-5 text-info" />
                            Tagged Objects
                        </h3>
                        <div class="space-y-3">
                            @foreach ($objects as $object)
                                <div class="border border-base-200 bg-base-100 rounded-lg p-3 hover:bg-base-50 transition-colors">
                                    <div class="flex items-start gap-3">
                                        <div class="w-8 h-8 rounded-full bg-info/10 flex items-center justify-center flex-shrink-0 mt-1">
                                            <x-icon name="o-cube" class="w-4 h-4 text-info" />
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-start justify-between gap-2 mb-1">
                                                <div class="flex items-center flex-wrap gap-1">
                                                    <x-object-ref :object="$object" :showType="true" />
                                                </div>
                                                <a href="{{ route('objects.show', $object->id) }}"
                                                   wire:navigate
                                                   class="btn btn-ghost btn-xs btn-square"
                                                   title="View object details">
                                                    <x-icon name="fas.arrow-right" class="w-3 h-3" />
                                                </a>
                                            </div>
                                            @if ($object->concept)
                                                <div class="text-sm text-base-content/70">
                                                    {{ Str::headline($object->concept) }}
                                                </div>
                                            @endif
                                            @if ($object->tags->isNotEmpty())
                                                <div class="flex flex-wrap gap-1 mt-2">
                                                    @foreach ($object->tags as $objectTag)
                                                        <x-tag-ref :tag="$objectTag" />
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-4">
                            {{ $objects->links() }}
                        </div>
                    </div>
                </div>
            @elseif ($filterType === 'objects')
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <div class="text-center py-12">
                            <x-icon name="o-cube" class="w-16 h-16 text-base-content/70 mx-auto mb-4" />
                            <h3 class="text-lg font-medium text-base-content mb-2">No Objects Found</h3>
                            <p class="text-base-content/70">No objects are tagged with "{{ $tag->name }}"</p>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </div>
</div>
