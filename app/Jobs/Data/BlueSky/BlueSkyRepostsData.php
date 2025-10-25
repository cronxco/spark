<?php

namespace App\Jobs\Data\BlueSky;

class BlueSkyRepostsData extends BlueSkyBookmarksData
{
    protected function getJobType(): string
    {
        return 'reposts';
    }

    protected function process(): void
    {
        $reposts = $this->rawData['reposts'] ?? [];
        $events = [];

        foreach ($reposts as $repost) {
            $post = $repost['post'] ?? null;

            if (! $post) {
                continue;
            }

            $eventData = $this->processPost($post, 'reposted');

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
