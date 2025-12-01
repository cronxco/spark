<?php

namespace Tests\Unit\Integrations;

use App\Jobs\Data\Untappd\UntappdRssData;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UntappdRssDataTest extends TestCase
{
    use RefreshDatabase;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'untappd',
            'auth_metadata' => [
                'rss_url' => 'https://untappd.com/rss/user/testuser?key=test123',
            ],
        ]);

        $this->integration = Integration::create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'untappd',
            'name' => 'Untappd RSS',
            'instance_type' => 'rss_feed',
            'configuration' => ['update_frequency_minutes' => 30],
        ]);
    }

    /** @test */
    public function it_parses_beer_checkin_with_brewery()
    {
        $items = [
            [
                'guid' => 'https://untappd.com/user/alice/checkin/1234567890',
                'pubDate' => 'Sun, 16 Nov 2025 19:16:25 +0000',
                'title' => 'Alice S. is drinking a Test IPA by Great Brewery',
                'link' => 'https://untappd.com/user/alice/checkin/1234567890',
                'description' => '',
            ],
        ];

        $job = new UntappdRssData($this->integration, ['items' => $items]);
        $job->handle();

        $this->assertDatabaseHas('events', [
            'service' => 'untappd',
            'action' => 'drank',
            'domain' => 'health',
        ]);

        $event = Event::where('service', 'untappd')->first();
        $this->assertEquals('drank', $event->action);
        $this->assertNull($event->value);

        // Check actor
        $this->assertEquals('untappd_user', $event->actor->type);
        $this->assertEquals('Alice S.', $event->actor->title);

        // Check target (beer)
        $this->assertEquals('untappd_beer', $event->target->type);
        $this->assertEquals('Test IPA', $event->target->title);
        $this->assertEquals('Great Brewery', $event->target->metadata['brewery']);

        // Check brewery block
        $breweryBlock = $event->blocks->where('block_type', 'beer_brewery')->first();
        $this->assertNotNull($breweryBlock);
        $this->assertEquals('Great Brewery', $breweryBlock->title);

        // Check brewery tag
        $tags = $event->tags;
        $this->assertTrue($tags->contains('name', 'Great Brewery'));
        $breweryTag = $tags->where('name', 'Great Brewery')->first();
        $this->assertEquals('untappd_brewery', $breweryTag->type);
    }

    /** @test */
    public function it_parses_beer_checkin_with_venue()
    {
        $items = [
            [
                'guid' => 'https://untappd.com/user/bob/checkin/9876543210',
                'pubDate' => 'Fri, 20 Jun 2025 18:11:11 +0000',
                'title' => 'Bob D. is drinking a Craft Lager by Amazing Brewing at Cool Bar',
                'link' => 'https://untappd.com/user/bob/checkin/9876543210',
                'description' => '',
            ],
        ];

        $job = new UntappdRssData($this->integration, ['items' => $items]);
        $job->handle();

        $event = Event::where('service', 'untappd')->first();
        $this->assertEquals('Bob D.', $event->actor->title);
        $this->assertEquals('Craft Lager', $event->target->title);
        $this->assertEquals('Amazing Brewing', $event->target->metadata['brewery']);
        $this->assertEquals('Cool Bar', $event->target->metadata['venue']);

        // Check tags
        $tags = $event->tags;
        $this->assertTrue($tags->contains('name', 'Amazing Brewing'));
        $this->assertTrue($tags->contains('name', 'Cool Bar'));

        $venueTag = $tags->where('name', 'Cool Bar')->first();
        $this->assertEquals('untappd_venue', $venueTag->type);
    }

    /** @test */
    public function it_parses_beer_checkin_with_comment()
    {
        $items = [
            [
                'guid' => 'https://untappd.com/user/charlie/checkin/1111111111',
                'pubDate' => 'Sun, 05 Oct 2025 13:10:23 +0000',
                'title' => 'Charlie M. is drinking a Delicious Stout by Perfect Brewery',
                'link' => 'https://untappd.com/user/charlie/checkin/1111111111',
                'description' => 'Absolutely fantastic!',
            ],
        ];

        $job = new UntappdRssData($this->integration, ['items' => $items]);
        $job->handle();

        $event = Event::where('service', 'untappd')->first();

        // Check comment block
        $commentBlock = $event->blocks->where('block_type', 'beer_comment')->first();
        $this->assertNotNull($commentBlock);
        $this->assertEquals('Absolutely fantastic!', $commentBlock->title);
    }

    /** @test */
    public function it_handles_html_entities_in_beer_names()
    {
        $items = [
            [
                'guid' => 'https://untappd.com/user/diana/checkin/2222222222',
                'pubDate' => 'Thu, 13 Nov 2025 21:13:34 +0000',
                'title' => 'Diana L. is drinking an Enchanté by Fancy Brewery',
                'link' => 'https://untappd.com/user/diana/checkin/2222222222',
                'description' => '',
            ],
        ];

        $job = new UntappdRssData($this->integration, ['items' => $items]);
        $job->handle();

        $event = Event::where('service', 'untappd')->first();
        // HTML entity should be decoded
        $this->assertEquals('Enchanté', $event->target->title);
    }

    /** @test */
    public function it_parses_beer_with_special_characters()
    {
        $items = [
            [
                'guid' => 'https://untappd.com/user/eve/checkin/3333333333',
                'pubDate' => 'Sun, 16 Nov 2025 16:12:08 +0000',
                'title' => 'Eve R. is drinking a Brewer&apos;s Choice [2025] by Artisan Brewery',
                'link' => 'https://untappd.com/user/eve/checkin/3333333333',
                'description' => '',
            ],
        ];

        $job = new UntappdRssData($this->integration, ['items' => $items]);
        $job->handle();

        $event = Event::where('service', 'untappd')->first();
        // Should decode &apos; to '
        $this->assertEquals("Brewer's Choice [2025]", $event->target->title);
        $this->assertEquals('Artisan Brewery', $event->target->metadata['brewery']);
    }

    /** @test */
    public function it_handles_multiple_checkins_in_one_batch()
    {
        $items = [
            [
                'guid' => 'https://untappd.com/user/frank/checkin/1001',
                'pubDate' => 'Sun, 16 Nov 2025 19:16:25 +0000',
                'title' => 'Frank P. is drinking a Beer One by Brewery A',
                'link' => 'https://untappd.com/user/frank/checkin/1001',
                'description' => '',
            ],
            [
                'guid' => 'https://untappd.com/user/frank/checkin/1002',
                'pubDate' => 'Sat, 15 Nov 2025 18:00:00 +0000',
                'title' => 'Frank P. is drinking a Beer Two by Brewery B at Venue X',
                'link' => 'https://untappd.com/user/frank/checkin/1002',
                'description' => 'Great atmosphere',
            ],
            [
                'guid' => 'https://untappd.com/user/frank/checkin/1003',
                'pubDate' => 'Fri, 14 Nov 2025 17:30:00 +0000',
                'title' => 'Frank P. is drinking an Beer Three by Brewery C',
                'link' => 'https://untappd.com/user/frank/checkin/1003',
                'description' => '',
            ],
        ];

        $job = new UntappdRssData($this->integration, ['items' => $items]);
        $job->handle();

        $events = Event::where('service', 'untappd')->get();
        $this->assertCount(3, $events);

        // All should be drank actions
        $this->assertTrue($events->every(fn ($e) => $e->action === 'drank'));

        // Check that one has a venue
        $eventWithVenue = $events->first(fn ($e) => ! empty($e->target->metadata['venue']));
        $this->assertNotNull($eventWithVenue);
        $this->assertEquals('Venue X', $eventWithVenue->target->metadata['venue']);

        // Check that one has a comment
        $eventWithComment = $events->first(fn ($e) => $e->blocks->where('block_type', 'beer_comment')->isNotEmpty());
        $this->assertNotNull($eventWithComment);
    }

    /** @test */
    public function it_uses_guid_for_idempotency()
    {
        $items = [
            [
                'guid' => 'https://untappd.com/user/george/checkin/9999999999',
                'pubDate' => 'Sun, 16 Nov 2025 19:16:25 +0000',
                'title' => 'George T. is drinking a Test Beer by Test Brewery',
                'link' => 'https://untappd.com/user/george/checkin/9999999999',
                'description' => '',
            ],
        ];

        // Run the job twice
        $job1 = new UntappdRssData($this->integration, ['items' => $items]);
        $job1->handle();

        $job2 = new UntappdRssData($this->integration, ['items' => $items]);
        $job2->handle();

        // Should only create one event due to source_id uniqueness
        $events = Event::where('service', 'untappd')->get();
        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertEquals('untappd_' . md5('https://untappd.com/user/george/checkin/9999999999'), $event->source_id);
    }

    /** @test */
    public function it_creates_both_brewery_block_and_tag()
    {
        $items = [
            [
                'guid' => 'https://untappd.com/user/helen/checkin/7777777777',
                'pubDate' => 'Sun, 16 Nov 2025 19:16:25 +0000',
                'title' => 'Helen W. is drinking a Sample Beer by Sample Brewery',
                'link' => 'https://untappd.com/user/helen/checkin/7777777777',
                'description' => '',
            ],
        ];

        $job = new UntappdRssData($this->integration, ['items' => $items]);
        $job->handle();

        $event = Event::where('service', 'untappd')->first();

        // Check brewery block exists
        $breweryBlocks = $event->blocks->where('block_type', 'beer_brewery');
        $this->assertCount(1, $breweryBlocks);
        $this->assertEquals('Sample Brewery', $breweryBlocks->first()->title);

        // Check brewery tag exists
        $breweryTags = $event->tags->where('type', 'untappd_brewery');
        $this->assertCount(1, $breweryTags);
        $this->assertEquals('Sample Brewery', $breweryTags->first()->name);
    }

    /** @test */
    public function it_skips_items_without_guid()
    {
        $items = [
            [
                'guid' => '',
                'pubDate' => 'Sun, 16 Nov 2025 19:16:25 +0000',
                'title' => 'Invalid User is drinking a Invalid Beer by Invalid Brewery',
                'link' => 'https://untappd.com/user/invalid/checkin/0',
                'description' => '',
            ],
        ];

        $job = new UntappdRssData($this->integration, ['items' => $items]);
        $job->handle();

        $events = Event::where('service', 'untappd')->get();
        $this->assertCount(0, $events);
    }

    /** @test */
    public function it_skips_items_that_dont_match_pattern()
    {
        $items = [
            [
                'guid' => 'https://untappd.com/user/invalid/checkin/8888888888',
                'pubDate' => 'Sun, 16 Nov 2025 19:16:25 +0000',
                'title' => 'This is not a valid title format',
                'link' => 'https://untappd.com/user/invalid/checkin/8888888888',
                'description' => '',
            ],
        ];

        $job = new UntappdRssData($this->integration, ['items' => $items]);
        $job->handle();

        $events = Event::where('service', 'untappd')->get();
        $this->assertCount(0, $events);
    }
}
