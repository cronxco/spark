<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Data\Karakeep\KarakeepBookmarksData;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KarakeepBookmarksDataTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected IntegrationGroup $group;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'karakeep',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'karakeep',
            'instance_type' => 'bookmarks',
        ]);
    }

    /**
     * @test
     */
    public function job_creation(): void
    {
        $rawData = [
            'user' => ['id' => 'user123', 'email' => 'test@example.com'],
            'bookmarks' => [],
            'tags' => [],
            'lists' => [],
            'highlights' => [],
        ];

        $job = new KarakeepBookmarksData($this->integration, $rawData);

        $this->assertInstanceOf(KarakeepBookmarksData::class, $job);
        $this->assertEquals(300, $job->timeout);
        $this->assertEquals(2, $job->tries);
    }

    /**
     * @test
     */
    public function processes_bookmark_creates_objects_and_events(): void
    {
        $rawData = [
            'user' => [
                'id' => 'user123',
                'email' => 'test@example.com',
                'name' => 'Test User',
            ],
            'bookmarks' => [
                [
                    'id' => 'bookmark1',
                    'url' => 'https://example.com',
                    'title' => 'Example Article',
                    'summary' => 'This is a test summary',
                    'content' => 'This is test content with more than enough words to test truncation. ' . str_repeat('word ', 200),
                    'createdAt' => '2025-01-01T00:00:00Z',
                    'tags' => [],
                    'lists' => [],
                ],
            ],
            'tags' => [],
            'lists' => [],
            'highlights' => [],
        ];

        $job = new KarakeepBookmarksData($this->integration, $rawData);
        $job->handle();

        // Check user object was created
        $userObject = EventObject::where('type', 'karakeep_user')->first();
        $this->assertNotNull($userObject);
        $this->assertEquals('Test User', $userObject->title);

        // Check bookmark object was created
        $bookmarkObject = EventObject::where('type', 'karakeep_bookmark')->first();
        $this->assertNotNull($bookmarkObject);
        $this->assertEquals('Example Article', $bookmarkObject->title);
        $this->assertStringContainsString('This is a test summary', $bookmarkObject->content);
        $this->assertEquals('bookmark1', $bookmarkObject->metadata['karakeep_id']);

        // Check event was created
        $event = Event::where('action', 'saved_bookmark')->first();
        $this->assertNotNull($event);
        $this->assertEquals($this->integration->id, $event->integration_id);
        $this->assertEquals($userObject->id, $event->actor_id);
        $this->assertEquals($bookmarkObject->id, $event->target_id);
    }

    /**
     * @test
     */
    public function processes_bookmark_with_tags(): void
    {
        $rawData = [
            'user' => ['id' => 'user123', 'email' => 'test@example.com'],
            'bookmarks' => [
                [
                    'id' => 'bookmark1',
                    'url' => 'https://example.com',
                    'title' => 'Tagged Article',
                    'summary' => 'Summary',
                    'createdAt' => '2025-01-01T00:00:00Z',
                    'tags' => ['tag1', 'tag2'],
                ],
            ],
            'tags' => [
                ['id' => 'tag1', 'name' => 'laravel'],
                ['id' => 'tag2', 'name' => 'php'],
            ],
            'lists' => [],
            'highlights' => [],
        ];

        $job = new KarakeepBookmarksData($this->integration, $rawData);
        $job->handle();

        $bookmarkObject = EventObject::where('type', 'karakeep_bookmark')->first();
        $this->assertNotNull($bookmarkObject);

        // Check tags were attached
        $tags = $bookmarkObject->tags()->get();
        $this->assertCount(2, $tags);
        $this->assertTrue($tags->pluck('name')->contains('laravel'));
        $this->assertTrue($tags->pluck('name')->contains('php'));
    }

    /**
     * @test
     */
    public function processes_bookmark_with_list_memberships(): void
    {
        $rawData = [
            'user' => ['id' => 'user123', 'email' => 'test@example.com'],
            'bookmarks' => [
                [
                    'id' => 'bookmark1',
                    'url' => 'https://example.com',
                    'title' => 'Article in List',
                    'summary' => 'Summary',
                    'createdAt' => '2025-01-01T00:00:00Z',
                    'tags' => [],
                    'lists' => ['list1'],
                ],
            ],
            'tags' => [],
            'lists' => [
                [
                    'id' => 'list1',
                    'name' => 'Reading List',
                    'description' => 'My reading list',
                ],
            ],
            'highlights' => [],
        ];

        $job = new KarakeepBookmarksData($this->integration, $rawData);
        $job->handle();

        // Check list object was created
        $listObject = EventObject::where('type', 'karakeep_list')->first();
        $this->assertNotNull($listObject);
        $this->assertEquals('Reading List', $listObject->title);

        // Check added_to_list event was created
        $listEvent = Event::where('action', 'added_to_list')->first();
        $this->assertNotNull($listEvent);

        // Check that bookmark is actor and list is target
        $bookmarkObject = EventObject::where('type', 'karakeep_bookmark')->first();
        $this->assertEquals($bookmarkObject->id, $listEvent->actor_id);
        $this->assertEquals($listObject->id, $listEvent->target_id);
    }

    /**
     * @test
     */
    public function processes_bookmark_with_highlights(): void
    {
        $rawData = [
            'user' => ['id' => 'user123', 'email' => 'test@example.com'],
            'bookmarks' => [
                [
                    'id' => 'bookmark1',
                    'url' => 'https://example.com',
                    'title' => 'Article with Highlights',
                    'summary' => 'Summary',
                    'createdAt' => '2025-01-01T00:00:00Z',
                    'tags' => [],
                    'lists' => [],
                ],
            ],
            'tags' => [],
            'lists' => [],
            'highlights' => [
                [
                    'bookmarkId' => 'bookmark1',
                    'text' => 'Important quote',
                    'color' => 'yellow',
                    'note' => 'Remember this',
                    'createdAt' => '2025-01-01T01:00:00Z',
                ],
            ],
        ];

        $job = new KarakeepBookmarksData($this->integration, $rawData);
        $job->handle();

        $event = Event::where('action', 'saved_bookmark')->first();
        $this->assertNotNull($event);

        // Check highlight block was created
        $highlightBlock = $event->blocks()->where('block_type', 'bookmark_highlight')->first();
        $this->assertNotNull($highlightBlock);
        $this->assertEquals('Important quote', $highlightBlock->metadata['text']);
        $this->assertEquals('Remember this', $highlightBlock->metadata['note']);
    }

    /**
     * @test
     */
    public function creates_summary_and_metadata_blocks(): void
    {
        $rawData = [
            'user' => ['id' => 'user123', 'email' => 'test@example.com'],
            'bookmarks' => [
                [
                    'id' => 'bookmark1',
                    'url' => 'https://example.com',
                    'title' => 'Article',
                    'summary' => 'AI generated summary',
                    'description' => 'Article description',
                    'imageUrl' => 'https://example.com/image.jpg',
                    'createdAt' => '2025-01-01T00:00:00Z',
                    'tags' => [],
                    'lists' => [],
                ],
            ],
            'tags' => [],
            'lists' => [],
            'highlights' => [],
        ];

        $job = new KarakeepBookmarksData($this->integration, $rawData);
        $job->handle();

        $event = Event::where('action', 'saved_bookmark')->first();
        $this->assertNotNull($event);

        // Check summary block
        $summaryBlock = $event->blocks()->where('block_type', 'bookmark_summary')->first();
        $this->assertNotNull($summaryBlock);
        $this->assertEquals('AI generated summary', $summaryBlock->metadata['summary']);

        // Check metadata block
        $metadataBlock = $event->blocks()->where('block_type', 'bookmark_metadata')->first();
        $this->assertNotNull($metadataBlock);
        $this->assertEquals('Article description', $metadataBlock->metadata['description']);
        $this->assertEquals('https://example.com/image.jpg', $metadataBlock->media_url);
    }

    /**
     * @test
     */
    public function skips_duplicate_events(): void
    {
        $rawData = [
            'user' => ['id' => 'user123', 'email' => 'test@example.com'],
            'bookmarks' => [
                [
                    'id' => 'bookmark1',
                    'url' => 'https://example.com',
                    'title' => 'Article',
                    'summary' => 'Summary',
                    'createdAt' => '2025-01-01T00:00:00Z',
                    'tags' => [],
                    'lists' => [],
                ],
            ],
            'tags' => [],
            'lists' => [],
            'highlights' => [],
        ];

        // Process once
        $job1 = new KarakeepBookmarksData($this->integration, $rawData);
        $job1->handle();

        $eventCount = Event::where('action', 'saved_bookmark')->count();
        $this->assertEquals(1, $eventCount);

        // Process again - should not create duplicate
        $job2 = new KarakeepBookmarksData($this->integration, $rawData);
        $job2->handle();

        $eventCountAfter = Event::where('action', 'saved_bookmark')->count();
        $this->assertEquals(1, $eventCountAfter);
    }

    /**
     * @test
     */
    public function truncates_content_to_150_words(): void
    {
        $longContent = str_repeat('word ', 200); // 200 words

        $rawData = [
            'user' => ['id' => 'user123', 'email' => 'test@example.com'],
            'bookmarks' => [
                [
                    'id' => 'bookmark1',
                    'url' => 'https://example.com',
                    'title' => 'Long Article',
                    'summary' => '',
                    'content' => $longContent,
                    'createdAt' => '2025-01-01T00:00:00Z',
                    'tags' => [],
                    'lists' => [],
                ],
            ],
            'tags' => [],
            'lists' => [],
            'highlights' => [],
        ];

        $job = new KarakeepBookmarksData($this->integration, $rawData);
        $job->handle();

        $bookmarkObject = EventObject::where('type', 'karakeep_bookmark')->first();
        $this->assertNotNull($bookmarkObject);

        // Content should be truncated and end with ...
        $this->assertStringEndsWith('...', $bookmarkObject->content);

        // Count words (approximately 150)
        $wordCount = str_word_count($bookmarkObject->content);
        $this->assertLessThanOrEqual(155, $wordCount); // Allow small margin for truncation
    }
}
