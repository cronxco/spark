<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\Event;
use App\Models\Integration;
use App\Services\Fetch\Assessment\ListAssessment;
use App\Services\Fetch\Assessment\ListPageDetector;
use App\Services\Fetch\Assessment\NewsletterLinkAssessor;
use App\Services\Fetch\Expansion\LinkListExpander;
use App\Services\Fetch\Expansion\ListItem;
use App\Services\Fetch\Links\TrackingLinkResolver;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Bookmarks the articles a digest newsletter links to.
 *
 * Runs alongside the usual extraction and summaries (the news roundup reads
 * those), so a digest keeps its own summary and additionally gains a
 * bookmark per recommended article. Jev being unavailable fails the task so
 * the pipeline retries it.
 */
class NewsletterExpandLinksTask extends BaseTaskJob
{
    public const BLOCK_TYPE = 'newsletter_link_list';

    // Up to 40 links, each with a five-second total redirect budget, plus Jev.
    public $timeout = 300;

    /**
     * The user's existing Fetch integration; link expansion never creates one.
     */
    public static function fetchIntegrationFor(Event $event): ?Integration
    {
        $userId = $event->integration?->user_id;

        if ($userId === null) {
            return null;
        }

        return Integration::query()
            ->where('user_id', $userId)
            ->where('service', 'fetch')
            ->where('instance_type', 'fetcher')
            ->oldest()
            ->first();
    }

    /**
     * Whether this newsletter integration expands digest links.
     */
    public static function isEnabledFor(Event $event): bool
    {
        return ListPageDetector::isEnabled()
            && (bool) ($event->integration?->configuration['expand_links'] ?? true);
    }

    protected function execute(): void
    {
        if (! $this->model instanceof Event) {
            throw new Exception('Newsletter link expansion requires an Event model.');
        }

        // Settings may have changed while the job was queued.
        $event = $this->model->fresh(['target', 'integration']);

        if (! $event || ! self::isEnabledFor($event)) {
            return;
        }
        $html = $event->event_metadata['raw_html'] ?? null;

        if (! is_string($html) || $html === '') {
            throw new Exception('Newsletter event has no raw_html metadata.');
        }

        $fetchIntegration = self::fetchIntegrationFor($event);

        if (! $fetchIntegration) {
            $this->recordAssessment($event, ['status' => ListAssessment::STATUS_SKIPPED, 'reason' => 'User has no Fetch integration']);
            Log::info('Newsletter: Skipping link expansion, no Fetch integration', ['event_id' => $event->id]);

            return;
        }

        $assessment = app(NewsletterLinkAssessor::class)->assess(
            $html,
            (string) ($event->event_metadata['email_subject'] ?? ''),
            (string) ($event->event_metadata['email_from_name'] ?? $event->event_metadata['email_from'] ?? ''),
            (array) ($event->event_metadata['list_unsubscribe'] ?? []),
            ['event_id' => $event->id],
        );

        $record = $assessment->toArray() + ['kind' => $assessment->isList ? 'link_digest' : 'not_digest', 'shadow' => ListPageDetector::isShadow()];
        $this->recordAssessment($event, $record);

        if (! $assessment->isList || ListPageDetector::isShadow()) {
            return;
        }

        $resolver = app(TrackingLinkResolver::class);
        $items = [];
        foreach ($assessment->acceptedItems as $link) {
            $url = $resolver->resolve($link->url, (array) ($event->event_metadata['list_unsubscribe'] ?? []));
            if ($url !== null) {
                $items[] = new ListItem($url, $link->anchorText);
            }
        }

        $result = app(LinkListExpander::class)->expandIssue($fetchIntegration, $event, $items, $record, self::BLOCK_TYPE);

        if ($result->countWithStatus(LinkListExpander::STATUS_RETRYABLE_FAILED) > 0) {
            throw new Exception('Some digest articles could not be bookmarked; retrying unresolved items.');
        }

        Log::info('Newsletter: Digest links expanded', [
            'event_id' => $event->id,
            'links' => count($items),
            'new' => $result->newCount(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function recordAssessment(Event $event, array $record): void
    {
        $metadata = $event->fresh()->event_metadata ?? [];
        $metadata['link_assessment'] = $record;
        $event->update(['event_metadata' => $metadata]);
    }
}
