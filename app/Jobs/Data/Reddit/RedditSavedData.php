<?php

namespace App\Jobs\Data\Reddit;

use App\Jobs\Base\BaseProcessingJob;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class RedditSavedData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'reddit';
    }

    protected function getJobType(): string
    {
        return 'saved';
    }

    protected function process(): void
    {
        $children = $this->rawData['children'] ?? [];
        $me = $this->rawData['me'] ?? [];

        $events = [];

        foreach ($children as $post) {
            $kind = $post['kind'] ?? '';
            $data = $post['data'] ?? [];
            $id = $data['id'] ?? null;
            if (! $id) {
                continue;
            }

            $createdUtc = (int) ($data['created_utc'] ?? time());

            // Build images
            $images = [];
            if (! empty($data['preview']['images'])) {
                foreach ($data['preview']['images'] as $image) {
                    $images[] = Arr::get($image, 'source.url');
                }
            }

            // Build URL list
            $contentField = $kind === 't3' ? 'selftext' : 'body';
            $content = (string) ($data[$contentField] ?? '');
            $pattern = '/https?:\/\/[^\s)\]]+/';
            preg_match_all($pattern, $content, $matches);
            $urls = $matches[0] ?? [];

            // Ensure main URL present
            $mainUrl = $kind === 't3' ? ($data['url'] ?? null) : ($data['link_permalink'] ?? null);
            if ($mainUrl && ! in_array($mainUrl, $urls)) {
                $urls[] = $mainUrl;
            }

            // Titles
            if ($kind === 't3') {
                $title = $data['title'] ?? 'Reddit Post';
            } else {
                $title = $data['title'] ?? ('Comment on ' . ($data['link_title'] ?? 'Reddit'));
            }

            // Actor (reddit account)
            $actor = [
                'concept' => 'a_party',
                'type' => 'reddit_account',
                'title' => ($me['subreddit']['display_name_prefixed'] ?? 'u/' . ($me['name'] ?? 'me')),
                'content' => null,
                'metadata' => [
                    'reddit_user_id' => $me['id'] ?? null,
                ],
                'url' => isset($me['name']) ? 'https://www.reddit.com/user/' . $me['name'] : null,
                'image_url' => null,
                'time' => now(),
            ];

            // Target (post or comment)
            $targetConcept = $kind === 't3' ? 'document' : 'social';
            $targetType = $kind === 't3' ? 'reddit_post' : 'reddit_comment';
            $target = [
                'concept' => $targetConcept,
                'type' => $targetType,
                'title' => $title,
                'content' => null,
                'metadata' => [
                    'subreddit' => $data['subreddit'] ?? null,
                    'author' => $data['author'] ?? null,
                    'score' => $data['score'] ?? null,
                ],
                'url' => 'https://old.reddit.com' . ($data['permalink'] ?? ''),
                'image_url' => $images[0] ?? null,
                'time' => Carbon::createFromTimestamp($createdUtc),
            ];

            // Blocks: images
            $blocks = [];
            foreach ($images as $img) {
                if (empty($img)) {
                    continue;
                }
                $blocks[] = [
                    'block_type' => 'image',
                    'title' => 'Image',
                    'metadata' => [
                        'source' => 'reddit_preview',
                    ],
                    'url' => null,
                    'media_url' => $img,
                    'value' => null,
                    'value_multiplier' => 1,
                    'value_unit' => null,
                    'time' => Carbon::createFromTimestamp($createdUtc),
                ];
            }

            // Blocks: urls
            foreach ($urls as $url) {
                $blocks[] = [
                    'block_type' => 'url',
                    'title' => $url,
                    'metadata' => [],
                    'url' => $url,
                    'media_url' => null,
                    'value' => null,
                    'value_multiplier' => 1,
                    'value_unit' => null,
                    'time' => Carbon::createFromTimestamp($createdUtc),
                ];
            }

            $sourceId = "reddit_{$kind}_{$id}_saved";

            $events[] = [
                'source_id' => $sourceId,
                'time' => Carbon::createFromTimestamp($createdUtc),
                'domain' => 'online',
                'action' => 'bookmarked',
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
                'event_metadata' => [],
                'actor' => $actor,
                'target' => $target,
                'blocks' => $blocks,
                'tags' => [
                    'reddit-subreddit:' . ($data['subreddit'] ?? 'unknown'),
                ],
            ];
        }

        // Persist
        $created = $this->createEventsPayload($events);

        // Tag events after creation
        foreach ($created as $event) {
            $tags = $event->event_metadata['__tags'] ?? [];
            foreach ($tags as $tag) {
                $event->attachTag($tag);
            }
        }
    }

    /**
     * Convert payloads to BaseProcessingJob::createEvents structure
     * and create events. Returns created events collection.
     */
    private function createEventsPayload(array $entries)
    {
        $payloads = [];
        foreach ($entries as $entry) {
            $payloads[] = [
                'source_id' => $entry['source_id'],
                'time' => $entry['time'],
                'domain' => $entry['domain'],
                'action' => $entry['action'],
                'value' => $entry['value'],
                'value_multiplier' => $entry['value_multiplier'],
                'value_unit' => $entry['value_unit'],
                'event_metadata' => array_merge($entry['event_metadata'] ?? [], [
                    '__tags' => $entry['tags'] ?? [],
                ]),
                'actor' => $entry['actor'],
                'target' => $entry['target'],
                'blocks' => array_map(function ($b) use ($entry) {
                    return [
                        'time' => $b['time'] ?? $entry['time'],
                        'block_type' => $b['block_type'],
                        'title' => $b['title'],
                        'metadata' => $b['metadata'] ?? [],
                        'url' => $b['url'] ?? null,
                        'media_url' => $b['media_url'] ?? null,
                        'value' => $b['value'] ?? null,
                        'value_multiplier' => $b['value_multiplier'] ?? 1,
                        'value_unit' => $b['value_unit'] ?? null,
                    ];
                }, $entry['blocks'] ?? []),
            ];
        }

        return $this->createEvents($payloads);
    }
}
