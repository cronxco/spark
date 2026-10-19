<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Fetch\PlaywrightFetchClient;
use App\Jobs\Data\ManualLog\VivinoEnrichmentData;
use App\Jobs\OAuth\ManualLog\VivinoEnrichmentPull;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class VivinoEnrichmentPullTest extends TestCase
{
    use RefreshDatabase;

    private function resultHtml(): string
    {
        return file_get_contents(base_path('tests/Fixtures/ManualLog/vivino-search-results.html'));
    }

    #[Test]
    public function searches_vivino_and_dispatches_the_data_job_on_a_match(): void
    {
        Queue::fake([VivinoEnrichmentData::class]);

        $this->mock(PlaywrightFetchClient::class)
            ->shouldReceive('fetch')
            ->once()
            ->withArgs(fn (string $url, $group) => str_contains($url, 'q=Raquillet+Mercurey'))
            ->andReturn(['success' => true, 'html' => $this->resultHtml()]);

        $integration = Integration::factory()->create(['service' => 'manual_log']);

        VivinoEnrichmentPull::dispatchSync($integration, 'event-id', 'Raquillet Mercurey');

        Queue::assertPushed(VivinoEnrichmentData::class, 1);
    }

    #[Test]
    public function does_not_dispatch_the_data_job_when_the_best_card_is_an_unrelated_wine(): void
    {
        Queue::fake([VivinoEnrichmentData::class]);

        $this->mock(PlaywrightFetchClient::class)
            ->shouldReceive('fetch')
            ->once()
            ->andReturn(['success' => true, 'html' => $this->resultHtml()]);

        $integration = Integration::factory()->create(['service' => 'manual_log']);

        VivinoEnrichmentPull::dispatchSync($integration, 'event-id', 'Cafe Francois Rouge');

        Queue::assertNotPushed(VivinoEnrichmentData::class);
    }

    #[Test]
    public function does_not_dispatch_the_data_job_when_the_search_returns_no_results(): void
    {
        Queue::fake([VivinoEnrichmentData::class]);

        $this->mock(PlaywrightFetchClient::class)
            ->shouldReceive('fetch')
            ->once()
            ->andReturn(['success' => true, 'html' => '<html><body>No results</body></html>']);

        $integration = Integration::factory()->create(['service' => 'manual_log']);

        VivinoEnrichmentPull::dispatchSync($integration, 'event-id', 'Some Obscure Bottle');

        Queue::assertNotPushed(VivinoEnrichmentData::class);
    }

    #[Test]
    public function does_not_dispatch_the_data_job_when_the_fetch_fails(): void
    {
        Queue::fake([VivinoEnrichmentData::class]);

        $this->mock(PlaywrightFetchClient::class)
            ->shouldReceive('fetch')
            ->once()
            ->andReturn(['success' => false, 'error' => 'timeout']);

        $integration = Integration::factory()->create(['service' => 'manual_log']);

        VivinoEnrichmentPull::dispatchSync($integration, 'event-id', 'Malbec');

        Queue::assertNotPushed(VivinoEnrichmentData::class);
    }

    #[Test]
    public function does_not_dispatch_the_data_job_when_the_fetch_throws(): void
    {
        Queue::fake([VivinoEnrichmentData::class]);

        $this->mock(PlaywrightFetchClient::class)
            ->shouldReceive('fetch')
            ->once()
            ->andThrow(new RuntimeException('worker unavailable'));

        $integration = Integration::factory()->create(['service' => 'manual_log']);

        VivinoEnrichmentPull::dispatchSync($integration, 'event-id', 'Malbec');

        Queue::assertNotPushed(VivinoEnrichmentData::class);
    }
}
