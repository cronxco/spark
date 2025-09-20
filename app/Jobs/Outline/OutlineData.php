<?php

namespace App\Jobs\Outline;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use Carbon\CarbonImmutable;
use DateTime;
use Illuminate\Support\Facades\Log;

class OutlineData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'outline';
    }

    protected function getJobType(): string
    {
        return 'data';
    }

    protected function process(): void
    {
        $collections = $this->rawData['collections'] ?? [];
        $documents = $this->rawData['documents'] ?? [];
        $migrationMetadata = $this->rawData['migration_metadata'] ?? null;

        $daynotesCollectionId = (string) (($this->integration->configuration['daynotes_collection_id'] ?? null)
            ?: config('services.outline.daynotes_collection_id'));

        // Upsert collections as objects
        foreach ($collections as $collection) {
            $objectData = [
                'concept' => 'category',
                'type' => 'outline_collection',
                'title' => $collection['name'] ?? 'Collection',
                'time' => $collection['createdAt'] ?? now()->toISOString(),
                'content' => $collection['description'] ?? null,
                'metadata' => $collection,
                'url' => rtrim((string) ($this->integration->configuration['api_url'] ?? config('services.outline.url')), '/') . ($collection['url'] ?? ''),
                'image_url' => null,
            ];

            $this->createOrUpdateObject($objectData);
        }

        // Process documents into events/objects/blocks
        foreach ($documents as $doc) {
            $isDayNote = ($doc['collectionId'] ?? '') === $daynotesCollectionId
                && isset($doc['title'])
                && (bool) DateTime::createFromFormat('Y-m-d: l', $doc['title']);

            // Target object
            $targetConcept = $isDayNote ? 'day_note' : 'document';
            $targetObject = $this->createOrUpdateObject([
                'concept' => $targetConcept,
                'type' => 'outline_document',
                'title' => $doc['title'] ?? 'Document',
                'time' => $doc['createdAt'] ?? now()->toISOString(),
                'content' => $doc['text'] ?? null,
                'metadata' => $doc,
                'url' => rtrim((string) ($this->integration->configuration['api_url'] ?? config('services.outline.url')), '/') . ($doc['url'] ?? ''),
                'image_url' => null,
            ]);

            // Actor object (Outline user who created the doc)
            $createdBy = $doc['createdBy'] ?? [];
            $actor = $this->createOrUpdateObject([
                'concept' => 'b_party',
                'type' => 'outline_user',
                'title' => $createdBy['name'] ?? 'Outline User',
                'time' => $createdBy['createdAt'] ?? now()->toISOString(),
                'content' => null,
                'metadata' => [
                    'outline_user_id' => $createdBy['id'] ?? null,
                    'avatar_url' => $createdBy['avatarUrl'] ?? null,
                ],
                'url' => null,
                'image_url' => $createdBy['avatarUrl'] ?? null,
            ]);

            // Event
            $sourceId = 'outline_doc_' . ($doc['id'] ?? 'unknown');
            $time = $isDayNote
                ? CarbonImmutable::createFromFormat('Y-m-d: l', (string) $doc['title'], 'UTC')->startOfDay()->toIso8601String()
                : ($doc['createdAt'] ?? now()->toISOString());

            $eventData = [[
                'source_id' => $sourceId,
                'time' => $time,
                'actor' => [
                    'concept' => $actor->concept,
                    'type' => $actor->type,
                    'title' => $actor->title,
                    'time' => $actor->time?->toIso8601String(),
                    'content' => $actor->content,
                    'metadata' => $actor->metadata ?? [],
                ],
                'service' => 'outline',
                'domain' => 'knowledge',
                'action' => $isDayNote ? 'had_day_note' : 'created',
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
                'event_metadata' => array_filter($doc, fn ($k) => $k !== 'text', ARRAY_FILTER_USE_KEY),
                'target' => [
                    'concept' => $targetObject->concept,
                    'type' => $targetObject->type,
                    'title' => $targetObject->title,
                    'time' => $targetObject->time?->toIso8601String(),
                    'content' => $targetObject->content,
                    'metadata' => $targetObject->metadata ?? [],
                    'url' => $targetObject->url,
                ],
                'blocks' => $this->extractTaskBlocks($doc, $isDayNote, $targetObject->url, $time),
            ]];

            // If event exists, reconcile task blocks; else create event (with blocks)
            $existingEvent = Event::where('integration_id', $this->integration->id)
                ->where('source_id', $sourceId)
                ->whereNull('deleted_at')
                ->first();

            if ($existingEvent) {
                $this->reconcileTaskBlocks(
                    $existingEvent,
                    $this->extractTaskBlocks($doc, $isDayNote, $targetObject->url, $time),
                    $isDayNote,
                    (string) ($doc['id'] ?? '')
                );
            } else {
                $this->createEvents($eventData);
            }
        }

        // Log migration progress if this is a migration chunk
        if ($migrationMetadata) {
            Log::info('Outline migration chunk processed', [
                'integration_id' => $this->integration->id,
                'offset' => $migrationMetadata['offset'],
                'limit' => $migrationMetadata['limit'],
                'documents_processed' => count($documents),
                'collections_processed' => count($collections),
                'is_last_chunk' => $migrationMetadata['is_last_chunk'],
            ]);
        }
    }

    private function extractTaskBlocks(array $doc, bool $isDayNote, ?string $docUrl, string $time): array
    {
        $text = (string) ($doc['text'] ?? '');
        if ($text === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $blocks = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*- \[( |x|X)\] (.*)$/', $line, $m)) {
                $checked = strtolower(trim($m[1])) === 'x';
                $taskText = trim($m[2]);
                $hash = hash('sha256', ($doc['id'] ?? '') . '|' . ($index + 1) . '|' . strtolower($taskText));

                $blocks[] = [
                    'block_type' => $isDayNote ? 'day_task' : 'doc_task',
                    'time' => $time,
                    'title' => $taskText,
                    'metadata' => [
                        'outline_document_id' => $doc['id'] ?? null,
                        'line_number' => $index + 1,
                        'checked' => $checked,
                        'hash' => $hash,
                    ],
                    'url' => $docUrl,
                ];
            }
        }

        return $blocks;
    }

    private function reconcileTaskBlocks(\App\Models\Event $event, array $currentBlocks, bool $isDayNote, string $docId): void
    {
        // Build map of current by hash
        $currentByHash = [];
        foreach ($currentBlocks as $b) {
            $hash = $b['metadata']['hash'] ?? null;
            if ($hash) {
                $currentByHash[$hash] = $b;
            }
        }

        // Load existing task blocks for this event (both types)
        $existing = $event->blocks
            ->whereIn('block_type', [$isDayNote ? 'day_task' : 'doc_task', $isDayNote ? 'doc_task' : 'day_task'])
            ->filter(function ($block) use ($docId) {
                $meta = $block->metadata ?? [];

                return ($meta['outline_document_id'] ?? null) === $docId;
            });

        $seen = [];

        foreach ($existing as $block) {
            $hash = $block->metadata['hash'] ?? null;
            if (! $hash || ! isset($currentByHash[$hash])) {
                // Task removed -> mark metadata and soft delete
                $meta = $block->metadata ?? [];
                $meta['removed'] = true;
                $meta['removed_at'] = now('UTC')->toIso8601String();
                $block->update([
                    'metadata' => $meta,
                ]);
                $block->delete();

                continue;
            }

            // Update changed fields (title/checked/type/time/url)
            $new = $currentByHash[$hash];
            $newMeta = $block->metadata;
            $newMeta['checked'] = $new['metadata']['checked'] ?? ($newMeta['checked'] ?? false);

            $block->update([
                'block_type' => $new['block_type'] ?? $block->block_type,
                'time' => $new['time'] ?? $block->time,
                'title' => $new['title'] ?? $block->title,
                'metadata' => $newMeta,
                'url' => $new['url'] ?? $block->url,
            ]);

            $seen[$hash] = true;
        }

        // Create new tasks not present before
        foreach ($currentByHash as $hash => $b) {
            if (! isset($seen[$hash])) {
                $event->blocks()->create([
                    'time' => $b['time'] ?? $event->time,
                    'block_type' => $b['block_type'] ?? '',
                    'title' => $b['title'] ?? '',
                    'metadata' => $b['metadata'] ?? [],
                    'url' => $b['url'] ?? null,
                    'media_url' => $b['media_url'] ?? null,
                    'value' => $b['value'] ?? null,
                    'value_multiplier' => $b['value_multiplier'] ?? 1,
                    'value_unit' => $b['value_unit'] ?? null,
                    'embeddings' => $b['embeddings'] ?? null,
                ]);
            }
        }
    }
}
