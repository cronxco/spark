<?php

namespace App\Jobs\Outline;

use App\Integrations\Outline\OutlineApi;
use App\Models\ActionProgress;
use App\Models\Integration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class GenerateDayNotes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Integration|string $integration,
        public int $year,
        public ?string $progressId = null
    ) {}

    public function handle(): void
    {
        $integration = $this->integration instanceof Integration
            ? $this->integration
            : Integration::findOrFail($this->integration);

        $api = new OutlineApi($integration);
        $collectionId = (string) (($integration->configuration['daynotes_collection_id'] ?? null)
            ?: config('services.outline.daynotes_collection_id'));

        // Create Year doc
        $yearDoc = $api->createDocument((string) $this->year, $collectionId);
        $yearId = $yearDoc['data']['id'] ?? null;

        if (! $yearId) {
            // Mark progress as failed
            if ($this->progressId) {
                ActionProgress::find($this->progressId)?->markFailed('Failed to create year document');
            }

            return;
        }

        // Create 12 months with chained day creations
        $totalDays = 0;
        $batches = [];

        for ($i = 1; $i <= 12; $i++) {
            $monthNum = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $monthName = date('F', strtotime("{$this->year}-{$monthNum}-01"));
            $monthDoc = $api->createDocument("{$this->year}-{$monthNum}: {$monthName}", $collectionId, $yearId);
            $monthId = $monthDoc['data']['id'] ?? null;

            if (! $monthId) {
                continue;
            }

            $jobs = collect();
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int) $i, (int) $this->year);
            $totalDays += $daysInMonth;

            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dayNum = str_pad((string) $d, 2, '0', STR_PAD_LEFT);
                $dayName = date('l', strtotime("{$this->year}-{$monthNum}-{$dayNum}"));

                // Delay each job by 5 seconds per job to avoid rate limiting
                $delaySeconds = ($d - 1) * 5;
                $job = (new NewDocJob($integration, "{$this->year}-{$monthNum}-{$dayNum}: {$dayName}", $collectionId, $monthId))
                    ->delay(now()->addSeconds($delaySeconds));

                $jobs->push($job);
            }

            // Add progress update job as the final job in this batch if progressId is set
            if ($this->progressId) {
                $isFinalMonth = ($i === 12);
                // Delay progress update until all day jobs have had time to run
                $progressDelaySeconds = $daysInMonth * 5;
                $progressJob = (new UpdateDayNoteProgress($this->progressId, $i, $daysInMonth, $isFinalMonth))
                    ->delay(now()->addSeconds($progressDelaySeconds));

                $jobs->push($progressJob);
            }

            $batch = Bus::batch($jobs->all())
                ->name("Generate {$this->year}-{$monthNum} ({$monthName}) daynotes")
                ->onQueue('migration')
                ->allowFailures()
                ->dispatch();

            $batches[] = $batch;
        }

        // Initialize progress tracking
        if ($this->progressId) {
            $progress = ActionProgress::find($this->progressId);
            if ($progress) {
                $details = [
                    'total_months' => 12,
                    'total_days' => $totalDays,
                    'completed_months' => 0,
                    'completed_days' => 0,
                    'batches_dispatched' => count($batches),
                    'message' => 'Batches dispatched. Processing in background.',
                ];
                $progress->update(['details' => $details]);
            }
        }
    }
}
