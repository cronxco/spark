<?php

namespace App\Services\Money;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\TaskExecution;
use Spatie\Activitylog\Models\Activity;

/**
 * Merges one GoCardless account object into another while keeping a manifest of everything moved.
 */
class GoCardlessAccountMerger
{
    /**
     * Returns why two account objects must not be merged, or null when they are compatible.
     *
     * A bank's `resourceId` can change when a consent is renewed, so a difference there is tolerated
     * only when the provider identity is verified (see {@see self::resourceHistoryDiffers()}). Any
     * other conflicting identity field blocks the merge.
     */
    public function conflict(EventObject $canonical, EventObject $duplicate): ?string
    {
        foreach (['currency', 'provider', 'account_number', 'account_type', 'sort_code', 'interest_rate', 'start_date', 'is_negative_balance'] as $field) {
            $left = $canonical->metadata[$field] ?? null;
            $right = $duplicate->metadata[$field] ?? null;
            if ($left !== null && $right !== null && $left !== $right) {
                return "Conflicting {$field} on {$canonical->id} and {$duplicate->id}";
            }
        }

        foreach (['iban', 'maskedPan', 'currency', 'ownerName', 'cashAccountType', 'resourceId'] as $field) {
            $left = $canonical->metadata['raw'][$field] ?? null;
            $right = $duplicate->metadata['raw'][$field] ?? null;
            if ($left && $right && $left !== $right) {
                if ($field === 'resourceId' && $this->hasMatchingProviderIdentity($canonical, $duplicate)) {
                    continue;
                }

                return "Conflicting bank identity on {$canonical->id} and {$duplicate->id}";
            }
        }

        return null;
    }

    /**
     * Whether the two objects carry different bank resource ids but are verified as the same account.
     */
    public function resourceHistoryDiffers(EventObject $canonical, EventObject $duplicate): bool
    {
        $left = $canonical->metadata['raw']['resourceId'] ?? null;
        $right = $duplicate->metadata['raw']['resourceId'] ?? null;

        return $left && $right && $left !== $right && $this->hasMatchingProviderIdentity($canonical, $duplicate);
    }

    private function hasMatchingProviderIdentity(EventObject $canonical, EventObject $duplicate): bool
    {
        if ($canonical->user_id !== $duplicate->user_id) {
            return false;
        }
        foreach (['account_id', 'integration_id', 'provider', 'currency', 'account_number'] as $field) {
            $left = $canonical->metadata[$field] ?? null;
            $right = $duplicate->metadata[$field] ?? null;
            if (! is_string($left) || trim($left) === '' || trim($left) === 'unknown' || $left !== $right) {
                return false;
            }
        }

        return true;
    }

    public function merge(EventObject $canonical, EventObject $duplicate): void
    {
        $owner = $canonical->user_id;
        $morph = $duplicate->getMorphClass();
        $actorEvents = Event::withTrashed()->where('actor_id', $duplicate->id)->pluck('id')->all();
        $targetEvents = Event::withTrashed()->where('target_id', $duplicate->id)->pluck('id')->all();
        $relationships = Relationship::withTrashed()->where('user_id', $owner)
            ->where(fn ($query) => $query->where(fn ($q) => $q->where('from_type', $morph)->where('from_id', $duplicate->id))
                ->orWhere(fn ($q) => $q->where('to_type', $morph)->where('to_id', $duplicate->id)))->get();
        $tasks = TaskExecution::where('user_id', $owner)->where('entity_type', 'object')->where('entity_id', $duplicate->id)->get();
        $metadata = $duplicate->metadata ?? [];
        $metadata['merged_into'] = $canonical->id;
        $metadata['merged_at'] = now()->toISOString();
        $metadata['gocardless_merge_manifest'] = [
            'actor_events' => $actorEvents, 'target_events' => $targetEvents,
            'relationships' => $relationships->toArray(), 'tasks' => $tasks->toArray(),
            'media' => $duplicate->media()->pluck('id')->all(),
            'bank_identity' => [
                'canonical' => $canonical->metadata['raw'] ?? [],
                'duplicate' => $duplicate->metadata['raw'] ?? [],
            ],
        ];
        $duplicate->update(['metadata' => $metadata]);
        Event::withTrashed()->whereIn('id', $actorEvents)->update(['actor_id' => $canonical->id]);
        Event::withTrashed()->whereIn('id', $targetEvents)->update(['target_id' => $canonical->id]);
        foreach ($relationships as $relationship) {
            if ($relationship->from_type === $morph && $relationship->from_id === $duplicate->id) {
                $relationship->from_id = $canonical->id;
            }
            if ($relationship->to_type === $morph && $relationship->to_id === $duplicate->id) {
                $relationship->to_id = $canonical->id;
            }
            $relationship->save();
        }
        foreach ($tasks as $task) {
            $existing = TaskExecution::where('entity_type', 'object')->where('entity_id', $canonical->id)
                ->where('task_key', $task->task_key)->first();
            if ($existing) {
                // Keep conflicting task state on the retired alias; its complete
                // snapshot is also retained in the manifest. Never discard history.
                continue;
            }
            $task->update(['entity_id' => $canonical->id]);
        }
        $canonical->tags()->syncWithoutDetaching($duplicate->tags->modelKeys());
        $duplicate->media()->update(['model_id' => $canonical->id]);
        Activity::where('subject_type', $morph)->where('subject_id', $duplicate->id)
            ->where('event', 'viewed')->where('causer_id', $owner)->update(['subject_id' => $canonical->id]);
        $merged = $canonical->metadata ?? [];
        foreach (['sort_code', 'interest_rate', 'start_date', 'is_negative_balance'] as $field) {
            if (! array_key_exists($field, $merged) && array_key_exists($field, $metadata)) {
                $merged[$field] = $metadata[$field];
            }
        }
        $merged['is_pinned'] = ($merged['is_pinned'] ?? false) || ($metadata['is_pinned'] ?? false);
        $merged['gocardless_account_ids'] = array_values(array_unique(array_filter(
            array_merge(
                $merged['gocardless_account_ids'] ?? [],
                $metadata['gocardless_account_ids'] ?? [],
                [$merged['account_id'] ?? null, $metadata['account_id'] ?? null],
            ),
            fn ($id) => is_string($id) && $id !== '' && $id !== 'unknown',
        )));
        $merged['gocardless_resource_ids'] = array_values(array_unique(array_filter(array_merge(
            $merged['gocardless_resource_ids'] ?? [],
            $metadata['gocardless_resource_ids'] ?? [],
            [$merged['raw']['resourceId'] ?? null, $metadata['raw']['resourceId'] ?? null],
        ), fn ($id) => is_string($id) && trim($id) !== '')));
        $canonical->update(['metadata' => $merged]);
        Integration::where('user_id', $owner)->where('service', 'gocardless')
            ->whereIn('configuration->account_id', $merged['gocardless_account_ids'])->get()->each(function ($integration) use ($canonical) {
                $config = $integration->configuration ?? [];
                $config['account_object_id'] = $canonical->id;
                $integration->update(['configuration' => $config]);
            });
        $duplicate->delete();
    }
}
