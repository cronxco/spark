<?php

namespace App\Services\Mobile;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\MetricStatistic;
use App\Models\User;
use App\Services\EmbeddingService;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Unified search entrypoint for the mobile /search endpoint.
 *
 * Modes: default, semantic, tag, metric, integration. Each delegates to the
 * appropriate underlying machinery (direct SQL / embedding / tag relation /
 * metric lookup / integration scan) and returns a normalised shape so the
 * controller can compose one response regardless of mode.
 */
class SearchDispatcher
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    public const MODES = ['default', 'semantic', 'tag', 'metric', 'integration'];

    public function __construct(protected ?EmbeddingService $embeddingService = null) {}

    /**
     * @return array{mode: string, query: string, events: Collection, objects: Collection, integrations: Collection, metrics: Collection}
     */
    public function search(User $user, string $mode, string $query, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $query = trim($query);

        $result = [
            'mode' => $mode,
            'query' => $query,
            'events' => collect(),
            'objects' => collect(),
            'integrations' => collect(),
            'metrics' => collect(),
        ];

        if ($query === '') {
            return $result;
        }

        return match ($mode) {
            'semantic' => $this->semantic($user, $query, $limit, $result),
            'tag' => $this->byTag($user, $query, $limit, $result),
            'metric' => $this->byMetric($user, $query, $result),
            'integration' => $this->byIntegration($user, $query, $limit, $result),
            default => $this->defaultSearch($user, $query, $limit, $result),
        };
    }

    protected function defaultSearch(User $user, string $query, int $limit, array $result): array
    {
        $integrationIds = $user->integrations()->pluck('id')->all();
        $like = '%' . $query . '%';

        if (! empty($integrationIds)) {
            $result['events'] = Event::query()
                ->whereIn('integration_id', $integrationIds)
                ->where(fn ($q) => $q->where('action', 'like', $like)->orWhere('service', 'like', $like))
                ->with(['actor', 'target'])
                ->orderBy('time', 'desc')
                ->limit($limit)
                ->get();
        }

        $result['objects'] = EventObject::query()
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('content', 'like', $like))
            ->orderBy('time', 'desc')
            ->limit($limit)
            ->get();

        return $result;
    }

    protected function semantic(User $user, string $query, int $limit, array $result): array
    {
        if (! $this->embeddingService) {
            return $this->defaultSearch($user, $query, $limit, $result);
        }

        try {
            $embedding = $this->embeddingService->embed($query);
        } catch (Throwable) {
            return $this->defaultSearch($user, $query, $limit, $result);
        }

        $integrationIds = $user->integrations()->pluck('id')->all();

        if (! empty($integrationIds)) {
            $result['events'] = Event::semanticSearch($embedding, threshold: 1.2, limit: $limit)
                ->whereIn('integration_id', $integrationIds)
                ->with(['actor', 'target'])
                ->get();
        }

        $result['objects'] = EventObject::semanticSearch($embedding, threshold: 1.2, limit: $limit)
            ->where('user_id', $user->id)
            ->get();

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
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('service', 'like', $like)->orWhere('name', 'like', $like))
            ->orderBy('service')
            ->limit($limit)
            ->get();

        return $result;
    }
}
