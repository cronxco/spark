<?php

namespace App\Services\Mobile;

use App\Http\Resources\Compact\CompactIntegrationResource;
use App\Http\Resources\Compact\CompactMetricResource;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spatie\Tags\Tag;

/**
 * Search contract v1: the one response shape clients decode.
 *
 * ```
 * { schema_version, query, mode,
 *   execution: { status: complete|degraded, fallback_reason },
 *   results: [ { kind, id, title, subtitle, ... } ],
 *   meta: { count, limit } }
 * ```
 *
 * `kind` is singular and every hit carries every key for its kind (null when
 * empty), so the keys are fixed. The shared fixtures in
 * `tests/Fixtures/search-contract/v1` pin this shape and spark-ios decodes the
 * same files: change both together, and bump {@see self::VERSION} for any
 * breaking change.
 */
class SearchContract
{
    public const VERSION = 1;

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_DEGRADED = 'degraded';

    /** The hit kinds v1 can return. Clients drop kinds they do not know. */
    public const KINDS = ['event', 'object', 'block', 'metric', 'integration', 'place', 'tag'];

    /**
     * @param  array{mode: string, query: string, events: Collection, objects: Collection, blocks?: Collection, metrics: Collection, integrations: Collection, places?: Collection, tags?: Collection, fallback_reason?: ?string}  $results
     * @return array<string, mixed>
     */
    public function present(array $results, Request $request, int $limit): array
    {
        $hits = collect()
            ->concat($results['events']->map(fn (Event $event) => $this->event($event)))
            ->concat($results['objects']->map(fn (EventObject $object) => $this->object($object)))
            ->concat(($results['blocks'] ?? collect())->map(fn (Block $block) => $this->block($block)))
            ->concat($results['metrics']->map(fn ($metric) => $this->metric($metric, $request)))
            ->concat($results['integrations']->map(fn ($integration) => $this->integration($integration, $request)))
            ->concat(($results['places'] ?? collect())->map(fn (EventObject $place) => $this->place($place)))
            ->concat(($results['tags'] ?? collect())->map(fn (Tag $tag) => $this->tag($tag)))
            ->values();

        $reason = $results['fallback_reason'] ?? null;

        return [
            'schema_version' => self::VERSION,
            'query' => $results['query'],
            'mode' => $results['mode'],
            'execution' => [
                'status' => $reason === null ? self::STATUS_COMPLETE : self::STATUS_DEGRADED,
                'fallback_reason' => $reason,
            ],
            'results' => $hits->all(),
            'meta' => ['count' => $hits->count(), 'limit' => $limit],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function event(Event $event): array
    {
        $title = $event->target?->title ?: format_action_title((string) $event->action);

        return [
            'kind' => 'event',
            'id' => (string) $event->id,
            'title' => (string) $title,
            'subtitle' => $event->domain,
            'domain' => $event->domain,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function object(EventObject $object): array
    {
        return [
            'kind' => 'object',
            'id' => (string) $object->id,
            'title' => (string) ($object->title ?: $object->concept),
            'subtitle' => $object->concept,
            'concept' => $object->concept,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function block(Block $block): array
    {
        return [
            'kind' => 'block',
            'id' => (string) $block->id,
            'title' => (string) ($block->title ?: $block->block_type),
            'subtitle' => $block->block_type,
            'block_type' => $block->block_type,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function metric(mixed $metric, Request $request): array
    {
        $compact = (new CompactMetricResource($metric))->resolve($request);

        return [
            'kind' => 'metric',
            'id' => $compact['identifier'],
            'identifier' => $compact['identifier'],
            'title' => (string) $compact['display_name'],
            'subtitle' => $compact['unit'],
            'domain' => $compact['domain'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function integration(mixed $integration, Request $request): array
    {
        $compact = (new CompactIntegrationResource($integration))->resolve($request);

        return [
            'kind' => 'integration',
            'id' => (string) $compact['id'],
            'title' => (string) ($compact['name'] ?: $compact['service']),
            'subtitle' => $compact['service'],
            'service' => $compact['service'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function place(EventObject $place): array
    {
        return [
            'kind' => 'place',
            'id' => (string) $place->id,
            'title' => (string) $place->title,
            'subtitle' => $place->location_address ?: $place->type,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function tag(Tag $tag): array
    {
        $count = (int) ($tag->events_count ?? 0) + (int) ($tag->objects_count ?? 0);

        return [
            'kind' => 'tag',
            'id' => (string) $tag->id,
            'name' => (string) $tag->name,
            'type' => $tag->type,
            'title' => (string) $tag->name,
            'subtitle' => $count . ' ' . ($count === 1 ? 'item' : 'items'),
            'count' => $count,
        ];
    }
}
