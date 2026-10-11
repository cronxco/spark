<?php

namespace App\Services\Money;

use App\Jobs\OAuth\Monzo\MonzoPotPull;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Services\GoCardlessAccounts;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Tags\Tag;

/**
 * Plans, and then applies, the money-data clean-ups shown in Data health.
 *
 * A plan is read-only. Applying it re-plans under the owner lock and refuses to run
 * when the plan no longer matches the fingerprint the user reviewed.
 *
 * @phpstan-type Step array{key: string, kind: string, label: string, detail: string, blocked: ?string, needs_input: bool, payload: array<string, mixed>}
 */
class AccountCleanup
{
    public const KIND_MERGE = 'merge_accounts';

    public const KIND_QUARANTINE = 'quarantine_account';

    public const KIND_RENAME = 'rename_account';

    public const KIND_SNAPSHOTS = 'archived_pot_snapshots';

    public const KIND_POT_DETAILS = 'refresh_pot_details';

    public const KIND_CATEGORY = 'rename_category';

    public const GOCARDLESS_KINDS = [self::KIND_MERGE, self::KIND_QUARANTINE, self::KIND_RENAME];

    public function __construct(
        private GoCardlessAccounts $accounts,
        private GoCardlessAccountMerger $merger,
    ) {}

    /**
     * @param  list<string>|null  $kinds  Restrict the plan to these step kinds
     * @return array{steps: list<array<string, mixed>>, fingerprint: string}
     */
    public function plan(string $userId, ?array $kinds = null): array
    {
        $steps = array_merge(
            $this->gocardlessSteps($userId),
            $this->snapshotSteps($userId),
            $this->potDetailSteps($userId),
            $this->categorySteps($userId),
        );
        if ($kinds !== null) {
            $steps = array_values(array_filter($steps, fn ($step) => in_array($step['kind'], $kinds, true)));
        }

        return ['steps' => $steps, 'fingerprint' => $this->fingerprint($steps)];
    }

    /**
     * Applies every runnable step of a reviewed plan.
     *
     * @param  list<string>|null  $kinds
     * @return array{applied: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function apply(string $userId, string $fingerprint, ?array $kinds = null): array
    {
        return $this->accounts->locked($userId, function () use ($userId, $fingerprint, $kinds) {
            $plan = $this->plan($userId, $kinds);
            if (! hash_equals($plan['fingerprint'], $fingerprint)) {
                throw new RuntimeException('The plan changed since it was reviewed. Review it again before applying.');
            }

            $applied = [];
            $skipped = [];
            foreach ($plan['steps'] as $step) {
                if ($step['blocked'] !== null || $step['needs_input']) {
                    $skipped[] = $step;

                    continue;
                }
                $this->applyStep($userId, $step);
                $applied[] = $step;
            }

            return ['applied' => $applied, 'skipped' => $skipped];
        });
    }

    /**
     * Stores the display name for a Monzo custom category id.
     */
    public function setCategoryName(string $userId, string $categoryId, string $name): void
    {
        $integration = $this->monzoMaster($userId);
        if (! $integration) {
            throw new RuntimeException('No Monzo integration to store the category name on.');
        }
        $config = $integration->configuration ?? [];
        $config['category_names'][$categoryId] = trim($name);
        $integration->update(['configuration' => $config]);
    }

    /**
     * @return array<string, string>
     */
    public function categoryNames(string $userId): array
    {
        return $this->monzoMaster($userId)?->configuration['category_names'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gocardlessSteps(string $userId): array
    {
        $objects = EventObject::where('user_id', $userId)->where('concept', 'account')
            ->where('type', 'bank_account')->orderBy('created_at')->orderBy('id')->get();

        $steps = [];
        $unidentified = collect();
        $mergedAway = [];
        foreach ($objects->groupBy(fn ($object) => $this->accounts->canonical($object)?->id ?? 'unknown') as $id => $members) {
            if ($id === 'unknown' || $id === '') {
                $unidentified = $members->reject(fn ($object) => $object->metadata['gocardless_quarantined'] ?? false);

                continue;
            }
            $canonical = $members->first();
            foreach ($members->skip(1) as $duplicate) {
                $steps[] = $this->mergeStep($canonical, $duplicate);
                $mergedAway[] = $duplicate->id;
            }
        }

        $identified = $objects->reject(fn ($object) => in_array($object->id, $mergedAway, true)
            || $this->accounts->canonical($object) === null);
        foreach ($unidentified as $object) {
            $matches = $identified->filter(fn ($candidate) => $this->sameBankAccount($candidate, $object))
                ->map(fn ($candidate) => $this->accounts->canonical($candidate))->unique('id')->values();
            if ($matches->count() === 1) {
                $steps[] = $this->mergeStep($matches->first(), $object, 'Unidentified account matches by bank identity');
                $mergedAway[] = $object->id;

                continue;
            }
            $steps[] = $this->step(
                self::KIND_QUARANTINE . ':' . $object->id,
                self::KIND_QUARANTINE,
                "Set aside \"{$object->title}\"",
                'It has no GoCardless account id and matches no other account. Its history is kept.',
                ['object_id' => $object->id],
            );
        }

        $taken = $objects->reject(fn ($object) => in_array($object->id, $mergedAway, true))->pluck('title')->all();
        foreach ($objects as $object) {
            if (in_array($object->id, $mergedAway, true)) {
                continue;
            }
            if (preg_match('/^(.+?) \[[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\]$/i', (string) $object->title, $matches)) {
                $clean = trim($matches[1]);
                $blocked = in_array($clean, $taken, true) ? "Another account is already called \"{$clean}\"" : null;
                $steps[] = $this->step(
                    self::KIND_RENAME . ':' . $object->id,
                    self::KIND_RENAME,
                    "Rename \"{$object->title}\" to \"{$clean}\"",
                    'The account id is part of its name.',
                    ['object_id' => $object->id, 'title' => $clean],
                    $blocked,
                );
                if ($blocked === null) {
                    $taken[] = $clean;
                }
            }
        }

        return $steps;
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeStep(EventObject $canonical, EventObject $duplicate, string $reason = 'Same GoCardless account'): array
    {
        $events = Event::withTrashed()->where('actor_id', $duplicate->id)->count()
            + Event::withTrashed()->where('target_id', $duplicate->id)->count();

        return $this->step(
            self::KIND_MERGE . ':' . $duplicate->id,
            self::KIND_MERGE,
            "Merge \"{$duplicate->title}\" into \"{$canonical->title}\"",
            "{$reason}. {$events} events move across and the old record is kept as an alias.",
            ['canonical_id' => $canonical->id, 'duplicate_id' => $duplicate->id, 'events' => $events,
                'resource_history' => $this->merger->resourceHistoryDiffers($canonical, $duplicate)],
            $this->merger->conflict($canonical, $duplicate),
        );
    }

    private function sameBankAccount(EventObject $identified, EventObject $unidentified): bool
    {
        $resourceId = $unidentified->metadata['raw']['resourceId'] ?? null;
        if (! $resourceId || $resourceId !== ($identified->metadata['raw']['resourceId'] ?? null)) {
            return false;
        }
        $iban = $unidentified->metadata['raw']['iban'] ?? null;
        $otherIban = $identified->metadata['raw']['iban'] ?? null;

        return ! ($iban && $otherIban && $iban !== $otherIban);
    }

    /**
     * Archived pots keep their non-zero history plus the first zero after it. Later daily zeros go.
     *
     * @return list<array<string, mixed>>
     */
    private function snapshotSteps(string $userId): array
    {
        $pots = EventObject::where('user_id', $userId)->where('concept', 'account')
            ->where('type', 'monzo_archived_pot')->get();

        $perPot = [];
        $total = 0;
        foreach ($pots as $pot) {
            $removable = $this->removableSnapshotIds($pot);
            if ($removable !== []) {
                $perPot[$pot->id] = count($removable);
                $total += count($removable);
            }
        }
        if ($total === 0) {
            return [];
        }

        return [$this->step(
            self::KIND_SNAPSHOTS,
            self::KIND_SNAPSHOTS,
            'Remove daily £0 balances from archived pots',
            number_format($total) . ' balance rows across ' . count($perPot) . ' archived pots. Rows are soft-deleted and can be restored.',
            ['pots' => $perPot, 'rows' => $total],
        )];
    }

    /**
     * @return list<string>
     */
    private function removableSnapshotIds(EventObject $pot): array
    {
        $rows = Event::where('actor_id', $pot->id)->where('action', 'had_balance')
            ->orderBy('time')->orderBy('id')->get(['id', 'value']);
        if ($rows->isEmpty()) {
            return [];
        }

        $lastNonZero = $rows->keys()->filter(fn ($i) => (int) $rows[$i]->value !== 0)->last();
        $keepUntil = $lastNonZero === null ? 0 : $lastNonZero + 1;

        return $rows->slice($keepUntil + 1)->filter(fn ($row) => (int) $row->value === 0)->pluck('id')->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function potDetailSteps(string $userId): array
    {
        $missing = EventObject::where('user_id', $userId)->where('concept', 'account')
            ->where('type', 'monzo_pot')->get()
            ->filter(fn ($pot) => empty($pot->metadata['monzo']))->count();
        if ($missing === 0) {
            return [];
        }

        $integration = $this->potsIntegration($userId);

        return [$this->step(
            self::KIND_POT_DETAILS,
            self::KIND_POT_DETAILS,
            'Fetch the missing details for ' . $missing . ' pots',
            'Goal, type, owning account and round-up settings come from Monzo. This only reads from Monzo.',
            ['pots' => $missing],
            $integration ? null : 'No connected Monzo pots integration',
        )];
    }

    /**
     * Monzo custom categories arrive as opaque ids. Each needs a name before it can be renamed.
     *
     * @return list<array<string, mixed>>
     */
    private function categorySteps(string $userId): array
    {
        $names = $this->categoryNames($userId);

        $rows = Event::query()
            ->where('service', 'monzo')
            ->whereHas('integration', fn ($query) => $query->where('user_id', $userId))
            ->where('event_metadata->category', 'like', 'category\_%')
            ->get(['id', 'event_metadata']);

        $steps = [];
        foreach ($rows->groupBy(fn ($event) => $event->event_metadata['category']) as $categoryId => $events) {
            $merchants = $events->map(fn ($event) => $event->event_metadata['raw']['merchant']['name'] ?? null)
                ->filter()->countBy()->sortDesc()->keys()->take(3)->implode(', ');
            $name = $names[$categoryId] ?? null;
            $detail = $events->count() . ' transactions' . ($merchants !== '' ? ", e.g. {$merchants}" : '') . '.';

            if ($name === null || $name === '') {
                $steps[] = $this->step(
                    self::KIND_CATEGORY . ':' . $categoryId,
                    self::KIND_CATEGORY,
                    "Name the Monzo category {$categoryId}",
                    $detail,
                    ['category_id' => $categoryId, 'count' => $events->count()],
                    null,
                    true,
                );

                continue;
            }
            if (! $this->tagNeedsRename($categoryId, $name)) {
                continue;
            }
            $steps[] = $this->step(
                self::KIND_CATEGORY . ':' . $categoryId,
                self::KIND_CATEGORY,
                "Rename category {$categoryId} to \"{$name}\"",
                $detail,
                ['category_id' => $categoryId, 'name' => $name, 'count' => $events->count()],
            );
        }

        return $steps;
    }

    private function tagNeedsRename(string $categoryId, string $name): bool
    {
        return Tag::findFromString($categoryId, 'transaction_category') !== null
            && mb_strtolower($categoryId) !== mb_strtolower($name);
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function applyStep(string $userId, array $step): void
    {
        $payload = $step['payload'];
        match ($step['kind']) {
            self::KIND_MERGE => $this->merger->merge(
                EventObject::where('user_id', $userId)->findOrFail($payload['canonical_id']),
                EventObject::where('user_id', $userId)->findOrFail($payload['duplicate_id']),
            ),
            self::KIND_QUARANTINE => $this->quarantine(EventObject::where('user_id', $userId)->findOrFail($payload['object_id'])),
            self::KIND_RENAME => $this->rename(EventObject::where('user_id', $userId)->findOrFail($payload['object_id']), $payload['title']),
            self::KIND_SNAPSHOTS => $this->removeSnapshots($userId),
            self::KIND_POT_DETAILS => MonzoPotPull::dispatch($this->potsIntegration($userId)),
            self::KIND_CATEGORY => $this->renameCategory($userId, $payload['category_id'], $payload['name']),
        };
    }

    private function quarantine(EventObject $object): void
    {
        $metadata = $object->metadata ?? [];
        $metadata['gocardless_quarantined'] = true;
        $object->update(['metadata' => $metadata]);
    }

    private function rename(EventObject $object, string $title): void
    {
        $metadata = $object->metadata ?? [];
        if (($metadata['name'] ?? null) === $object->title) {
            $metadata['name'] = $title;
        }
        $object->update(['title' => $title, 'metadata' => $metadata]);
    }

    private function removeSnapshots(string $userId): void
    {
        $pots = EventObject::where('user_id', $userId)->where('concept', 'account')
            ->where('type', 'monzo_archived_pot')->get();
        foreach ($pots as $pot) {
            $ids = $this->removableSnapshotIds($pot);
            if ($ids === []) {
                continue;
            }
            foreach (array_chunk($ids, 500) as $chunk) {
                Event::whereIn('id', $chunk)->delete();
            }
            $metadata = $pot->metadata ?? [];
            $metadata['snapshot_cleanup'] = ['cleaned_at' => now()->toIso8601String(), 'removed' => count($ids)];
            $pot->update(['metadata' => $metadata]);
        }
    }

    private function renameCategory(string $userId, string $categoryId, string $name): void
    {
        $old = Tag::findFromString($categoryId, 'transaction_category');
        if (! $old) {
            return;
        }
        $new = Tag::findOrCreateFromString($name, 'transaction_category');
        if ($new->id === $old->id) {
            return;
        }

        $morph = (new Event)->getMorphClass();
        $owned = Event::withTrashed()
            ->join('integrations', 'integrations.id', '=', 'events.integration_id')
            ->where('integrations.user_id', $userId)
            ->select('events.id');
        $already = DB::table('taggables')->where('tag_id', $new->id)->where('taggable_type', $morph)
            ->pluck('taggable_id')->map(fn ($id) => (string) $id)->all();
        $rows = DB::table('taggables')->where('tag_id', $old->id)->where('taggable_type', $morph)
            ->whereIn('taggable_id', $owned)->pluck('taggable_id');
        foreach ($rows as $eventId) {
            $query = DB::table('taggables')->where('tag_id', $old->id)
                ->where('taggable_id', $eventId)->where('taggable_type', $morph);
            if (in_array((string) $eventId, $already, true)) {
                $query->delete();
            } else {
                $query->update(['tag_id' => $new->id]);
            }
        }
        if (! DB::table('taggables')->where('tag_id', $old->id)->exists()) {
            $old->delete();
        }
    }

    private function monzoMaster(string $userId): ?Integration
    {
        return Integration::where('user_id', $userId)->where('service', 'monzo')
            ->orderByRaw("case when instance_type = 'accounts' then 0 else 1 end")->first();
    }

    private function potsIntegration(string $userId): ?Integration
    {
        return Integration::where('user_id', $userId)->where('service', 'monzo')
            ->where('instance_type', 'pots')->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function step(string $key, string $kind, string $label, string $detail, array $payload, ?string $blocked = null, bool $needsInput = false): array
    {
        return [
            'key' => $key, 'kind' => $kind, 'label' => $label, 'detail' => $detail,
            'blocked' => $blocked, 'needs_input' => $needsInput, 'payload' => $payload,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function fingerprint(array $steps): string
    {
        $runnable = array_values(array_filter($steps, fn ($step) => $step['blocked'] === null && ! $step['needs_input']));

        return hash('sha256', json_encode(array_map(fn ($step) => [$step['key'], $step['payload']], $runnable)));
    }
}
