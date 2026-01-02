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
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dayNum = str_pad((string) $d, 2, '0', STR_PAD_LEFT);
                $dayName = date('l', strtotime("{$this->year}-{$monthNum}-{$dayNum}"));
                $jobs->push(new NewDocJob($integration, "{$this->year}-{$monthNum}-{$dayName}", $collectionId, $monthId));
            }

            Bus::batch($jobs->all())
                ->onQueue('pull')
                ->allowFailures()
                ->finally(function () use ($i, $daysInMonth) {
                    // Update progress after each month batch completes
                    if ($this->progressId) {
                        $progress = ActionProgress::find($this->progressId);
                        if ($progress) {
                            $details = $progress->details ?? [];
                            $details['completed_months'] = $i;
                            $details['completed_days'] = ($details['completed_days'] ?? 0) + $daysInMonth;
                            $progress->update(['details' => $details]);
                        }
                    }
                })
                ->dispatch();
        }

        // Mark as completed
        if ($this->progressId) {
            ActionProgress::find($this->progressId)?->markCompleted();
        }
    }
}
