<?php

use App\Models\Block;
use App\Models\Event;
use App\Models\SearchLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Illuminate\Support\Str;
use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    use Toast, WithPagination;

    public string $search = '';
    public string $typeFilter = '';
    public string $sourceFilter = '';
    public int $perPage = 25;
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    public array $collapse = [
        'embedding_coverage' => false,
        'popular_queries' => false,
        'performance_metrics' => false,
        'quality_insights' => false,
    ];

    protected $queryString = [
        'search' => ['except' => ''],
        'typeFilter' => ['except' => ''],
        'sourceFilter' => ['except' => ''],
        'sortBy' => ['except' => ['column' => 'created_at', 'direction' => 'desc']],
        'perPage' => ['except' => 25],
        'page' => ['except' => 1],
    ];

    public function mount(): void
    {
        // Initialize
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSourceFilter(): void
    {
        $this->resetPage();
    }

    public function toggle(string $key): void
    {
        $this->collapse[$key] = ! ($this->collapse[$key] ?? false);
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'typeFilter', 'sourceFilter']);
        $this->resetPage();
    }

    public function headers(): array
    {
        return [
            ['key' => 'query', 'label' => 'Query', 'sortable' => true],
            ['key' => 'type', 'label' => 'Type', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'source', 'label' => 'Source', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'results_count', 'label' => 'Results', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'top_similarity', 'label' => 'Match %', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'response_time_ms', 'label' => 'Time', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'created_at', 'label' => 'When', 'sortable' => true],
        ];
    }

    public function getSearchLogs()
    {
        $query = SearchLog::query();

        // Apply search filter
        if ($this->search) {
            $query->where('query', 'ilike', '%' . $this->search . '%');
        }

        // Apply type filter
        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        }

        // Apply source filter
        if ($this->sourceFilter) {
            $query->where('source', $this->sourceFilter);
        }

        // Apply sorting
        $sortColumn = $this->sortBy['column'] ?? 'created_at';
        $sortDirection = $this->sortBy['direction'] ?? 'desc';
        $query->orderBy($sortColumn, $sortDirection);

        return $query->paginate($this->perPage);
    }

    public function getStatsProperty(): array
    {
        $totalSearches = SearchLog::where('created_at', '>=', now()->subDays(7))->count();

        $typeBreakdown = SearchLog::where('created_at', '>=', now()->subDays(7))
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $semanticCount = $typeBreakdown['semantic'] ?? 0;
        $keywordCount = $typeBreakdown['keyword'] ?? 0;
        $hybridCount = $typeBreakdown['hybrid'] ?? 0;

        $semanticPercent = $totalSearches > 0 ? round(($semanticCount / $totalSearches) * 100) : 0;
        $keywordPercent = $totalSearches > 0 ? round(($keywordCount / $totalSearches) * 100) : 0;

        $avgSimilarity = SearchLog::where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('avg_similarity')
            ->avg('avg_similarity');

        $avgSimilarityPercent = $avgSimilarity ? round((1 - $avgSimilarity) * 100) : 0;

        return [
            'total_searches' => $totalSearches,
            'semantic_count' => $semanticCount,
            'keyword_count' => $keywordCount,
            'hybrid_count' => $hybridCount,
            'semantic_percent' => $semanticPercent,
            'keyword_percent' => $keywordPercent,
            'avg_similarity_percent' => $avgSimilarityPercent,
        ];
    }

    public function getEmbeddingCoverageProperty(): array
    {
        $eventsTotal = Event::count();
        $eventsWithEmbeddings = Event::whereNotNull('embeddings')->count();
        $eventsPercent = $eventsTotal > 0 ? round(($eventsWithEmbeddings / $eventsTotal) * 100) : 0;

        $blocksTotal = Block::count();
        $blocksWithEmbeddings = Block::whereNotNull('embeddings')->count();
        $blocksPercent = $blocksTotal > 0 ? round(($blocksWithEmbeddings / $blocksTotal) * 100) : 0;

        return [
            'events_total' => $eventsTotal,
            'events_with_embeddings' => $eventsWithEmbeddings,
            'events_missing' => $eventsTotal - $eventsWithEmbeddings,
            'events_percent' => $eventsPercent,
            'blocks_total' => $blocksTotal,
            'blocks_with_embeddings' => $blocksWithEmbeddings,
            'blocks_missing' => $blocksTotal - $blocksWithEmbeddings,
            'blocks_percent' => $blocksPercent,
        ];
    }

    public function getPopularQueriesProperty(): array
    {
        return SearchLog::getPopularQueries(null, 10, 30)->toArray();
    }

    public function getZeroResultQueriesProperty(): array
    {
        return SearchLog::getZeroResultQueries(null, 10, 30)->toArray();
    }

    public function getPerformanceMetricsProperty(): array
    {
        $last30Days = SearchLog::where('created_at', '>=', now()->subDays(30))->get();

        $apiCalls = $last30Days->where('type', 'semantic')->count();
        $estimatedCost = ($apiCalls * 20 * 0.02) / 1000000; // Rough estimate: 20 tokens avg, $0.02 per 1M tokens

        $avgResponseTime = $last30Days->where('type', 'semantic')->avg('response_time_ms');
        $keywordAvgTime = $last30Days->where('type', 'keyword')->avg('response_time_ms');

        $cacheHitRate = 78; // Placeholder - would need actual cache tracking

        return [
            'api_calls' => $apiCalls,
            'estimated_cost' => round($estimatedCost, 2),
            'avg_response_time' => round($avgResponseTime ?? 0),
            'keyword_avg_time' => round($keywordAvgTime ?? 0),
            'cache_hit_rate' => $cacheHitRate,
        ];
    }

    public function getQualityInsightsProperty(): array
    {
        $lowSimilarity = SearchLog::where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('avg_similarity')
            ->where('avg_similarity', '>', 0.4) // Low similarity = high distance
            ->count();

        $noEmbeddings = SearchLog::where('created_at', '>=', now()->subDays(30))
            ->where('results_count', 0)
            ->whereNotNull('threshold')
            ->count();

        return [
            'low_similarity_count' => $lowSimilarity,
            'no_embeddings_count' => $noEmbeddings,
        ];
    }

    public function formatType(string $type): string
    {
        return match($type) {
            'semantic' => '🔍 Semantic',
            'keyword' => '🔎 Keyword',
            'hybrid' => '🔀 Hybrid',
            default => $type,
        };
    }

    public function formatSource(string $source): string
    {
        return match($source) {
            'api' => 'API',
            'spotlight_auto' => 'Spotlight (Auto)',
            'spotlight_mode' => 'Spotlight (~)',
            default => $source,
        };
    }

    public function formatSimilarity(?float $similarity): string
    {
        if ($similarity === null) {
            return '-';
        }

        $percent = round((1 - $similarity) * 100);
        return $percent . '%';
    }

    public function formatResponseTime(?int $ms): string
    {
        if ($ms === null) {
            return '-';
        }

        return $ms . 'ms';
    }

    public function generateEmbeddings(string $type = 'all'): void
    {
        try {
            $limit = match ($type) {
                'events' => 1000,
                'blocks' => 1000,
                'all' => 500,
                default => 500,
            };

            Illuminate\Support\Facades\Artisan::call('embeddings:generate', [
                '--type' => $type,
                '--batch' => 50,
                '--limit' => $limit,
            ]);

            $output = Illuminate\Support\Facades\Artisan::output();

            $this->success("Started generating {$type} embeddings. Check queue for progress.");
        } catch (\Exception $e) {
            $this->error('Failed to start embedding generation: ' . $e->getMessage());
        }
    }
};

?>

<div>
    <x-header title="Search Analytics" subtitle="Monitor semantic search performance and usage" separator>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <div class="dropdown dropdown-end">
                    <label tabindex="0" class="btn btn-primary btn-sm">
                        <x-icon name="fas-wand-magic-sparkles" class="w-4 h-4 mr-1" />
                        Generate Embeddings
                    </label>
                    <ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow bg-base-200 rounded-box w-52 mt-2">
                        <li>
                            <button wire:click="generateEmbeddings('events')" class="text-sm">
                                <x-icon name="fas-calendar" class="w-4 h-4" />
                                Events Only
                            </button>
                        </li>
                        <li>
                            <button wire:click="generateEmbeddings('blocks')" class="text-sm">
                                <x-icon name="fas-grip" class="w-4 h-4" />
                                Blocks Only
                            </button>
                        </li>
                        <li>
                            <button wire:click="generateEmbeddings('all')" class="text-sm">
                                <x-icon name="fas-bolt" class="w-4 h-4" />
                                All (Events + Blocks)
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </x-slot:actions>
    </x-header>

    <div class="space-y-4 lg:space-y-6">
        <!-- Stats Overview -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="stat bg-base-200 rounded-lg shadow">
                <div class="stat-title">Total Searches</div>
                <div class="stat-value text-primary">{{ number_format($this->stats['total_searches']) }}</div>
                <div class="stat-desc">Last 7 days</div>
            </div>
            <div class="stat bg-base-200 rounded-lg shadow">
                <div class="stat-title">Semantic vs Keyword</div>
                <div class="stat-value text-sm">{{ $this->stats['semantic_percent'] }}% / {{ $this->stats['keyword_percent'] }}%</div>
                <div class="stat-desc">{{ number_format($this->stats['semantic_count']) }} semantic searches</div>
            </div>
            <div class="stat bg-base-200 rounded-lg shadow">
                <div class="stat-title">Avg Match Quality</div>
                <div class="stat-value text-success">{{ $this->stats['avg_similarity_percent'] }}%</div>
                <div class="stat-desc">Average similarity score</div>
            </div>
            <div class="stat bg-base-200 rounded-lg shadow">
                <div class="stat-title">Missing Embeddings</div>
                <div class="stat-value text-warning text-2xl">{{ number_format($this->embeddingCoverage['events_missing']) }}</div>
                <div class="stat-desc">{{ number_format($this->embeddingCoverage['blocks_missing']) }} blocks</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <div class="flex flex-col lg:flex-row gap-4">
                    <div class="form-control flex-1">
                        <label class="label"><span class="label-text">Search Queries</span></label>
                        <input type="text" class="input input-bordered w-full" placeholder="Search queries..." wire:model.live.debounce.300ms="search" />
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Type</span></label>
                        <select class="select select-bordered" wire:model.live="typeFilter">
                            <option value="">All Types</option>
                            <option value="semantic">🔍 Semantic</option>
                            <option value="keyword">🔎 Keyword</option>
                            <option value="hybrid">🔀 Hybrid</option>
                        </select>
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Source</span></label>
                        <select class="select select-bordered" wire:model.live="sourceFilter">
                            <option value="">All Sources</option>
                            <option value="api">API</option>
                            <option value="spotlight_auto">Spotlight (Auto)</option>
                            <option value="spotlight_mode">Spotlight (~)</option>
                        </select>
                    </div>
                    @if ($search || $typeFilter || $sourceFilter)
                    <div class="form-control content-end">
                        <label class="label"><span class="label-text">&nbsp;</span></label>
                        <button class="btn btn-outline" wire:click="clearFilters">
                            <x-icon name="fas-xmark" class="w-4 h-4" />
                            Clear
                        </button>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Recent Searches Table -->
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <h3 class="card-title mb-4">Recent Searches</h3>
                <x-table
                    :headers="$this->headers()"
                    :rows="$this->getSearchLogs()"
                    :sort-by="$sortBy"
                    with-pagination
                    per-page="perPage"
                    :per-page-values="[10, 25, 50, 100]"
                    striped
                    class="[&_table]:!static [&_td]:!static">
                    <x-slot:empty>
                        <div class="text-center py-12">
                            <x-icon name="fas-magnifying-glass" class="w-16 h-16 mx-auto mb-4 text-base-content/70" />
                            <h3 class="text-lg font-medium text-base-content mb-2">No searches found</h3>
                            <p class="text-base-content/70">
                                @if ($search || $typeFilter || $sourceFilter)
                                Try adjusting your filters
                                @else
                                No searches have been logged yet
                                @endif
                            </p>
                        </div>
                    </x-slot:empty>

                    @scope('cell_query', $log)
                    <div class="flex flex-col gap-1">
                        <span class="text-sm font-medium">{{ Str::limit($log->query, 50) }}</span>
                        <div class="flex gap-2 sm:hidden">
                            <span class="badge badge-sm">{{ $this->formatType($log->type) }}</span>
                            <span class="badge badge-sm badge-ghost">{{ $log->results_count }} results</span>
                        </div>
                    </div>
                    @endscope

                    @scope('cell_type', $log)
                    <span class="text-sm">{{ $this->formatType($log->type) }}</span>
                    @endscope

                    @scope('cell_source', $log)
                    <span class="text-sm">{{ $this->formatSource($log->source) }}</span>
                    @endscope

                    @scope('cell_results_count', $log)
                    @if ($log->results_count === 0)
                    <span class="badge badge-warning badge-sm">0</span>
                    @else
                    <span class="text-sm">{{ $log->results_count }}</span>
                    @endif
                    @endscope

                    @scope('cell_top_similarity', $log)
                    <span class="text-sm">{{ $this->formatSimilarity($log->top_similarity) }}</span>
                    @endscope

                    @scope('cell_response_time_ms', $log)
                    <span class="text-sm text-base-content/70">{{ $this->formatResponseTime($log->response_time_ms) }}</span>
                    @endscope

                    @scope('cell_created_at', $log)
                    <x-uk-date :date="$log->created_at" />
                    @endscope
                </x-table>
            </div>
        </div>

        <!-- Embedding Coverage -->
        <x-collapse wire:model="collapse.embedding_coverage" separator class="bg-base-100">
            <x-slot:heading>
                <div class="flex items-center gap-3 w-full" wire:click="toggle('embedding_coverage')">
                    <x-icon name="o-circle-stack" class="w-5 h-5" />
                    <span class="flex-1 text-left">Embedding Coverage</span>
                    @if ($this->embeddingCoverage['events_missing'] > 0 || $this->embeddingCoverage['blocks_missing'] > 0)
                    <x-badge :value="$this->embeddingCoverage['events_missing'] + $this->embeddingCoverage['blocks_missing']" class="badge-warning" />
                    @else
                    <x-badge value="✓" class="badge-success" />
                    @endif
                </div>
            </x-slot:heading>
            <x-slot:content>
                <div class="space-y-4">
                    <!-- Events -->
                    <div class="border border-base-300 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <div class="font-semibold">Events</div>
                                <div class="text-sm text-base-content/70">{{ number_format($this->embeddingCoverage['events_with_embeddings']) }} / {{ number_format($this->embeddingCoverage['events_total']) }}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-bold">{{ $this->embeddingCoverage['events_percent'] }}%</div>
                                @if ($this->embeddingCoverage['events_missing'] > 0)
                                <a href="{{ route('admin.search.index') }}" class="text-sm text-warning hover:underline">{{ number_format($this->embeddingCoverage['events_missing']) }} missing</a>
                                @endif
                            </div>
                        </div>
                        <progress class="progress progress-success w-full" value="{{ $this->embeddingCoverage['events_percent'] }}" max="100"></progress>
                    </div>

                    <!-- Blocks -->
                    <div class="border border-base-300 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <div class="font-semibold">Blocks</div>
                                <div class="text-sm text-base-content/70">{{ number_format($this->embeddingCoverage['blocks_with_embeddings']) }} / {{ number_format($this->embeddingCoverage['blocks_total']) }}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-bold">{{ $this->embeddingCoverage['blocks_percent'] }}%</div>
                                @if ($this->embeddingCoverage['blocks_missing'] > 0)
                                <a href="{{ route('admin.search.index') }}" class="text-sm text-warning hover:underline">{{ number_format($this->embeddingCoverage['blocks_missing']) }} missing</a>
                                @endif
                            </div>
                        </div>
                        <progress class="progress progress-success w-full" value="{{ $this->embeddingCoverage['blocks_percent'] }}" max="100"></progress>
                    </div>
                </div>
            </x-slot:content>
        </x-collapse>

        <!-- Popular Queries -->
        <x-collapse wire:model="collapse.popular_queries" separator class="bg-base-100">
            <x-slot:heading>
                <div class="flex items-center gap-3 w-full" wire:click="toggle('popular_queries')">
                    <x-icon name="fas-fire" class="w-5 h-5" />
                    <span class="flex-1 text-left">Popular Queries & Zero Results</span>
                    <x-badge :value="count($this->popularQueries) + count($this->zeroResultQueries)" class="badge-ghost" />
                </div>
            </x-slot:heading>
            <x-slot:content>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Top Searches -->
                    <div>
                        <h4 class="font-semibold mb-3">Top Searches (Last 30 Days)</h4>
                        @if (empty($this->popularQueries))
                        <div class="text-sm text-base-content/70">No searches yet</div>
                        @else
                        <div class="space-y-2">
                            @foreach ($this->popularQueries as $item)
                            <div class="flex items-center justify-between p-2 bg-base-200 rounded">
                                <span class="text-sm">{{ $item['query'] }}</span>
                                <span class="badge badge-sm">{{ $item['count'] }}×</span>
                            </div>
                            @endforeach
                        </div>
                        @endif
                    </div>

                    <!-- Zero Results -->
                    <div>
                        <h4 class="font-semibold mb-3">Zero Results (Last 30 Days)</h4>
                        @if (empty($this->zeroResultQueries))
                        <div class="text-sm text-base-content/70">No zero-result queries</div>
                        @else
                        <div class="space-y-2">
                            @foreach ($this->zeroResultQueries as $item)
                            <div class="flex items-center justify-between p-2 bg-base-200 rounded">
                                <span class="text-sm text-warning">{{ $item['query'] }}</span>
                                <span class="badge badge-warning badge-sm">{{ $item['count'] }}×</span>
                            </div>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </div>
            </x-slot:content>
        </x-collapse>

        <!-- Performance Metrics -->
        <x-collapse wire:model="collapse.performance_metrics" separator class="bg-base-100">
            <x-slot:heading>
                <div class="flex items-center gap-3 w-full" wire:click="toggle('performance_metrics')">
                    <x-icon name="fas-chart-simple" class="w-5 h-5" />
                    <span class="flex-1 text-left">Performance & Cost Metrics</span>
                    <x-badge value="Info" class="badge-ghost" />
                </div>
            </x-slot:heading>
            <x-slot:content>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="stat bg-base-200 rounded-lg">
                        <div class="stat-title">OpenAI API Calls</div>
                        <div class="stat-value text-primary text-2xl">{{ number_format($this->performanceMetrics['api_calls']) }}</div>
                        <div class="stat-desc">Last 30 days</div>
                    </div>
                    <div class="stat bg-base-200 rounded-lg">
                        <div class="stat-title">Estimated Cost</div>
                        <div class="stat-value text-success text-2xl">${{ $this->performanceMetrics['estimated_cost'] }}</div>
                        <div class="stat-desc">Based on usage</div>
                    </div>
                    <div class="stat bg-base-200 rounded-lg">
                        <div class="stat-title">Avg Response Time</div>
                        <div class="stat-value text-2xl">{{ $this->performanceMetrics['avg_response_time'] }}ms</div>
                        <div class="stat-desc">Semantic searches</div>
                    </div>
                </div>
            </x-slot:content>
        </x-collapse>

        <!-- Quality Insights -->
        <x-collapse wire:model="collapse.quality_insights" separator class="bg-base-100">
            <x-slot:heading>
                <div class="flex items-center gap-3 w-full" wire:click="toggle('quality_insights')">
                    <x-icon name="fas-lightbulb" class="w-5 h-5" />
                    <span class="flex-1 text-left">Search Quality Insights</span>
                    @if ($this->qualityInsights['low_similarity_count'] > 0 || $this->qualityInsights['no_embeddings_count'] > 0)
                    <x-badge :value="$this->qualityInsights['low_similarity_count'] + $this->qualityInsights['no_embeddings_count']" class="badge-warning" />
                    @else
                    <x-badge value="✓" class="badge-success" />
                    @endif
                </div>
            </x-slot:heading>
            <x-slot:content>
                <div class="space-y-3">
                    @if ($this->qualityInsights['low_similarity_count'] > 0)
                    <div class="alert alert-warning">
                        <x-icon name="fas-triangle-exclamation" class="w-5 h-5" />
                        <span><strong>{{ $this->qualityInsights['low_similarity_count'] }}</strong> searches with low match quality (&lt;60% similarity)</span>
                    </div>
                    @endif

                    @if ($this->qualityInsights['no_embeddings_count'] > 0)
                    <div class="alert alert-warning">
                        <x-icon name="fas-triangle-exclamation" class="w-5 h-5" />
                        <span><strong>{{ $this->qualityInsights['no_embeddings_count'] }}</strong> searches returned zero results (possible missing embeddings)</span>
                    </div>
                    @endif

                    @if ($this->qualityInsights['low_similarity_count'] === 0 && $this->qualityInsights['no_embeddings_count'] === 0)
                    <div class="alert alert-success">
                        <x-icon name="fas-circle-check" class="w-5 h-5" />
                        <span>No quality issues detected in the last 30 days 🎉</span>
                    </div>
                    @endif
                </div>
            </x-slot:content>
        </x-collapse>
    </div>
</div>
