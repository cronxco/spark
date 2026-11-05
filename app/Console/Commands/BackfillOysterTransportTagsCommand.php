<?php

namespace App\Console\Commands;

use App\Integrations\Oyster\OysterTransportModeDetector;
use App\Models\Event;
use Illuminate\Console\Command;

class BackfillOysterTransportTagsCommand extends Command
{
    protected $signature = 'oyster:backfill-transport-tags';

    protected $description = 'Backfill transport_mode tags on existing Oyster journey events';

    public function handle(): int
    {
        $query = Event::where('service', 'oyster')
            ->whereIn('action', ['touched_in_at', 'touched_out_at'])
            ->whereNotNull('event_metadata->transport_mode');

        $total = $query->count();

        if ($total === 0) {
            $this->info('No Oyster journey events found.');

            return self::SUCCESS;
        }

        $this->info("Backfilling transport_mode tags on {$total} events...");

        $tagged = 0;
        $skipped = 0;

        $query->chunkById(200, function ($events) use (&$tagged, &$skipped) {
            foreach ($events as $event) {
                $mode = $event->event_metadata['transport_mode'] ?? null;

                if (! $mode || $mode === OysterTransportModeDetector::MODE_UNKNOWN) {
                    $skipped++;

                    continue;
                }

                $event->attachTag($mode, 'transport_mode');
                $tagged++;
            }

            $this->output->write('.');
        });

        $this->newLine();
        $this->info("Done. Tagged: {$tagged}, Skipped (unknown/missing): {$skipped}");

        return self::SUCCESS;
    }
}
