<?php

namespace App\Jobs\Data\Fetch;

use App\Jobs\Concerns\EnhancedIdempotency;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Services\Ai\AiModel;
use App\Services\Ai\Knowledge\SummaryGenerator;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateSummariesJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes for AI processing

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        public Integration $integration,
        public ?Event $event,
        public EventObject $webpage,
        public array $extracted,
        public string $articleText,
        public ?string $sourceObjectId = null,
        public ?string $sourceEventId = null,
        public bool $sourceIsObject = false
    ) {}

    public function handle(): void
    {
        $isLinkable = ! is_null($this->sourceObjectId) || ! is_null($this->sourceEventId);

        Log::info('Fetch: Generating summaries with AI', [
            'integration_id' => $this->integration->id,
            'event_id' => $this->event?->id,
            'webpage_id' => $this->webpage->id,
            'url' => $this->webpage->url,
            'is_linkable' => $isLinkable,
            'source_object_id' => $this->sourceObjectId,
            'source_event_id' => $this->sourceEventId,
        ]);

        try {
            // Generate summaries using AI
            $summaries = $this->generateSummaries(
                $this->extracted['title'],
                $this->articleText
            );

            // For linkable URLs, attach blocks to source events instead of creating new ones
            if ($isLinkable) {
                $this->attachBlocksToSourceEvents($summaries);
                $this->attachTagsToSourceObjectsAndEvents($summaries);
            } else {
                // Create summary blocks on the fetch event (Blocks 3-9)
                $this->createSummaryBlocks($summaries);

                // Attach tags to webpage EventObject (only if tags are present)
                if (! empty($summaries['emoji']) || ! empty($summaries['tags'])) {
                    $this->attachTags($summaries);
                }
            }

            Log::info('Fetch: Summaries generated successfully', [
                'event_id' => $this->event?->id,
                'url' => $this->webpage->url,
                'is_linkable' => $isLinkable,
            ]);
        } catch (Exception $e) {
            Log::error('Fetch: Summary generation failed', [
                'url' => $this->webpage->url,
                'event_id' => $this->event?->id,
                'error' => $e->getMessage(),
            ]);

            // Update webpage metadata with error
            $metadata = $this->webpage->metadata ?? [];
            $metadata['last_summary_error'] = $e->getMessage();
            $metadata['last_summary_error_at'] = now()->toIso8601String();
            $this->webpage->update(['metadata' => $metadata]);

            throw $e;
        }
    }

    public function uniqueId(): string
    {
        return 'generate_summaries_' . $this->integration->id . '_' . ($this->event?->id ?? 'linkable_' . $this->webpage->id);
    }

    private function generateSummaries(string $title, string $articleText): array
    {
        return app(SummaryGenerator::class)->generate($title, $articleText, ['url' => $this->webpage->url]);
    }

    private function createSummaryBlocks(array $summaries): void
    {
        $model = AiModel::Extraction->model();

        $eventTime = $this->event->time;

        // Store metadata on webpage EventObject instead of as block
        $webpageMetadata = $this->webpage->metadata ?? [];
        $webpageMetadata['author'] = $this->extracted['author'];
        $webpageMetadata['image_url'] = $this->extracted['image'];
        $webpageMetadata['direction'] = $this->extracted['direction'];
        $webpageMetadata['extracted_at'] = now()->toIso8601String();
        $this->webpage->update(['metadata' => $webpageMetadata]);

        // Block 1: Tweet Summary
        $tweetContent = is_array($summaries['summary_tweet']) ? json_encode($summaries['summary_tweet']) : $summaries['summary_tweet'];
        $this->event->createBlock([
            'title' => 'Tweet Summary',
            'block_type' => 'fetch_summary_tweet',
            'time' => $eventTime,
            'metadata' => [
                'content' => $tweetContent,
                'char_count' => strlen($tweetContent),
                'generated_at' => now()->toIso8601String(),
                'model' => $model,
            ],
        ]);

        // Block 2: Short Summary
        $this->event->createBlock([
            'title' => 'Short Summary',
            'block_type' => 'fetch_summary_short',
            'time' => $eventTime,
            'metadata' => [
                'content' => $summaries['summary_short'],
                'word_count' => str_word_count($summaries['summary_short']),
                'generated_at' => now()->toIso8601String(),
                'model' => $model,
            ],
        ]);

        // Block 3: Paragraph Summary
        $this->event->createBlock([
            'title' => 'Paragraph Summary',
            'block_type' => 'fetch_summary_paragraph',
            'time' => $eventTime,
            'metadata' => [
                'content' => $summaries['summary_paragraph'],
                'word_count' => str_word_count($summaries['summary_paragraph']),
                'generated_at' => now()->toIso8601String(),
                'model' => $model,
            ],
        ]);

        // Block 4: Key Takeaways
        $this->event->createBlock([
            'title' => 'Key Takeaways',
            'block_type' => 'fetch_key_takeaways',
            'time' => $eventTime,
            'metadata' => [
                'content' => $summaries['key_takeaways'],
                'count' => count($summaries['key_takeaways']),
                'generated_at' => now()->toIso8601String(),
                'model' => $model,
            ],
        ]);

        // Block 5: TL;DR
        $this->event->createBlock([
            'title' => 'TL;DR',
            'block_type' => 'fetch_tldr',
            'time' => $eventTime,
            'metadata' => [
                'content' => $summaries['tldr'],
                'word_count' => str_word_count($summaries['tldr']),
                'generated_at' => now()->toIso8601String(),
                'model' => $model,
            ],
        ]);

        Log::info('Fetch: Created 5 summary blocks for event', [
            'event_id' => $this->event->id,
        ]);
    }

    private function attachTags(array $summaries): void
    {
        // Attach emoji tag if present
        if (! empty($summaries['emoji'])) {
            // Attach to EventObject
            $this->webpage->attachTags([$summaries['emoji']], 'spark-emoji');

            // Attach to Event (detach old ones first to override)
            $this->event->detachTags($this->event->tagsWithType('spark-emoji'));
            $this->event->attachTags([$summaries['emoji']], 'spark-emoji');

            Log::debug('Fetch: Attached emoji tag', [
                'emoji' => $summaries['emoji'],
                'webpage_id' => $this->webpage->id,
                'event_id' => $this->event->id,
            ]);
        }

        // Attach semantic tags if present
        if (! empty($summaries['tags']) && is_array($summaries['tags'])) {
            // Group tags by type for efficient processing
            $tagsByType = [];
            foreach ($summaries['tags'] as $tagData) {
                if (isset($tagData['tag']) && isset($tagData['tag_type'])) {
                    $tagsByType[$tagData['tag_type']][] = $tagData['tag'];
                }
            }

            // Attach tags by type
            foreach ($tagsByType as $type => $tags) {
                // Attach to EventObject
                $this->webpage->attachTags($tags, $type);

                // Attach to Event (replace existing tags of this type)
                $this->event->detachTags($this->event->tagsWithType($type));
                $this->event->attachTags($tags, $type);

                Log::debug('Fetch: Attached semantic tags', [
                    'tag_type' => $type,
                    'tags' => $tags,
                    'webpage_id' => $this->webpage->id,
                    'event_id' => $this->event->id,
                ]);
            }

            Log::info('Fetch: Attached tags to webpage and event', [
                'webpage_id' => $this->webpage->id,
                'event_id' => $this->event->id,
                'emoji' => $summaries['emoji'] ?? null,
                'tag_count' => count($summaries['tags']),
            ]);
        }

        // Mark one-time bookmarks as completed
        $metadata = $this->webpage->metadata ?? [];
        $fetchMode = $metadata['fetch_mode'] ?? 'recurring';

        if ($fetchMode === 'once') {
            $metadata['discovery_status'] = 'completed';
            $this->webpage->metadata = $metadata;
            $this->webpage->save();

            Log::info('Fetch: Marked one-time bookmark as completed', [
                'webpage_id' => $this->webpage->id,
                'url' => $this->webpage->url,
            ]);
        }
    }

    private function attachBlocksToSourceEvents(array $summaries): void
    {
        $model = AiModel::Extraction->model();

        // Get all relevant events to attach blocks to
        $events = $this->getSourceEvents();

        if ($events->isEmpty()) {
            Log::warning('Fetch: No source events found to attach blocks to', [
                'source_object_id' => $this->sourceObjectId,
                'source_event_id' => $this->sourceEventId,
            ]);

            return;
        }

        Log::info('Fetch: Attaching blocks to source events', [
            'event_count' => $events->count(),
            'event_ids' => $events->pluck('id')->toArray(),
        ]);

        // Store metadata on webpage EventObject instead of as blocks
        $webpageMetadata = $this->webpage->metadata ?? [];
        $webpageMetadata['author'] = $this->extracted['author'];
        $webpageMetadata['image_url'] = $this->extracted['image'];
        $webpageMetadata['direction'] = $this->extracted['direction'];
        $webpageMetadata['extracted_at'] = now()->toIso8601String();
        $this->webpage->update(['metadata' => $webpageMetadata]);

        // Attach 5 summary blocks to each event
        foreach ($events as $event) {
            $eventTime = $event->time;

            // Block 1: Tweet Summary
            $event->createBlock([
                'title' => 'Tweet Summary',
                'block_type' => 'fetch_summary_tweet',
                'time' => $eventTime,
                'metadata' => [
                    'content' => $summaries['summary_tweet'],
                    'char_count' => strlen($summaries['summary_tweet']),
                    'generated_at' => now()->toIso8601String(),
                    'model' => $model,
                ],
            ]);

            // Block 2: Short Summary
            $event->createBlock([
                'title' => 'Short Summary',
                'block_type' => 'fetch_summary_short',
                'time' => $eventTime,
                'metadata' => [
                    'content' => $summaries['summary_short'],
                    'word_count' => str_word_count($summaries['summary_short']),
                    'generated_at' => now()->toIso8601String(),
                    'model' => $model,
                ],
            ]);

            // Block 3: Paragraph Summary
            $event->createBlock([
                'title' => 'Paragraph Summary',
                'block_type' => 'fetch_summary_paragraph',
                'time' => $eventTime,
                'metadata' => [
                    'content' => $summaries['summary_paragraph'],
                    'word_count' => str_word_count($summaries['summary_paragraph']),
                    'generated_at' => now()->toIso8601String(),
                    'model' => $model,
                ],
            ]);

            // Block 4: Key Takeaways
            $event->createBlock([
                'title' => 'Key Takeaways',
                'block_type' => 'fetch_key_takeaways',
                'time' => $eventTime,
                'metadata' => [
                    'content' => $summaries['key_takeaways'],
                    'count' => count($summaries['key_takeaways']),
                    'generated_at' => now()->toIso8601String(),
                    'model' => $model,
                ],
            ]);

            // Block 5: TL;DR
            $event->createBlock([
                'title' => 'TL;DR',
                'block_type' => 'fetch_tldr',
                'time' => $eventTime,
                'metadata' => [
                    'content' => $summaries['tldr'],
                    'word_count' => str_word_count($summaries['tldr']),
                    'generated_at' => now()->toIso8601String(),
                    'model' => $model,
                ],
            ]);

            Log::info('Fetch: Attached 5 summary blocks to source event', [
                'event_id' => $event->id,
            ]);
        }
    }

    private function attachTagsToSourceObjectsAndEvents(array $summaries): void
    {
        // Get source object and events
        $sourceObject = null;
        $sourceEvents = $this->getSourceEvents();

        if ($this->sourceObjectId) {
            $sourceObject = EventObject::find($this->sourceObjectId);
        } elseif ($this->sourceEventId) {
            $sourceEvent = Event::find($this->sourceEventId);
            if ($sourceEvent && $sourceEvent->target_id) {
                $sourceObject = EventObject::find($sourceEvent->target_id);
            }
        }

        // Attach emoji tag if present
        if (! empty($summaries['emoji'])) {
            if ($sourceObject) {
                $sourceObject->attachTags([$summaries['emoji']], 'spark-emoji');
                Log::debug('Fetch: Attached emoji tag to source object', [
                    'emoji' => $summaries['emoji'],
                    'object_id' => $sourceObject->id,
                ]);
            }

            foreach ($sourceEvents as $event) {
                $event->detachTags($event->tagsWithType('spark-emoji'));
                $event->attachTags([$summaries['emoji']], 'spark-emoji');
                Log::debug('Fetch: Attached emoji tag to source event', [
                    'emoji' => $summaries['emoji'],
                    'event_id' => $event->id,
                ]);
            }
        }

        // Attach semantic tags if present
        if (! empty($summaries['tags']) && is_array($summaries['tags'])) {
            $tagsByType = [];
            foreach ($summaries['tags'] as $tagData) {
                if (isset($tagData['tag']) && isset($tagData['tag_type'])) {
                    $tagsByType[$tagData['tag_type']][] = $tagData['tag'];
                }
            }

            foreach ($tagsByType as $type => $tags) {
                if ($sourceObject) {
                    $sourceObject->attachTags($tags, $type);
                }

                foreach ($sourceEvents as $event) {
                    $event->detachTags($event->tagsWithType($type));
                    $event->attachTags($tags, $type);
                }

                Log::debug('Fetch: Attached semantic tags to source objects/events', [
                    'tag_type' => $type,
                    'tags' => $tags,
                    'object_id' => $sourceObject?->id,
                    'event_count' => $sourceEvents->count(),
                ]);
            }
        }

        // Mark one-time bookmarks as completed
        $metadata = $this->webpage->metadata ?? [];
        $fetchMode = $metadata['fetch_mode'] ?? 'recurring';

        if ($fetchMode === 'once') {
            $metadata['discovery_status'] = 'completed';
            $this->webpage->metadata = $metadata;
            $this->webpage->save();

            Log::info('Fetch: Marked one-time linkable bookmark as completed', [
                'webpage_id' => $this->webpage->id,
                'url' => $this->webpage->url,
            ]);
        }
    }

    private function getSourceEvents()
    {
        if ($this->sourceIsObject && $this->sourceObjectId) {
            // URL was from an object - get all events where this object is actor or target
            return Event::where(function ($query) {
                $query->where('actor_id', $this->sourceObjectId)
                    ->orWhere('target_id', $this->sourceObjectId);
            })->get();
        } elseif (! $this->sourceIsObject && $this->sourceEventId) {
            // URL was from an event - return just that event
            $event = Event::find($this->sourceEventId);

            return $event ? collect([$event]) : collect();
        }

        return collect();
    }
}
