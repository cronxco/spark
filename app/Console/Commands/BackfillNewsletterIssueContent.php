<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\Knowledge\KnowledgeReprocessingService;
use Illuminate\Console\Command;

/**
 * Re-extracts recent newsletter issues that predate the per-issue
 * `newsletter_content` block. Until then an issue's text lived only on its
 * shared publication object, so every issue read back as the latest one and
 * its summaries may have been written against the wrong issue.
 */
class BackfillNewsletterIssueContent extends Command
{
    protected $signature = 'newsletter:backfill-issue-content
                            {--days=14 : How far back to look}
                            {--limit=200 : Maximum events to queue}
                            {--dry-run : List matching events without queueing them}';

    protected $description = 'Queue re-extraction for newsletter issues missing their own issue text';

    public function handle(KnowledgeReprocessingService $reprocessing): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));

        $events = Event::query()
            ->with(['target', 'integration'])
            ->where('service', 'newsletter')
            ->where('action', 'received_post')
            ->where('time', '>=', now()->subDays($days))
            ->whereNotNull('event_metadata->raw_html')
            ->whereDoesntHave('blocks', fn ($query) => $query->where('block_type', 'newsletter_content'))
            ->orderByDesc('time')
            ->limit($limit)
            ->get();

        $this->info("Found {$events->count()} newsletter issue(s) without their own content.");

        if ($this->option('dry-run')) {
            $this->table(
                ['Event ID', 'Time', 'Publication', 'Subject'],
                $events->map(fn (Event $event) => [
                    $event->id,
                    $event->time?->toIso8601String(),
                    $event->target?->title,
                    $event->event_metadata['email_subject'] ?? null,
                ])->all(),
            );

            return Command::SUCCESS;
        }

        foreach ($events as $event) {
            $reprocessing->reprocess($event, KnowledgeReprocessingService::MODE_AUTO);
        }

        $this->info("Queued {$events->count()} issue(s) for re-extraction.");

        return Command::SUCCESS;
    }
}
