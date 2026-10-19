<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Fetch\PlaywrightFetchClient;
use App\Jobs\Data\ManualLog\UntappdBeerEnrichmentData;
use App\Jobs\OAuth\ManualLog\UntappdBeerEnrichmentPull;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class UntappdBeerEnrichmentPullTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function searches_untappd_and_dispatches_the_data_job_on_a_match(): void
    {
        Queue::fake([UntappdBeerEnrichmentData::class]);

        $this->mock(PlaywrightFetchClient::class)
            ->shouldReceive('fetch')
            ->once()
            ->withArgs(fn (string $url, $group) => str_starts_with($url, 'https://untappd.com/search?q=Verdant+Nebulous+Sky'))
            ->andReturn(['success' => true, 'html' => $this->resultHtml()]);

        $integration = Integration::factory()->create(['service' => 'manual_log']);

        UntappdBeerEnrichmentPull::dispatchSync($integration, 'event-id', 'Verdant Nebulous Sky');

        Queue::assertPushed(UntappdBeerEnrichmentData::class, 1);
    }

    #[Test]
    public function does_not_dispatch_the_data_job_for_an_unrelated_result(): void
    {
        Queue::fake([UntappdBeerEnrichmentData::class]);

        $this->mock(PlaywrightFetchClient::class)
            ->shouldReceive('fetch')
            ->once()
            ->andReturn(['success' => true, 'html' => $this->resultHtml()]);

        $integration = Integration::factory()->create(['service' => 'manual_log']);

        UntappdBeerEnrichmentPull::dispatchSync($integration, 'event-id', 'Guinness Draught');

        Queue::assertNotPushed(UntappdBeerEnrichmentData::class);
    }

    #[Test]
    public function does_not_dispatch_the_data_job_when_the_fetch_fails(): void
    {
        Queue::fake([UntappdBeerEnrichmentData::class]);

        $this->mock(PlaywrightFetchClient::class)
            ->shouldReceive('fetch')
            ->once()
            ->andReturn(['success' => false, 'error' => 'timeout']);

        $integration = Integration::factory()->create(['service' => 'manual_log']);

        UntappdBeerEnrichmentPull::dispatchSync($integration, 'event-id', 'Verdant Nebulous Sky');

        Queue::assertNotPushed(UntappdBeerEnrichmentData::class);
    }

    #[Test]
    public function does_not_dispatch_the_data_job_when_the_fetch_throws(): void
    {
        Queue::fake([UntappdBeerEnrichmentData::class]);

        $this->mock(PlaywrightFetchClient::class)
            ->shouldReceive('fetch')
            ->once()
            ->andThrow(new RuntimeException('worker unavailable'));

        $integration = Integration::factory()->create(['service' => 'manual_log']);

        UntappdBeerEnrichmentPull::dispatchSync($integration, 'event-id', 'Verdant Nebulous Sky');

        Queue::assertNotPushed(UntappdBeerEnrichmentData::class);
    }

    private function resultHtml(): string
    {
        return file_get_contents(base_path('tests/Fixtures/ManualLog/untappd-search-results.html'));
    }
}
