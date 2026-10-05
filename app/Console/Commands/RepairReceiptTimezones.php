<?php

namespace App\Console\Commands;

use App\Integrations\Receipt\ReceiptTimeResolver;
use App\Models\Block;
use App\Models\Event;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class RepairReceiptTimezones extends Command
{
    protected $signature = 'receipts:repair-timezones
        {--timezone= : Confirmed IANA timezone of the receipts being reviewed}
        {--user= : Limit to an owning user UUID}
        {--event=* : Limit to selected receipt event UUIDs}
        {--apply : Write the proposed repairs; otherwise only report them}
        {--dry-run : Explicitly report without writing (cannot be combined with --apply)}';

    protected $description = 'Report or repair legacy receipt UTC-suffix mistakes that were future-dated at import';

    public function handle(ReceiptTimeResolver $resolver): int
    {
        $timezone = $this->option('timezone');
        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            $this->error('Supply the confirmed receipt timezone, e.g. --timezone=Europe/London. Currency is not timezone evidence.');

            return self::FAILURE;
        }
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose --apply or --dry-run, not both.');

            return self::FAILURE;
        }

        $query = Event::query()->where('service', 'receipt')->where('action', 'had_receipt_from');
        if ($user = $this->option('user')) {
            $query->whereHas('integration', fn ($query) => $query->where('user_id', $user));
        }
        if ($ids = $this->option('event')) {
            $query->whereIn('id', $ids);
        }

        $scanned = 0;
        $candidates = 0;
        $updated = 0;
        $rows = [];
        $reasons = [];
        $apply = (bool) $this->option('apply');
        $query->chunkById(200, function ($events) use ($resolver, $timezone, $apply, &$scanned, &$candidates, &$updated, &$rows, &$reasons) {
            foreach ($events as $event) {
                $scanned++;
                $proposal = $this->propose($event, $timezone, $resolver);
                if ($proposal['time'] === null) {
                    $reason = $proposal['reason'];
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;

                    continue;
                }
                $candidates++;
                $status = 'proposed';
                if ($apply) {
                    $changed = $this->repair($event->id, $timezone, $resolver);
                    $updated += (int) $changed;
                    $status = $changed ? 'repaired' : 'changed since scan; skipped';
                }
                $rows[] = [$event->id, $event->time->toIso8601String(), $proposal['time']->toIso8601String(), $status];
            }
        });

        $this->table(['Receipt UUID', 'Old UTC', 'Proposed UTC', 'Status'], $rows);
        $this->table(['Mode', 'Scanned', 'Likely affected', 'Updated'], [[$apply ? 'apply' : 'dry-run', $scanned, $candidates, $updated]]);
        if ($reasons !== []) {
            $this->table(['Skipped reason', 'Count'], collect($reasons)->map(fn ($count, $reason) => [$reason, $count])->values()->all());
        }
        $this->info('Only legacy Z-suffixed timestamps that were future at import and become plausible in the confirmed timezone qualify. Delayed imports and other ambiguous cases need source verification.');

        return self::SUCCESS;
    }

    /** @return array{time: ?Carbon, reason: string} */
    private function propose(Event $event, string $timezone, ReceiptTimeResolver $resolver): array
    {
        $metadata = $event->event_metadata ?? [];
        if (isset($metadata['receipt_timezone_repair']) || isset($metadata['time_resolution'])) {
            return ['time' => null, 'reason' => 'already repaired or normalized'];
        }
        $transaction = $metadata['raw_extraction']['transaction_metadata'] ?? $metadata['transaction_metadata'] ?? [];
        $raw = $transaction['transaction_date'] ?? null;
        if (! is_string($raw) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $raw)) {
            return ['time' => null, 'reason' => 'no legacy UTC-suffix evidence'];
        }
        if (! empty($transaction['transaction_timezone']) && $transaction['transaction_timezone'] !== $timezone) {
            return ['time' => null, 'reason' => 'conflicting extracted timezone'];
        }
        try {
            $originalTime = Carbon::parse($raw);
        } catch (Throwable) {
            return ['time' => null, 'reason' => 'invalid original date'];
        }
        if (! $event->time->equalTo($originalTime)) {
            return ['time' => null, 'reason' => 'event differs from original extraction'];
        }
        if (! $event->time->greaterThan($event->created_at->copy()->addMinutes(5))) {
            return ['time' => null, 'reason' => 'not future at import; needs source verification'];
        }

        $resolved = $resolver->resolve(
            ['transaction_date' => substr($raw, 0, -1), 'transaction_timezone' => $timezone],
            $event->created_at->toIso8601String(),
            $timezone,
            $event->created_at,
        );
        if ($resolved['metadata']['source'] !== 'receipt' || ! $resolved['time']->lessThan($event->time)) {
            return ['time' => null, 'reason' => 'timezone reinterpretation is not a plausible repair'];
        }

        return ['time' => $resolved['time'], 'reason' => 'local clock incorrectly labelled UTC'];
    }

    private function repair(string $eventId, string $timezone, ReceiptTimeResolver $resolver): bool
    {
        return DB::transaction(function () use ($eventId, $timezone, $resolver) {
            $event = Event::query()->lockForUpdate()->find($eventId);
            if ($event === null) {
                return false;
            }
            $proposal = $this->propose($event, $timezone, $resolver);
            if ($proposal['time'] === null) {
                return false;
            }
            $oldTime = $event->time->copy();
            $time = $proposal['time'];
            $metadata = $event->event_metadata ?? [];
            $metadata['receipt_timezone_repair'] = [
                'version' => 1,
                'original_time' => $oldTime->toIso8601String(),
                'corrected_time' => $time->toIso8601String(),
                'confirmed_timezone' => $timezone,
                'reason' => $proposal['reason'],
                'repaired_at' => now()->toIso8601String(),
                'original_transaction_metadata' => $metadata['transaction_metadata'] ?? null,
                'original_matching_hints' => $metadata['matching_hints'] ?? null,
            ];
            $metadata['transaction_metadata']['transaction_date'] = $time->toIso8601String();
            $metadata['transaction_metadata']['transaction_timezone'] = $timezone;
            $metadata['matching_hints']['suggested_date_range'] = [
                'start' => $time->copy()->subHours(2)->toIso8601String(),
                'end' => $time->copy()->addHours(2)->toIso8601String(),
            ];

            // Keep explicitly edited block times and non-receipt derived blocks intact.
            $blocks = Block::withTrashed()->where('event_id', $event->id)
                ->whereIn('block_type', ['receipt_line_item', 'receipt_tax_summary', 'receipt_payment_method'])
                ->where('time', $oldTime)->lockForUpdate()->get();
            foreach ($blocks as $block) {
                $block->update(['time' => $time]);
            }
            $event->update(['time' => $time, 'event_metadata' => $metadata]);

            return true;
        });
    }
}
