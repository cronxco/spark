<?php

namespace App\Observers;

use App\Models\EventObject;
use App\Models\Integration;
use App\Services\Notifications\NotificationIncidentResolver;

class NotificationEntityObserver
{
    public function updated(Integration|EventObject $entity): void
    {
        if (($entity instanceof Integration && $entity->wasChanged('last_successful_update_at'))
            || ($entity instanceof EventObject && $entity->wasChanged('metadata')
                && array_key_exists('last_checked_at', $entity->metadata ?? [])
                && array_key_exists('last_error', $entity->metadata ?? [])
                && $entity->metadata['last_error'] === null)) {
            $this->afterCommit($entity);
        }
    }

    private function afterCommit(Integration|EventObject $entity): void
    {
        // Capture changes before another save mutates the model's change set.
        $snapshot = clone $entity;
        $entity->getConnection()->afterCommit(fn () =>
            app(NotificationIncidentResolver::class)->reconcileEntity($snapshot));
    }

    public function deleted(Integration|EventObject $entity): void
    {
        $this->afterCommit($entity);
    }
}
