<?php

namespace Tests\Feature;

use App\Integrations\BlueSky\BlueSkyPlugin;
use App\Jobs\Data\BlueSky\BlueSkyBookmarksData;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BlueSkyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function bluesky_plugin_has_correct_metadata(): void
    {
        $this->assertEquals('bluesky', BlueSkyPlugin::getIdentifier());
        $this->assertEquals('BlueSky', BlueSkyPlugin::getDisplayName());
        $this->assertEquals('oauth', BlueSkyPlugin::getServiceType());

        $description = BlueSkyPlugin::getDescription();
        $this->assertStringContainsString('BlueSky', $description);
        $this->assertStringContainsString('bookmarks', $description);
    }

    #[Test]
    public function bluesky_plugin_has_configuration_schema(): void
    {
        $schema = BlueSkyPlugin::getConfigurationSchema();

        $this->assertIsArray($schema);
        $this->assertArrayHasKey('update_frequency_minutes', $schema);
        $this->assertArrayHasKey('track_bookmarks', $schema);
        $this->assertArrayHasKey('track_likes', $schema);
        $this->assertArrayHasKey('track_reposts', $schema);
    }

    #[Test]
    public function bluesky_plugin_can_process_bookmark(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'bluesky',
            'account_id' => 'did:plc:test123',
            'auth_metadata' => [
                'did' => 'did:plc:test123',
                'handle' => 'testuser.bsky.social',
                'display_name' => 'Test User',
            ],
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'bluesky',
            'instance_type' => 'activity',
            'name' => 'BlueSky Activity',
        ]);

        // Mock bookmark data
        $bookmarkData = [
            'post' => [
                'uri' => 'at://did:plc:author123/app.bsky.feed.post/3l4k5j6h',
                'cid' => 'bafyreibafyrei123',
                'author' => [
                    'did' => 'did:plc:author123',
                    'handle' => 'author.bsky.social',
                    'displayName' => 'Test Author',
                ],
                'record' => [
                    'text' => 'This is a test post on BlueSky #testing',
                    'createdAt' => now()->subHours(2)->toIso8601String(),
                    'facets' => [
                        [
                            'features' => [
                                [
                                    '$type' => 'app.bsky.richtext.facet#tag',
                                    'tag' => 'testing',
                                ],
                            ],
                        ],
                    ],
                ],
                'likeCount' => 42,
                'repostCount' => 13,
                'replyCount' => 7,
                'embed' => [
                    '$type' => 'app.bsky.embed.images#view',
                    'images' => [
                        [
                            'fullsize' => 'https://cdn.bsky.app/img/test123.jpg',
                            'alt' => 'Test image',
                        ],
                    ],
                ],
            ],
        ];

        // Process the bookmark
        $job = new BlueSkyBookmarksData($integration, ['bookmarks' => [$bookmarkData]]);
        $job->handle();

        // Verify event was created
        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('bluesky', $event->service);
        $this->assertEquals('online', $event->domain);
        $this->assertEquals('bookmarked_post', $event->action);

        // Verify actor (user) was created
        $actor = $event->actor;
        $this->assertNotNull($actor);
        $this->assertEquals('user', $actor->concept);
        $this->assertEquals('bluesky_user', $actor->type);
        $this->assertEquals('Test User', $actor->title);

        // Verify target (post) was created
        $target = $event->target;
        $this->assertNotNull($target);
        $this->assertEquals('social', $target->concept);
        $this->assertEquals('bluesky_post', $target->type);
        $this->assertStringContainsString('This is a test post', $target->title);

        // Verify blocks were created
        $blocks = $event->blocks;
        $this->assertGreaterThan(0, $blocks->count());

        // Check for post content block
        $contentBlock = $blocks->where('block_type', 'post_content')->first();
        $this->assertNotNull($contentBlock);
        $this->assertEquals('Post Content', $contentBlock->title);
        $this->assertArrayHasKey('text', $contentBlock->metadata);

        // Check for post metrics block
        $metricsBlock = $blocks->where('block_type', 'post_metrics')->first();
        $this->assertNotNull($metricsBlock);
        $this->assertEquals('Engagement', $metricsBlock->title);
        $this->assertEquals(42, $metricsBlock->metadata['likes']);
        $this->assertEquals(13, $metricsBlock->metadata['reposts']);

        // Check for media block
        $mediaBlock = $blocks->where('block_type', 'post_media')->first();
        $this->assertNotNull($mediaBlock);
        $this->assertEquals('https://cdn.bsky.app/img/test123.jpg', $mediaBlock->media_url);

        // Verify tags were attached
        $tags = $event->tags;
        $this->assertGreaterThan(0, $tags->count());

        $hashtagTag = $tags->where('name', '#testing')->first();
        $this->assertNotNull($hashtagTag);
        $this->assertEquals('bluesky_hashtag', $hashtagTag->type);
    }

    #[Test]
    public function bluesky_plugin_prevents_duplicate_events(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'bluesky',
            'account_id' => 'did:plc:test123',
            'auth_metadata' => [
                'did' => 'did:plc:test123',
                'handle' => 'testuser.bsky.social',
                'display_name' => 'Test User',
            ],
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'bluesky',
            'instance_type' => 'activity',
        ]);

        $bookmarkData = [
            'post' => [
                'uri' => 'at://did:plc:author123/app.bsky.feed.post/3l4k5j6h',
                'cid' => 'bafyreibafyrei123',
                'author' => [
                    'did' => 'did:plc:author123',
                    'handle' => 'author.bsky.social',
                ],
                'record' => [
                    'text' => 'Test post',
                    'createdAt' => now()->toIso8601String(),
                ],
                'likeCount' => 0,
                'repostCount' => 0,
                'replyCount' => 0,
            ],
        ];

        // Process the same bookmark twice
        $job1 = new BlueSkyBookmarksData($integration, ['bookmarks' => [$bookmarkData]]);
        $job1->handle();

        $job2 = new BlueSkyBookmarksData($integration, ['bookmarks' => [$bookmarkData]]);
        $job2->handle();

        // Should only have one event
        $events = Event::where('integration_id', $integration->id)->get();
        $this->assertEquals(1, $events->count());
    }

    #[Test]
    public function bluesky_plugin_handles_quoted_posts(): void
    {
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'bluesky',
            'account_id' => 'did:plc:test123',
            'auth_metadata' => [
                'did' => 'did:plc:test123',
                'handle' => 'testuser.bsky.social',
                'display_name' => 'Test User',
            ],
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'bluesky',
            'instance_type' => 'activity',
        ]);

        $bookmarkData = [
            'post' => [
                'uri' => 'at://did:plc:author123/app.bsky.feed.post/3l4k5j6h',
                'cid' => 'bafyreibafyrei123',
                'author' => [
                    'did' => 'did:plc:author123',
                    'handle' => 'author.bsky.social',
                ],
                'record' => [
                    'text' => 'Check out this post!',
                    'createdAt' => now()->toIso8601String(),
                ],
                'likeCount' => 5,
                'repostCount' => 2,
                'replyCount' => 1,
                'embed' => [
                    '$type' => 'app.bsky.embed.record#view',
                    'record' => [
                        'uri' => 'at://did:plc:quoted/app.bsky.feed.post/quoted123',
                        'record' => [
                            'value' => [
                                'text' => 'This is the quoted post content',
                            ],
                        ],
                        'author' => [
                            'handle' => 'quoted.bsky.social',
                            'displayName' => 'Quoted Author',
                        ],
                    ],
                ],
            ],
        ];

        // Process the bookmark with quoted post
        $job = new BlueSkyBookmarksData($integration, ['bookmarks' => [$bookmarkData]]);
        $job->handle();

        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);

        // Check for quoted post block
        $quotedBlock = $event->blocks->where('block_type', 'quoted_post_content')->first();
        $this->assertNotNull($quotedBlock);
        $this->assertEquals('Quoted Post', $quotedBlock->title);
        $this->assertEquals('This is the quoted post content', $quotedBlock->metadata['text']);
        $this->assertEquals('Quoted Author', $quotedBlock->metadata['author']);
    }
}
