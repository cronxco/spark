<?php

namespace App\Services\Mobile;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\MetricStatistic;
use App\Models\Place;
use App\Services\Actions\ActionDefinition;
use App\Services\Actions\ActionRegistry;
use App\Models\User;
use App\Services\Ai\EmbeddingClient;
use App\Services\Search\RecencyRanking;
use App\Support\OwnedTagQuery;
use App\Support\SparkAbility;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Unified search entrypoint for the mobile /search endpoint.
 *
 * Modes: default, semantic, tag, metric, integration. Each delegates to the
 * appropriate underlying machinery (direct SQL / embedding / tag relation /
 * metric lookup / integration scan) and returns a normalised shape so the
 * controller can compose one response regardless of mode.
 *
 * Default and semantic results are ordered by {@see RecencyRanking}, the
 * ranking Spotlight uses too, so recency is tuned in one place.
 *
 * Contract v1 asks for `$allFamilies`, which makes the default mode cover
 * events, objects, blocks, metrics, integrations, places, tags and commands. A semantic
 * search that cannot embed falls back to keyword matching and says why in
 * `fallback_reason`; it never falls back silently.
 */
class SearchDispatcher
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    public const MODES = ['default', 'semantic', 'tag', 'metric', 'integration'];

    /** Modes contract v1 accepts: the pre-v1 modes plus commands only. */
    public const V1_MODES = [...self::MODES, 'command'];

    /** Commands shown in "All", so they do not crowd out the data families. */
    public const COMMANDS_IN_ALL = 5;

    /**
     * Plural mode names sent by shipped iOS builds, mapped to their canonical
     * mode so those clients' Tags, Metrics and Integrations pills work.
     *
     * @var array<string, string>
     */
    public const MODE_ALIASES = ['tags' => 'tag', 'metrics' => 'metric', 'integrations' => 'integration'];

    public const FALLBACK_EMBEDDINGS_UNAVAILABLE = 'embeddings_unavailable';

    public const FALLBACK_EMBEDDING_FAILED = 'embedding_failed';

    protected RecencyRanking $ranking;

    public function __construct(protected ?EmbeddingClient $embeddingService = null, ?RecencyRanking $ranking = null, protected ?ActionRegistry $actions = null)
    {
        $this->ranking = $ranking ?? new RecencyRanking;
    }

    /**
     * @return array{mode: string, query: string, events: Collection, objects: Collection, blocks: Collection, integrations: Collection, metrics: Collection, places: Collection, tags: Collection, commands: Collection, fallback_reason: ?string}
     */
    public function search(User $user, string $mode, string $query, int $limit = self::DEFAULT_LIMIT, bool $allFamilies = false): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $query = trim($query);

        $result = [
            'mode' => $mode,
            'query' => $query,
            'events' => collect(),
            'objects' => collect(),
            'blocks' => collect(),
            'integrations' => collect(),
            'metrics' => collect(),
            'places' => collect(),
            'tags' => collect(),
            'commands' => collect(),
            'fallback_reason' => null,
        ];

        if ($query === '') {
            return $result;
        }

        return match ($mode) {
            'semantic' => $this->semantic($user, $query, $limit, $result, $allFamilies),
            'tag' => $this->byTag($user, $query, $limit, $result),
            'metric' => $this->byMetric($user, $query, $result),
            'integration' => $this->byIntegration($user, $query, $limit, $result),
            'command' => $this->byCommand($user, $query, $limit, $result),
            default => $this->defaultSearch($user, $query, $limit, $result, $allFamilies),
        };
    }

    protected function defaultSearch(User $user, string $query, int $limit, array $result, bool $allFamilies = false): array
    {
        $integrationIds = $user->integrations()->pluck('id')->all();
        $like = '%' . $query . '%';

        if (! empty($integrationIds)) {
            $events = Event::query()
                ->whereIn('integration_id', $integrationIds)
                ->where(fn ($q) => $q->where('action', 'like', $like)->orWhere('service', 'like', $like))
                ->with(['actor', 'target'])
                ->limit($limit);

            $result['events'] = $this->ranking->orderByText($events, $query, 'action')->get();
        }

        $objects = EventObject::query()
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('content', 'like', $like))
            ->when($allFamilies, fn ($q) => $q->where(fn ($q) => $q->whereNull('concept')->orWhere('concept', '!=', 'place')))
            ->limit($limit);

        $result['objects'] = $this->ranking->orderByText($objects, $query, 'title')->get();

        if ($allFamilies) {
            $result = $this->otherFamilies($user, $query, $limit, $integrationIds, $result);
        }

        return $result;
    }

    /**
     * The families "All" adds in contract v1. Each has a detail route.
     */
    protected function otherFamilies(User $user, string $query, int $limit, array $integrationIds, array $result): array
    {
        $like = '%' . $query . '%';

        if (! empty($integrationIds)) {
            $blocks = Block::query()
                ->whereHas('event', fn ($q) => $q->whereIn('integration_id', $integrationIds))
                ->where(fn ($q) => $q->where('title', 'ILIKE', $like)->orWhere('block_type', 'ILIKE', $like))
                ->limit($limit);

            $result['blocks'] = $this->ranking->orderByText($blocks, $query, 'title')->get();
        }

        $result = $this->byMetric($user, $query, $result);
        $result = $this->byIntegration($user, $query, $limit, $result);

        $places = Place::query()
            ->where('user_id', $user->id)
            ->where('title', 'ILIKE', $like)
            ->limit($limit);

        $result['places'] = $this->ranking->orderByText($places, $query, 'title')->get();

        $result['tags'] = OwnedTagQuery::for($user, $query)->limit($limit)->get();

        return $this->byCommand($user, $query, min($limit, self::COMMANDS_IN_ALL), $result);
    }

    /**
     * Native commands from the {@see ActionRegistry} that match the query and
     * that this token may run (the same filter as GET /actions), so a command
     * in the results is one the app can offer.
     */
    protected function byCommand(User $user, string $query, int $limit, array $result): array
    {
        if ($this->actions === null) {
            return $result;
        }

        $result['commands'] = $this->actions->forSurface('native')
            ->filter(fn (ActionDefinition $action): bool => $action->capability === null || SparkAbility::allows($user, $action->capability))
            ->filter(fn (ActionDefinition $action): bool => $action->matches($query))
            ->take($limit)
            ->values();

        return $result;
    }

    protected function semantic(User $user, string $query, int $limit, array $result, bool $allFamilies = false): array
    {
        if (! $this->embeddingService) {
            $result['fallback_reason'] = self::FALLBACK_EMBEDDINGS_UNAVAILABLE;

            return $this->defaultSearch($user, $query, $limit, $result, $allFamilies);
        }

        try {
            $embedding = $this->embeddingService->embed($query);
        } catch (Throwable) {
            $result['fallback_reason'] = self::FALLBACK_EMBEDDING_FAILED;

            return $this->defaultSearch($user, $query, $limit, $result, $allFamilies);
        }

        $integrationIds = $user->integrations()->pluck('id')->all();

        if (! empty($integrationIds)) {
            $events = Event::semanticSearch($embedding, threshold: 1.2, limit: $limit, temporalWeight: 0)
                ->whereIn('integration_id', $integrationIds)
                ->with(['actor', 'target']);

            $result['events'] = $this->ranking->orderBySemantic($events, $embedding)->get();
        }

        $objects = EventObject::semanticSearch($embedding, threshold: 1.2, limit: $limit, temporalWeight: 0)
            ->where('user_id', $user->id);

        $result['objects'] = $this->ranking->orderBySemantic($objects, $embedding)->get();

        return $result;
    }

    protected function byTag(User $user, string $query, int $limit, array $result): array
    {
        $integrationIds = $user->integrations()->pluck('id')->all();

        if (! empty($integrationIds)) {
            $result['events'] = Event::withAnyTags([$query])
                ->whereIn('integration_id', $integrationIds)
                ->with(['actor', 'target'])
                ->orderBy('time', 'desc')
                ->limit($limit)
                ->get();
        }

        $result['objects'] = EventObject::withAnyTags([$query])
            ->where('user_id', $user->id)
            ->orderBy('time', 'desc')
            ->limit($limit)
            ->get();

        return $result;
    }

    protected function byMetric(User $user, string $query, array $result): array
    {
        $like = '%' . $query . '%';

        $result['metrics'] = MetricStatistic::query()
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('service', 'like', $like)->orWhere('action', 'like', $like))
            ->orderBy('service')
            ->orderBy('action')
            ->limit(self::MAX_LIMIT)
            ->get();

        return $result;
    }

    protected function byIntegration(User $user, string $query, int $limit, array $result): array
    {
        $like = '%' . $query . '%';

        $result['integrations'] = Integration::query()
            ->external()
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('service', 'like', $like)->orWhere('name', 'like', $like))
            ->orderBy('service')
            ->limit($limit)
            ->get();

        return $result;
    }
}
