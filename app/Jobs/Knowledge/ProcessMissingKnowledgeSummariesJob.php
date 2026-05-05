<?php

namespace App\Jobs\Knowledge;

use App\Services\Knowledge\KnowledgeReprocessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ProcessMissingKnowledgeSummariesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function __construct(
        public ?string $service = null,
        public ?int $limit = 100,
    ) {}

    public function handle(KnowledgeReprocessingService $reprocessing): void
    {
        $events = $reprocessing->missingTldrEvents($this->service, $this->limit);
        $queued = 0;
        $skipped = 0;

        foreach ($events as $event) {
            try {
                $reprocessing->reprocess($event, KnowledgeReprocessingService::MODE_AUTO);
                $queued++;
            } catch (InvalidArgumentException $e) {
                $skipped++;

                Log::warning('Knowledge: Skipping missing summary reprocessing', [
                    'event_id' => $event->id,
                    'service' => $event->service,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Knowledge: Missing summary scan completed', [
            'service' => $this->service,
            'limit' => $this->limit,
            'examined' => $events->count(),
            'queued' => $queued,
            'skipped' => $skipped,
        ]);
    }
}
