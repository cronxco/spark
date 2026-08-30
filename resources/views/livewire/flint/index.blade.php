<?php

use App\Models\Event;
use App\Models\EventObject;
use App\Services\AgentWorkingMemoryService;
use App\Services\FlintTopicService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public string $activeTab = 'today';

    // --- Settings (mirrors the keys the flint-digest-dispatcher actually reads) ---
    public bool $digestsEnabled = true;
    public string $morningTimeWeekday = '07:30';
    public string $morningTimeWeekend = '09:30';
    public string $morningFallback = '11:00';
    public string $eveningTime = '19:30';
    public string $topicsTime = '21:00';
    public string $readingListTime = '20:00';
    public string $newsRoundupTime = '07:00';

    // --- Today ---
    public ?string $selectedDigestId = null;

    // --- Topics ---
    public string $topicKindFilter = '';
    public string $topicStatusFilter = 'active';
    public ?string $expandedTopicId = null;
    public ?string $editingTopicId = null;
    public string $editTitle = '';
    public string $editContent = '';
    public string $editKind = 'thematic';
    public string $editStatus = 'active';
    public ?string $editNextReviewAt = null;

    public array $timeOptions = [];

    public array $topicKinds = [
        'strategic' => 'Strategic — a long horizon outcome',
        'thematic' => 'Thematic — an ongoing thread with no end date',
        'tactical' => 'Tactical — time-bound, expires when it resolves',
    ];

    public array $topicStatuses = ['active', 'dormant', 'resolved', 'expired'];

    public function mount(): void
    {
        $settings = Auth::user()->settings['flint'] ?? [];

        $this->digestsEnabled = $settings['digests_enabled'] ?? true;
        $this->morningTimeWeekday = $settings['morning_time_weekday'] ?? config('services.flint_routine.morning_time_weekday');
        $this->morningTimeWeekend = $settings['morning_time_weekend'] ?? config('services.flint_routine.morning_time_weekend');
        $this->morningFallback = $settings['morning_fallback'] ?? config('services.flint_routine.morning_fallback');
        $this->eveningTime = $settings['evening_time'] ?? config('services.flint_routine.evening_time');
        $this->topicsTime = $settings['topics_time'] ?? config('services.flint_routine.topics_time');
        $this->readingListTime = $settings['reading_list_time'] ?? config('services.flint_routine.reading_list_time');
        $this->newsRoundupTime = $settings['news_roundup_time'] ?? config('services.flint_routine.news_roundup_time');

        // Every quarter hour — the dispatcher only wakes up on that cadence, so
        // finer-grained times would be misleading.
        $this->timeOptions = collect(range(0, 23))
            ->flatMap(fn ($hour) => collect([0, 15, 30, 45])->mapWithKeys(function ($minute) use ($hour) {
                $time = sprintf('%02d:%02d', $hour, $minute);

                return [$time => $time];
            }))
            ->toArray();
    }

    // ------------------------------------------------------------------
    // Today
    // ------------------------------------------------------------------

    /**
     * Digest events, newest first. These are written by the Flint routine via
     * the create-flint-digest MCP tool — the same rows the mobile app reads.
     */
    public function digests(int $limit = 30)
    {
        return Event::query()
            ->whereIn('integration_id', Auth::user()->integrations()->pluck('id'))
            ->where('service', 'flint')
            ->where('action', 'had_summary')
            ->orderByDesc('time')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /** The digest currently on screen: the one picked from the list, else the newest. */
    public function currentDigest(): ?Event
    {
        $digests = $this->digests();

        $digest = $this->selectedDigestId
            ? $digests->firstWhere('id', $this->selectedDigestId)
            : $digests->first();

        return $digest?->loadMissing('blocks');
    }

    public function selectDigest(string $eventId): void
    {
        $this->selectedDigestId = $eventId;
    }

    /**
     * The digest's blocks, split so the view can lead with anything awaiting an
     * answer rather than burying it under commentary.
     *
     * @return array{questions: \Illuminate\Support\Collection, notes: \Illuminate\Support\Collection}
     */
    public function digestBlocks(?Event $digest): array
    {
        $blocks = $digest?->blocks ?? collect();

        return [
            'questions' => $blocks->where('block_type', 'flint_user_question')
                ->sortBy(fn ($block) => is_null($block->metadata['answer'] ?? null) ? 0 : 1)
                ->values(),
            'notes' => $blocks->whereNotIn('block_type', ['flint_user_question'])
                ->sortBy('time')
                ->values(),
        ];
    }

    /** Topics this digest touched, via the `discussed_in` relationship. */
    public function topicsTouchedBy(?Event $digest)
    {
        if (! $digest) {
            return collect();
        }

        return $digest->relatedObjects('discussed_in')
            ->where('concept', 'flint')
            ->where('type', 'topic')
            ->get();
    }

    // ------------------------------------------------------------------
    // Topics
    // ------------------------------------------------------------------

    public function topics()
    {
        return app(FlintTopicService::class)
            ->query(Auth::user(), $this->topicStatusFilter ?: null, $this->topicKindFilter ?: null)
            ->orderByDesc('updated_at')
            ->get();
    }

    public function topicStatusCounts(): array
    {
        return app(FlintTopicService::class)->statusCounts(Auth::user());
    }

    public function topicMentions(EventObject $topic)
    {
        return app(FlintTopicService::class)->mentions($topic);
    }

    public function expandTopic(string $topicId): void
    {
        $this->expandedTopicId = $this->expandedTopicId === $topicId ? null : $topicId;
    }

    public function editTopic(string $topicId): void
    {
        $topic = app(FlintTopicService::class)->query(Auth::user())->find($topicId);

        if (! $topic) {
            $this->error('Topic not found.');

            return;
        }

        $this->editingTopicId = $topic->id;
        $this->editTitle = $topic->title ?? '';
        $this->editContent = $topic->content ?? '';
        $this->editKind = $topic->metadata['kind'] ?? 'thematic';
        $this->editStatus = $topic->metadata['status'] ?? 'active';
        $this->editNextReviewAt = $topic->metadata['next_review_at'] ?? null;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingTopicId', 'editTitle', 'editContent', 'editKind', 'editStatus', 'editNextReviewAt']);
    }

    public function saveTopic(): void
    {
        if (! $this->editingTopicId) {
            return;
        }

        try {
            $updated = app(FlintTopicService::class)->update(Auth::user(), $this->editingTopicId, [
                'title' => $this->editTitle,
                'content' => $this->editContent,
                'kind' => $this->editKind,
                'status' => $this->editStatus,
                'next_review_at' => $this->editNextReviewAt ?: null,
            ]);
        } catch (ValidationException $exception) {
            $this->error($exception->validator->errors()->first());

            return;
        }

        if (! $updated) {
            $this->error('Topic not found.');

            return;
        }

        $this->cancelEdit();
        $this->success('Topic updated.');
    }

    /** Quick status change from the card, without opening the editor. */
    public function setTopicStatus(string $topicId, string $status): void
    {
        try {
            $updated = app(FlintTopicService::class)->update(Auth::user(), $topicId, ['status' => $status]);
        } catch (ValidationException $exception) {
            $this->error($exception->validator->errors()->first());

            return;
        }

        $updated
            ? $this->success("Topic marked {$status}.")
            : $this->error('Topic not found.');
    }

    public function deleteTopic(string $topicId): void
    {
        if (! app(FlintTopicService::class)->delete(Auth::user(), $topicId)) {
            $this->error('Topic not found.');

            return;
        }

        if ($this->expandedTopicId === $topicId) {
            $this->expandedTopicId = null;
        }

        $this->cancelEdit();
        $this->success('Topic deleted.');
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    public function feedbackStats(): array
    {
        return app(AgentWorkingMemoryService::class)->getFeedbackStatistics(Auth::id());
    }

    public function save(): void
    {
        $this->validate([
            'morningTimeWeekday' => ['required', 'date_format:H:i'],
            'morningTimeWeekend' => ['required', 'date_format:H:i'],
            'morningFallback' => ['required', 'date_format:H:i'],
            'eveningTime' => ['required', 'date_format:H:i'],
            'topicsTime' => ['required', 'date_format:H:i'],
            'readingListTime' => ['required', 'date_format:H:i'],
            'newsRoundupTime' => ['required', 'date_format:H:i'],
        ]);

        $user = Auth::user();
        $settings = $user->settings;

        // Merge rather than replace: the scheduler and the routine both read out
        // of this bag, and a blind overwrite would drop keys set elsewhere.
        $settings['flint'] = array_merge($settings['flint'] ?? [], [
            'digests_enabled' => $this->digestsEnabled,
            'morning_time_weekday' => $this->morningTimeWeekday,
            'morning_time_weekend' => $this->morningTimeWeekend,
            'morning_fallback' => $this->morningFallback,
            'evening_time' => $this->eveningTime,
            'topics_time' => $this->topicsTime,
            'reading_list_time' => $this->readingListTime,
            'news_roundup_time' => $this->newsRoundupTime,
        ]);

        $user->settings = $settings;
        $user->save();

        $this->success('Flint settings saved.');
    }
}; ?>

<div>
    <x-header title="{{ __('Flint') }}" subtitle="{{ __('Your personal AI assistant') }}" separator />

    <x-tabs wire:model="activeTab">
        {{-- ============================== Today ============================== --}}
        <x-tab name="today" label="Today" icon="fas.newspaper">
            @php
                $digests = $this->digests();
                $digest = $this->currentDigest();
                $grouped = $this->digestBlocks($digest);
                $touchedTopics = $this->topicsTouchedBy($digest);
            @endphp

            @if (! $digest)
                <div class="card bg-base-200 shadow">
                    <div class="card-body text-center py-12">
                        <x-icon name="fas.newspaper" class="w-16 h-16 mx-auto text-base-content/30 mb-4" />
                        <h3 class="text-lg font-semibold mb-2">{{ __('No digests yet') }}</h3>
                        <p class="text-sm text-base-content/60">
                            {{ __('Flint writes a digest at each scheduled slot. The next one will appear here.') }}
                        </p>
                    </div>
                </div>
            @else
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 space-y-6">
                        {{-- The digest itself: prose first, because that is the digest --}}
                        <div class="card bg-base-200 shadow">
                            <div class="card-body p-5 lg:p-6 gap-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="badge badge-primary badge-sm">
                                        {{ ucfirst($digest->event_metadata['period'] ?? 'digest') }}
                                    </span>
                                    <span class="text-xs text-base-content/60">
                                        <x-user-time :time="$digest->time" format="l j F Y" />
                                    </span>
                                    @if ($grouped['questions']->whereNull('metadata.answer')->isNotEmpty())
                                        <span class="badge badge-warning badge-sm">
                                            {{ $grouped['questions']->whereNull('metadata.answer')->count() }} {{ __('awaiting you') }}
                                        </span>
                                    @endif
                                </div>

                                <h2 class="text-2xl font-serif font-bold leading-tight">
                                    {{ $digest->event_metadata['title'] ?? __('Daily digest') }}
                                </h2>

                                @if ($summary = $digest->event_metadata['summary'] ?? null)
                                    <div class="prose prose-sm lg:prose-base max-w-none text-base-content/90">
                                        {!! Str::markdown($summary) !!}
                                    </div>
                                @endif

                                <div class="pt-2 border-t border-base-300">
                                    <a href="{{ route('events.show', $digest->id) }}" class="btn btn-ghost btn-xs">
                                        <x-icon name="fas.arrow-up-right-from-square" class="w-3 h-3" />
                                        {{ __('Open full event') }}
                                    </a>
                                </div>
                            </div>
                        </div>

                        {{-- Questions Flint asked, unanswered first --}}
                        @if ($grouped['questions']->isNotEmpty())
                            <div class="space-y-3">
                                <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/60">
                                    {{ __('Flint asked') }}
                                </h3>
                                @foreach ($grouped['questions'] as $block)
                                    <div wire:key="question-{{ $block->id }}">
                                        <x-block-card :block="$block" />
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Insights and editorial notes --}}
                        @if ($grouped['notes']->isNotEmpty())
                            <div class="space-y-3">
                                <h3 class="text-sm font-semibold uppercase tracking-wider text-base-content/60">
                                    {{ __('In this digest') }}
                                </h3>
                                <div class="grid grid-cols-1 xl:grid-cols-2 gap-3">
                                    @foreach ($grouped['notes'] as $block)
                                        <div wire:key="block-{{ $block->id }}">
                                            <x-block-card :block="$block" />
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Sidebar --}}
                    <div class="space-y-6">
                        {{-- Topics this digest touched --}}
                        <div class="card bg-base-200 shadow">
                            <div class="card-body p-4 gap-3">
                                <div class="flex items-center gap-2">
                                    <x-icon name="fas.compass" class="w-4 h-4 text-primary" />
                                    <span class="text-xs font-semibold uppercase tracking-wider">{{ __('Topics touched') }}</span>
                                </div>

                                @forelse ($touchedTopics as $topic)
                                    <a
                                        href="{{ route('objects.show', $topic->id) }}"
                                        class="block p-2 rounded-lg bg-base-100 hover:bg-base-300 transition-colors"
                                        wire:key="touched-{{ $topic->id }}"
                                    >
                                        <div class="text-sm font-medium">{{ $topic->title }}</div>
                                        <div class="text-xs text-base-content/60">
                                            {{ ucfirst($topic->metadata['kind'] ?? 'thematic') }}
                                            &middot; {{ ucfirst($topic->metadata['status'] ?? 'active') }}
                                        </div>
                                    </a>
                                @empty
                                    <p class="text-xs text-base-content/60">
                                        {{ __('This digest is not linked to any topic yet.') }}
                                    </p>
                                @endforelse

                                <button wire:click="$set('activeTab', 'topics')" class="btn btn-ghost btn-xs justify-start">
                                    {{ __('Browse all topics') }}
                                    <x-icon name="fas.arrow-right" class="w-3 h-3" />
                                </button>
                            </div>
                        </div>

                        {{-- Recent digests --}}
                        <div class="card bg-base-200 shadow">
                            <div class="card-body p-4 gap-2">
                                <div class="flex items-center gap-2 mb-1">
                                    <x-icon name="fas.clock-rotate-left" class="w-4 h-4 text-base-content/60" />
                                    <span class="text-xs font-semibold uppercase tracking-wider">{{ __('Recent digests') }}</span>
                                </div>

                                @foreach ($digests as $item)
                                    <button
                                        wire:click="selectDigest('{{ $item->id }}')"
                                        wire:key="digest-{{ $item->id }}"
                                        class="text-left p-2 rounded-lg transition-colors {{ $item->id === $digest->id ? 'bg-primary/10 ring-1 ring-primary/30' : 'bg-base-100 hover:bg-base-300' }}"
                                    >
                                        <div class="text-sm font-medium line-clamp-1">
                                            {{ $item->event_metadata['title'] ?? __('Daily digest') }}
                                        </div>
                                        <div class="text-xs text-base-content/60">
                                            <x-user-time :time="$item->time" format="j M" />
                                            &middot; {{ ucfirst($item->event_metadata['period'] ?? '') }}
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </x-tab>

        {{-- ============================== Topics ============================== --}}
        <x-tab name="topics" label="Topics" icon="fas.compass">
            @php
                $topics = $this->topics();
                $statusCounts = $this->topicStatusCounts();
            @endphp

            <div class="space-y-4 lg:space-y-6">
                <div class="card bg-base-200 shadow">
                    <div class="card-body gap-4">
                        <div>
                            <h3 class="text-lg font-semibold">{{ __('Topics') }}</h3>
                            <p class="text-sm text-base-content/70">
                                {{ __('The long-lived threads Flint tracks across digests and coaching sessions. Flint proposes and updates these; you have the final say on all of them.') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <select wire:model.live="topicStatusFilter" class="select select-bordered select-sm">
                                <option value="">{{ __('All statuses') }} ({{ array_sum($statusCounts) }})</option>
                                @foreach ($topicStatuses as $status)
                                    <option value="{{ $status }}">
                                        {{ ucfirst($status) }} ({{ $statusCounts[$status] ?? 0 }})
                                    </option>
                                @endforeach
                            </select>

                            <select wire:model.live="topicKindFilter" class="select select-bordered select-sm">
                                <option value="">{{ __('All kinds') }}</option>
                                @foreach ($topicKinds as $kind => $description)
                                    <option value="{{ $kind }}">{{ ucfirst($kind) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                @forelse ($topics as $topic)
                    @php
                        $kind = $topic->metadata['kind'] ?? 'thematic';
                        $status = $topic->metadata['status'] ?? 'active';
                        $statusBadge = match ($status) {
                            'active' => 'badge-success',
                            'dormant' => 'badge-ghost',
                            'resolved' => 'badge-info',
                            default => 'badge-neutral',
                        };
                    @endphp

                    <div class="card bg-base-200 shadow" wire:key="topic-{{ $topic->id }}">
                        <div class="card-body p-4 gap-3">
                            @if ($editingTopicId === $topic->id)
                                {{-- Inline editor --}}
                                <div class="space-y-3">
                                    <x-input label="{{ __('Title') }}" wire:model="editTitle" />
                                    <x-textarea label="{{ __('Summary') }}" wire:model="editContent" rows="6"
                                        hint="{{ __('Markdown. This is the current understanding, not a log.') }}" />

                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                        <div class="form-control">
                                            <label class="label"><span class="label-text">{{ __('Kind') }}</span></label>
                                            <select wire:model="editKind" class="select select-bordered">
                                                @foreach ($topicKinds as $value => $description)
                                                    <option value="{{ $value }}">{{ ucfirst($value) }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="form-control">
                                            <label class="label"><span class="label-text">{{ __('Status') }}</span></label>
                                            <select wire:model="editStatus" class="select select-bordered">
                                                @foreach ($topicStatuses as $value)
                                                    <option value="{{ $value }}">{{ ucfirst($value) }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <x-input type="date" label="{{ __('Review on') }}" wire:model="editNextReviewAt" />
                                    </div>

                                    <div class="flex justify-end gap-2">
                                        <x-button label="{{ __('Cancel') }}" wire:click="cancelEdit" class="btn-ghost btn-sm" />
                                        <x-button label="{{ __('Save') }}" wire:click="saveTopic" class="btn-primary btn-sm" spinner="saveTopic" />
                                    </div>
                                </div>
                            @else
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <a href="{{ route('objects.show', $topic->id) }}" class="font-semibold hover:text-primary">
                                            {{ $topic->title }}
                                        </a>
                                        <div class="flex flex-wrap items-center gap-2 mt-1">
                                            <span class="badge badge-outline badge-sm">{{ ucfirst($kind) }}</span>
                                            <span class="badge {{ $statusBadge }} badge-sm">{{ ucfirst($status) }}</span>
                                            @if ($touched = $topic->metadata['last_touched_at'] ?? null)
                                                <span class="text-xs text-base-content/60">
                                                    {{ __('Touched') }} {{ \Carbon\Carbon::parse($touched)->diffForHumans() }}
                                                </span>
                                            @endif
                                            @if ($review = $topic->metadata['next_review_at'] ?? null)
                                                <span class="text-xs text-base-content/60">
                                                    &middot; {{ __('Review') }} {{ \Carbon\Carbon::parse($review)->format('j M Y') }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-1 flex-shrink-0">
                                        <button wire:click="editTopic('{{ $topic->id }}')" class="btn btn-ghost btn-xs" title="{{ __('Edit') }}">
                                            <x-icon name="fas.pen" class="w-3 h-3" />
                                        </button>
                                        @if ($status !== 'dormant')
                                            <button wire:click="setTopicStatus('{{ $topic->id }}', 'dormant')" class="btn btn-ghost btn-xs" title="{{ __('Mark dormant') }}">
                                                <x-icon name="fas.moon" class="w-3 h-3" />
                                            </button>
                                        @endif
                                        @if ($status !== 'resolved')
                                            <button wire:click="setTopicStatus('{{ $topic->id }}', 'resolved')" class="btn btn-ghost btn-xs" title="{{ __('Mark resolved') }}">
                                                <x-icon name="fas.check" class="w-3 h-3" />
                                            </button>
                                        @endif
                                        <button
                                            wire:click="deleteTopic('{{ $topic->id }}')"
                                            wire:confirm="{{ __('Delete this topic? Its mention history goes with it.') }}"
                                            class="btn btn-ghost btn-xs hover:text-error"
                                            title="{{ __('Delete') }}"
                                        >
                                            <x-icon name="fas.trash" class="w-3 h-3" />
                                        </button>
                                        <button wire:click="expandTopic('{{ $topic->id }}')" class="btn btn-ghost btn-xs">
                                            <x-icon name="fas.chevron-{{ $expandedTopicId === $topic->id ? 'up' : 'down' }}" class="w-3 h-3" />
                                        </button>
                                    </div>
                                </div>

                                @if ($topic->content)
                                    <div class="prose prose-sm max-w-none text-base-content/80 {{ $expandedTopicId === $topic->id ? '' : 'line-clamp-3' }}">
                                        {!! Str::markdown($topic->content) !!}
                                    </div>
                                @endif

                                @if ($expandedTopicId === $topic->id)
                                    @php $mentions = $this->topicMentions($topic); @endphp
                                    <div class="pt-3 border-t border-base-300">
                                        <div class="text-xs font-semibold uppercase tracking-wider text-base-content/60 mb-2">
                                            {{ __('Mentioned in') }}
                                        </div>
                                        @forelse ($mentions as $mention)
                                            <a
                                                href="{{ $mention['kind'] === 'event' ? route('events.show', $mention['id']) : route('blocks.show', $mention['id']) }}"
                                                class="flex items-center justify-between gap-3 py-1.5 text-sm hover:text-primary"
                                                wire:key="mention-{{ $mention['id'] }}"
                                            >
                                                <span class="line-clamp-1">{{ $mention['title'] }}</span>
                                                <span class="text-xs text-base-content/50 flex-shrink-0">
                                                    <x-user-time :time="$mention['time']" format="j M" />
                                                </span>
                                            </a>
                                        @empty
                                            <p class="text-xs text-base-content/60">
                                                {{ __('Nothing has linked to this topic yet.') }}
                                            </p>
                                        @endforelse
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="card bg-base-200 shadow">
                        <div class="card-body text-center py-12">
                            <x-icon name="fas.compass" class="w-16 h-16 mx-auto text-base-content/30 mb-4" />
                            <h3 class="text-lg font-semibold mb-2">{{ __('No topics here') }}</h3>
                            <p class="text-sm text-base-content/60">
                                {{ $topicStatusFilter || $topicKindFilter
                                    ? __('No topics match these filters.')
                                    : __('Flint proposes topics as recurring threads show up in your digests and coaching sessions.') }}
                            </p>
                        </div>
                    </div>
                @endforelse
            </div>
        </x-tab>

        {{-- ============================== Fitness Coach ============================== --}}
        <x-tab name="coach" label="Fitness Coach" icon="fas.dumbbell">
            <div class="space-y-4 lg:space-y-6">
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">{{ __('Hevy Fitness Coach') }}</h3>
                        <p class="text-sm text-base-content/70 mb-4">
                            {{ __('Automatically analyzes your workouts and updates your Hevy routine with progressive overload recommendations.') }}
                        </p>

                        @php
                        $hevyIntegration = \App\Models\Integration::where('user_id', Auth::id())
                            ->where('service', 'hevy')
                            ->where('instance_type', 'workouts')
                            ->first();

                        $coachEnabled = $hevyIntegration && ($hevyIntegration->configuration['coach_enabled'] ?? false);

                        $lastAnalysis = null;
                        $recommendationCount = 0;

                        if ($hevyIntegration) {
                            $lastAnalysis = \App\Models\Event::where('integration_id', $hevyIntegration->id)
                                ->where('action', 'had_coach_recommendation')
                                ->latest('time')
                                ->first();

                            if ($lastAnalysis) {
                                $recommendationCount = \App\Models\Block::whereHas('event', function($q) use ($hevyIntegration) {
                                    $q->where('integration_id', $hevyIntegration->id)
                                      ->where('action', 'had_coach_recommendation');
                                })
                                ->where('block_type', 'coach_recommendation')
                                ->where('created_at', '>=', now()->subDays(7))
                                ->count();
                            }
                        }
                        @endphp

                        @if (!$hevyIntegration)
                        <div class="alert alert-info">
                            <x-icon name="o-information-circle" class="w-5 h-5" />
                            <div>
                                <div class="font-medium">{{ __('No Hevy Integration Found') }}</div>
                                <div class="text-sm">{{ __('Connect your Hevy account to use the fitness coach.') }}</div>
                            </div>
                        </div>
                        @else
                        {{-- Stats --}}
                        <div class="stats stats-vertical lg:stats-horizontal shadow mt-4 mb-4">
                            <div class="stat bg-base-100">
                                <div class="stat-title">{{ __('Last Analysis') }}</div>
                                <div class="stat-value text-sm">
                                    {{ $lastAnalysis ? $lastAnalysis->time->diffForHumans() : __('Never') }}
                                </div>
                            </div>

                            <div class="stat bg-base-100">
                                <div class="stat-title">{{ __('Recommendations') }}</div>
                                <div class="stat-value text-sm">
                                    {{ $recommendationCount }}
                                </div>
                                <div class="stat-desc">{{ __('Last 7 days') }}</div>
                            </div>

                            <div class="stat bg-base-100">
                                <div class="stat-title">{{ __('Coach Status') }}</div>
                                <div class="stat-value text-sm">
                                    @if ($coachEnabled)
                                        <span class="text-success">{{ __('Enabled') }}</span>
                                    @else
                                        <span class="text-base-content/50">{{ __('Disabled') }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Quick Actions --}}
                        <div class="card-actions justify-end">
                            <a href="{{ route('integrations.details', $hevyIntegration) }}" class="btn btn-outline btn-sm">
                                <x-icon name="o-cog-6-tooth" class="w-4 h-4" />
                                {{ __('Configure') }}
                            </a>
                        </div>
                        @endif
                    </div>
                </div>

                {{-- Recent Recommendations --}}
                @if ($hevyIntegration && $recommendationCount > 0)
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">{{ __('Recent Recommendations') }}</h3>
                        <p class="text-sm text-base-content/70 mb-4">
                            {{ __('Latest progression recommendations from your fitness coach') }}
                        </p>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            @php
                            $recentBlocks = \App\Models\Block::whereHas('event', function($q) use ($hevyIntegration) {
                                $q->where('integration_id', $hevyIntegration->id)
                                  ->where('action', 'had_coach_recommendation');
                            })
                            ->where('block_type', 'coach_recommendation')
                            ->where('created_at', '>=', now()->subDays(7))
                            ->latest()
                            ->limit(6)
                            ->get();
                            @endphp

                            @foreach ($recentBlocks as $block)
                                <x-block-card :block="$block" />
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </x-tab>

        {{-- ============================== Settings ============================== --}}
        <x-tab name="settings" label="Settings" icon="o-cog-6-tooth">
            @php $feedback = $this->feedbackStats(); @endphp

            <div class="space-y-4 lg:space-y-6">
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">{{ __('Digests') }}</h3>
                        <p class="text-sm text-base-content/70 mb-4">
                            {{ __('Spark owns the timing and asks the Flint routine to write the digest. Times are read in your effective timezone and checked every fifteen minutes.') }}
                        </p>

                        <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                            <div>
                                <div class="font-medium text-sm">{{ __('Enable digests') }}</div>
                                <div class="text-xs text-base-content/60">{{ __('Turn off to stop Flint being asked for digests entirely') }}</div>
                            </div>
                            <input type="checkbox" class="toggle toggle-primary" wire:model.live="digestsEnabled" />
                        </div>
                    </div>
                </div>

                @if ($digestsEnabled)
                    <div class="card bg-base-200 shadow">
                        <div class="card-body">
                            <h3 class="text-lg font-semibold mb-1">{{ __('Digest schedule') }}</h3>
                            <p class="text-sm text-base-content/70 mb-4">
                                {{ __('The morning digest waits for your Oura sleep score, so it fires at the later of the morning slot and that reading — the fallback is the cutoff if sleep never lands.') }}
                            </p>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="form-control">
                                    <label class="label"><span class="label-text">{{ __('Morning (Mon–Fri)') }}</span></label>
                                    <select wire:model="morningTimeWeekday" class="select select-bordered">
                                        @foreach ($timeOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-control">
                                    <label class="label"><span class="label-text">{{ __('Morning (Sat–Sun)') }}</span></label>
                                    <select wire:model="morningTimeWeekend" class="select select-bordered">
                                        @foreach ($timeOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-control">
                                    <label class="label"><span class="label-text">{{ __('Morning fallback') }}</span></label>
                                    <select wire:model="morningFallback" class="select select-bordered">
                                        @foreach ($timeOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-control">
                                    <label class="label"><span class="label-text">{{ __('Evening') }}</span></label>
                                    <select wire:model="eveningTime" class="select select-bordered">
                                        @foreach ($timeOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-1">{{ __('Other Flint routines') }}</h3>
                        <p class="text-sm text-base-content/70 mb-4">
                            {{ __('Each of these runs once a day on its own slot. A routine stays idle until its webhook is configured on the server.') }}
                        </p>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="form-control">
                                <label class="label"><span class="label-text">{{ __('Topic review') }}</span></label>
                                <select wire:model="topicsTime" class="select select-bordered">
                                    @foreach ($timeOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <label class="label"><span class="label-text-alt">{{ __('After the evening digest') }}</span></label>
                            </div>
                            <div class="form-control">
                                <label class="label"><span class="label-text">{{ __('Reading list') }}</span></label>
                                <select wire:model="readingListTime" class="select select-bordered">
                                    @foreach ($timeOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-control">
                                <label class="label"><span class="label-text">{{ __('News roundup') }}</span></label>
                                <select wire:model="newsRoundupTime" class="select select-bordered">
                                    @foreach ($timeOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                @if (($feedback['total_feedback_count'] ?? 0) > 0)
                    <div class="card bg-base-200 shadow">
                        <div class="card-body">
                            <h3 class="text-lg font-semibold mb-4">{{ __('Your feedback') }}</h3>
                            <div class="stats stats-vertical sm:stats-horizontal shadow">
                                <div class="stat bg-base-100">
                                    <div class="stat-title">{{ __('Rated or dismissed') }}</div>
                                    <div class="stat-value text-lg">{{ $feedback['total_feedback_count'] }}</div>
                                </div>
                                @if (($feedback['rating_average'] ?? 0) > 0)
                                    <div class="stat bg-base-100">
                                        <div class="stat-title">{{ __('Average rating') }}</div>
                                        <div class="stat-value text-lg">{{ number_format($feedback['rating_average'], 1) }}/5</div>
                                    </div>
                                @endif
                                <div class="stat bg-base-100">
                                    <div class="stat-title">{{ __('Acted on') }}</div>
                                    <div class="stat-value text-lg">{{ $feedback['acted_count'] ?? 0 }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="flex justify-end">
                    <x-button label="{{ __('Save Settings') }}" wire:click="save" class="btn-primary" spinner="save" />
                </div>
            </div>
        </x-tab>
    </x-tabs>
</div>
