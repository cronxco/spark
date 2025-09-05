<?php

namespace Tests\Feature\Integrations\Reddit;

use App\Jobs\Data\Reddit\RedditSavedData;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedditSavedDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function creates_event_objects_and_blocks_with_tags(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'reddit',
            'instance_type' => 'saved',
        ]);

        $createdUtc = now()->subMinutes(5)->timestamp;
        $raw = [
            'children' => [
                [
                    'kind' => 't3',
                    'data' => [
                        'id' => 'abc',
                        'created_utc' => $createdUtc,
                        'title' => 'Interesting Post',
                        'permalink' => '/r/laravel/comments/abc/interesting_post/',
                        'url' => 'https://laravel.com',
                        'subreddit' => 'laravel',
                        'author' => 'someone',
                        'score' => 100,
                        'selftext' => 'Checkout https://laravel.com for docs',
                        'preview' => [
                            'images' => [['source' => ['url' => 'https://img.test/pic.jpg']]],
                        ],
                    ],
                ],
            ],
            'me' => [
                'name' => 'testuser',
                'id' => 'abc123',
                'subreddit' => ['display_name_prefixed' => 'u/testuser'],
            ],
        ];

        (new RedditSavedData($integration, $raw))->handle();

        $event = Event::where('integration_id', $integration->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('reddit', $event->service);
        $this->assertEquals('online', $event->domain);
        $this->assertEquals('bookmarked', $event->action);
        $this->assertTrue($event->blocks()->count() >= 2); // image + url

        // Tag exists
        $this->assertTrue($event->hasTag('reddit-subreddit:laravel'));
    }
}
