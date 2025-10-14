<?php

namespace App\Jobs\Data\Karakeep;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;

class KarakeepBookmarkData extends BaseProcessingJob
{
    protected array $contextData;

    public function __construct($integration, array $rawData, array $contextData = [])
    {
        parent::__construct($integration, $rawData);
        $this->contextData = $contextData;
    }

    protected function getServiceName(): string
    {
        return 'karakeep';
    }

    protected function getJobType(): string
    {
        return 'bookmark';
    }

    protected function process(): void
    {
        $bookmark = $this->rawData;
        $bookmarkId = $bookmark['id'] ?? 'unknown';

        Log::info('KarakeepBookmarkData: Processing bookmark', [
            'integration_id' => $this->integration->id,
            'bookmark_id' => $bookmarkId,
        ]);

        try {
            // Extract context data
            $tagsMap = $this->contextData['tags'] ?? [];
            $listsMap = $this->contextData['lists'] ?? [];
            $highlightsMap = $this->contextData['highlights'] ?? [];

            // Process saved_bookmark event
            $this->processSavedBookmark($bookmark, $tagsMap, $highlightsMap);

            // Process added_to_list events for each list this bookmark is in
            $this->processAddedToList($bookmark, $listsMap);

            Log::info('KarakeepBookmarkData: Completed processing bookmark', [
                'integration_id' => $this->integration->id,
                'bookmark_id' => $bookmarkId,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to process Karakeep bookmark', [
                'bookmark_id' => $bookmarkId,
                'error' => $e->getMessage(),
                'integration_id' => $this->integration->id,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // Re-throw to mark job as failed
        }
    }

    protected function processSavedBookmark(array $bookmark, array $tagsMap, array $highlightsMap): void
    {
        $bookmarkId = $bookmark['id'] ?? null;
        if (! $bookmarkId) {
            return;
        }

        // Create or update the user profile object
        $userObject = $this->upsertUserObject();

        // Create or update the bookmark object
        $bookmarkObject = $this->upsertBookmarkObject($bookmark, $tagsMap);

        // Create the saved_bookmark event
        $createdAt = isset($bookmark['createdAt']) ? Carbon::parse($bookmark['createdAt']) : now();
        $sourceId = "karakeep_bookmark_{$bookmarkId}";

        // Check if event already exists
        $existingEvent = Event::where('source_id', $sourceId)
            ->where('integration_id', $this->integration->id)
            ->first();

        if ($existingEvent) {
            Log::debug('Karakeep event already exists, skipping', [
                'source_id' => $sourceId,
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Create the event
        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $createdAt,
            'integration_id' => $this->integration->id,
            'actor_id' => $userObject->id,
            'service' => 'karakeep',
            'domain' => 'knowledge',
            'action' => 'saved_bookmark',
            'value' => null,
            'value_multiplier' => null,
            'value_unit' => null,
            'event_metadata' => [
                'bookmark_id' => $bookmarkId,
                'url' => $bookmark['content']['url'] ?? null,
                'title' => $bookmark['content']['title'] ?? $bookmark['title'] ?? null,
                'content_type' => $bookmark['content']['type'] ?? null,
            ],
            'target_id' => $bookmarkObject->id,
        ]);

        // Add tags to event
        $this->attachTagsToEvent($event, $bookmark, $tagsMap);

        // Create blocks for the event
        $this->createEventBlocks($event, $bookmark, $highlightsMap);

        Log::debug('Created Karakeep saved_bookmark event', [
            'event_id' => $event->id,
            'bookmark_id' => $bookmarkId,
        ]);
    }

    protected function processAddedToList(array $bookmark, array $listsMap): void
    {
        $bookmarkId = $bookmark['id'] ?? null;
        if (! $bookmarkId) {
            return;
        }

        // Get all lists that contain this bookmark
        $bookmarkLists = [];
        foreach ($listsMap as $list) {
            $listBookmarkIds = $list['bookmarkIds'] ?? [];
            if (in_array($bookmarkId, $listBookmarkIds)) {
                $bookmarkLists[] = $list;
            }
        }

        // Create or update the bookmark object
        $bookmarkObject = $this->getBookmarkObject($bookmarkId);
        if (! $bookmarkObject) {
            return;
        }

        // Create added_to_list event for each list
        foreach ($bookmarkLists as $list) {
            $this->createAddedToListEvent($bookmark, $list, $bookmarkObject);
        }
    }

    protected function upsertUserObject(): EventObject
    {
        // Create or get the Karakeep user object
        return EventObject::firstOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'user',
                'type' => 'karakeep_user',
                'title' => 'Karakeep User',
            ],
            [
                'time' => now(),
                'content' => null,
                'metadata' => [
                    'service' => 'karakeep',
                ],
            ]
        );
    }

    protected function upsertBookmarkObject(array $bookmark, array $tagsMap): EventObject
    {
        $bookmarkId = $bookmark['id'] ?? null;

        // Try to find existing bookmark by karakeep_id in metadata
        $bookmarkObject = null;
        if ($bookmarkId) {
            $bookmarkObject = EventObject::where('user_id', $this->integration->user_id)
                ->where('concept', 'bookmark')
                ->where('type', 'karakeep_bookmark')
                ->whereJsonContains('metadata->karakeep_id', $bookmarkId)
                ->first();
        }

        // Prepare content: AI summary + first 150 words of content
        $content = $this->buildBookmarkContent($bookmark);

        // Prepare metadata
        $metadata = [
            'karakeep_id' => $bookmarkId,
            'summary' => $bookmark['summary'] ?? null,
            'description' => $bookmark['content']['description'] ?? null,
            'content_type' => $bookmark['content']['type'] ?? null,
            'read_status' => ($bookmark['favourited'] ?? false) ? 'read' : 'unread',
            'is_archived' => (bool) ($bookmark['archived'] ?? false),
            'is_favorited' => (bool) ($bookmark['favourited'] ?? false),
            'favicon' => $bookmark['content']['favicon'] ?? null,
            'created_at' => $bookmark['createdAt'] ?? null,
            'updated_at' => $bookmark['updatedAt'] ?? null,
        ];

        $title = $bookmark['content']['title'] ?? $bookmark['title'] ?? 'Untitled';
        $url = $bookmark['content']['url'] ?? null;
        $previewImage = $bookmark['content']['imageUrl'] ?? null;
        $createdAt = isset($bookmark['createdAt']) ? Carbon::parse($bookmark['createdAt']) : now();

        // Create or update the bookmark object
        if ($bookmarkObject) {
            $bookmarkObject->update([
                'title' => $title,
                'content' => $content,
                'url' => $url,
                'media_url' => $previewImage,
                'time' => $createdAt,
                'metadata' => $metadata,
            ]);
        } else {
            $bookmarkObject = EventObject::create([
                'user_id' => $this->integration->user_id,
                'integration_id' => $this->integration->id,
                'concept' => 'bookmark',
                'type' => 'karakeep_bookmark',
                'title' => $title,
                'content' => $content,
                'url' => $url,
                'media_url' => $previewImage,
                'time' => $createdAt,
                'metadata' => $metadata,
            ]);
        }

        // Add tags to bookmark object
        $this->attachTagsToObject($bookmarkObject, $bookmark, $tagsMap);

        return $bookmarkObject;
    }

    protected function getBookmarkObject(string $bookmarkId): ?EventObject
    {
        return EventObject::where('user_id', $this->integration->user_id)
            ->where('concept', 'bookmark')
            ->where('type', 'karakeep_bookmark')
            ->whereJsonContains('metadata->karakeep_id', $bookmarkId)
            ->first();
    }

    protected function upsertListObject(array $list): EventObject
    {
        $listId = $list['id'] ?? null;

        // Try to find existing list by karakeep_id in metadata
        $listObject = null;
        if ($listId) {
            $listObject = EventObject::where('user_id', $this->integration->user_id)
                ->where('concept', 'collection')
                ->where('type', 'karakeep_list')
                ->whereJsonContains('metadata->karakeep_id', $listId)
                ->first();
        }

        $title = $list['name'] ?? 'Untitled List';
        $icon = $list['icon'] ?? null;
        $createdAt = isset($list['createdAt']) ? Carbon::parse($list['createdAt']) : now();

        $metadata = [
            'karakeep_id' => $listId,
            'icon' => $icon,
            'parent_id' => $list['parentId'] ?? null,
            'created_at' => $list['createdAt'] ?? null,
            'updated_at' => $list['updatedAt'] ?? null,
        ];

        // Create or update the list object
        if ($listObject) {
            $listObject->update([
                'title' => $title,
                'time' => $createdAt,
                'metadata' => $metadata,
            ]);
        } else {
            $listObject = EventObject::create([
                'user_id' => $this->integration->user_id,
                'integration_id' => $this->integration->id,
                'concept' => 'collection',
                'type' => 'karakeep_list',
                'title' => $title,
                'time' => $createdAt,
                'content' => null,
                'metadata' => $metadata,
            ]);
        }

        return $listObject;
    }

    protected function createAddedToListEvent(array $bookmark, array $list, EventObject $bookmarkObject): void
    {
        $bookmarkId = $bookmark['id'] ?? null;
        $listId = $list['id'] ?? null;

        if (! $bookmarkId || ! $listId) {
            return;
        }

        // Create or update the list object
        $listObject = $this->upsertListObject($list);

        // Create the added_to_list event
        $sourceId = "karakeep_added_to_list_{$bookmarkId}_{$listId}";

        // Check if event already exists
        $existingEvent = Event::where('source_id', $sourceId)
            ->where('integration_id', $this->integration->id)
            ->first();

        if ($existingEvent) {
            return;
        }

        // Use the bookmark's createdAt or current time
        $createdAt = isset($bookmark['createdAt']) ? Carbon::parse($bookmark['createdAt']) : now();

        Event::create([
            'source_id' => $sourceId,
            'time' => $createdAt,
            'integration_id' => $this->integration->id,
            'actor_id' => $bookmarkObject->id,
            'service' => 'karakeep',
            'domain' => 'knowledge',
            'action' => 'added_to_list',
            'value' => null,
            'value_multiplier' => null,
            'value_unit' => null,
            'event_metadata' => [
                'bookmark_id' => $bookmarkId,
                'list_id' => $listId,
                'list_name' => $list['name'] ?? null,
            ],
            'target_id' => $listObject->id,
        ]);

        Log::debug('Created Karakeep added_to_list event', [
            'bookmark_id' => $bookmarkId,
            'list_id' => $listId,
        ]);
    }

    protected function buildBookmarkContent(array $bookmark): string
    {
        $parts = [];

        // Add AI summary if available
        if (! empty($bookmark['summary'])) {
            $parts[] = $bookmark['summary'];
        }

        // Add description if available and different from summary
        $description = $bookmark['content']['description'] ?? null;
        if ($description && $description !== ($bookmark['summary'] ?? null)) {
            $parts[] = $description;
        }

        return implode("\n\n", $parts);
    }

    protected function truncateToWords(string $text, int $wordLimit = 150): string
    {
        $words = explode(' ', $text);
        if (count($words) <= $wordLimit) {
            return $text;
        }

        return implode(' ', array_slice($words, 0, $wordLimit)) . '...';
    }

    protected function attachTagsToEvent(Event $event, array $bookmark, array $tagsMap): void
    {
        $tagNames = [];
        foreach ($bookmark['tags'] ?? [] as $tag) {
            // Tags are already full objects in the bookmark, not just IDs
            if (is_array($tag) && isset($tag['name'])) {
                $tagNames[] = $tag['name'];
            }
        }

        if (! empty($tagNames)) {
            $event->attachTags($tagNames, 'karakeep');
        }
    }

    protected function attachTagsToObject(EventObject $object, array $bookmark, array $tagsMap): void
    {
        $tagNames = [];
        foreach ($bookmark['tags'] ?? [] as $tag) {
            // Tags are already full objects in the bookmark, not just IDs
            if (is_array($tag) && isset($tag['name'])) {
                $tagNames[] = $tag['name'];
            }
        }

        if (! empty($tagNames)) {
            $object->attachTags($tagNames, 'karakeep');
        }
    }

    protected function createEventBlocks(Event $event, array $bookmark, array $highlightsMap): void
    {
        $bookmarkId = $bookmark['id'] ?? null;

        // Create AI summary block if available
        if (! empty($bookmark['summary'])) {
            Block::create([
                'event_id' => $event->id,
                'title' => 'AI Summary',
                'block_type' => 'bookmark_summary',
                'metadata' => [
                    'summary' => $bookmark['summary'],
                ],
            ]);
        }

        // Create metadata block for preview data
        $content = $bookmark['content'] ?? [];
        if (! empty($content)) {
            Block::create([
                'event_id' => $event->id,
                'title' => 'Preview Card',
                'block_type' => 'bookmark_metadata',
                'url' => $content['url'] ?? null,
                'media_url' => $content['imageUrl'] ?? null,
                'metadata' => [
                    'title' => $content['title'] ?? null,
                    'description' => $content['description'] ?? null,
                    'favicon' => $content['favicon'] ?? null,
                ],
            ]);
        }

        // Create highlight blocks
        foreach ($highlightsMap as $highlight) {
            if (($highlight['bookmarkId'] ?? null) === $bookmarkId) {
                $highlightText = $highlight['text'] ?? '';
                $truncatedText = strlen($highlightText) > 50 ? substr($highlightText, 0, 50) . '...' : $highlightText;

                Block::create([
                    'event_id' => $event->id,
                    'title' => 'Highlight: ' . $truncatedText,
                    'block_type' => 'bookmark_highlight',
                    'metadata' => [
                        'text' => $highlight['text'] ?? null,
                        'highlight_id' => $highlight['id'] ?? null,
                        'color' => $highlight['color'] ?? null,
                        'note' => $highlight['note'] ?? null,
                        'created_at' => $highlight['createdAt'] ?? null,
                        'type' => 'highlight',
                    ],
                ]);
            }
        }
    }
}
