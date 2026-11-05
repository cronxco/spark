<?php

namespace App\Console\Commands;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RepairOysterBstTimesCommand extends Command
{
    protected $signature = 'oyster:repair-bst-times
        {--before= : Only repair events last updated before this UTC time (the deploy time of the parser fix)}
        {--user= : Limit to an owning user UUID}
        {--apply : Write the corrections; otherwise only report them}';

    protected $description = 'Report or repair Oyster event times stored as London wall-clock time instead of UTC';

    public function handle(): int
    {
        $before = $this->option('before');
        if (! is_string($before) || strtotime($before) === false) {
            $this->error('Supply --before=<UTC time the parser fix was deployed>, e.g. --before="2026-10-13 09:00:00".');

            return self::FAILURE;
        }
        $cutoff = Carbon::parse($before, 'UTC');
        if ($cutoff->isFuture()) {
            $this->error('--before must be in the past, otherwise repaired rows would be repaired again on the next run.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');

        $query = Event::query()
            ->where('service', 'oyster')
            ->where('updated_at', '<', $cutoff);

        if ($user = $this->option('user')) {
            $query->whereHas('integration', fn ($query) => $query->where('user_id', $user));
        }

        $scanned = 0;
        $affected = 0;
        $updated = 0;
        $rows = [];

        $query->chunkById(200, function ($events) use ($apply, &$scanned, &$affected, &$updated, &$rows) {
            foreach ($events as $event) {
                $scanned++;
                $stored = $event->time->copy()->utc();
                $corrected = Carbon::parse($stored->format('Y-m-d H:i:s'), 'Europe/London')->utc();

                if ($corrected->equalTo($stored)) {
                    continue;
                }

                $affected++;
                $status = 'proposed';
                if ($apply) {
                    $changed = Event::query()
                        ->whereKey($event->id)
                        ->where('time', $stored)
                        ->toBase()
                        ->update(['time' => $corrected->format('Y-m-d H:i:s'), 'updated_at' => now()]);
                    $updated += $changed;
                    $status = $changed ? 'repaired' : 'changed since scan; skipped';
                }
                $rows[] = [$event->id, $stored->toIso8601String(), $corrected->toIso8601String(), $status];
            }
        });

        $this->table(['Event UUID', 'Stored UTC', 'Corrected UTC', 'Status'], $rows);
        $this->table(['Mode', 'Scanned', 'Affected (BST)', 'Updated'], [[$apply ? 'apply' : 'dry-run', $scanned, $affected, $updated]]);
        $this->info('Winter (GMT) events are identical in both readings and are never changed. Re-running after --apply is safe: repaired rows get a fresh updated_at past the cutoff.');

        return self::SUCCESS;
    }
}
