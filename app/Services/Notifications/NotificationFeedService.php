<?php

namespace App\Services\Notifications;

use App\Models\ActionProgress;
use App\Models\User;
use App\Notifications\NotificationCatalogue;
use App\Services\Api\ResourceVersion;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Throwable;

class NotificationFeedService
{
    public function __construct(private ResourceVersion $versions) {}

    /**
     * @return array{data: array<int, array<string, mixed>>, next_cursor: ?string, has_more: bool, counts: array<string, mixed>}
     */
    public function feed(
        User $user,
        string $scope = 'active',
        ?string $stream = null,
        ?string $search = null,
        ?string $cursor = null,
        int $limit = 25,
    ): array {
        $limit = max(1, min($limit, 50));
        $boundary = $this->decodeCursor($cursor);

        $notifications = $this->notificationCandidates($user, $scope, $stream, $search, $boundary, $limit);
        $activities = $this->activityCandidates($user, $scope, $stream, $search, $boundary, $limit);

        $items = $notifications
            ->concat($activities)
            ->sort($this->compareItems(...))
            ->values();

        $hasMore = $items->count() > $limit
            || $notifications->count() > $limit
            || $activities->count() > $limit;
        $page = $items->take($limit)->values();
        $nextCursor = $hasMore && $page->isNotEmpty()
            ? $this->encodeCursor($page->last())
            : null;

        return [
            'data' => $page->map(fn (array $item): array => $this->withoutSortMetadata($item))->all(),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'counts' => $this->counts($user),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(User $user, string $id): ?array
    {
        if (str_starts_with($id, 'activity:')) {
            $progress = ActionProgress::query()
                ->where('user_id', $user->id)
                ->find(substr($id, strlen('activity:')));

            if ($progress === null) {
                return null;
            }

            return [
                ...$this->withoutSortMetadata($this->activityItem($progress)),
                'technical_detail' => $progress->isFailed()
                    ? $this->sanitiseTechnicalDetail($progress->error_message)
                    : null,
                'updates' => $progress->updates ?? [],
            ];
        }

        $notification = $user->notifications()->find($id);
        if ($notification === null) {
            return null;
        }

        $data = is_array($notification->data) ? $notification->data : [];

        return [
            ...$this->withoutSortMetadata($this->notificationItem($notification)),
            'technical_detail' => $this->sanitiseTechnicalDetail($data['technical_detail'] ?? null),
            'updates' => [],
        ];
    }

    /** @param array{time: CarbonImmutable, source: string, id: string}|null $boundary */
    private function notificationCandidates(
        User $user,
        string $scope,
        ?string $stream,
        ?string $search,
        ?array $boundary,
        int $limit,
    ): Collection {
        if ($stream === 'activity' && NotificationCatalogue::typesForStream('activity') === []) {
            return collect();
        }

        $query = $user->notifications()->getQuery()
            ->when($scope === 'history', fn ($query) => $query->whereNotNull('archived_at'))
            ->when($scope !== 'history', fn ($query) => $query->whereNull('archived_at'))
            ->when($stream !== null, function ($query) use ($stream) {
                $types = NotificationCatalogue::typesForStream($stream);
                $query->whereIn('type', $types === [] ? ['__none__'] : $types);
            })
            ->when($search !== null && $search !== '', fn ($query) => $query->where('data', 'ilike', '%' . addcslashes($search, '%_') . '%'));

        $this->applyBoundary($query, 'created_at', 'id', 'notification', $boundary);

        return $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => $this->notificationItem($notification));
    }

    /** @param array{time: CarbonImmutable, source: string, id: string}|null $boundary */
    private function activityCandidates(
        User $user,
        string $scope,
        ?string $stream,
        ?string $search,
        ?array $boundary,
        int $limit,
    ): Collection {
        if ($stream !== null && $stream !== 'activity') {
            return collect();
        }

        $recentCutoff = now()->subDay();
        $historyCutoff = now()->subDays(30);
        $query = ActionProgress::query()
            ->where('user_id', $user->id)
            ->when($scope === 'history', function ($query) use ($recentCutoff, $historyCutoff) {
                $query->where(function ($query) {
                    $query->whereNotNull('completed_at')->orWhereNotNull('failed_at');
                })->where('updated_at', '<=', $recentCutoff)
                    ->where('updated_at', '>=', $historyCutoff);
            })
            ->when($scope !== 'history', function ($query) use ($recentCutoff) {
                $query->where(function ($query) use ($recentCutoff) {
                    $query->whereNull('completed_at')->whereNull('failed_at')
                        ->orWhere('updated_at', '>', $recentCutoff);
                });
            })
            ->when($search !== null && $search !== '', fn ($query) => $query->where('message', 'ilike', '%' . addcslashes($search, '%_') . '%'));

        $this->applyBoundary($query, 'updated_at', 'id', 'activity', $boundary);

        return $query->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get()
            ->map(fn (ActionProgress $progress): array => $this->activityItem($progress));
    }

    /** @param array{time: CarbonImmutable, source: string, id: string}|null $boundary */
    private function applyBoundary($query, string $timeColumn, string $idColumn, string $source, ?array $boundary): void
    {
        if ($boundary === null) {
            return;
        }

        $sourceRank = $source === 'notification' ? 0 : 1;
        $boundaryRank = $boundary['source'] === 'notification' ? 0 : 1;

        $query->where(function ($query) use ($timeColumn, $idColumn, $sourceRank, $boundaryRank, $boundary) {
            $query->where($timeColumn, '<', $boundary['time'])
                ->orWhere(function ($query) use ($timeColumn, $idColumn, $sourceRank, $boundaryRank, $boundary) {
                    $query->where($timeColumn, '=', $boundary['time']);
                    if ($sourceRank === $boundaryRank) {
                        $query->where($idColumn, '<', $boundary['id']);
                    } elseif ($sourceRank < $boundaryRank) {
                        $query->whereRaw('1 = 0');
                    }
                });
        });
    }

    /** @return array<string, mixed> */
    private function notificationItem(DatabaseNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $type = $this->stableType($notification, $data);
        $archiveReason = $data['archive_reason'] ?? null;

        return [
            'contract_version' => 1,
            'id' => (string) $notification->id,
            'kind' => 'notification',
            'type' => $type,
            'stream' => $data['stream'] ?? NotificationCatalogue::streamFor($type),
            'severity' => $data['severity'] ?? NotificationCatalogue::severityFor($type),
            'state' => $notification->archived_at === null ? 'active' : match ($archiveReason) {
                'resolved' => 'resolved',
                'expired', 'superseded' => 'expired',
                default => 'archived',
            },
            'title' => (string) ($data['title'] ?? 'Notification'),
            'body' => $data['body'] ?? $data['message'] ?? $data['headline'] ?? null,
            'is_read' => $notification->read_at !== null,
            'occurrence_count' => max(1, (int) ($data['occurrence_count'] ?? 1)),
            'occurred_at' => $notification->created_at?->toJSON(),
            'updated_at' => $notification->updated_at?->toJSON(),
            'archived_at' => $notification->archived_at === null
                ? null
                : CarbonImmutable::parse($notification->archived_at)->toJSON(),
            'entity' => $this->entity($data),
            'destination' => $data['deep_link'] ?? $data['action_url'] ?? null,
            'primary_action' => $this->primaryAction($data),
            'progress' => null,
            'has_technical_detail' => filled($data['technical_detail'] ?? null),
            'version' => $this->versions->etag($notification),
            '_sort_time' => CarbonImmutable::instance($notification->created_at),
            '_source' => 'notification',
        ];
    }

    /** @return array<string, mixed> */
    private function activityItem(ActionProgress $progress): array
    {
        $state = $progress->isFailed() ? 'failed' : ($progress->isCompleted() ? 'completed' : 'active');

        return [
            'contract_version' => 1,
            'id' => 'activity:' . $progress->id,
            'kind' => 'activity',
            'type' => $progress->action_type,
            'stream' => 'activity',
            'severity' => $progress->isFailed() ? 'error' : ($progress->isCompleted() ? 'success' : 'info'),
            'state' => $state,
            'title' => str($progress->action_type)->replace('_', ' ')->title()->toString(),
            'body' => $progress->message,
            'is_read' => ! $progress->isInProgress(),
            'occurrence_count' => 1,
            'occurred_at' => $progress->created_at?->toJSON(),
            'updated_at' => $progress->updated_at?->toJSON(),
            'archived_at' => null,
            'entity' => null,
            'destination' => null,
            'primary_action' => null,
            'progress' => [
                'current' => (int) $progress->progress,
                'total' => max(1, (int) $progress->total),
                'step' => (string) $progress->step,
            ],
            'has_technical_detail' => $progress->isFailed() && filled($progress->error_message),
            'version' => $this->versions->etag($progress),
            '_sort_time' => CarbonImmutable::instance($progress->updated_at),
            '_source' => 'activity',
        ];
    }

    /** @return array<string, mixed> */
    private function counts(User $user): array
    {
        $active = $user->notifications()->whereNull('archived_at');
        $byStream = [];
        foreach (['updates', 'activity', 'attention', 'system'] as $stream) {
            $byStream[$stream] = (clone $active)
                ->whereIn('type', NotificationCatalogue::typesForStream($stream))
                ->count();
        }

        $activeActivities = ActionProgress::query()
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->whereNull('failed_at')
            ->count();
        $byStream['activity'] += $activeActivities;

        return [
            'unread' => (clone $active)->whereNull('read_at')->count(),
            'unresolved_attention' => (clone $active)
                ->whereIn('type', NotificationCatalogue::typesForStream('attention'))
                ->count(),
            'active_activity' => $activeActivities,
            'by_stream' => $byStream,
        ];
    }

    private function stableType(DatabaseNotification $notification, array $data): string
    {
        return (string) ($data['type'] ?? $notification->type);
    }

    /** @return array{kind: string, id: string}|null */
    private function entity(array $data): ?array
    {
        $kind = $data['entity_type'] ?? data_get($data, 'entity.kind');
        $id = $data['entity_id'] ?? data_get($data, 'entity.id');

        return is_string($kind) && $kind !== '' && is_string($id) && $id !== ''
            ? ['kind' => $kind, 'id' => $id]
            : null;
    }

    /** @return array{id: string, label: string}|null */
    private function primaryAction(array $data): ?array
    {
        if (blank($data['deep_link'] ?? null) && blank($data['action_url'] ?? null)) {
            return null;
        }

        return [
            'id' => ($data['type'] ?? null) === 'integration_authentication_failed' ? 'reconnect' : 'view',
            'label' => ($data['type'] ?? null) === 'integration_authentication_failed' ? 'Reconnect' : 'View',
        ];
    }

    private function compareItems(array $left, array $right): int
    {
        $timeComparison = $right['_sort_time'] <=> $left['_sort_time'];
        if ($timeComparison !== 0) {
            return $timeComparison;
        }

        $sourceComparison = ($left['_source'] === 'notification' ? 0 : 1)
            <=> ($right['_source'] === 'notification' ? 0 : 1);

        return $sourceComparison !== 0 ? $sourceComparison : strcmp($right['id'], $left['id']);
    }

    /** @param array<string, mixed> $item */
    private function encodeCursor(array $item): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            'time' => $item['_sort_time']->format('Y-m-d H:i:s.u'),
            'source' => $item['_source'],
            'id' => str_starts_with($item['id'], 'activity:') ? substr($item['id'], 9) : $item['id'],
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{time: CarbonImmutable, source: string, id: string}|null */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(str_pad(strtr($cursor, '-_', '+/'), (int) ceil(strlen($cursor) / 4) * 4, '='), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($payload)
            || ! isset($payload['time'], $payload['source'], $payload['id'])
            || ! in_array($payload['source'], ['notification', 'activity'], true)) {
            return null;
        }

        try {
            return [
                'time' => CarbonImmutable::parse($payload['time']),
                'source' => $payload['source'],
                'id' => (string) $payload['id'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function withoutSortMetadata(array $item): array
    {
        unset($item['_sort_time'], $item['_source']);

        return $item;
    }

    private function sanitiseTechnicalDetail(?string $detail): ?string
    {
        if (blank($detail)) {
            return null;
        }

        $redacted = redact_sensitive_urls(strip_tags($detail));
        $redacted = preg_replace(
            '/(?i)(token|access_token|refresh_token|api_key|key|password|secret)=([^&\s"\'<>]+)/',
            '$1=[REDACTED]',
            $redacted,
        ) ?? $redacted;
        $redacted = preg_replace('/(?i)bearer\s+[a-z0-9._~+\/-]+=*/', 'Bearer [REDACTED]', $redacted) ?? $redacted;

        return str($redacted)->limit(2_000)->toString();
    }
}
