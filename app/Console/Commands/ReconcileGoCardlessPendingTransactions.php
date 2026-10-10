<?php

namespace App\Console\Commands;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Models\Event;
use App\Models\Relationship;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileGoCardlessPendingTransactions extends Command
{
    protected $signature = 'gocardless:reconcile-pending {--apply : Apply the plan; default is read-only}';

    protected $description = 'Retire pending GoCardless transactions that were later booked under a different ID';

    public function handle(): int
    {
        $days = GoCardlessBankPlugin::PENDING_TWIN_DAYS;
        $pending = Event::where('service', 'gocardless')
            ->where('event_metadata->transaction_status', 'pending')
            ->orderBy('time')
            ->get();
        $claimed = [];
        $planned = 0;

        foreach ($pending as $event) {
            $twins = Event::where('integration_id', $event->integration_id)
                ->where('id', '!=', $event->id)
                ->where('action', $event->action)
                ->where('value', $event->value)
                ->where('value_unit', $event->value_unit)
                ->where('event_metadata->transaction_status', 'booked')
                ->whereBetween('time', [$event->time->copy()->subDays($days)->startOfDay(), $event->time->copy()->addDays($days)->endOfDay()])
                ->whereNotIn('id', $claimed)
                ->get();

            // Only act when exactly one booked transaction could be this one
            if ($twins->count() !== 1) {
                if ($twins->count() > 1) {
                    $this->warn("Skip {$event->id}: {$twins->count()} booked candidates.");
                }

                continue;
            }

            $booked = $twins->first();
            $claimed[] = $booked->id;
            $planned++;
            $this->line("Retire pending {$event->id} ({$event->time->toDateString()}, {$event->value}) as booked {$booked->id} ({$booked->time->toDateString()}).");

            if ($this->option('apply')) {
                DB::transaction(fn () => $this->retire($event, $booked));
            }
        }

        $this->info($this->option('apply')
            ? "Retired {$planned} pending transactions."
            : "Dry run: {$planned} pending transactions would be retired. Repeat with --apply.");

        return self::SUCCESS;
    }

    private function retire(Event $pending, Event $booked): void
    {
        $morph = $pending->getMorphClass();
        Relationship::where('from_type', $morph)->where('from_id', $pending->id)->update(['from_id' => $booked->id]);
        Relationship::where('to_type', $morph)->where('to_id', $pending->id)->update(['to_id' => $booked->id]);

        $metadata = $pending->event_metadata ?? [];
        $metadata['settled_as'] = (string) $booked->id;
        $pending->event_metadata = $metadata;
        $pending->saveQuietly();
        $pending->delete();
    }
}
