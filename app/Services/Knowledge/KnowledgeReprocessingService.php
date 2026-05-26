<?php

namespace App\Services\Knowledge;

use App\Jobs\Fetch\FetchSingleUrl;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

class KnowledgeReprocessingService
{
    public const MODE_AUTO = 'auto';

    public const MODE_SUMMARY_ONLY = 'summary_only';

    public const MODE_REFETCH = 'refetch';

    /**
     * @return array{event_id: string, service: string, status: string, mode: string}
     */
    public function reprocessForUser(User $user, string $eventId, string $mode = self::MODE_AUTO): array
    {
        $event = $this->findUserEvent($user, $eventId);

        if (! $event) {
            throw new RuntimeException('Knowledge event not found.', 404);
        }

        return $this->reprocess($event, $mode);
    }

    /**
     * @return array{event_id: string, service: string, status: string, mode: string}
     */
    public function reprocess(Event $event, string $mode = self::MODE_AUTO): array
    {
        if (! in_array($mode, [self::MODE_AUTO, self::MODE_SUMMARY_ONLY, self::MODE_REFETCH], true)) {
            throw new InvalidArgumentException('Invalid reprocessing mode.');
        }

        if ($event->domain !== 'knowledge' || ! in_array($event->service, ['fetch', 'newsletter'], true)) {
            throw new InvalidArgumentException('Only Fetch and Newsletter knowledge events can be reprocessed.');
        }

        $event->loadMissing(['integration', 'target', 'blocks']);

        if (! $event->integration) {
            throw new InvalidArgumentException('The event does not have an integration.');
        }

        match ($event->service) {
            'fetch' => $this->reprocessFetchEvent($event, $mode),
            'newsletter' => $this->reprocessNewsletterEvent($event, $mode),
        };

        return [
            'event_id' => $event->id,
            'service' => $event->service,
            'status' => 'queued',
            'mode' => $mode,
        ];
    }

    /**
     * @return Collection<int, Event>
     */
    public function missingTldrEvents(?string $service = null, ?int $limit = null): Collection
    {
        $query = Event::query()
            ->where('domain', 'knowledge')
            ->whereIn('service', ['fetch', 'newsletter'])
            ->whereHas('integration')
            ->with(['integration', 'target', 'blocks'])
            ->orderByDesc('time');

        if ($service !== null) {
            if (! in_array($service, ['fetch', 'newsletter'], true)) {
                throw new InvalidArgumentException('Service must be fetch or newsletter.');
            }

            $query->where('service', $service);
        }

        $query->where(function (Builder $query): void {
            $query
                ->where(function (Builder $query): void {
                    $query->where('service', 'fetch')
                        ->whereDoesntHave('blocks', function (Builder $query): void {
                            $this->whereUsableTldrBlock($query, 'fetch_tldr');
                        });
                })
                ->orWhere(function (Builder $query): void {
                    $query->where('service', 'newsletter')
                        ->whereDoesntHave('blocks', function (Builder $query): void {
                            $this->whereUsableTldrBlock($query, 'newsletter_tldr');
                        });
                });
        });

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    private function findUserEvent(User $user, string $eventId): ?Event
    {
        return Event::query()
            ->where('id', $eventId)
            ->whereHas('integration', fn (Builder $query) => $query->where('user_id', $user->id))
            ->with(['integration', 'target', 'blocks'])
            ->first();
    }

    private function reprocessFetchEvent(Event $event, string $mode): void
    {
        $webpage = $event->target;

        if (! $webpage || $webpage->type !== 'fetch_webpage') {
            throw new InvalidArgumentException('Fetch event does not have a webpage target.');
        }

        if ($mode === self::MODE_REFETCH) {
            if (empty($webpage->url)) {
                throw new InvalidArgumentException('Fetch webpage does not have a URL to refetch.');
            }

            FetchSingleUrl::dispatch($event->integration, $webpage->id, $webpage->url, true);

            return;
        }

        if ($mode === self::MODE_SUMMARY_ONLY && empty($webpage->content)) {
            throw new InvalidArgumentException('Fetch webpage has no extracted content to summarize.');
        }

        ProcessTaskPipelineJob::dispatch(
            model: $event,
            trigger: 'manual',
            taskFilter: $mode === self::MODE_AUTO
                ? ['fetch_extract_content', 'fetch_generate_summaries']
                : ['fetch_generate_summaries'],
        );
    }

    private function reprocessNewsletterEvent(Event $event, string $mode): void
    {
        if ($mode === self::MODE_REFETCH) {
            throw new InvalidArgumentException('Newsletter events cannot be refetched.');
        }

        $publication = $event->target;

        if (! $publication || $publication->type !== 'newsletter_publication') {
            throw new InvalidArgumentException('Newsletter event does not have a publication target.');
        }

        if ($mode === self::MODE_SUMMARY_ONLY && empty($publication->content)) {
            throw new InvalidArgumentException('Newsletter event has no extracted content to summarize.');
        }

        ProcessTaskPipelineJob::dispatch(
            model: $event,
            trigger: 'manual',
            taskFilter: $mode === self::MODE_AUTO
                ? ['newsletter_extract_content', 'newsletter_generate_summaries']
                : ['newsletter_generate_summaries'],
        );
    }

    private function whereUsableTldrBlock(Builder $query, string $blockType): void
    {
        $query->where('block_type', $blockType)
            ->whereNull('deleted_at')
            ->whereNotNull('metadata->content')
            ->where('metadata->content', '!=', '');
    }
}
