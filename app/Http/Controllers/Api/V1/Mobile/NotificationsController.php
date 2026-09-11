<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\ListNotificationFeedRequest;
use App\Http\Resources\Compact\CompactNotificationResource;
use App\Services\Api\ResourceVersion;
use App\Services\Notifications\NotificationFeedService;
use App\Support\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    public function __construct(
        private ResourceVersion $versions,
        private NotificationFeedService $feed,
    ) {}

    public function feed(ListNotificationFeedRequest $request): JsonResponse
    {
        return response()->json($this->feed->feed(
            user: $request->user(),
            scope: (string) $request->validated('scope', 'active'),
            stream: $request->validated('stream'),
            search: $request->validated('search'),
            cursor: $request->validated('cursor'),
            limit: (int) $request->validated('limit', 25),
        ));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $item = $this->feed->detail($request->user(), $id);

        return $item === null
            ? response()->json(['message' => 'Notification not found.'], 404)
            : response()->json(['data' => $item]);
    }

    /**
     * GET /api/v1/mobile/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $cursor = $request->query('cursor');
        $limit = (int) $request->query('limit', CursorPaginator::DEFAULT_LIMIT);

        [$notifications, $nextCursor, $hasMore] = CursorPaginator::paginate(
            $request->user()->notifications()->whereNull('archived_at')->getQuery(),
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
        $notification = $request->user()->notifications()->whereNull('archived_at')->find($id);

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->markAsRead();

        return response()->json(null, 204)->header('ETag', $this->versions->etag($notification->fresh()));
    }

    public function markUnread(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereNull('archived_at')->find($id);

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->markAsUnread();

        return response()->json(null, 204)->header('ETag', $this->versions->etag($notification->fresh()));
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereNull('archived_at')->find($id);

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $data = is_array($notification->data) ? $notification->data : [];
        $notification->forceFill([
            'archived_at' => now(),
            'data' => [...$data, 'archive_reason' => 'manual'],
        ])->save();

        return response()->json(null, 204)->header('ETag', $this->versions->etag($notification->fresh()));
    }

    /**
     * POST /api/v1/mobile/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->whereNull('archived_at')->update(['read_at' => now()]);
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

        // Computed before delete(): the row is gone afterward, and
        // ResourceVersion::etag() falls back to a live query for any model
        // that doesn't already have `xmin` loaded.
        $etag = $this->versions->etag($notification);
        $notification->delete();

        return response()->json(null, 204)->header('ETag', $etag);
    }
}
