<?php

namespace App\Integrations\GoCardless;

use App\Jobs\GoCardless\RefreshRenewedConnectionJob;
use App\Jobs\GoCardless\RetireRequisitionJob;
use App\Models\IntegrationGroup;
use App\Services\GoCardlessAccounts;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use RuntimeException;

trait GoCardlessRenewal
{
    public function getOAuthUrl(IntegrationGroup $group): string
    {
        if (($group->auth_metadata['gocardless_pending']['requires_mapping'] ?? false) &&
            Carbon::parse($group->auth_metadata['gocardless_pending']['expires_at'])->isFuture()) {
            return route('integrations.gocardless.renewal.show', $group);
        }
        $institution = $group->auth_metadata['gocardless_institution_id']
            ?? $group->auth_metadata['institution_id']
            ?? Session::get('gocardless_institution_id_' . $group->id);
        if (! $institution) {
            throw new RuntimeException('No bank institution selected for this connection.');
        }

        return $this->createNewEuaAndRequisition($group, $institution)['link'];
    }

    public function createNewEuaAndRequisition(IntegrationGroup $group, string $institutionId): array
    {
        return app(GoCardlessAccounts::class)->locked($group->user_id, function () use ($group, $institutionId) {
            $group->refresh();
            if ($group->service !== 'gocardless') {
                throw new RuntimeException('This is not a GoCardless connection.');
            }
            $activeInstitution = $group->auth_metadata['gocardless_institution_id']
                ?? $group->auth_metadata['institution_id'] ?? null;
            if ($activeInstitution && $group->integrations()->exists() && $activeInstitution !== $institutionId) {
                throw new RuntimeException('Renew this connection with its existing bank.');
            }

            $meta = $group->auth_metadata ?? [];
            $pending = $meta['gocardless_pending'] ?? null;
            if ($pending && $pending['institution_id'] === $institutionId &&
                Carbon::parse($pending['expires_at'])->isFuture() && empty($pending['requires_mapping'])) {
                return ['agreement' => ['id' => $pending['agreement_id']],
                    'requisition' => ['id' => $pending['requisition_id']], 'link' => $pending['link']];
            }

            $agreement = $this->createEndUserAgreementWithReconfirmation($institutionId);
            $requisition = $this->createRequisition($institutionId, $agreement['id']);
            if (empty($requisition['id']) || empty($requisition['reference']) || empty($requisition['link'])) {
                throw new RuntimeException('The bank did not return a complete consent attempt.');
            }
            $meta['gocardless_pending'] = [
                'id' => (string) Str::uuid(),
                'institution_id' => $institutionId,
                'agreement_id' => $agreement['id'],
                'requisition_id' => $requisition['id'],
                'reference' => $requisition['reference'],
                'link' => $requisition['link'],
                'expires_at' => now()->addHour()->toISOString(),
            ];
            $group->update(['auth_metadata' => $meta]);

            return ['agreement' => $agreement, 'requisition' => $requisition, 'link' => $requisition['link']];
        });
    }

    public function handleOAuthCallback(Request $request, IntegrationGroup $group): void
    {
        $this->completeRenewal($group, (string) $request->query('ref'));
    }

    /** Returns false when the user must map accounts before consent is promoted. */
    public function completeRenewal(IntegrationGroup $group, string $reference, ?array $mapping = null): bool
    {
        $group->refresh();
        $meta = $group->auth_metadata ?? [];
        if ($reference !== '' && empty($meta['gocardless_pending']) &&
            ($meta['gocardless_completed_reference'] ?? null) === $reference) {
            return true;
        }
        $pending = $meta['gocardless_pending'] ?? null;
        if (! $pending || $reference === '' || ! hash_equals($pending['reference'], $reference) ||
            Carbon::parse($pending['expires_at'])->isPast()) {
            throw new RuntimeException('This consent attempt has expired. Please reconnect again.');
        }

        // Never accept the cached pre-authentication requisition (CR/empty accounts).
        $this->clearRequisitionCache($pending['requisition_id']);
        $requisition = $this->getRequisition($pending['requisition_id']);
        if (($requisition['status'] ?? '') !== 'LN' ||
            ($requisition['institution_id'] ?? null) !== $pending['institution_id'] ||
            ($requisition['reference'] ?? null) !== $reference || empty($requisition['accounts'])) {
            throw new RuntimeException('Bank consent is not complete. Please select your accounts and try again.');
        }
        $accounts = [];
        foreach ($requisition['accounts'] as $id) {
            $this->clearAccountCache($id);
            $details = $this->getAccount($id);
            if (! $details || ($details['status'] ?? null) === 'rate_limited') {
                throw new RuntimeException('Account details are temporarily unavailable. Please retry this consent callback later.');
            }
            $accounts[$id] = GoCardlessAccounts::normalize($details, $id);
        }

        return app(GoCardlessAccounts::class)->locked($group->user_id, function () use ($group, $pending, $reference, $accounts, $mapping) {
            $group->refresh();
            $meta = $group->auth_metadata ?? [];
            if (empty($meta['gocardless_pending']) &&
                ($meta['gocardless_completed_reference'] ?? null) === $reference) {
                return true;
            }
            if (($meta['gocardless_pending']['id'] ?? null) !== $pending['id']) {
                throw new RuntimeException('A newer consent attempt has replaced this one.');
            }
            $integrations = $group->integrations()->get();
            $oldIds = $integrations->pluck('configuration.account_id')->filter()->unique()->values()->all();
            $resolved = [];
            foreach ($oldIds as $oldId) {
                $selected = $mapping[$oldId] ?? null;
                if ($mapping !== null && $selected === null) {
                    throw new RuntimeException('Choose a renewed account for every existing account.');
                }
                if ($selected === '__missing') {
                    $resolved[$oldId] = null;

                    continue;
                }
                if ($selected !== null) {
                    if (! isset($accounts[$selected])) {
                        throw new RuntimeException('The selected account is not part of this bank consent.');
                    }
                    $resolved[$oldId] = $selected;

                    continue;
                }
                if (isset($accounts[$oldId])) {
                    $resolved[$oldId] = $oldId;

                    continue;
                }
                $old = app(GoCardlessAccounts::class)->find($group->user_id, $oldId);
                $matches = $old ? array_keys(array_filter($accounts, fn ($details) => $this->sameBankAccount($old->metadata['raw'] ?? [], $details))) : [];
                $resolved[$oldId] = count($matches) === 1 ? $matches[0] : null;
            }
            $newIds = array_filter($resolved);
            if (count(array_unique($newIds)) !== count($newIds)) {
                throw new RuntimeException('Each renewed account can replace only one existing account.');
            }
            if ($mapping === null && in_array(null, $resolved, true)) {
                $meta['gocardless_pending']['requires_mapping'] = true;
                $meta['gocardless_pending']['accounts'] = $accounts;
                $group->update(['auth_metadata' => $meta]);

                return false;
            }

            foreach ($integrations as $integration) {
                $config = $integration->configuration ?? [];
                $oldId = $config['account_id'] ?? '';
                $newId = $resolved[$oldId] ?? null;
                if (! $newId) {
                    $config['paused'] = true;
                    $config['gocardless_pause_reason'] = 'account_missing';
                } else {
                    $object = app(GoCardlessAccounts::class)->find($group->user_id, $oldId);
                    $other = app(GoCardlessAccounts::class)->find($group->user_id, $newId);
                    if ($object && $other && $object->id !== $other->id) {
                        throw new RuntimeException('The renewed account already has a different Spark record. Reconcile its duplicates before renewing.');
                    }
                    if ($object) {
                        $objectMeta = $object->metadata ?? [];
                        $objectMeta['gocardless_account_ids'] = array_values(array_unique(array_merge(
                            $objectMeta['gocardless_account_ids'] ?? [], [$oldId, $newId])));
                        $objectMeta['account_id'] = $newId;
                        $object->update(['metadata' => $objectMeta]);
                        $config['account_object_id'] = $object->id;
                    }
                    $config['account_id'] = $newId;
                    // Legacy pauses have no reason: keep them paused rather than
                    // silently undoing a user's choice. The user can resume them.
                    if (($config['gocardless_pause_reason'] ?? null) === 'eua_expired') {
                        $config['paused'] = false;
                        unset($config['gocardless_pause_reason']);
                    }
                }
                $integration->update(['configuration' => $config]);
                if ($newId) {
                    Cache::forget('gocardless_balances_' . $newId);
                    Cache::forget('gocardless_account_validation_' . $newId);
                    $this->upsertAccountObject($integration, $accounts[$newId]);
                }
            }
            $oldRequisition = $group->account_id;
            $meta['institution_id'] = $pending['institution_id'];
            $meta['gocardless_institution_id'] = $pending['institution_id'];
            $meta['gocardless_agreement_id'] = $pending['agreement_id'];
            $meta['gocardless_requisition_id'] = $pending['requisition_id'];
            $meta['gocardless_reference'] = $reference;
            $meta['gocardless_completed_reference'] = $reference;
            $meta['gocardless_generation'] = $pending['id'];
            $meta['eua_expired'] = false;
            $meta['requires_reconfirmation'] = false;
            unset($meta['gocardless_pending'], $meta['eua_expired_at'], $meta['old_requisition_id']);
            $group->update(['account_id' => $pending['requisition_id'],
                'access_token' => 'requisition:' . $pending['requisition_id'], 'auth_metadata' => $meta]);
            $this->clearGroupCaches($group->id);
            $this->cacheAccountList($group->id, array_keys($accounts));
            if ($integrations->isNotEmpty()) {
                RefreshRenewedConnectionJob::dispatch($group->id, $pending['id'])->afterCommit();
            }
            if ($oldRequisition && $oldRequisition !== $pending['requisition_id']) {
                RetireRequisitionJob::dispatch($group->id, $oldRequisition)->afterCommit();
            }

            return true;
        });
    }

    private function sameBankAccount(array $old, array $new): bool
    {
        if (empty($old['currency']) || $old['currency'] !== ($new['currency'] ?? null)) {
            return false;
        }
        // Scope is the same owner and institution. Names and masked PANs
        // are deliberately not identity evidence. Conflicting strong IDs fail closed.
        $matched = false;
        foreach (['iban', 'resourceId'] as $key) {
            if (! empty($old[$key]) && ! empty($new[$key])) {
                if (strtoupper(preg_replace('/\s+/', '', (string) $old[$key])) !==
                    strtoupper(preg_replace('/\s+/', '', (string) $new[$key]))) {
                    return false;
                }
                $matched = true;
            }
        }

        return $matched;
    }
}
