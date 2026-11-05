<?php

namespace App\Console\Commands;

use App\Jobs\Knowledge\ProcessMissingKnowledgeSummariesJob;
use App\Services\Knowledge\KnowledgeReprocessingService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ReprocessMissingKnowledgeSummaries extends Command
{
    protected $signature = 'knowledge:reprocess-missing-summaries
                            {--service= : Filter by service (fetch|newsletter)}
                            {--limit=100 : Maximum events to scan}
                            {--dry-run : Show matching events without dispatching jobs}';

    protected $description = 'Queue AI reprocessing for Fetch and Newsletter knowledge events missing TLDR blocks';

    public function handle(KnowledgeReprocessingService $reprocessing): int
    {
        $service = $this->option('service');
        $service = is_string($service) && $service !== '' ? $service : null;
        $limit = max(1, (int) $this->option('limit'));

        if ($this->option('dry-run')) {
            try {
                $events = $reprocessing->missingTldrEvents($service, $limit);
            } catch (InvalidArgumentException $e) {
                $this->error($e->getMessage());

                return Command::FAILURE;
            }

            $this->info("Found {$events->count()} knowledge event(s) missing TLDR blocks.");

            if ($events->isNotEmpty()) {
                $this->table(
                    ['Event ID', 'Service', 'Time', 'Target'],
                    $events->map(fn ($event) => [
                        $event->id,
                        $event->service,
                        $event->time?->toIso8601String(),
                        $event->target?->title,
                    ])->all(),
                );
            }

            return Command::SUCCESS;
        }

        ProcessMissingKnowledgeSummariesJob::dispatch($service, $limit)->onQueue('default');
        $this->info('Missing knowledge summary reprocessing job dispatched.');

        return Command::SUCCESS;
    }
}
