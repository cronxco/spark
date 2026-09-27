<?php

namespace App\Console\Commands;

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use Illuminate\Console\Command;

/**
 * Re-extracts recent newsletter issues that predate the per-issue
 * `newsletter_content` block. Until then an issue's text lived only on its
 * shared publication object, so every issue read back as the latest one and
 * its summaries may have been written against the wrong issue.
 *
 * Extraction is forced, because the pipeline otherwise skips a task that has
 * already succeeded. Summaries are left alone by default: an issue was
 * summarised while its own text was still the publication's latest, so only an
 * issue caught in a race with another from the same publication is wrong, and
 * re-summarising every issue spends a model call apiece. `--resummarise` does
 * it anyway, soft-deleting the old summary blocks so they stay recoverable.
 */
class BackfillNewsletterIssueContent extends Command
{
    protected $signature = 'newsletter:backfill-issue-content
                            {--days=14 : How far back to look}
                            {--limit=200 : Maximum events to queue}
                            {--resummarise : Also regenerate each issue\'s summaries from its own text}
                            {--dry-run : List matching events without queueing them}';

    protected $description = 'Queue re-extraction for newsletter issues missing their own issue text';

    /** @var array<int, string> */
    private const SUMMARY_BLOCK_TYPES = [
        'newsletter_summary_tweet',
        'newsletter_summary_short',
        'newsletter_summary_paragraph',
        'newsletter_key_takeaways',
        'newsletter_tldr',
    ];

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));

        $events = Event::query()
            ->with(['target', 'integration'])
            ->where('service', 'newsletter')
            ->where('action', 'received_post')
            ->where('time', '>=', now()->subDays($days))
            ->whereNotNull('event_metadata->raw_html')
            ->whereDoesntHave('blocks', fn ($query) => $query->where('block_type', 'newsletter_content')->whereNull('deleted_at'))
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

        $resummarise = (bool) $this->option('resummarise');

        foreach ($events as $event) {
            if ($resummarise) {
                $event->blocks()
                    ->whereIn('block_type', self::SUMMARY_BLOCK_TYPES)
                    ->whereNull('deleted_at')
                    ->get()
                    ->each->delete();
            }

            ProcessTaskPipelineJob::dispatch(
                model: $event,
                trigger: 'manual',
                taskFilter: $resummarise
                    ? ['newsletter_extract_content', 'newsletter_generate_summaries']
                    : ['newsletter_extract_content'],
                force: true,
            );
        }

        $this->info("Queued {$events->count()} issue(s) for re-extraction.");

        return Command::SUCCESS;
    }
}
