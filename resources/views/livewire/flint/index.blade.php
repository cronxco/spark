<?php

use App\Models\Block;
use App\Models\EventObject;
use App\Services\PatternLearningService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public bool $digestsEnabled = true;
    public string $scheduleTimezone = 'Europe/London';
    public array $scheduleTimesWeekday = ['06:00', '18:00'];
    public array $scheduleTimesWeekend = ['08:00', '19:00'];
    public array $enabledDomains = ['health', 'knowledge', 'online'];

    // UI state
    public string $activeTab = 'newspaper';
    public string $newWeekdayTime = '09:00';
    public string $newWeekendTime = '09:00';
    public bool $loadingTab = false;

    // Memory data
    public array $workingMemory = [];
    public array $feedbackStats = [];
    public array $patterns = [];
    public array $learnedPatterns = [];
    public array $recentInsights = [];
    public int $patternsPerPage = 10;
    public int $patternsPage = 1;
    public int $totalPatterns = 0;

    // Digest/Newspaper data
    public ?array $latestDigest = null;
    public ?array $newsBriefing = null;
    public ?array $articlesWaiting = null;
    public array $digestArchive = [];
    public bool $showArchive = false;
    public ?string $expandedArchiveDigestId = null;
    public array $readArticles = [];

    public array $availableDomains = [
        'health' => [
            'label' => 'Health & Fitness',
            'description' => 'Sleep, exercise, heart rate, and wellness metrics',
        ],
        'knowledge' => [
            'label' => 'Knowledge & Learning',
            'description' => 'Articles, notes, and learning patterns',
        ],
        'online' => [
            'label' => 'Online & Productivity',
            'description' => 'Tasks, projects, and digital productivity',
        ],
    ];

    public array $timezones = [
        'UTC' => 'UTC',
        'Europe/London' => 'London',
        'Europe/Paris' => 'Paris',
        'America/New_York' => 'New York',
        'America/Chicago' => 'Chicago',
        'America/Los_Angeles' => 'Los Angeles',
        'America/Toronto' => 'Toronto',
        'Australia/Sydney' => 'Sydney',
        'Asia/Tokyo' => 'Tokyo',
    ];

    public array $timeOptions = [];

    public function mount(): void
    {
        $user = Auth::user();
        $settings = $user->settings['flint'] ?? [];

        $this->digestsEnabled = $settings['digests_enabled'] ?? true;
        $this->scheduleTimezone = $settings['schedule_timezone'] ?? 'Europe/London';
        $this->scheduleTimesWeekday = $settings['schedule_times_weekday'] ?? ['06:00', '18:00'];
        $this->scheduleTimesWeekend = $settings['schedule_times_weekend'] ?? ['08:00', '19:00'];
        $this->enabledDomains = $settings['enabled_domains'] ?? ['health', 'knowledge', 'online'];

        // Generate time options (every 15 minutes from 00:00 to 23:45)
        $this->timeOptions = collect(range(0, 23))
            ->flatMap(function ($hour) {
                return collect([0, 15, 30, 45])
                    ->mapWithKeys(function ($minute) use ($hour) {
                        $time = sprintf('%02d:%02d', $hour, $minute);
                        return [$time => $time];
                    });
            })
            ->toArray();

        // Load data based on active tab
        $this->loadNewspaperData();
    }

    public function loadNewspaperData(): void
    {
        $user = Auth::user();

        // Load latest digest
        $latestDigestBlock = Block::where('block_type', 'flint_digest')
            ->whereHas('event.integration', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->whereHas('event', function ($query) {
                $query->where('service', 'flint');
            })
            ->with(['event'])
            ->latest('time')
            ->first();

        if ($latestDigestBlock) {
            $this->latestDigest = [
                'id' => $latestDigestBlock->id,
                'time' => $latestDigestBlock->time,
                'metadata' => $latestDigestBlock->metadata,
            ];
        }

        // Load latest news briefing
        $newsBriefingBlock = Block::where('block_type', 'flint_news_briefing')
            ->whereHas('event.integration', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->where('time', '>=', now()->subHours(24))
            ->latest('time')
            ->first();

        if ($newsBriefingBlock) {
            $this->newsBriefing = [
                'id' => $newsBriefingBlock->id,
                'time' => $newsBriefingBlock->time,
                'metadata' => $newsBriefingBlock->metadata,
            ];
        }

        // Load articles waiting
        $articlesBlock = Block::where('block_type', 'flint_articles_waiting')
            ->whereHas('event.integration', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->where('time', '>=', now()->subHours(24))
            ->latest('time')
            ->first();

        if ($articlesBlock) {
            $this->articlesWaiting = [
                'id' => $articlesBlock->id,
                'time' => $articlesBlock->time,
                'metadata' => $articlesBlock->metadata,
            ];
        }

        // Load archive (past 30 days, excluding latest)
        $this->digestArchive = Block::where('block_type', 'flint_digest')
            ->whereHas('event.integration', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->whereHas('event', function ($query) {
                $query->where('service', 'flint');
            })
            ->when($latestDigestBlock, fn($query) => $query->where('id', '!=', $latestDigestBlock->id))
            ->where('time', '>=', now()->subDays(30))
            ->with(['event'])
            ->latest('time')
            ->get()
            ->map(function ($block) {
                return [
                    'id' => $block->id,
                    'time' => $block->time,
                    'metadata' => $block->metadata,
                ];
            })
            ->toArray();
    }

    public function loadPatternsData(): void
    {
        $user = Auth::user();
        $patternLearning = app(PatternLearningService::class);

        $allPatterns = $patternLearning->getLearnedPatterns($user);
        $this->totalPatterns = $allPatterns->count();

        $this->learnedPatterns = $allPatterns
            ->take($this->patternsPage * $this->patternsPerPage)
            ->map(function ($pattern) {
                return [
                    'id' => $pattern->id,
                    'title' => $pattern->title,
                    'metadata' => $pattern->metadata,
                    'time' => $pattern->time,
                ];
            })
            ->toArray();
    }

    public function loadMorePatterns(): void
    {
        $this->patternsPage++;
        $this->loadPatternsData();
    }

    public function loadMemoryData(): void
    {
        $user = Auth::user();
        $workingMemoryService = app(\App\Services\AgentWorkingMemoryService::class);
        $memoryService = app(\App\Services\AgentMemoryService::class);

        // Load working memory data
        $this->feedbackStats = $workingMemoryService->getFeedbackStatistics($user->id);
        $this->workingMemory = [
            'domain_insights' => $workingMemoryService->getAllDomainInsights($user->id),
            'cross_domain' => $workingMemoryService->getCrossDomainObservations($user->id, 10),
            'urgent_flags' => $workingMemoryService->getUnresolvedUrgentFlags($user->id),
            'actions' => $workingMemoryService->getPrioritizedActions($user->id),
            'last_execution' => [
                'pre_digest' => $workingMemoryService->getLastExecutionTime($user->id, 'pre_digest_refresh'),
                'digest' => $workingMemoryService->getLastExecutionTime($user->id, 'digest_generation'),
                'patterns' => $workingMemoryService->getLastExecutionTime($user->id, 'pattern_detection'),
            ],
        ];

        // Load long-term memory data
        $this->patterns = $memoryService->getPatterns($user->id, 90);
        $this->recentInsights = $memoryService->getAllInsightBlocks($user->id, 7);
    }

    public function updatedActiveTab(): void
    {
        if ($this->activeTab === 'memory') {
            $this->loadMemoryData();
        } elseif ($this->activeTab === 'newspaper') {
            $this->loadNewspaperData();
        } elseif ($this->activeTab === 'patterns') {
            $this->loadPatternsData();
        }
    }

    public function toggleArchive(): void
    {
        $this->showArchive = !$this->showArchive;
        if ($this->showArchive && empty($this->digestArchive) && empty($this->latestDigest)) {
            $this->loadNewspaperData();
        }
    }

    public function expandArchiveDigest(string $digestId): void
    {
        $this->expandedArchiveDigestId = $this->expandedArchiveDigestId === $digestId ? null : $digestId;
    }

    public function markArticleAsRead(string $articleId): void
    {
        $article = EventObject::find($articleId);

        if ($article && $article->user_id === Auth::id()) {
            $metadata = $article->metadata ?? [];
            $metadata['read_at'] = now()->toIso8601String();
            $article->metadata = $metadata;
            $article->save();

            // Add to read articles array for visual feedback
            $this->readArticles[] = $articleId;

            $this->success('Article marked as read');
            $this->loadNewspaperData();
        }
    }

    public function addWeekdayTime(): void
    {
        if (!in_array($this->newWeekdayTime, $this->scheduleTimesWeekday)) {
            $this->scheduleTimesWeekday[] = $this->newWeekdayTime;
            sort($this->scheduleTimesWeekday);
        }
    }

    public function removeWeekdayTime(string $time): void
    {
        $this->scheduleTimesWeekday = array_values(
            array_filter($this->scheduleTimesWeekday, fn($t) => $t !== $time)
        );
    }

    public function addWeekendTime(): void
    {
        if (!in_array($this->newWeekendTime, $this->scheduleTimesWeekend)) {
            $this->scheduleTimesWeekend[] = $this->newWeekendTime;
            sort($this->scheduleTimesWeekend);
        }
    }

    public function removeWeekendTime(string $time): void
    {
        $this->scheduleTimesWeekend = array_values(
            array_filter($this->scheduleTimesWeekend, fn($t) => $t !== $time)
        );
    }

    public function toggleDomain(string $domain): void
    {
        if (in_array($domain, $this->enabledDomains)) {
            $this->enabledDomains = array_values(
                array_filter($this->enabledDomains, fn($d) => $d !== $domain)
            );
        } else {
            $this->enabledDomains[] = $domain;
        }
    }

    public function save(): void
    {
        try {
            $user = Auth::user();

            $settings = $user->settings;
            $settings['flint'] = [
                'digests_enabled' => $this->digestsEnabled,
                'schedule_timezone' => $this->scheduleTimezone,
                'schedule_times_weekday' => $this->scheduleTimesWeekday,
                'schedule_times_weekend' => $this->scheduleTimesWeekend,
                'enabled_domains' => $this->enabledDomains,
            ];

            $user->settings = $settings;
            $user->save();

            $this->success('Flint settings saved successfully!');
        } catch (\Exception $e) {
            $this->error('Failed to save settings. Please try again.');
        }
    }
}; ?>

<div>
    <x-header title="{{ __('Flint') }}" subtitle="{{ __('Your personal AI assistant') }}" separator />

    {{-- Tabs --}}
    <x-tabs wire:model="activeTab">
        {{-- Newspaper Tab --}}
        <x-tab name="newspaper" label="Today" icon="fas.newspaper">
            <div class="space-y-6">
                {{-- Newspaper Header --}}
                <div class="text-center border-b-4 border-double border-base-300 pb-4">
                    <h1 class="text-4xl font-serif font-bold tracking-tight">THE DAILY SPARK</h1>
                    <p class="text-sm text-base-content/60 mt-1">
                        {{ now()->format('l, F j, Y') }} • Vol. {{ now()->format('Y') }}, No. {{ now()->dayOfYear }}
                    </p>
                </div>

                {{-- Two Column Layout --}}
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {{-- Main Column (2/3) --}}
                    <div class="lg:col-span-2 space-y-6">
                        {{-- News Briefing --}}
                        @if ($newsBriefing)
                            <div class="card bg-base-200 shadow">
                                <div class="card-body p-5">
                                    <div class="flex items-center gap-2 mb-3">
                                        <x-icon name="fas.rss" class="w-4 h-4 text-primary" />
                                        <span class="text-xs font-semibold uppercase tracking-wider text-primary">News Briefing</span>
                                    </div>
                                    <h2 class="text-xl font-serif font-bold mb-2">
                                        {{ $newsBriefing['metadata']['title'] ?? 'Today\'s News' }}
                                    </h2>
                                    <p class="text-sm text-base-content/80 leading-relaxed mb-4">
                                        {{ $newsBriefing['metadata']['summary'] ?? '' }}
                                    </p>

                                    {{-- Key Stories --}}
                                    @if (!empty($newsBriefing['metadata']['key_stories']) && is_array($newsBriefing['metadata']['key_stories']))
                                        <div class="space-y-3">
                                            @foreach ($newsBriefing['metadata']['key_stories'] as $story)
                                                <div class="border-l-2 border-primary pl-3">
                                                    <h3 class="font-semibold text-sm">{{ $story['headline'] }}</h3>
                                                    <p class="text-xs text-base-content/70 mt-1">{{ $story['summary'] }}</p>
                                                    @if (!empty($story['action_needed']))
                                                        <p class="text-xs text-warning mt-1">
                                                            <x-icon name="fas.arrow-right" class="w-3 h-3 inline" />
                                                            {{ $story['action_needed'] }}
                                                        </p>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    {{-- Themes --}}
                                    @if (!empty($newsBriefing['metadata']['themes']) && is_array($newsBriefing['metadata']['themes']))
                                        <div class="mt-4 flex flex-wrap gap-2">
                                            @foreach ($newsBriefing['metadata']['themes'] as $theme)
                                                <span class="badge badge-ghost badge-sm">{{ $theme['theme'] }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif

                        {{-- Latest Digest Insights --}}
                        @if ($latestDigest && !empty($latestDigest['metadata']['top_insights']) && is_array($latestDigest['metadata']['top_insights']))
                            <div class="card bg-base-200 shadow">
                                <div class="card-body p-5">
                                    <div class="flex items-center gap-2 mb-3">
                                        <x-icon name="fas.lightbulb" class="w-4 h-4 text-warning" />
                                        <span class="text-xs font-semibold uppercase tracking-wider text-warning">Key Insights</span>
                                    </div>

                                    <div class="space-y-4">
                                        @foreach (array_slice($latestDigest['metadata']['top_insights'], 0, 3) as $insight)
                                            <div class="flex items-start gap-3">
                                                <div class="text-2xl">{{ $insight['icon'] ?? '💡' }}</div>
                                                <div class="flex-1">
                                                    <h3 class="font-semibold text-sm">{{ $insight['title'] ?? 'Insight' }}</h3>
                                                    <p class="text-xs text-base-content/70 mt-1">{{ $insight['description'] ?? '' }}</p>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif

                        {{-- Wins & Watch Points --}}
                        @if ($latestDigest && ((!empty($latestDigest['metadata']['wins']) && is_array($latestDigest['metadata']['wins'])) || (!empty($latestDigest['metadata']['watch_points']) && is_array($latestDigest['metadata']['watch_points']))))
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                @if (!empty($latestDigest['metadata']['wins']) && is_array($latestDigest['metadata']['wins']))
                                    <div class="card bg-success/10 shadow-sm">
                                        <div class="card-body p-4">
                                            <h3 class="text-sm font-semibold text-success flex items-center gap-2 mb-3">
                                                <x-icon name="fas.check-circle" class="w-4 h-4" />
                                                {{ __('Wins') }}
                                            </h3>
                                            <ul class="space-y-2">
                                                @foreach ($latestDigest['metadata']['wins'] as $win)
                                                    <li class="text-xs text-base-content/80 flex items-start gap-2">
                                                        <x-icon name="fas.check" class="w-3 h-3 text-success mt-0.5 flex-shrink-0" />
                                                        {{ $win }}
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </div>
                                @endif

                                @if (!empty($latestDigest['metadata']['watch_points']) && is_array($latestDigest['metadata']['watch_points']))
                                    <div class="card bg-warning/10 shadow-sm">
                                        <div class="card-body p-4">
                                            <h3 class="text-sm font-semibold text-warning flex items-center gap-2 mb-3">
                                                <x-icon name="fas.exclamation-triangle" class="w-4 h-4" />
                                                {{ __('Watch Points') }}
                                            </h3>
                                            <ul class="space-y-2">
                                                @foreach ($latestDigest['metadata']['watch_points'] as $watchPoint)
                                                    <li class="text-xs text-base-content/80 flex items-start gap-2">
                                                        <x-icon name="fas.exclamation-circle" class="w-3 h-3 text-warning mt-0.5 flex-shrink-0" />
                                                        {{ $watchPoint }}
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    {{-- Sidebar (1/3) --}}
                    <div class="space-y-6">
                        {{-- Health Coach Section --}}
                        <div>
                            <div class="flex items-center gap-2 mb-3">
                                <x-icon name="fas.heart-pulse" class="w-4 h-4 text-error" />
                                <span class="text-xs font-semibold uppercase tracking-wider">Health Coach</span>
                            </div>
                            <livewire:flint.coach-section />
                        </div>

                        {{-- Articles Waiting --}}
                        @if ($articlesWaiting && !empty($articlesWaiting['metadata']['articles']))
                            <div class="card bg-base-200 shadow">
                                <div class="card-body p-4">
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="flex items-center gap-2">
                                            <x-icon name="fas.bookmark" class="w-4 h-4 text-info" />
                                            <span class="text-xs font-semibold uppercase tracking-wider text-info">Reading List</span>
                                        </div>
                                        <span class="badge badge-info badge-sm">{{ count($articlesWaiting['metadata']['articles']) }}</span>
                                    </div>

                                    <div class="space-y-3">
                                        @foreach ($articlesWaiting['metadata']['articles'] as $article)
                                            @php
                                                $isRead = in_array($article['id'], $readArticles);
                                            @endphp
                                            <div
                                                class="border-b border-base-300 pb-3 last:border-0 last:pb-0 transition-all duration-300 {{ $isRead ? 'opacity-50' : 'opacity-100' }}"
                                                wire:key="article-{{ $article['id'] }}"
                                            >
                                                <div class="flex items-start justify-between gap-2">
                                                    <div class="flex-1 min-w-0">
                                                        <a
                                                            href="{{ $article['url'] }}"
                                                            target="_blank"
                                                            class="text-sm font-medium hover:text-primary line-clamp-2 {{ $isRead ? 'line-through' : '' }}"
                                                        >
                                                            {{ $article['title'] }}
                                                        </a>
                                                        <p class="text-xs text-base-content/60 mt-1">
                                                            {{ $article['domain'] }}
                                                            @if (!empty($article['reading_time']))
                                                                • {{ $article['reading_time'] }}
                                                            @endif
                                                            @if ($isRead)
                                                                • <span class="text-success">✓ Read</span>
                                                            @endif
                                                        </p>
                                                    </div>
                                                    @if (!$isRead)
                                                        <button
                                                            wire:click="markArticleAsRead('{{ $article['id'] }}')"
                                                            class="btn btn-ghost btn-xs hover:btn-success transition-colors"
                                                            title="Mark as read"
                                                            wire:loading.attr="disabled"
                                                            wire:target="markArticleAsRead"
                                                        >
                                                            <x-icon name="fas.check" class="w-3 h-3" />
                                                        </button>
                                                    @else
                                                        <span class="text-success">
                                                            <x-icon name="fas.check-circle" class="w-4 h-4" />
                                                        </span>
                                                    @endif
                                                </div>
                                                @if (!empty($article['pitch']))
                                                    <p class="text-xs text-base-content/70 mt-2 italic {{ $isRead ? 'opacity-70' : '' }}">
                                                        "{{ $article['pitch'] }}"
                                                    </p>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif

                        {{-- Tomorrow's Focus --}}
                        @if ($latestDigest && !empty($latestDigest['metadata']['tomorrow_focus']) && is_array($latestDigest['metadata']['tomorrow_focus']))
                            <div class="card bg-base-200 shadow">
                                <div class="card-body p-4">
                                    <div class="flex items-center gap-2 mb-3">
                                        <x-icon name="fas.calendar" class="w-4 h-4 text-primary" />
                                        <span class="text-xs font-semibold uppercase tracking-wider">Tomorrow's Focus</span>
                                    </div>
                                    <ul class="space-y-2">
                                        @foreach ($latestDigest['metadata']['tomorrow_focus'] as $focus)
                                            <li class="text-xs text-base-content/80 flex items-start gap-2">
                                                <x-icon name="fas.arrow-right" class="w-3 h-3 text-primary mt-0.5" />
                                                {{ $focus }}
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Archive Toggle --}}
                <div class="flex justify-center pt-4">
                    <button
                        wire:click="toggleArchive"
                        class="btn btn-outline btn-sm"
                    >
                        <x-icon name="fas.clock" class="w-4 h-4" />
                        {{ $showArchive ? 'Hide Archive' : 'View Past Editions' }}
                        @if (!empty($digestArchive))
                            <span class="badge badge-sm">{{ count($digestArchive) }}</span>
                        @endif
                    </button>
                </div>

                {{-- Archive --}}
                @if ($showArchive)
                    <div class="space-y-3">
                        <h3 class="text-lg font-semibold">Past 30 Days</h3>
                        @forelse ($digestArchive as $digest)
                            <div class="card bg-base-200 shadow">
                                <div class="card-body p-4">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="text-xs font-medium text-base-content/60">
                                                    {{ $digest['time']->format('M j, Y g:i A') }}
                                                </span>
                                                <span class="badge badge-xs badge-ghost">
                                                    {{ $digest['metadata']['metrics']['total_insights'] ?? 0 }} insights
                                                </span>
                                            </div>
                                            <h4 class="font-medium text-sm">{{ $digest['metadata']['headline'] ?? 'Daily Digest' }}</h4>

                                            {{-- Expandable content --}}
                                            @if ($expandedArchiveDigestId === $digest['id'])
                                                <div class="mt-3 space-y-3 text-sm">
                                                    @if (!empty($digest['metadata']['top_insights']) && is_array($digest['metadata']['top_insights']))
                                                        <div class="space-y-2">
                                                            @foreach (array_slice($digest['metadata']['top_insights'], 0, 2) as $insight)
                                                                <div class="flex items-start gap-2">
                                                                    <span>{{ $insight['icon'] ?? '💡' }}</span>
                                                                    <span class="text-base-content/80">{{ $insight['title'] ?? '' }}</span>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                        <button
                                            wire:click="expandArchiveDigest('{{ $digest['id'] }}')"
                                            class="btn btn-ghost btn-xs"
                                        >
                                            <x-icon name="fas.chevron-{{ $expandedArchiveDigestId === $digest['id'] ? 'up' : 'down' }}" class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-8 text-base-content/50 text-sm">
                                No archive editions found
                            </div>
                        @endforelse
                    </div>
                @endif

                {{-- Empty State --}}
                @if (!$latestDigest && !$newsBriefing && !$articlesWaiting)
                    <div class="card bg-base-200 shadow">
                        <div class="card-body text-center py-12">
                            <x-icon name="fas.newspaper" class="w-16 h-16 mx-auto text-base-content/30 mb-4" />
                            <h3 class="text-lg font-semibold mb-2">{{ __('No Content Yet') }}</h3>
                            <p class="text-sm text-base-content/60 mb-4">
                                {{ __('Your personalized newspaper will appear here once Flint starts analyzing your data.') }}
                            </p>
                            <p class="text-xs text-base-content/50">
                                {{ __('Digests are generated based on your schedule in Settings.') }}
                            </p>
                        </div>
                    </div>
                @endif
            </div>
        </x-tab>

        {{-- Coach Tab (Hevy) --}}
        <x-tab name="coach" label="Fitness Coach" icon="fas.dumbbell">
            <div class="space-y-4 lg:space-y-6">
                {{-- Coach Status Card --}}
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

        {{-- Patterns Tab --}}
        <x-tab name="patterns" label="Patterns" icon="fas.brain" wire:click="loadPatternsData">
            <div class="space-y-4 lg:space-y-6">
                {{-- Loading State --}}
                <div wire:loading wire:target="loadPatternsData" class="card bg-base-200 shadow">
                    <div class="card-body text-center py-12">
                        <div class="loading loading-spinner loading-lg mx-auto mb-4"></div>
                        <p class="text-sm text-base-content/60">Loading patterns...</p>
                    </div>
                </div>

                <div wire:loading.remove wire:target="loadPatternsData">
                @if (empty($learnedPatterns))
                    <div class="card bg-base-200 shadow">
                        <div class="card-body text-center py-12">
                            <x-icon name="fas.brain" class="w-16 h-16 mx-auto text-base-content/30 mb-4" />
                            <h3 class="text-lg font-semibold mb-2">{{ __('No Patterns Yet') }}</h3>
                            <p class="text-sm text-base-content/60 mb-4">
                                {{ __('Flint learns patterns from your health check-in responses.') }}
                            </p>
                            <p class="text-xs text-base-content/50">
                                {{ __('When health anomalies are detected, answer the coaching questions to help Flint learn your patterns.') }}
                            </p>
                        </div>
                    </div>
                @else
                    <div class="card bg-base-200 shadow">
                        <div class="card-body">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold">{{ __('Learned Patterns') }}</h3>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-base-content/60">
                                        Showing {{ count($learnedPatterns) }} of {{ $totalPatterns }}
                                    </span>
                                    <span class="badge badge-primary">{{ $totalPatterns }}</span>
                                </div>
                            </div>

                            <div class="space-y-4">
                                @foreach ($learnedPatterns as $pattern)
                                    <div class="card bg-base-100 shadow-sm">
                                        <div class="card-body p-4">
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="flex-1">
                                                    <h4 class="font-semibold text-sm">{{ $pattern['title'] }}</h4>
                                                    @if (!empty($pattern['metadata']['user_explanation']))
                                                        <p class="text-xs text-base-content/70 mt-1 italic">
                                                            "{{ $pattern['metadata']['user_explanation'] }}"
                                                        </p>
                                                    @endif
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <div class="badge badge-outline badge-sm">
                                                        {{ round(($pattern['metadata']['confidence_score'] ?? 0.3) * 100) }}% confidence
                                                    </div>
                                                    <div class="badge badge-ghost badge-sm">
                                                        {{ $pattern['metadata']['confirmation_count'] ?? 1 }}x confirmed
                                                    </div>
                                                </div>
                                            </div>

                                            {{-- Trigger Conditions --}}
                                            @if (!empty($pattern['metadata']['trigger_conditions']))
                                                <div class="mt-3 p-2 bg-base-200 rounded text-xs">
                                                    <span class="font-medium">Triggers:</span>
                                                    @foreach ($pattern['metadata']['trigger_conditions'] as $key => $value)
                                                        <span class="text-base-content/70 ml-1">{{ $key }}: {{ is_string($value) ? $value : json_encode($value) }}</span>
                                                    @endforeach
                                                </div>
                                            @endif

                                            {{-- Consequences --}}
                                            @if (!empty($pattern['metadata']['consequences']))
                                                <div class="mt-2 p-2 bg-warning/10 rounded text-xs">
                                                    <span class="font-medium text-warning">Effects:</span>
                                                    @foreach ($pattern['metadata']['consequences'] as $key => $value)
                                                        <span class="text-base-content/70 ml-1">{{ $key }}: {{ is_string($value) ? $value : json_encode($value) }}</span>
                                                    @endforeach
                                                </div>
                                            @endif

                                            <div class="mt-2 text-xs text-base-content/50">
                                                Last confirmed: {{ $pattern['metadata']['last_confirmed_at'] ? \Carbon\Carbon::parse($pattern['metadata']['last_confirmed_at'])->diffForHumans() : 'Never' }}
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            {{-- Load More Button --}}
                            @if (count($learnedPatterns) < $totalPatterns)
                                <div class="flex justify-center mt-6">
                                    <button
                                        wire:click="loadMorePatterns"
                                        class="btn btn-outline btn-sm"
                                        wire:loading.attr="disabled"
                                        wire:target="loadMorePatterns"
                                    >
                                        <span wire:loading.remove wire:target="loadMorePatterns">
                                            Load More Patterns
                                        </span>
                                        <span wire:loading wire:target="loadMorePatterns">
                                            <span class="loading loading-spinner loading-sm"></span>
                                            Loading...
                                        </span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
                </div>
            </div>
        </x-tab>

        {{-- Memory Tab --}}
        <x-tab name="memory" label="Memory" icon="o-cpu-chip">
            @include('livewire.flint.memory')
        </x-tab>

        {{-- Settings Tab --}}
        <x-tab name="settings" label="Settings" icon="o-cog-6-tooth">
            <div class="space-y-4 lg:space-y-6">
                {{-- General Settings --}}
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">{{ __('General Settings') }}</h3>
                        <p class="text-sm text-base-content/70 mb-4">
                            {{ __('Configure how Flint analyzes your data and delivers insights. Agents run 15 minutes before each scheduled digest.') }}
                        </p>

                        <div class="space-y-3">
                            <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                                <div>
                                    <div class="font-medium text-sm">{{ __('Enable Daily Digests') }}</div>
                                    <div class="text-xs text-base-content/60">{{ __('Receive scheduled digest notifications with AI-generated insights') }}</div>
                                </div>
                                <input
                                    type="checkbox"
                                    class="toggle toggle-primary"
                                    wire:model.live="digestsEnabled"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Digest Schedule --}}
                @if ($digestsEnabled)
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">{{ __('Digest Schedule') }}</h3>
                        <p class="text-sm text-base-content/70 mb-4">
                            {{ __('Choose when you want to receive daily digests') }}
                        </p>

                        <div class="space-y-4">
                            {{-- Timezone --}}
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">{{ __('Timezone') }}</span>
                                </label>
                                <select wire:model.live="scheduleTimezone" class="select select-bordered w-full">
                                    @foreach ($timezones as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Weekday Times --}}
                            <div>
                                <label class="label">
                                    <span class="label-text">{{ __('Weekday Times (Mon-Fri)') }}</span>
                                </label>
                                <div class="space-y-2 mb-3">
                                    @foreach ($scheduleTimesWeekday as $time)
                                        <div class="flex items-center justify-between p-2 bg-base-100 rounded-lg">
                                            <span class="text-sm">{{ $time }}</span>
                                            <button
                                                type="button"
                                                wire:click="removeWeekdayTime('{{ $time }}')"
                                                class="btn btn-ghost btn-xs"
                                            >
                                                <x-icon name="fas.trash" class="w-3 h-3" />
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="flex gap-2">
                                    <select wire:model="newWeekdayTime" class="select select-bordered flex-1">
                                        @foreach ($timeOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <button wire:click="addWeekdayTime" class="btn btn-outline btn-sm">
                                        {{ __('Add') }}
                                    </button>
                                </div>
                            </div>

                            {{-- Weekend Times --}}
                            <div>
                                <label class="label">
                                    <span class="label-text">{{ __('Weekend Times (Sat-Sun)') }}</span>
                                </label>
                                <div class="space-y-2 mb-3">
                                    @foreach ($scheduleTimesWeekend as $time)
                                        <div class="flex items-center justify-between p-2 bg-base-100 rounded-lg">
                                            <span class="text-sm">{{ $time }}</span>
                                            <button
                                                type="button"
                                                wire:click="removeWeekendTime('{{ $time }}')"
                                                class="btn btn-ghost btn-xs"
                                            >
                                                <x-icon name="fas.trash" class="w-3 h-3" />
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="flex gap-2">
                                    <select wire:model="newWeekendTime" class="select select-bordered flex-1">
                                        @foreach ($timeOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <button wire:click="addWeekendTime" class="btn btn-outline btn-sm">
                                        {{ __('Add') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                {{-- Analysis Domains --}}
                <div class="card bg-base-200 shadow">
                    <div class="card-body">
                        <h3 class="text-lg font-semibold mb-4">{{ __('Analysis Domains') }}</h3>
                        <p class="text-sm text-base-content/70 mb-4">
                            {{ __('Choose which domains Flint should analyze') }}
                        </p>

                        <div class="space-y-3">
                            @foreach ($availableDomains as $key => $domain)
                                <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
                                    <div>
                                        <div class="font-medium text-sm">{{ $domain['label'] }}</div>
                                        <div class="text-xs text-base-content/60">{{ $domain['description'] }}</div>
                                    </div>
                                    <input
                                        type="checkbox"
                                        class="toggle toggle-primary"
                                        wire:model="enabledDomains"
                                        value="{{ $key }}"
                                    />
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Save Button --}}
                <div class="flex justify-end">
                    <x-button
                        label="{{ __('Save Settings') }}"
                        wire:click="save"
                        class="btn-primary"
                        spinner="save"
                    />
                </div>
            </div>
        </x-tab>
    </x-tabs>
</div>
