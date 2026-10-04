<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\ListNotificationFeedRequest;
use App\Http\Requests\Api\V1\Mobile\RecordNotificationReceiptRequest;
use App\Http\Requests\Api\V1\Mobile\RecordNotificationReceiptsRequest;
use App\Http\Resources\Compact\CompactNotificationResource;
use App\Services\Api\ResourceVersion;
use App\Services\Notifications\NotificationArchiver;
use App\Services\Notifications\NotificationFeedService;
use App\Services\Notifications\NotificationReceiptRecorder;
use App\Support\CursorPaginator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NotificationsController extends Controller
{
    public function __construct(
        private ResourceVersion $versions,
        private NotificationFeedService $feed,
        private NotificationArchiver $archiver,
        private NotificationReceiptRecorder $receipts,
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
        ))->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $item = $this->feed->detail($request->user(), $id);

        $response = $item === null
            ? response()->json(['message' => 'Notification not found.'], 404)
            : response()->json(['data' => $item]);

        return $response->header('Cache-Control', 'no-store');
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

        $notification = $this->archiver->archive($notification, 'manual');

        return response()->json(null, 204)->header('ETag', $this->versions->etag($notification));
    }

    /**
     * POST /api/v1/mobile/notifications/{id}/receipts
     *
     * Records that the app showed, opened or tapped one of the user's own
     * notifications. Idempotent per event: the first time wins.
     */
    public function recordReceipt(RecordNotificationReceiptRequest $request, string $id): JsonResponse
    {
        $notification = Str::isUuid($id) ? $request->user()->notifications()->find($id) : null;

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $this->receipts->record(
            $notification,
            (string) $request->validated('event'),
            Carbon::parse((string) $request->validated('occurred_at')),
            $request->validated('action'),
        );

        return response()->json(null, 204);
    }

    /**
     * POST /api/v1/mobile/notifications/receipts
     *
     * A batch of receipts, so the app can flush the ones it queued offline.
     * Receipts for notifications that are not the user's are ignored and
     * counted, never stored.
     */
    public function recordReceipts(RecordNotificationReceiptsRequest $request): JsonResponse
    {
        /** @var array<int, array{notification_id: string, event: string, occurred_at: string, action?: string|null}> $receipts */
        $receipts = $request->validated('receipts');

        $notifications = $request->user()->notifications()
            ->whereIn('id', array_values(array_unique(array_map(strtolower(...), array_column($receipts, 'notification_id')))))
            ->get()
            ->keyBy('id');

        $counts = ['recorded' => 0, 'unchanged' => 0, 'not_found' => 0];
        foreach ($receipts as $receipt) {
            $notification = $notifications->get(strtolower($receipt['notification_id']));
            if ($notification === null) {
                $counts['not_found']++;

                continue;
            }

            $recorded = $this->receipts->record(
                $notification,
                $receipt['event'],
                Carbon::parse($receipt['occurred_at']),
                $receipt['action'] ?? null,
            );
            $counts[$recorded ? 'recorded' : 'unchanged']++;
        }

        return response()->json(['data' => $counts]);
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
