<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Data\Karakeep\KarakeepBookmarkData;
use App\Jobs\Data\Karakeep\KarakeepBookmarksData;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
    public function handles_empty_bookmarks(): void
    {
        Queue::fake();

        $rawData = [
            'user' => ['id' => 'user123', 'email' => 'test@example.com'],
            'bookmarks' => [],
            'tags' => [],
            'lists' => [],
            'highlights' => [],
        ];

        $job = new KarakeepBookmarksData($this->integration, $rawData);
        $job->handle();

        Queue::assertNotPushed(KarakeepBookmarkData::class);
    }

    /**
     * @test
     */
    public function dispatches_individual_bookmark_jobs(): void
    {
        Queue::fake();

        $rawData = [
            'user' => [
                'id' => 'user123',
                'email' => 'test@example.com',
                'name' => 'Test User',
            ],
            'bookmarks' => [
                [
                    'id' => 'bookmark1',
                    'createdAt' => '2025-01-01T00:00:00Z',
                    'summary' => 'This is a test summary',
                    'content' => [
                        'type' => 'link',
                        'url' => 'https://example.com',
                        'title' => 'Example Article',
                        'description' => 'Test content',
                    ],
                    'tags' => [],
                    'lists' => [],
                ],
                [
                    'id' => 'bookmark2',
                    'createdAt' => '2025-01-01T00:00:00Z',
                    'summary' => 'Another summary',
                    'content' => [
                        'type' => 'link',
                        'url' => 'https://example2.com',
                        'title' => 'Second Article',
                        'description' => 'More test content',
                    ],
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

        // Assert that individual jobs were dispatched for each bookmark
        Queue::assertPushed(KarakeepBookmarkData::class, 2);
        Queue::assertPushed(KarakeepBookmarkData::class, function ($job) {
            return $job->getBookmarkId() === 'bookmark1';
        });
        Queue::assertPushed(KarakeepBookmarkData::class, function ($job) {
            return $job->getBookmarkId() === 'bookmark2';
        });
    }

    /**
     * @test
     */
    public function dispatches_jobs_with_context_data(): void
    {
        Queue::fake();

        $rawData = [
            'user' => [
                'id' => 'user123',
                'email' => 'test@example.com',
                'name' => 'Test User',
            ],
            'bookmarks' => [
                [
                    'id' => 'bookmark1',
                    'createdAt' => '2025-01-01T00:00:00Z',
                    'content' => [
                        'type' => 'link',
                        'url' => 'https://example.com',
                        'title' => 'Tagged Article',
                    ],
                    'summary' => 'Summary',
                    'tags' => ['tag1', 'tag2'],
                    'lists' => ['list1'],
                ],
            ],
            'tags' => [
                ['id' => 'tag1', 'name' => 'laravel'],
                ['id' => 'tag2', 'name' => 'php'],
            ],
            'lists' => [
                [
                    'id' => 'list1',
                    'name' => 'Reading List',
                    'description' => 'My reading list',
                ],
            ],
            'highlights' => [
                [
                    'bookmarkId' => 'bookmark1',
                    'text' => 'Important quote',
                ],
            ],
        ];

        $job = new KarakeepBookmarksData($this->integration, $rawData);
        $job->handle();

        Queue::assertPushed(KarakeepBookmarkData::class, function ($job) {
            $contextData = $job->getContextData();

            return $job->getBookmarkId() === 'bookmark1' &&
                $contextData['user']['name'] === 'Test User' &&
                isset($contextData['tags']['tag1']) &&
                isset($contextData['lists']['list1']) &&
                isset($contextData['highlights'][0]);
        });
    }

    /**
     * @test
     */
    public function skips_bookmarks_without_id(): void
    {
        Queue::fake();

        $rawData = [
            'user' => ['id' => 'user123', 'email' => 'test@example.com'],
            'bookmarks' => [
                [
                    'createdAt' => '2025-01-01T00:00:00Z',
                    'content' => [
                        'type' => 'link',
                        'url' => 'https://example.com',
                        'title' => 'Missing ID Article',
                    ],
                ],
                [
                    'id' => 'bookmark1',
                    'createdAt' => '2025-01-01T00:00:00Z',
                    'content' => [
                        'type' => 'link',
                        'url' => 'https://example2.com',
                        'title' => 'Valid Article',
                    ],
                ],
            ],
            'tags' => [],
            'lists' => [],
            'highlights' => [],
        ];

        $job = new KarakeepBookmarksData($this->integration, $rawData);
        $job->handle();

        // Only the valid bookmark should be dispatched
        Queue::assertPushed(KarakeepBookmarkData::class, 1);
        Queue::assertPushed(KarakeepBookmarkData::class, function ($job) {
            return $job->getBookmarkId() === 'bookmark1';
        });
    }
}
