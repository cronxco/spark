<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactEventResource;
use App\Integrations\PluginRegistry;
use App\Services\Mobile\EventFeed;
use App\Support\CursorPaginator;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class FeedController extends Controller
{
    public function __construct(protected EventFeed $eventFeed) {}

    /**
     * GET /api/v1/mobile/feed
     *
     * Cursor-paginated reverse-chronological feed of the user's events.
     * `cursor` is opaque (issued by a prior response); `limit` caps at 100.
     */
    public function index(Request $request): JsonResponse
    {
        $domain = $request->query('domain');

        if ($domain !== null && ! in_array($domain, PluginRegistry::getValidDomains(), true)) {
            return response()->json([
                'message' => "Unknown domain: {$domain}.",
                'hint' => 'Valid domains: ' . implode(', ', PluginRegistry::getValidDomains()),
            ], 422);
        }

        $dateParam = $request->query('date');
        $date = null;

        if ($dateParam !== null) {
            try {
                $parsed = Carbon::createFromFormat('Y-m-d', is_string($dateParam) ? $dateParam : '');
                if ($parsed === false || $parsed->format('Y-m-d') !== $dateParam) {
                    throw new InvalidArgumentException;
                }
                $date = $parsed;
            } catch (Exception) {
                return response()->json(['message' => 'Invalid date. Use YYYY-MM-DD format.'], 422);
            }
        }

        $cursor = $request->query('cursor');
        $limit = (int) $request->query('limit', CursorPaginator::DEFAULT_LIMIT);

        [$events, $nextCursor, $hasMore] = CursorPaginator::paginate(
            $this->eventFeed->query($request->user(), is_string($domain) ? $domain : null, $date),
            is_string($cursor) && $cursor !== '' ? $cursor : null,
            $limit,
            timeColumn: 'time',
            idColumn: 'id',
        );

        $payload = [
            'data' => CompactEventResource::collection($events)->resolve($request),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];

        $response = response()->json($payload);

        $lastModified = $events->max('updated_at') ?? $events->first()?->time;
        if ($lastModified) {
            $response->header('Last-Modified', $lastModified->toRfc7231String());
        }

        return $response;
    }
}
