<div>
    <x-header :title="'Day — ' . $this->dateLabel" separator>
        <x-slot:actions>
            <div class="flex items-center gap-2 sm:gap-3 w-full">
                <div class="join">
                    <x-button class="join-item btn-ghost btn-sm" wire:click="previousDay">
                        <x-icon name="fas.chevron-left" class="w-4 h-4" />
                    </x-button>
                    <label class="join-item">
                        <input
                            type="date"
                            class="input input-sm"
                            wire:model.live.debounce.0ms="date"
                            @change="$wire.call('navigateToDate')" />
                    </label>
                    <x-button class="join-item btn-ghost btn-sm" wire:click="nextDay">
                        <x-icon name="fas.chevron-right" class="w-4 h-4" />
                    </x-button>
                </div>

                <div class="flex-1 min-w-0" wire:ignore.self>
                    <x-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search events..."
                        class="w-full" />
                </div>

                <div class="hidden sm:flex items-center gap-2">
                    <!-- Expand/Collapse all (icon-only) -->
                    <x-button
                        class="btn-ghost btn-sm"
                        wire:click="toggleAllGroups"
                        aria-label="{{ $this->areAllGroupsExpanded ? 'Collapse all' : 'Expand all' }}">
                        <x-icon name="{{ $this->areAllGroupsExpanded ? 'o-arrows-pointing-in' : 'o-arrows-pointing-out' }}" class="w-4 h-4" />
                    </x-button>
                    <!-- Polling mode toggle: keep-alive vs visible -->
                    <x-button
                        class="btn-ghost btn-sm"
                        wire:click="togglePollMode"
                        aria-label="{{ $this->pollMode === 'keep' ? 'Switch to visible polling' : 'Switch to keep-alive polling' }}"
                        title="{{ $this->pollMode === 'keep' ? 'Polling: keep-alive' : 'Polling: visible' }}">
                        <x-icon name="{{ $this->pollMode === 'keep' ? 'fas.bolt' : 'fas.eye' }}" class="w-4 h-4" />
                    </x-button>
                </div>
            </div>
        </x-slot:actions>
    </x-header>

    <!-- Day Note editor -->
    <div class="mb-2" x-data="{ dayNoteOpenState: @entangle('dayNoteOpen').live }">
        <x-collapse x-model="dayNoteOpenState" separator class="bg-base-200">
            <x-slot:heading>
                <div class="flex items-center gap-2">
                    <x-icon name="fas.calendar" />
                    <span>
                        {{ \Carbon\Carbon::parse($this->date)->format('j F Y') }}
                        @if ($this->dayNoteSaving)
                        - <span class="text-sm text-info">Saving…</span>
                        @elseif ($this->dayNoteSavedAt)
                        - <span class="text-sm text-success">Saved</span>
                        @endif
                    </span>
                    <!-- Check-in status indicator -->
                    <span class="ml-auto">
                        @if (!$checkinStatusLoaded)
                        <div class="skeleton h-6 w-6 rounded-full"></div>
                        @else
                        @if ($this->checkinStatus === 'green')
                        <div class="badge badge-success badge-sm gap-1">
                            <x-icon name="fas.circle-check" class="w-3 h-3" />
                        </div>
                        @elseif ($this->checkinStatus === 'amber')
                        <div class="badge badge-warning badge-sm gap-1">
                            <x-icon name="fas.clock" class="w-3 h-3" />
                        </div>
                        @else
                        <div class="badge badge-error badge-sm gap-1">
                            <x-icon name="o-exclamation-circle" class="w-3 h-3" />
                        </div>
                        @endif
                        @endif
                    </span>
                </div>
            </x-slot:heading>
            <x-slot:content>
                <!-- Daily Check-in -->
                <div>
                    <livewire:daily-checkin :date="$this->date" :key="'checkin-' . $this->date" />
                </div>

                <!-- Divider -->
                <div class="divider my-3"></div>

                <!-- Day Note -->
                @if (!$dayNoteLoaded)
                <div class="space-y-3">
                    <div class="skeleton h-24 w-full"></div>
                </div>
                @elseif ($this->dayNoteDocId)
                <x-card title="" subtitle="" class="pt-0 pl-0 pr-0 pb-0 bg-base-200 shadow">
                    <div class="space-y-3">
                        <x-markdown wire:model.live.debounce.800ms="dayNoteText" label="" :config="['maxHeight' => '200px', 'status' => 'false', 'sideBySideFullscreen' => 'false']" />
                    </div>
                </x-card>
                @else
                <x-alert title="No Day Note found for this date" icon="fas.book-open" />
                @endif
            </x-slot:content>
        </x-collapse>
    </div>

    <div class="space-y-6">
        @if (!$coreEventsLoaded)
        <!-- Skeleton loader while core events are loading -->
        <div class="bg-base-100 rounded-lg p-2 sm:p-4">
            <div class="space-y-4">
                @for ($i = 0; $i < 5; $i++)
                <div class="grid grid-cols-[1.25rem_1fr_auto] gap-3">
                    <div class="relative">
                        <div class="absolute left-2 top-0 bottom-0 w-px bg-base-300"></div>
                        <div class="absolute left-2 top-1/2 -translate-x-1/2 -translate-y-1/2">
                            <div class="skeleton w-7 h-7 rounded-full"></div>
                        </div>
                    </div>
                    <div class="py-2 px-2 space-y-2">
                        <div class="skeleton h-6 w-48"></div>
                        <div class="skeleton h-4 w-32"></div>
                    </div>
                    <div class="py-2 pr-2">
                        <div class="skeleton h-6 w-16"></div>
                    </div>
                </div>
                @endfor
            </div>
        </div>
        @elseif ($this->events->isEmpty())
        <x-card class="bg-base-200 shadow">
            <div class="text-center py-8">
                <x-icon name="fas.calendar" class="w-12 h-12 text-base-content mx-auto mb-4" />
                <h3 class="text-lg font-semibold text-base-content mb-2">No events found for this date</h3>
            </div>
        </x-card>
        @else
        <!-- Custom Vertical Timeline View -->
        @if ($this->pollMode === 'keep')
        <div class="bg-base-100 rounded-lg p-2 sm:p-4" wire:poll.90s.keep-alive>
            @else
            <div class="bg-base-100 rounded-lg p-2 sm:p-4" wire:poll.90s.visible>
                @endif
                @php $previousHour = null; @endphp

                @foreach (($this->groupedEvents ?? []) as $eventGroup)
                @php
                $first = $eventGroup['events'][0];
                $userTime = to_user_timezone($first->time, auth()->user());
                $hour = $userTime->format('H');
                $showHourMarker = $previousHour !== $hour;
                $previousHour = $hour;
                $isCollapsed = ($this->collapsedGroups[$eventGroup['key']] ?? $this->initialCollapsedGroups[$eventGroup['key']] ?? false);
                @endphp

                <!-- Hour marker inside spine -->
                @if ($showHourMarker)
                <div class="grid grid-cols-[1.25rem_1fr_auto] gap-3 items-center py-1 select-none">
                    <div class="relative h-8">
                        <div class="absolute left-2 top-0 bottom-0 w-px bg-base-300"></div>
                        <div class="absolute left-2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-5 h-5 rounded-full bg-base-100 ring-2 ring-base-300 flex items-center justify-center text-[10px] text-base-content/70">{{ $hour }}</div>
                    </div>
                    <div></div>
                    <div></div>
                </div>
                @endif

                <!-- Group header -->
                <div class="grid grid-cols-[1.25rem_1fr_auto] gap-3 {{ $isCollapsed ? 'py-1' : '' }}">
                    <div class="relative">
                        <div class="absolute left-2 top-0 bottom-0 w-px bg-base-300"></div>
                        <button class="absolute left-2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-7 h-7 rounded-full bg-base-100 ring-2 ring-base-300 flex items-center justify-center hover:bg-base-200"
                            wire:click="toggleGroup(@js($eventGroup['key']))"
                            aria-expanded="{{ $isCollapsed ? 'false' : 'true' }}"
                            aria-label="Toggle group">
                            <x-icon name="{{ $this->getEventIcon($eventGroup['action'], $eventGroup['service']) }}" class="w-4 h-4 {{ $this->getAccentColorForService($eventGroup['service']) }}" />
                        </button>
                    </div>
                    <div>
                        <div class="min-w-0">
                            @if ($isCollapsed)
                            @php $firstEvent = $eventGroup['events'][0]; @endphp
                            <div class="py-2 px-2">
                                <div class="text-xl">
                                    <span class="font-semibold">{{ $this->formatAction($firstEvent->action) }}</span>
                                    @if (should_display_action_with_object($firstEvent->action, $firstEvent->service))
                                        @if ($eventRelationshipsLoaded && $firstEvent->target)
                                            <x-object-ref :object="$firstEvent->target" variant="text" :href="route('events.show', $firstEvent)" />
                                        @elseif ($eventRelationshipsLoaded && $firstEvent->actor)
                                            <x-object-ref :object="$firstEvent->actor" variant="text" :href="route('events.show', $firstEvent)" />
                                        @endif
                                    @endif
                                    @if ($eventGroup['count'] > 1)
                                        <span class="text-base-content/70">{{ ' + ' . ($eventGroup['count'] - 1) . ' other' . ($eventGroup['count'] > 2 ? 's' : '') }}</span>
                                    @endif
                                </div>
                                <div class="mt-1 text-sm text-base-content/70 flex items-center flex-wrap gap-1">
                                    {{ to_user_timezone($firstEvent->time, auth()->user())->format(' H:i') }} ·
                                    <span title="{{ to_user_timezone($firstEvent->time, auth()->user())->toDayDateTimeString() }}">{{ to_user_timezone($firstEvent->time, auth()->user())->diffForHumans() }}</span>
                                    @if ($eventRelationshipsLoaded)
                                    <span class="hidden sm:inline">·</span>
                                    <span class="sm:hidden w-full"></span>
                                    @if ($firstEvent->integration)
                                    <x-integration-ref :integration="$firstEvent->integration" :showStatus="false" />
                                    @endif
                                    @if ($firstEvent->tags && count($firstEvent->tags) > 0)
                                    <span class="hidden sm:inline">·</span>
                                    <span class="sm:hidden w-full"></span>
                                    @endif
                                    @foreach ($firstEvent->tags ?? [] as $tag)<x-tag-ref :tag="$tag" size="md" fill />@endforeach
                                    @endif
                                </div>
                            </div>
                            @else
                            @php $firstEvent = $eventGroup['events'][0]; @endphp
                            <div class="py-2 px-2">
                                <div class="text-xl">
                                    <a href="{{ route('events.show', $firstEvent->id) }}" wire:navigate class="hover:text-primary transition-colors font-semibold">{{ $this->formatAction($firstEvent->action) }}</a>
                                    @if (should_display_action_with_object($firstEvent->action, $firstEvent->service))
                                    @if ($eventRelationshipsLoaded && $firstEvent->target)
                                    <x-object-ref :object="$firstEvent->target" variant="text" :href="route('events.show', $firstEvent)" />
                                    @elseif ($eventRelationshipsLoaded && $firstEvent->actor)
                                    <x-object-ref :object="$firstEvent->actor" variant="text" :href="route('events.show', $firstEvent)" />
                                    @endif
                                    @endif
                                </div>
                                <div class="mt-1 text-sm text-base-content/70 flex items-center flex-wrap gap-1">
                                    {{ to_user_timezone($firstEvent->time, auth()->user())->format(' H:i') }} ·
                                    <span title="{{ to_user_timezone($firstEvent->time, auth()->user())->toDayDateTimeString() }}">{{ to_user_timezone($firstEvent->time, auth()->user())->diffForHumans() }}</span>
                                    @if ($eventRelationshipsLoaded)
                                    <span class="hidden sm:inline">·</span>
                                    <span class="sm:hidden w-full"></span>
                                    @if ($firstEvent->integration)
                                    <x-integration-ref :integration="$firstEvent->integration" :showStatus="false" />
                                    @endif
                                    @if ($firstEvent->tags && count($firstEvent->tags) > 0)
                                    <span class="hidden sm:inline">·</span>
                                    <span class="sm:hidden w-full"></span>
                                    @endif
                                    @foreach ($firstEvent->tags ?? [] as $tag)<x-tag-ref :tag="$tag" size="md" fill />@endforeach
                                    @if ($firstEvent->blocks && count($firstEvent->blocks) > 0)
                                    <span class="hidden sm:inline">·</span>
                                    <span class="sm:hidden w-full"></span>
                                    @foreach ($firstEvent->blocks->take(3) as $block)<x-block-ref :block="$block" :showType="false" />@endforeach
                                    @if (count($firstEvent->blocks) > 3)
                                    <span class="badge badge-ghost badge-sm">+{{ count($firstEvent->blocks) - 3 }}</span>
                                    @endif
                                    @endif
                                    @endif
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>
                    <div class="py-2 pr-2 text-right">
                        @php $displayEvent = $eventGroup['events'][0]; @endphp
                        @if (! is_null($displayEvent->value))
                        <span class="text-lg font-bold {{ $this->valueColorClass($displayEvent) }}">{!! $this->formatValueDisplay($displayEvent) !!}</span>
                        @endif
                    </div>
                </div>

                @if (! $isCollapsed)
                @php $eventsToShow = array_slice($eventGroup['events'], 1); @endphp
                @foreach ($eventsToShow as $event)
                <div class="grid grid-cols-[1.25rem_1fr_auto] gap-3">
                    <div class="relative">
                        <div class="absolute left-2 top-0 bottom-0 w-px bg-base-300"></div>
                    </div>
                    <div class="py-2 px-2">
                        <div class="text-lg">
                            <a href="{{ route('events.show', $event->id) }}" wire:navigate class="text-base-content hover:text-primary transition-colors font-medium">{{ $this->formatAction($event->action) }}</a>
                            @if (should_display_action_with_object($event->action, $event->service))
                            @if ($eventRelationshipsLoaded && $event->target)
                            <x-object-ref :object="$event->target" variant="text" :href="route('events.show', $event)" />
                            @elseif ($eventRelationshipsLoaded && $event->actor)
                            <x-object-ref :object="$event->actor" variant="text" :href="route('events.show', $event)" />
                            @endif
                            @endif
                        </div>
                        <div class="mt-1 text-sm text-base-content/70 flex items-center flex-wrap gap-1">
                            <span title="{{ to_user_timezone($event->time, auth()->user())->toDayDateTimeString() }}">{{ to_user_timezone($event->time, auth()->user())->diffForHumans() }}</span>
                            @if ($eventRelationshipsLoaded)
                            <span class="hidden sm:inline">·</span>
                            <span class="sm:hidden w-full"></span>
                            @if ($event->integration)
                            <x-integration-ref :integration="$event->integration" :showStatus="false" />
                            @endif
                            @if ($event->tags && count($event->tags) > 0)
                            <span class="hidden sm:inline">·</span>
                            <span class="sm:hidden w-full"></span>
                            @endif
                            @foreach ($event->tags ?? [] as $tag)<x-tag-ref :tag="$tag" size="sm" />@endforeach
                            @if ($event->blocks && count($event->blocks) > 0)
                            <span class="hidden sm:inline">·</span>
                            <span class="sm:hidden w-full"></span>
                            @foreach ($event->blocks->take(2) as $block)<x-block-ref :block="$block" :showType="false" />@endforeach
                            @if (count($event->blocks) > 2)
                            <span class="badge badge-ghost badge-xs">+{{ count($event->blocks) - 2 }}</span>
                            @endif
                            @endif
                            @endif
                        </div>
                    </div>
                    <div class="py-2 pr-2 text-right">
                        @if (! is_null($event->value))
                        <span class="text-lg font-semibold {{ $this->valueColorClass($event) }}">{!! $this->formatValueDisplay($event) !!}</span>
                        @endif
                    </div>
                </div>
                @endforeach
                @endif

                @endforeach
            </div>
            @endif
        </div>

    <!-- Floating Action Button for Card Streams -->
    @if ($cardStreamsLoaded)
    @php
        try {
            $availableStreamsCount = $this->availableStreams->count();
            $hasStreams = $availableStreamsCount > 0;
        } catch (\Throwable $e) {
            $hasStreams = false;
            $availableStreamsCount = 0;
        }
    @endphp

    <script>
        console.log('=== FAB Debug Info ===');
        console.log('Current time:', new Date().toLocaleTimeString());
        console.log('Viewing date:', @js($this->date));
        console.log('Is viewing today?:', @js(\Carbon\Carbon::parse($this->date)->isToday()));
        console.log('Has streams:', @js($hasStreams));
        console.log('Available streams count:', @js($availableStreamsCount));
        console.log('Available streams:', @js($this->availableStreams->toArray()));

        @if (!$hasStreams)
        console.log('⚠️ No streams detected! This could mean:');
        console.log('  1. No cards are eligible based on time/date constraints');
        console.log('  2. Card eligibility logic is filtering out all cards');
        console.log('  3. CardRegistry is not returning any eligible cards');
        @endif
    </script>

    @if ($hasStreams)
    <div class="fixed bottom-6 right-6 z-40"
        x-data="{
            userId: '{{ auth()->id() }}',
            currentDate: '{{ $this->date }}',
            streams: @js($this->availableStreams->values()->toArray()),
            shouldShow: true,

            getStorageKey() {
                return `spark_card_views_${this.userId}_${this.currentDate}`;
            },

            getCardState() {
                if (!this.userId) return {};

                try {
                    const stored = localStorage.getItem(this.getStorageKey());
                    return stored ? JSON.parse(stored) : {};
                } catch (e) {
                    return {};
                }
            },

            hasUnviewedCards(stream) {
                const state = this.getCardState();
                const streamState = state[stream.id] || {};
                const eligibleCards = stream.eligibleCardsMeta || [];

                console.log('=== Checking Stream:', stream.id, '===');
                console.log('Stream state:', streamState);
                console.log('Eligible cards:', eligibleCards);

                // Check each eligible card
                for (const card of eligibleCards) {
                    const cardState = streamState[card.id];

                    console.log(`Card ${card.id}:`, {
                        cardState,
                        viewed: cardState?.viewed,
                        requiresInteraction: card.requiresInteraction,
                        interacted: cardState?.interacted
                    });

                    // Card not viewed yet
                    if (!cardState || !cardState.viewed) {
                        console.log(`✓ Card ${card.id} is unviewed - showing FAB`);
                        return true;
                    }

                    // Card requires interaction but not completed
                    if (card.requiresInteraction && !cardState.interacted) {
                        console.log(`✓ Card ${card.id} requires interaction - showing FAB`);
                        return true;
                    }
                }

                console.log('✗ No unviewed cards in stream', stream.id);
                return false;
            },

            hasAnyUnviewedCards() {
                console.log('=== FAB Visibility Check ===');
                console.log('All streams:', this.streams);
                console.log('User ID:', this.userId);
                console.log('Current Date:', this.currentDate);
                console.log('Storage Key:', this.getStorageKey());

                const result = this.streams.some(stream => this.hasUnviewedCards(stream));
                console.log('=== FAB Should Show:', result, '===');
                return result;
            },

            checkVisibility() {
                this.shouldShow = this.hasAnyUnviewedCards();
            }
        }"
        x-init="checkVisibility()"
        x-show="shouldShow"
        x-transition
        @card-stream-closed.window="checkVisibility()">
        @if ($availableStreamsCount === 1)
        <!-- Single stream: simple FAB -->
        @php $stream = $this->availableStreams->first(); @endphp
        <button
            class="btn btn-circle btn-lg btn-primary shadow-lg"
            @click="console.log('FAB clicked!', { streamId: '{{ $stream->id }}', date: '{{ $this->date }}' }); $dispatch('open-card-stream', { streamId: '{{ $stream->id }}', date: '{{ $this->date }}' }); console.log('Event dispatched');"
            aria-label="Open {{ $stream->name }} stream">
            <x-icon name="{{ $stream->icon }}" class="w-6 h-6" />
        </button>
        @else
        <!-- Multiple streams: flower FAB -->
        <div class="dropdown dropdown-top dropdown-end">
            <div tabindex="0" role="button" class="btn btn-circle btn-lg btn-primary shadow-lg m-1">
                <x-icon name="fas.layer-group" class="w-6 h-6" />
            </div>
            <ul tabindex="0" class="dropdown-content menu bg-base-200 rounded-box z-[1] w-52 p-2 shadow-xl mb-2">
                @foreach ($this->availableStreams as $stream)
                <li>
                    <button
                        @click="$dispatch('open-card-stream', { streamId: '{{ $stream->id }}', date: '{{ $this->date }}' })"
                        class="flex items-center gap-2">
                        <x-icon name="{{ $stream->icon }}" class="w-5 h-5" />
                        <span>{{ $stream->name }}</span>
                        @if ($stream->description)
                        <span class="text-xs text-base-content/60">{{ $stream->description }}</span>
                        @endif
                    </button>
                </li>
                @endforeach
            </ul>
        </div>
        @endif
    </div>
    @endif
    @endif
</div>
