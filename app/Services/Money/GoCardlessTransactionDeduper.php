<?php

namespace App\Services\Money;

use App\Models\Block;
use App\Models\Event;
use App\Models\Relationship;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds and merges GoCardless events that are the same bank transaction stored twice.
 *
 * Two events are the same transaction when they sit on the same account object and share the
 * bank's `transactionId` or `internalTransactionId`. Merchant, date and amount alone never match,
 * so two genuine purchases of the same amount at the same shop are kept. The surviving event is the
 * one other records lean on most (links, then buffer state), then the oldest; everything on the
 * other copy is moved across and the copy is soft-deleted with a pointer to the survivor.
 *
 * @phpstan-type Group array{canonical_id: string, duplicate_ids: list<string>, account_id: string, provider_id: string, amount: int, date: string, merchant: string, conflict: ?string}
 */
class GoCardlessTransactionDeduper
{
    public function __construct(private SpendingScope $scope) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function groups(string $userId): array
    {
        $rows = Event::forUser($userId)->where('service', 'gocardless')
            ->select(['id', 'actor_id', 'action', 'value', 'value_unit', 'time', 'created_at', 'event_metadata'])
            ->selectRaw("event_metadata->'raw'->>'transactionId' as provider_tid, event_metadata->'raw'->>'internalTransactionId' as provider_itid")
            ->whereNotNull('actor_id')
            ->where(function ($query): void {
                $query->whereNotNull(DB::raw("event_metadata->'raw'->>'transactionId'"))
                    ->orWhereNotNull(DB::raw("event_metadata->'raw'->>'internalTransactionId'"));
            })
            ->get();

        $parent = [];
        $find = function (string $id) use (&$parent, &$find): string {
            if (($parent[$id] ?? $id) === $id) {
                return $parent[$id] = $id;
            }

            return $parent[$id] = $find($parent[$id]);
        };
        $seen = [];
        foreach ($rows as $row) {
            $id = (string) $row->id;
            $find($id);
            foreach (['t' => $row->provider_tid, 'i' => $row->provider_itid] as $kind => $value) {
                if ($value === null || trim((string) $value) === '') {
                    continue;
                }
                $key = $row->actor_id . '|' . $kind . '|' . $value;
                if (isset($seen[$key])) {
                    $parent[$find($id)] = $find($seen[$key]);
                } else {
                    $seen[$key] = $id;
                }
            }
        }

        $byId = $rows->keyBy(fn ($row): string => (string) $row->id);
        $groups = [];
        foreach ($rows->groupBy(fn ($row): string => $find((string) $row->id)) as $members) {
            if ($members->count() < 2) {
                continue;
            }
            $groups[] = $this->group($members->map(fn ($row) => $byId[(string) $row->id])->values());
        }
        usort($groups, fn (array $a, array $b): int => [$a['date'], $a['canonical_id']] <=> [$b['date'], $b['canonical_id']]);

        return $groups;
    }

    /**
     * Pending authorisations that booked lines already settle in whole or in part. Read-only: the
     * spending total reads these at query time, so nothing needs repairing.
     *
     * @return list<array{id: string, date: string, merchant: string, authorised: int, remaining: int, account: string}>
     */
    public function pendingOverlaps(string $userId, CarbonImmutable $now): array
    {
        $first = Event::forUser($userId)->where('service', 'gocardless')
            ->where('event_metadata->transaction_status', 'pending')->min('time');
        if ($first === null) {
            return [];
        }

        return $this->scope->entries($userId, CarbonImmutable::parse($first), $now)
            ->filter(fn (array $entry): bool => $entry['pending'] && $entry['pending_authorised'] !== null && $entry['pending_authorised'] !== $entry['amount'])
            ->map(fn (array $entry): array => [
                'id' => $entry['id'],
                'date' => CarbonImmutable::instance($entry['time'])->toDateString(),
                'merchant' => $entry['merchant'],
                'authorised' => $entry['pending_authorised'],
                'remaining' => $entry['amount'],
                'account' => $entry['account'],
            ])->values()->all();
    }

    /**
     * @param  list<string>  $duplicateIds
     */
    public function merge(string $userId, string $canonicalId, array $duplicateIds): void
    {
        $canonical = Event::forUser($userId)->findOrFail($canonicalId);

        foreach (Event::forUser($userId)->whereIn('id', $duplicateIds)->get() as $duplicate) {
            DB::transaction(function () use ($canonical, $duplicate): void {
                $this->moveRelationships($canonical, $duplicate);
                $this->moveBlocks($canonical, $duplicate);
                $canonical->tags()->syncWithoutDetaching(
                    $duplicate->tags->reject(fn ($tag): bool => $tag->type === 'transaction_status')->modelKeys()
                );

                $metadata = $canonical->event_metadata ?? [];
                if (! isset($metadata['buffer']) && isset($duplicate->event_metadata['buffer'])) {
                    $metadata['buffer'] = $duplicate->event_metadata['buffer'];
                }
                $metadata['merged_duplicates'] = array_values(array_unique([...($metadata['merged_duplicates'] ?? []), (string) $duplicate->id]));
                $canonical->event_metadata = $metadata;
                $canonical->saveQuietly();

                $copy = $duplicate->event_metadata ?? [];
                $copy['merged_into'] = (string) $canonical->id;
                $duplicate->event_metadata = $copy;
                $duplicate->saveQuietly();
                $duplicate->delete();
            });
        }
    }

    /**
     * @param  Collection<int, object>  $members
     * @return array<string, mixed>
     */
    private function group(Collection $members): array
    {
        $links = Relationship::where(fn ($query) => $query->where('from_type', Event::class)->whereIn('from_id', $members->pluck('id')))
            ->orWhere(fn ($query) => $query->where('to_type', Event::class)->whereIn('to_id', $members->pluck('id')))
            ->get();
        $weight = fn ($row): int => $links->filter(fn ($link): bool => (string) $link->from_id === (string) $row->id || (string) $link->to_id === (string) $row->id)->count();

        $ranked = $members->sortBy(fn ($row): array => [
            -$weight($row),
            isset($row->event_metadata['buffer']) ? 0 : 1,
            ($row->event_metadata['transaction_status'] ?? null) === 'booked' ? 0 : 1,
            $row->created_at?->getTimestamp() ?? 0,
            (string) $row->id,
        ])->values();
        $canonical = $ranked->first();

        $conflict = null;
        foreach (['action', 'value', 'value_unit'] as $field) {
            if ($members->pluck($field)->map(fn ($value): string => (string) $value)->unique()->count() > 1) {
                $conflict = "Copies disagree on {$field}";
            }
        }

        return [
            'canonical_id' => (string) $canonical->id,
            'duplicate_ids' => $ranked->skip(1)->map(fn ($row): string => (string) $row->id)->values()->all(),
            'account_id' => (string) $canonical->actor_id,
            'provider_id' => (string) ($canonical->provider_tid ?: $canonical->provider_itid),
            'amount' => (int) $canonical->value,
            'date' => CarbonImmutable::instance($canonical->time)->toDateString(),
            'merchant' => (string) ($canonical->event_metadata['description'] ?? ''),
            'conflict' => $conflict,
        ];
    }

    private function moveRelationships(Event $canonical, Event $duplicate): void
    {
        $morph = $duplicate->getMorphClass();
        $links = Relationship::where(fn ($query) => $query->where('from_type', $morph)->where('from_id', $duplicate->id))
            ->orWhere(fn ($query) => $query->where('to_type', $morph)->where('to_id', $duplicate->id))
            ->get();

        foreach ($links as $link) {
            $fromId = $link->from_type === $morph && (string) $link->from_id === (string) $duplicate->id ? $canonical->id : $link->from_id;
            $toId = $link->to_type === $morph && (string) $link->to_id === (string) $duplicate->id ? $canonical->id : $link->to_id;
            if ((string) $fromId === (string) $toId && $link->from_type === $link->to_type) {
                continue;
            }

            $exists = Relationship::withTrashed()->where('user_id', $link->user_id)->where('type', $link->type)
                ->where('from_type', $link->from_type)->where('from_id', $fromId)
                ->where('to_type', $link->to_type)->where('to_id', $toId)->exists();
            if ($exists) {
                continue;
            }
            $link->from_id = $fromId;
            $link->to_id = $toId;
            $link->save();
        }
    }

    private function moveBlocks(Event $canonical, Event $duplicate): void
    {
        $taken = Block::withTrashed()->where('event_id', $canonical->id)->get()
            ->map(fn (Block $block): string => $block->title . '|' . $block->block_type)->all();

        foreach (Block::withTrashed()->where('event_id', $duplicate->id)->get() as $block) {
            if (in_array($block->title . '|' . $block->block_type, $taken, true)) {
                continue;
            }
            $block->event_id = $canonical->id;
            $block->saveQuietly();
        }
    }
}
