<?php

namespace Tests\Unit\Jobs\Fetch;

use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\EventObject;
use App\Models\Integration;
use App\Services\Media\MediaDownloadHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class FetchSingleUrlTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_classifies_cloudflare_403_as_handled_permanent_failure(): void
    {
        $integration = Integration::factory()->create(['service' => 'fetch']);
        $webpage = EventObject::factory()->create([
            'user_id' => $integration->user_id,
            'type' => 'fetch_webpage',
            'url' => 'https://www.economist.com/the-world-in-brief',
        ]);
        $job = new FetchSingleUrl($integration, $webpage->id, $webpage->url);

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('isHandledPermanentFetchFailure');
        $method->setAccessible(true);

        $handled = $method->invoke(
            $job,
            'Client error: `GET https://www.economist.com/the-world-in-brief` resulted in a `403 Forbidden` response: <!DOCTYPE html><html><head><title>Just a moment...</title></head>'
        );

        $this->assertTrue($handled);
    }

    #[Test]
    public function it_extracts_status_code_from_fetch_error(): void
    {
        $integration = Integration::factory()->create(['service' => 'fetch']);
        $webpage = EventObject::factory()->create(['user_id' => $integration->user_id]);
        $job = new FetchSingleUrl($integration, $webpage->id, 'https://example.com');

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('statusCodeFromError');
        $method->setAccessible(true);

        $this->assertSame(403, $method->invoke($job, 'resulted in a `403 Forbidden` response'));
    }

    #[Test]
    public function successful_pdf_download_resolves_the_repeated_failure_incident(): void
    {
        $integration = Integration::factory()->create(['service' => 'fetch']);
        $webpage = EventObject::factory()->create([
            'user_id' => $integration->user_id,
            'type' => 'fetch_webpage',
            'url' => 'https://example.com/report.pdf',
            'metadata' => ['last_error' => ['message' => 'timeout']],
        ]);

        $activeIncident = DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'fetch_multiple_failures',
            'notifiable_type' => $integration->user->getMorphClass(),
            'notifiable_id' => $integration->user_id,
            'data' => ['title' => 'Repeated failures'],
            'group_key' => "fetch_multiple_failures:{$webpage->id}",
        ]);

        $mediaHelper = Mockery::mock(MediaDownloadHelper::class);
        $mediaHelper->shouldReceive('downloadAndAttachMedia')->once();
        $this->app->instance(MediaDownloadHelper::class, $mediaHelper);

        $job = new FetchSingleUrl($integration, $webpage->id, $webpage->url);
        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('handlePdf');
        $method->setAccessible(true);
        $method->invoke($job, $webpage->fresh());

        $this->assertNotNull($activeIncident->fresh()->archived_at);
        $this->assertSame('resolved', $activeIncident->fresh()->data['archive_reason']);
    }
}
