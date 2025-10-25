<?php

namespace App\Jobs\Data\BlueSky;

use App\Jobs\Base\BaseProcessingJob;
use Carbon\Carbon;

class BlueSkyBookmarksData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'bluesky';
    }

    protected function getJobType(): string
    {
        return 'bookmarks';
    }

    protected function process(): void
    {
        $bookmarks = $this->rawData['bookmarks'] ?? [];
        $events = [];

        foreach ($bookmarks as $bookmark) {
            $post = $bookmark['post'] ?? null;

            if (! $post) {
                continue;
            }

            $eventData = $this->processPost($post, 'bookmarked_post');

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

    protected function processPost(array $post, string $action): ?array
    {
        $uri = $post['uri'] ?? null;
        $cid = $post['cid'] ?? null;

        if (! $uri || ! $cid) {
            return null;
        }

        // Extract post data
        $record = $post['record'] ?? [];
        $author = $post['author'] ?? [];
        $text = $record['text'] ?? '';
        $createdAt = $record['createdAt'] ?? now()->toIso8601String();
        $time = Carbon::parse($createdAt);

        // Engagement metrics
        $likeCount = $post['likeCount'] ?? 0;
        $repostCount = $post['repostCount'] ?? 0;
        $replyCount = $post['replyCount'] ?? 0;

        // Build source ID
        $sourceId = "bluesky_{$action}_" . str_replace(['at://', '/'], ['', '_'], $uri);

        // Actor (current user)
        $group = $this->integration->group;
        $actor = [
            'concept' => 'user',
            'type' => 'bluesky_user',
            'title' => $group->auth_metadata['display_name'] ?? $group->auth_metadata['handle'] ?? 'Me',
            'content' => null,
            'metadata' => [
                'did' => $group->account_id,
                'handle' => $group->auth_metadata['handle'] ?? null,
            ],
            'url' => isset($group->auth_metadata['handle'])
                ? "https://bsky.app/profile/{$group->auth_metadata['handle']}"
                : null,
            'image_url' => $group->auth_metadata['avatar'] ?? null,
            'time' => now(),
        ];

        // Target (the post)
        $authorHandle = $author['handle'] ?? 'unknown';
        $target = [
            'concept' => 'social',
            'type' => 'bluesky_post',
            'title' => substr($text, 0, 100) . (strlen($text) > 100 ? '...' : ''),
            'content' => $text,
            'metadata' => [
                'uri' => $uri,
                'cid' => $cid,
                'author_did' => $author['did'] ?? null,
                'author_handle' => $authorHandle,
                'author_display_name' => $author['displayName'] ?? $authorHandle,
                'like_count' => $likeCount,
                'repost_count' => $repostCount,
                'reply_count' => $replyCount,
            ],
            'url' => "https://bsky.app/profile/{$authorHandle}/post/" . basename($uri),
            'image_url' => null,
            'time' => $time,
        ];

        // Build blocks
        $blocks = [];

        // Post content block
        if (! empty($text)) {
            $blocks[] = [
                'block_type' => 'post_content',
                'title' => 'Post Content',
                'metadata' => [
                    'text' => $text,
                    'author' => $author['displayName'] ?? $authorHandle,
                ],
                'url' => $target['url'],
                'media_url' => null,
                'value' => strlen($text),
                'value_multiplier' => 1,
                'value_unit' => 'characters',
                'time' => $time,
            ];
        }

        // Post metrics block
        $blocks[] = [
            'block_type' => 'post_metrics',
            'title' => 'Engagement',
            'metadata' => [
                'likes' => $likeCount,
                'reposts' => $repostCount,
                'replies' => $replyCount,
            ],
            'url' => $target['url'],
            'media_url' => null,
            'value' => $likeCount + $repostCount,
            'value_multiplier' => 1,
            'value_unit' => 'engagements',
            'time' => $time,
        ];

        // Media blocks (images/videos)
        $embed = $post['embed'] ?? null;
        if ($embed) {
            $blocks = array_merge($blocks, $this->processEmbed($embed, $time, $target['url']));
        }

        // Extract hashtags from facets
        $tags = $this->extractTags($record);

        return [
            'source_id' => $sourceId,
            'time' => $time,
            'domain' => 'online',
            'action' => $action,
            'value' => null,
            'value_multiplier' => 1,
            'value_unit' => null,
            'event_metadata' => [
                'uri' => $uri,
                'cid' => $cid,
            ],
            'actor' => $actor,
            'target' => $target,
            'blocks' => $blocks,
            'tags' => $tags,
        ];
    }

    protected function processEmbed(?array $embed, Carbon $time, string $postUrl): array
    {
        $blocks = [];

        if (! $embed) {
            return $blocks;
        }

        $embedType = $embed['$type'] ?? null;

        // Images
        if ($embedType === 'app.bsky.embed.images#view' || isset($embed['images'])) {
            $images = $embed['images'] ?? [];
            foreach ($images as $image) {
                $fullsize = $image['fullsize'] ?? $image['thumb'] ?? null;
                $alt = $image['alt'] ?? '';

                if ($fullsize) {
                    $blocks[] = [
                        'block_type' => 'post_media',
                        'title' => ! empty($alt) ? $alt : 'Image',
                        'metadata' => [
                            'type' => 'image',
                            'alt' => $alt,
                        ],
                        'url' => $postUrl,
                        'media_url' => $fullsize,
                        'value' => null,
                        'value_multiplier' => 1,
                        'value_unit' => null,
                        'time' => $time,
                    ];
                }
            }
        }

        // Video
        if ($embedType === 'app.bsky.embed.video#view' || isset($embed['playlist'])) {
            $playlist = $embed['playlist'] ?? null;
            $thumbnail = $embed['thumbnail'] ?? null;

            if ($playlist) {
                $blocks[] = [
                    'block_type' => 'post_media',
                    'title' => 'Video',
                    'metadata' => [
                        'type' => 'video',
                        'playlist' => $playlist,
                    ],
                    'url' => $postUrl,
                    'media_url' => $thumbnail,
                    'value' => null,
                    'value_multiplier' => 1,
                    'value_unit' => null,
                    'time' => $time,
                ];
            }
        }

        // External link (link preview)
        if ($embedType === 'app.bsky.embed.external#view' || isset($embed['external'])) {
            $external = $embed['external'] ?? null;

            if ($external) {
                $blocks[] = [
                    'block_type' => 'link_preview',
                    'title' => $external['title'] ?? $external['uri'] ?? 'Link',
                    'metadata' => [
                        'url' => $external['uri'] ?? null,
                        'description' => $external['description'] ?? null,
                    ],
                    'url' => $external['uri'] ?? null,
                    'media_url' => $external['thumb'] ?? null,
                    'value' => null,
                    'value_multiplier' => 1,
                    'value_unit' => null,
                    'time' => $time,
                ];
            }
        }

        // Quoted post (record embed)
        if ($embedType === 'app.bsky.embed.record#view' || isset($embed['record'])) {
            $recordEmbed = $embed['record'] ?? null;

            if ($recordEmbed && isset($recordEmbed['record'])) {
                $quotedPost = $recordEmbed['record'];
                $quotedText = $quotedPost['value']['text'] ?? '';
                $quotedAuthor = $recordEmbed['author'] ?? [];

                if ($quotedText) {
                    $blocks[] = [
                        'block_type' => 'quoted_post_content',
                        'title' => 'Quoted Post',
                        'metadata' => [
                            'text' => $quotedText,
                            'author' => $quotedAuthor['displayName'] ?? $quotedAuthor['handle'] ?? 'unknown',
                            'author_handle' => $quotedAuthor['handle'] ?? null,
                        ],
                        'url' => $quotedPost['uri'] ?? null,
                        'media_url' => null,
                        'value' => null,
                        'value_multiplier' => 1,
                        'value_unit' => null,
                        'time' => $time,
                    ];
                }
            }
        }

        // Record with media (quote post + images)
        if ($embedType === 'app.bsky.embed.recordWithMedia#view') {
            // Process both record and media
            if (isset($embed['record'])) {
                $blocks = array_merge($blocks, $this->processEmbed($embed['record'], $time, $postUrl));
            }
            if (isset($embed['media'])) {
                $blocks = array_merge($blocks, $this->processEmbed($embed['media'], $time, $postUrl));
            }
        }

        return $blocks;
    }

    protected function extractTags(array $record): array
    {
        $tags = [];
        $facets = $record['facets'] ?? [];

        foreach ($facets as $facet) {
            $features = $facet['features'] ?? [];

            foreach ($features as $feature) {
                $featureType = $feature['$type'] ?? null;

                // Hashtags
                if ($featureType === 'app.bsky.richtext.facet#tag') {
                    $tag = $feature['tag'] ?? null;
                    if ($tag) {
                        $tags[] = [
                            'name' => "#{$tag}",
                            'type' => 'bluesky_hashtag',
                        ];
                    }
                }

                // Mentions
                if ($featureType === 'app.bsky.richtext.facet#mention') {
                    $did = $feature['did'] ?? null;
                    if ($did) {
                        $tags[] = [
                            'name' => $did,
                            'type' => 'bluesky_mention',
                        ];
                    }
                }
            }
        }

        return $tags;
    }

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
