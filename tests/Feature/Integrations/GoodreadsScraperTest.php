<?php

namespace Tests\Feature\Integrations;

use App\Integrations\Fetch\PlaywrightFetchClient;
use App\Integrations\Goodreads\GoodreadsPageParser;
use App\Integrations\Goodreads\GoodreadsPlugin;
use App\Integrations\Goodreads\GoodreadsScraper;
use App\Jobs\Data\Goodreads\GoodreadsPageData;
use App\Jobs\OAuth\Goodreads\GoodreadsBookPull;
use App\Jobs\OAuth\Goodreads\GoodreadsProgressPull;
use App\Jobs\OAuth\Goodreads\GoodreadsShelfPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\IntegrationRuns\IntegrationRunService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class GoodreadsScraperTest extends TestCase
{
    private Integration $integration;

    private IntegrationGroup $fetchGroup;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id, 'service' => 'goodreads', 'auth_metadata' => ['user_id' => '42', 'source_timezone' => 'America/Los_Angeles'],
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $user->id, 'integration_group_id' => $group->id, 'service' => 'goodreads', 'instance_type' => 'shelf_read',
            'configuration' => ['update_frequency_minutes' => 60],
        ]);
        $this->fetchGroup = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'fetch']);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/Goodreads/'.$name.'.html');
    }

    private function browser(array $pages): PlaywrightFetchClient
    {
        $browser = Mockery::mock(PlaywrightFetchClient::class);
        $browser->shouldReceive('fetchForMcp')->andReturnUsing(function (string $url, IntegrationGroup $group) use ($pages) {
            $this->assertSame($this->fetchGroup->id, $group->id);
            $this->assertArrayHasKey($url, $pages);

            return ['success' => true, 'html' => $pages[$url], 'url' => $url, 'status' => 200, 'html_truncated' => false];
        });
        $this->app->instance(PlaywrightFetchClient::class, $browser);

        return $browser;
    }

    #[Test]
    public function fetches_shelves_through_the_callers_fetch_session_without_an_rss_key(): void
    {
        $this->browser([
            GoodreadsPlugin::buildShelfPageUrl('42', 'read') => $this->fixture('shelf'),
            'https://www.goodreads.com/user/show/42' => $this->fixture('activity'),
        ]);
        $data = $this->app->make(GoodreadsScraper::class)->shelf($this->integration, 'read', 1);
        $this->assertSame('shelf', $data['kind']);
        $this->assertSame('123', $data['items'][0]['book_id']);
        $this->assertCount(3, $data['activity']);
        $schema = GoodreadsPlugin::getGroupConfigurationSchema();
        $this->assertArrayNotHasKey('api_key', $schema);
        $this->assertArrayHasKey('source_timezone', $schema);
    }

    #[Test]
    public function resolves_starting_updates_from_the_review_timeline_and_page_counts_from_the_edition(): void
    {
        $this->browser([
            GoodreadsPlugin::buildStatusPageUrl('42') => $this->fixture('statuses'),
            'https://www.goodreads.com/user/show/42' => $this->fixture('activity'),
            'https://www.goodreads.com/user_status/show/102' => '<html><body><a href="/review/show/9001">Review</a></body></html>',
            'https://www.goodreads.com/review/show/9001' => $this->fixture('review'),
            'https://www.goodreads.com/book/show/123' => $this->fixture('book'),
        ]);
        $data = $this->app->make(GoodreadsScraper::class)->progress($this->integration, 1);
        $this->assertSame([63, 1, 23], array_column(array_slice($data['items'], 0, 3), 'progress_percentage'));
        $this->assertSame('start_reading', $data['items'][3]['type']);
        $this->assertSame('ReadStatus202', $data['items'][3]['guid']);
    }

    #[Test]
    #[DataProvider('failedResponses')]
    public function rejects_fetch_failures_and_does_not_turn_them_into_empty_shelves(array $response): void
    {
        $browser = Mockery::mock(PlaywrightFetchClient::class);
        $browser->shouldReceive('fetchForMcp')->once()->andReturn(array_merge([
            'success' => true, 'html' => $this->fixture('shelf'), 'url' => GoodreadsPlugin::buildShelfPageUrl('42', 'read'),
            'status' => 200, 'html_truncated' => false,
        ], $response));
        $this->expectException(RuntimeException::class);
        (new GoodreadsScraper($browser, new GoodreadsPageParser))->shelf($this->integration, 'read', 1);
    }

    public static function failedResponses(): array
    {
        return [
            [['success' => false, 'error' => 'Browser unavailable']],
            [['html_truncated' => true]],
            [['status' => 429]],
            [['status' => 403]],
            [['url' => 'https://www.goodreads.com/user/sign_in']],
            [['url' => 'https://www.goodreads.com/review/list/999']],
            [['url' => 'https://evil.example/review/list/42']],
        ];
    }

    #[Test]
    public function queues_continuations_and_book_details_without_processing_an_entire_library_in_one_job(): void
    {
        Queue::fake();
        $scraper = Mockery::mock(GoodreadsScraper::class);
        $scraper->shouldReceive('shelf')->once()->with(Mockery::type(Integration::class), 'read', 1)->andReturn([
            'kind' => 'shelf', 'shelf' => 'read', 'items' => [['book_id' => '123']], 'next_page' => 2, 'activity' => [],
        ]);
        $scraper->shouldReceive('needsBookDetails')->with(Mockery::type(Integration::class), '123')->andReturn(true);
        $this->app->instance(GoodreadsScraper::class, $scraper);
        (new GoodreadsShelfPull($this->integration))->handle();
        Queue::assertPushed(GoodreadsPageData::class, fn ($job) => $job->queue === 'pull');
        Queue::assertPushed(GoodreadsBookPull::class, fn ($job) => $job->bookId === '123' && $job->delay !== null);
        Queue::assertPushed(GoodreadsShelfPull::class, fn ($job) => $job->page === 2 && $job->queue === 'pull');
        $this->assertNotSame((new GoodreadsShelfPull($this->integration))->uniqueId(), (new GoodreadsShelfPull($this->integration, 2))->uniqueId());
    }

    #[Test]
    public function progress_continuations_remain_progress_jobs(): void
    {
        Queue::fake();
        $scraper = Mockery::mock(GoodreadsScraper::class);
        $scraper->shouldReceive('progress')->once()->with(Mockery::type(Integration::class), 2)->andReturn([
            'kind' => 'progress', 'items' => [], 'next_page' => 3,
        ]);
        $this->app->instance(GoodreadsScraper::class, $scraper);
        (new GoodreadsProgressPull($this->integration, 2))->handle();
        Queue::assertPushed(GoodreadsProgressPull::class, fn ($job) => $job->page === 3);
        Queue::assertNotPushed(GoodreadsShelfPull::class);
    }

    #[Test]
    public function processing_book_details_and_continuations_all_join_the_same_ingestion_run(): void
    {
        Queue::fake();
        $scraper = Mockery::mock(GoodreadsScraper::class);
        $scraper->shouldReceive('shelf')->once()->andReturn([
            'kind' => 'shelf', 'shelf' => 'read', 'items' => [['book_id' => '123']], 'next_page' => 2, 'activity' => [],
        ]);
        $scraper->shouldReceive('needsBookDetails')->andReturn(true);
        $this->app->instance(GoodreadsScraper::class, $scraper);
        $fetch = new GoodreadsShelfPull($this->integration);
        $batch = app(IntegrationRunService::class)->start($this->integration, [$fetch]);
        $fetch->withBatchId($batch->id)->handle();
        Queue::assertPushed(GoodreadsPageData::class, fn ($job) => $job->batchId === $batch->id);
        Queue::assertPushed(GoodreadsBookPull::class, fn ($job) => $job->batchId === $batch->id);
        Queue::assertPushed(GoodreadsShelfPull::class, fn ($job) => $job->page === 2 && $job->batchId === $batch->id);
        $this->assertSame(4, Bus::findBatch($batch->id)->totalJobs);
    }

    #[Test]
    public function failed_fetches_do_not_advance_the_integration_sync_time(): void
    {
        Queue::fake();
        $scraper = Mockery::mock(GoodreadsScraper::class);
        $scraper->shouldReceive('shelf')->andThrow(new RuntimeException('Sign in required'));
        $this->app->instance(GoodreadsScraper::class, $scraper);
        $before = $this->integration->last_sync_at;
        try {
            (new GoodreadsShelfPull($this->integration))->handle();
            $this->fail('Expected the fetch to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('Sign in required', $e->getMessage());
        }
        Queue::assertNothingPushed();
        $this->assertEquals($before, $this->integration->fresh()->last_sync_at);
    }
}
