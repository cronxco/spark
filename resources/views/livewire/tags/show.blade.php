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
};
?>

<div>
    <x-header :title="$tag->name" separator>
        <x-slot:subtitle>
            <div class="flex items-center gap-2">
                <x-icon name="o-tag" class="w-4 h-4" />
                <span>{{ $this->getTagTypeLabel($tag->type ?? 'untyped') }}</span>
            </div>
        </x-slot:subtitle>
        <x-slot:actions>
            <a href="{{ route('tags.index') }}" class="btn btn-sm btn-ghost">
                <x-icon name="o-arrow-left" class="w-4 h-4" />
                All Tags
            </a>
        </x-slot:actions>
    </x-header>

    <!-- Filter Tabs -->
    <div class="tabs tabs-boxed mb-6">
        <a class="tab {{ $filterType === 'all' ? 'tab-active' : '' }}" wire:click="setFilter('all')">
            All
        </a>
        <a class="tab {{ $filterType === 'events' ? 'tab-active' : '' }}" wire:click="setFilter('events')">
            Events
        </a>
        <a class="tab {{ $filterType === 'objects' ? 'tab-active' : '' }}" wire:click="setFilter('objects')">
            Objects
        </a>
    </div>

    @if ($filterType === 'all' || $filterType === 'events')
        <!-- Tagged Events -->
        @php $events = $this->getTaggedEvents(); @endphp
        @if ($events->isNotEmpty())
            <x-card class="mb-6">
                <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                    <x-icon name="o-bolt" class="w-5 h-5 text-primary" />
                    Tagged Events ({{ $events->total() }})
                </h3>
                <div class="space-y-3">
                    @foreach ($events as $event)
                        <div class="border border-base-300 rounded-lg p-3 hover:bg-base-50 transition-colors">
                            <a href="{{ route('events.show', $event->id) }}"
                               class="block hover:text-primary transition-colors">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                                        <x-icon name="o-bolt" class="w-4 h-4 text-primary" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="mb-1">
                                            <span class="font-medium">
                                                {{ $this->formatAction($event->action) }}
                                                @if (should_display_action_with_object($event->action, $event->service))
                                                    @if ($event->target)
                                                        {{ $event->target->title }}
                                                    @elseif ($event->actor)
                                                        {{ $event->actor->title }}
                                                    @endif
                                                @endif
                                                @if ($event->value)
                                                    <span class="text-primary">
                                                        ({{ format_event_value_display($event->formatted_value, $event->value_unit) }})
                                                    </span>
                                                @endif
                                            </span>
                                        </div>
                                        <div class="text-sm text-base-content/70">
                                            {{ $event->time->format('M j, Y g:i A') }}
                                        </div>
                                        @if ($event->tags->isNotEmpty())
                                            <div class="flex flex-wrap gap-1 mt-2">
                                                @foreach ($event->tags as $eventTag)
                                                    <x-spark-tag :tag="$eventTag" />
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                    <x-icon name="o-chevron-right" class="w-4 h-4 text-base-content/40" />
                                </div>
                            </a>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4">
                    {{ $events->links() }}
                </div>
            </x-card>
        @elseif ($filterType === 'events')
            <x-card>
                <div class="text-center py-8">
                    <x-icon name="o-bolt" class="w-12 h-12 text-base-content/40 mx-auto mb-4" />
                    <h3 class="text-lg font-semibold text-base-content mb-2">No Events Found</h3>
                    <p class="text-base-content/70">No events are tagged with "{{ $tag->name }}"</p>
                </div>
            </x-card>
        @endif
    @endif

    @if ($filterType === 'all' || $filterType === 'objects')
        <!-- Tagged Objects -->
        @php $objects = $this->getTaggedObjects(); @endphp
        @if ($objects->isNotEmpty())
            <x-card>
                <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                    <x-icon name="o-cube" class="w-5 h-5 text-secondary" />
                    Tagged Objects ({{ $objects->total() }})
                </h3>
                <div class="space-y-3">
                    @foreach ($objects as $object)
                        <div class="border border-base-300 rounded-lg p-3 hover:bg-base-50 transition-colors">
                            <a href="{{ route('objects.show', $object->id) }}"
                               class="block hover:text-primary transition-colors">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-secondary/10 flex items-center justify-center">
                                        <x-icon name="o-cube" class="w-4 h-4 text-secondary" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="mb-1">
                                            <span class="font-medium">{{ $object->title }}</span>
                                            @if ($object->type)
                                                <x-badge :value="$object->type" class="badge-sm badge-secondary ml-2" />
                                            @endif
                                        </div>
                                        @if ($object->concept)
                                            <div class="text-sm text-base-content/70">
                                                {{ Str::headline($object->concept) }}
                                            </div>
                                        @endif
                                        @if ($object->tags->isNotEmpty())
                                            <div class="flex flex-wrap gap-1 mt-2">
                                                @foreach ($object->tags as $objectTag)
                                                    <x-spark-tag :tag="$objectTag" />
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                    <x-icon name="o-chevron-right" class="w-4 h-4 text-base-content/40" />
                                </div>
                            </a>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4">
                    {{ $objects->links() }}
                </div>
            </x-card>
        @elseif ($filterType === 'objects')
            <x-card>
                <div class="text-center py-8">
                    <x-icon name="o-cube" class="w-12 h-12 text-base-content/40 mx-auto mb-4" />
                    <h3 class="text-lg font-semibold text-base-content mb-2">No Objects Found</h3>
                    <p class="text-base-content/70">No objects are tagged with "{{ $tag->name }}"</p>
                </div>
            </x-card>
        @endif
    @endif
</div>
