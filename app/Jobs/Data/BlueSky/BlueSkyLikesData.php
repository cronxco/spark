<?php

namespace App\Jobs\Data\BlueSky;

class BlueSkyLikesData extends BlueSkyBookmarksData
{
    protected function getJobType(): string
    {
        return 'likes';
    }

    protected function process(): void
    {
        $likes = $this->rawData['likes'] ?? [];
        $events = [];

        foreach ($likes as $like) {
            $post = $like['post'] ?? null;

            if (! $post) {
                continue;
            }

            $eventData = $this->processPost($post, 'liked_post');

            if ($eventData) {
                $events[] = $eventData;
            }
        }

        // Persist events
        $created = $this->createEventsPayload($events);

        // Attach tags after event creation
        foreach ($created as $event) {
            $tags = $event->event_metadata['__tags'] ?? [];
            foreach ($tags as $tagData) {
                $event->attachTag($tagData['name'], $tagData['type']);
            }
        }
    }
}
