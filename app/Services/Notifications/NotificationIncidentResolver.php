<?php

namespace App\Services\Notifications;

use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Notifications\NotificationCatalogue;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

class NotificationIncidentResolver
{
    public function __construct(private NotificationArchiver $archiver) {}

    /** @param array<int, string> $groupKeys */
    public function resolve(User $user, array $groupKeys, ?DateTimeInterface $recoveredAt = null): int
    {
        $cutoff = CarbonImmutable::instance($recoveredAt ?? now());
        $resolved = 0;
        foreach ($user->notifications()->whereNull('archived_at')->whereIn('group_key', $groupKeys)->get() as $notification) {
            if ($this->archiver->archive($notification, 'resolved', fn ($current) =>
                $current->archived_at === null && NotificationOccurrence::last($current)->lte($cutoff)) !== null) {
                $resolved++;
            }
        }

        return $resolved;
    }

    public function reconcileEntity(Integration|EventObject $entity): void
    {
        $types = $entity instanceof Integration
            ? ['integration_failed', 'integration_authentication_failed', 'migration_failed']
            : ['fetch_multiple_failures'];
        $keys = array_map(fn ($type) => "{$type}:{$entity->id}", $types);
        $user = $entity->user;
        if ($user === null) {
            return;
        }
        foreach ($user->notifications()->whereNull('archived_at')->whereIn('group_key', $keys)->get() as $notification) {
            $this->reconcile($notification);
        }
    }

    public function reconcile(DatabaseNotification $notification, bool $dryRun = false): bool
    {
        $reason = $this->archiveReason($notification);
        if ($reason === null) {
            return false;
        }
        if ($dryRun) {
            return true;
        }

        // Recheck under the row lock: a newer failure may have coalesced since
        // selection. A stale success must not clear that newer failure.
        return $this->archiver->archive($notification, $reason, fn ($current) =>
            $current->archived_at === null && $this->archiveReason($current) === $reason) !== null;
    }

    public function supersedeDigests(DatabaseNotification $latest): void
    {
        $period = $latest->data['period'] ?? null;
        if ($latest->type !== 'daily_digest' || $latest->archived_at !== null || ! is_string($period)) {
            return;
        }
        $occurredAt = NotificationOccurrence::last($latest);
        DatabaseNotification::query()
            ->where('notifiable_type', $latest->notifiable_type)
            ->where('notifiable_id', $latest->notifiable_id)
            ->where('type', 'daily_digest')->whereNull('archived_at')->whereKeyNot($latest->id)
            ->get()->each(function ($notification) use ($period, $occurredAt) {
                $this->archiver->archive($notification, 'superseded', fn ($current) =>
                    $current->archived_at === null && ($current->data['period'] ?? null) === $period
                    && NotificationOccurrence::last($current)->lte($occurredAt));
            });
    }

    private function archiveReason(DatabaseNotification $notification): ?string
    {
        if ($notification->archived_at !== null) {
            return null;
        }
        $data = $notification->data;
        $type = $data['type'] ?? $notification->type;
        $occurredAt = NotificationOccurrence::last($notification);
        $hours = NotificationCatalogue::activeHoursFor($type);
        if ($hours !== null && $occurredAt->lte(now()->subHours($hours))) {
            return 'expired';
        }

        $id = $data['entity_id'] ?? data_get($data, 'entity.id') ?? Str::after((string) $notification->group_key, ':');
        if (! Str::isUuid($id)) {
            // Old domain-wide Fetch warnings cannot identify a tracked page.
            // Retain history without pretending recovery was verified.
            return $type === 'fetch_multiple_failures' && $occurredAt->lte(now()->subDay())
                ? 'legacy_unverified' : null;
        }

        if (in_array($type, ['integration_failed', 'integration_authentication_failed', 'migration_failed'], true)) {
            $integration = Integration::withTrashed()->where('user_id', $notification->notifiable_id)->find($id);
            if ($integration === null || $integration->trashed()) {
                return 'entity_removed';
            }
            // A regular sync does not prove a historical migration completed.
            if ($type !== 'migration_failed' && $integration->last_successful_update_at?->gt($occurredAt)) {
                return 'resolved';
            }
        }

        if ($type === 'fetch_multiple_failures') {
            $object = EventObject::withTrashed()->where('user_id', $notification->notifiable_id)->find($id);
            if ($object === null || $object->trashed()) {
                return 'entity_removed';
            }
            $metadata = $object->metadata ?? [];
            $checkedAt = $metadata['last_checked_at'] ?? null;
            if (array_key_exists('last_error', $metadata) && $metadata['last_error'] === null
                && $checkedAt !== null && CarbonImmutable::parse($checkedAt)->gt($occurredAt)) {
                return 'resolved';
            }
        }

        return null;
    }
}
