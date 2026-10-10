<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\TaskExecution;
use App\Models\User;
use App\Services\GoCardlessAccounts;
use Illuminate\Console\Command;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;

class ReconcileGoCardlessAccounts extends Command
{
    protected $signature = 'gocardless:reconcile-accounts {--user= : Required owner UUID} {--apply : Apply the reviewed plan; default is read-only}';

    protected $description = 'Consolidate verified GoCardless account duplicates while preserving history and retired aliases';

    public function handle(GoCardlessAccounts $accounts): int
    {
        $userId = $this->option('user');
        if (! $userId || ! User::whereKey($userId)->exists()) {
            $this->error('Provide a valid --user UUID. No records were changed.');

            return self::FAILURE;
        }
        $run = function () use ($userId, $accounts) {
            $objects = EventObject::where('user_id', $userId)->where('concept', 'account')
                ->where('type', 'bank_account')->orderBy('created_at')->orderBy('id')->get();
            foreach ($objects->groupBy(fn ($object) => $accounts->canonical($object)?->id ?? 'unknown') as $id => $members) {
                if ($id === 'unknown' || $id === '') {
                    foreach ($members as $object) {
                        $this->line("Quarantine unidentified account {$object->id} ({$object->title}); keep all history.");
                        if ($this->option('apply')) {
                            $metadata = $object->metadata ?? [];
                            $metadata['gocardless_quarantined'] = true;
                            $object->update(['metadata' => $metadata]);
                        }
                    }

                    continue;
                }
                $canonical = $members->first();
                foreach ($members->skip(1) as $duplicate) {
                    $this->assertCompatible($canonical, $duplicate);
                    $this->line("Merge {$duplicate->id} ({$duplicate->title}) into {$canonical->id} ({$canonical->title}).");
                    if ($this->option('apply')) {
                        $this->merge($canonical, $duplicate);
                    }
                }
            }
        };
        try {
            if ($this->option('apply')) {
                // Every merge is revalidated under the same owner lock as syncing.
                $accounts->locked($userId, $run);
            } else {
                $run();
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage() . ' No repair was committed.');

            return self::FAILURE;
        }
        $this->info($this->option('apply') ? 'Reconciliation committed.' : 'Dry run only. Review this plan before repeating with --apply.');

        return self::SUCCESS;
    }

    private function assertCompatible(EventObject $canonical, EventObject $duplicate): void
    {
        foreach (['currency', 'provider', 'account_number', 'account_type', 'sort_code', 'interest_rate', 'start_date', 'is_negative_balance'] as $field) {
            $left = $canonical->metadata[$field] ?? null;
            $right = $duplicate->metadata[$field] ?? null;
            if ($left !== null && $right !== null && $left !== $right) {
                throw new RuntimeException("Conflicting {$field} on {$canonical->id} and {$duplicate->id}; resolve before merging.");
            }
        }
        foreach (['iban', 'maskedPan', 'currency', 'ownerName', 'cashAccountType', 'resourceId'] as $field) {
            $left = $canonical->metadata['raw'][$field] ?? null;
            $right = $duplicate->metadata['raw'][$field] ?? null;
            if ($left && $right && $left !== $right) {
                if ($field === 'resourceId' && $this->hasMatchingProviderIdentity($canonical, $duplicate)) {
                    $this->line("Resource ID history differs on {$canonical->id} and {$duplicate->id}; verified provider identity matches.");

                    continue;
                }
                throw new RuntimeException("Conflicting bank identity on {$canonical->id} and {$duplicate->id}; manual review required.");
            }
        }
    }

    private function hasMatchingProviderIdentity(EventObject $canonical, EventObject $duplicate): bool
    {
        if ($canonical->user_id !== $duplicate->user_id) {
            return false;
        }
        foreach (['account_id', 'integration_id', 'provider', 'currency', 'account_number'] as $field) {
            $left = $canonical->metadata[$field] ?? null;
            $right = $duplicate->metadata[$field] ?? null;
            if (! is_string($left) || trim($left) === '' || $left === 'unknown' || $left !== $right) {
                return false;
            }
        }

        return true;
    }

    private function merge(EventObject $canonical, EventObject $duplicate): void
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
        $merged['gocardless_account_ids'] = array_values(array_unique(array_merge(
            $merged['gocardless_account_ids'] ?? [], $metadata['gocardless_account_ids'] ?? [], [$merged['account_id'], $metadata['account_id']])));
        $merged['gocardless_resource_ids'] = array_values(array_unique(array_filter(array_merge(
            $merged['gocardless_resource_ids'] ?? [], $metadata['gocardless_resource_ids'] ?? [],
            [$merged['raw']['resourceId'] ?? null, $metadata['raw']['resourceId'] ?? null]
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
