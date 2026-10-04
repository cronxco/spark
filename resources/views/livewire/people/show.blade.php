<?php

use App\Models\Event;
use App\Models\Person;
use App\Models\Relationship;
use App\Services\PersonProfileService;
use App\Traits\AuthorizesOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    use AuthorizesOwnership;

    public Person $person;

    public function mount(Person $person): void
    {
        $this->authorizeOwner($person->user_id);

        $this->person = $person;
        $this->person->logViewIfNotRecent(5);
    }

    /**
     * @return Collection<int, Event>
     */
    #[Computed]
    public function events(): Collection
    {
        return app(PersonProfileService::class)->events($this->person);
    }

    #[Computed]
    public function eventCount(): int
    {
        return app(PersonProfileService::class)->eventCount($this->person);
    }

    /**
     * @return Collection<string, Collection<int, array{relationship: Relationship, related: Model, outgoing: bool}>>
     */
    #[Computed]
    public function connections(): Collection
    {
        return app(PersonProfileService::class)->connections($this->person);
    }

    #[Computed]
    public function avatarUrl(): ?string
    {
        return get_media_temporary_url($this->person, 'downloaded_images', 'thumbnail', 60)
            ?? $this->person->media_url;
    }

    #[Computed]
    public function birthDate(): ?Carbon
    {
        return rescue(fn () => $this->person->birth_date ? Carbon::parse($this->person->birth_date) : null, null, false);
    }
};

?>

<div class="space-y-4 lg:space-y-6">
    <x-header title="Person" separator>
        <x-slot:actions>
            <x-button
                link="{{ route('objects.show', $person->id) }}"
                class="btn-ghost btn-sm"
                icon="fas.sliders"
                label="Object details"
                wire:navigate />
        </x-slot:actions>
    </x-header>

    <x-card class="bg-base-200 shadow">
        <div class="flex flex-col items-center gap-4 text-center sm:flex-row sm:text-left">
            @if ($this->avatarUrl)
                <img src="{{ $this->avatarUrl }}" alt="{{ $person->title }}" class="h-24 w-24 rounded-full object-cover ring-2 ring-secondary/30">
            @else
                <div class="flex h-24 w-24 items-center justify-center rounded-full bg-secondary/10 ring-2 ring-secondary/30">
                    <x-icon name="fas.user" class="h-10 w-10 text-secondary" />
                </div>
            @endif

            <div class="flex flex-col gap-2">
                <h1 class="text-2xl font-bold leading-tight lg:text-3xl">{{ $person->title }}</h1>
                <div class="flex flex-wrap justify-center gap-2 text-sm text-base-content/70 sm:justify-start">
                    <x-type-ref :type="$person->type" :concept="$person->concept" :service="$person->metadata['service'] ?? null" />
                    @if ($this->birthDate)
                        <span class="flex items-center gap-1">
                            <x-icon name="fas.cake-candles" class="h-4 w-4" />
                            {{ $this->birthDate->format('j M Y') }}
                        </span>
                    @endif
                    @if ($person->photo_count > 0)
                        <span class="flex items-center gap-1">
                            <x-icon name="fas.images" class="h-4 w-4" />
                            {{ $person->photo_count }} {{ Str::plural('photo', $person->photo_count) }}
                        </span>
                    @endif
                </div>
                <div class="flex flex-wrap justify-center gap-4 text-sm text-base-content/60 sm:justify-start">
                    <span>{{ $this->eventCount }} {{ Str::plural('event', $this->eventCount) }}</span>
                    <span>{{ $this->connections->flatten(1)->count() }} {{ Str::plural('connection', $this->connections->flatten(1)->count()) }}</span>
                </div>
            </div>
        </div>
    </x-card>

    <x-card class="bg-base-200/50 border-2 border-accent/10">
        <h3 class="mb-4 flex items-center gap-2 text-lg font-semibold">
            <x-icon name="fas.right-left" class="h-5 w-5 text-accent" />
            Connections
        </h3>

        @forelse ($this->connections as $type => $connections)
            <div class="mb-4 last:mb-0" wire:key="connections-{{ $type }}">
                <div class="mb-2 flex items-center gap-2 text-sm font-medium text-base-content/70">
                    <x-icon name="{{ \App\Services\RelationshipTypeRegistry::getIcon($type) ?? 'fas.link' }}" class="h-4 w-4 text-accent" />
                    {{ \App\Services\RelationshipTypeRegistry::getDisplayName($type) ?? Str::headline($type) }}
                    <span class="badge badge-ghost badge-sm">{{ $connections->count() }}</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($connections as $connection)
                        <span wire:key="connection-{{ $connection['relationship']->id }}">
                            @if ($connection['related'] instanceof \App\Models\Event)
                                <x-event-ref :event="$connection['related']" :showService="true" />
                            @elseif ($connection['related'] instanceof \App\Models\EventObject)
                                <x-object-ref :object="$connection['related']" :showType="true" />
                            @else
                                <x-block-ref :block="$connection['related']" :showType="true" />
                            @endif
                        </span>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="text-sm text-base-content/60">No connections yet.</p>
        @endforelse
    </x-card>

    <x-card class="bg-base-200/50 border-2 border-primary/10">
        <h3 class="mb-4 flex items-center gap-2 text-lg font-semibold">
            <x-icon name="fas.bolt" class="h-5 w-5 text-primary" />
            Events
            @if ($this->eventCount > $this->events->count())
                <span class="text-sm font-normal text-base-content/60">latest {{ $this->events->count() }} of {{ $this->eventCount }}</span>
            @endif
        </h3>

        <div class="flex flex-col gap-3">
            @forelse ($this->events as $event)
                <div class="flex flex-wrap items-center gap-2 rounded-lg border border-base-300/50 bg-base-100 p-3" wire:key="event-{{ $event->id }}">
                    @if ($event->actor)
                        <x-object-ref :object="$event->actor" />
                    @endif
                    <x-event-ref :event="$event" :showService="false" />
                    @if ($event->target)
                        <x-object-ref :object="$event->target" />
                    @endif
                    <span class="ml-auto text-xs text-base-content/60">
                        <x-uk-date :date="$event->time" />
                    </span>
                </div>
            @empty
                <p class="text-sm text-base-content/60">No events yet.</p>
            @endforelse
        </div>
    </x-card>
</div>
