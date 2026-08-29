<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactNotificationResource;
use App\Services\Api\ResourceVersion;
use App\Support\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    public function __construct(private ResourceVersion $versions) {}

    /**
     * GET /api/v1/mobile/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $cursor = $request->query('cursor');
        $limit = (int) $request->query('limit', CursorPaginator::DEFAULT_LIMIT);

        [$notifications, $nextCursor, $hasMore] = CursorPaginator::paginate(
            $request->user()->notifications()->getQuery(),
            is_string($cursor) && $cursor !== '' ? $cursor : null,
            $limit,
            timeColumn: 'created_at',
            idColumn: 'id',
        );

        $response = response()->json([
            'data' => CompactNotificationResource::collection($notifications)->resolve($request),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ]);

        $lastModified = $notifications->max('updated_at') ?? $notifications->first()?->created_at;
        if ($lastModified) {
            $response->header('Last-Modified', $lastModified->toRfc7231String());
        }

        return $response;
    }

    /**
     * POST /api/v1/mobile/notifications/{id}/read
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->find($id);

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->markAsRead();

        return response()->json(null, 204)->header('ETag', $this->versions->etag($notification->fresh()));
    }

    /**
     * POST /api/v1/mobile/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);
        $request->user()->touch();

        return response()->json(null, 204)->header('ETag', $this->versions->etag($request->user()->fresh()));
    }

    /**
     * DELETE /api/v1/mobile/notifications/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->find($id);

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->delete();

        return response()->json(null, 204)->header('ETag', $this->versions->etag($notification));
    }
}
