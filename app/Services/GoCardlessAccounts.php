<?php

namespace App\Services;

use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GoCardlessAccounts
{
    public static function normalize(array $response, string $accountId): array
    {
        if ($accountId === '' || $accountId === 'unknown') {
            throw new InvalidArgumentException('A real GoCardless account ID is required.');
        }

        $account = $response['account'] ?? $response;
        $account['id'] = $accountId;

        return $account;
    }

    public function find(string $userId, string $accountId): ?EventObject
    {
        if ($accountId === '' || $accountId === 'unknown') {
            return null;
        }

        return EventObject::where('user_id', $userId)
            ->where('concept', 'account')->where('type', 'bank_account')
            ->where(function ($query) use ($accountId) {
                $query->where('metadata->account_id', $accountId)
                    ->orWhereJsonContains('metadata->gocardless_account_ids', $accountId);
            })
            ->orderBy('created_at')->orderBy('id')->first();
    }

    public function memberIds(EventObject $account): array
    {
        $id = $account->metadata['account_id'] ?? '';
        if ($account->type !== 'bank_account' || $id === '' || $id === 'unknown') {
            return [$account->id];
        }

        $ids = array_values(array_unique(array_merge(
            $account->metadata['gocardless_account_ids'] ?? [], [$id])));

        return EventObject::where('user_id', $account->user_id)
            ->where('concept', 'account')->where('type', 'bank_account')
            ->where(function ($query) use ($ids, $account) {
                $query->whereIn('metadata->account_id', $ids)
                    ->orWhere('metadata->merged_into', $account->id);
                foreach ($ids as $id) {
                    $query->orWhereJsonContains('metadata->gocardless_account_ids', $id);
                }
            })->pluck('id')->all();
    }

    public function canonical(EventObject $account): ?EventObject
    {
        if ($account->type !== 'bank_account') {
            return $account;
        }
        if ($account->metadata['merged_into'] ?? null) {
            return EventObject::where('user_id', $account->user_id)->where('concept', 'account')
                ->where('type', 'bank_account')->find($account->metadata['merged_into']);
        }
        if ($account->metadata['gocardless_quarantined'] ?? false) {
            return null;
        }

        return $this->find($account->user_id, $account->metadata['account_id'] ?? '');
    }

    // Lock the existing owner row: all GoCardless account/instance writers use
    // this transaction, including first creation where no account row exists.
    public function locked(string $userId, Closure $operation): mixed
    {
        return DB::transaction(function () use ($userId, $operation) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();

            return $operation();
        });
    }

    public function resolve(Integration $integration, string $accountId, Closure $write): ?EventObject
    {
        if ($accountId === '' || $accountId === 'unknown' || $integration->service !== 'gocardless') {
            throw new InvalidArgumentException('Cannot resolve an unidentified GoCardless account.');
        }

        return $this->locked($integration->user_id, function () use ($integration, $accountId, $write) {
            $current = Integration::findOrFail($integration->id);
            if (($current->configuration['account_id'] ?? null) !== $accountId) {
                return null;
            }
            $object = $write($this->find($integration->user_id, $accountId));
            if ($object) {
                $config = $current->configuration ?? [];
                $config['account_object_id'] = $object->id;
                $current->update(['configuration' => $config]);
                $integration->configuration = $config;
            }

            return $object;
        });
    }

    public function createInstance(IntegrationGroup $group, string $type, array $config, Closure $create): Integration
    {
        $id = (string) ($config['account_id'] ?? '');
        if ($id === '' || $id === 'unknown') {
            throw new InvalidArgumentException('Cannot link an unidentified account.');
        }

        return $this->locked($group->user_id, function () use ($group, $type, $config, $id, $create) {
            return Integration::where('user_id', $group->user_id)
                ->where('integration_group_id', $group->id)
                ->where('service', 'gocardless')->where('instance_type', $type)
                ->where('configuration->account_id', $id)->first() ?? $create($config);
        });
    }

    public function setPaused(Integration $integration, bool $paused): void
    {
        $this->locked($integration->user_id, function () use ($integration, $paused) {
            $integration->refresh();
            $config = $integration->configuration ?? [];
            $config['paused'] = $paused;
            $config['gocardless_pause_reason'] = $paused ? 'user' : null;
            $integration->update(['configuration' => $config]);
        });
    }

    public static function snapshot(Integration $integration): array
    {
        return [
            'account_id' => $integration->configuration['account_id'] ?? null,
            'requisition_id' => $integration->group?->account_id,
            'generation' => $integration->group?->auth_metadata['gocardless_generation'] ?? null,
        ];
    }

    public static function isCurrent(Integration $integration, ?array $snapshot): bool
    {
        $current = Integration::with('group')->find($integration->id);
        if (! $current || ($current->configuration['paused'] ?? false) ||
            ($current->group?->auth_metadata['eua_expired'] ?? false)) {
            return false;
        }

        // Jobs queued before this deployment have no snapshot. They may run
        // only before the first renewal using the new generation contract.
        if ($snapshot === null) {
            return empty($current->group?->auth_metadata['gocardless_generation']);
        }

        return self::snapshot($current) === $snapshot;
    }
}
