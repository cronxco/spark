<?php

namespace App\Services\Mobile;

use App\Mcp\Helpers\DateParser;
use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class EventFeed
{
    use DateParser;

    public const LIMIT_DEFAULT = 50;

    public const LIMIT_MAX = 100;

    /**
     * Filter the user's events by service/action/date range.
     *
     * Returns a structured array matching the MCP tool's output so the tool
     * can stay a one-liner. `total_count` ignores the `$limit` cap.
     *
     * @return array{service: string, action: ?string, total_count: int, returned_count: int, events: Collection}
     */
    public function filter(
        User $user,
        string $service,
        ?string $action = null,
        ?string $from = null,
        ?string $to = null,
        int $limit = self::LIMIT_DEFAULT,
    ): array {
        $limit = max(1, min($limit, self::LIMIT_MAX));
        $integrationIds = $user->integrations()->pluck('id')->all();

        if (empty($integrationIds)) {
            return [
                'service' => $service,
                'action' => $action,
                'total_count' => 0,
                'returned_count' => 0,
                'events' => Event::query()->whereRaw('1 = 0')->get(),
            ];
        }

        $query = Event::query()
            ->whereIn('integration_id', $integrationIds)
            ->where('service', $service)
            ->with(['actor', 'target', 'blocks', 'tags']);

        if ($action) {
            $query->where('action', $action);
        }

        $dateRange = $this->parseDateRange($from, $to);
        if ($dateRange) {
            $query->whereBetween('time', $dateRange);
        }

        $totalCount = (clone $query)->count();

        $events = $query->orderBy('time', 'desc')->limit($limit)->get();

        return [
            'service' => $service,
            'action' => $action,
            'total_count' => $totalCount,
            'returned_count' => $events->count(),
            'events' => $events,
        ];
    }

    /**
     * Build a base query for a user's events — used by the mobile `/feed` endpoint
     * which paginates via cursor rather than counting totals.
     *
     * When `$domain` is provided the result is scoped to that domain and, for
     * the `knowledge` domain, blocks are eager-loaded so the enrichment fields
     * (`tldr`, `target.media_url`) can be resolved without extra queries.
     *
     * @return Builder<Event>
     */
    public function query(User $user, ?string $domain = null, ?Carbon $date = null): Builder
    {
        $integrationIds = $user->integrations()->pluck('id')->all();

        $eagerLoads = ['actor', 'target', 'integration', 'tags', 'blocks'];

        $query = Event::query()
            ->whereIn('integration_id', empty($integrationIds) ? [-1] : $integrationIds)
            ->withCount('blocks')
            ->with($eagerLoads);

        if ($domain !== null) {
            $query->where('domain', $domain);
        }

        if ($date !== null) {
            $query->whereBetween('time', [$date->copy()->startOfDay(), $date->copy()->endOfDay()]);
        } else {
            $query->where('time', '<=', now());
        }

        return $query;
    }
}
