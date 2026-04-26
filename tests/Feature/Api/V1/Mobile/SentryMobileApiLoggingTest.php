<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SentryMobileApiLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
    }

    #[Test]
    public function successful_get_request_is_logged_with_expected_context(): void
    {
        $logs = $this->collectLogs();

        Sanctum::actingAs(User::factory()->create(), ['ios:read']);
        $this->getJson('/api/v1/mobile/ping')->assertOk();

        $entry = $this->findMobileApiLog($logs);
        $this->assertNotNull($entry, 'Expected a Mobile API log entry for the ping request.');
        $this->assertEquals('info', $entry['level']);
        $this->assertStringContainsString('GET', $entry['message']);
        $this->assertSame(200, $entry['context']['response_status']);
        $this->assertStringStartsWith('api.v1.mobile.', $entry['context']['route'] ?? '');
    }

    #[Test]
    public function paginated_response_logs_item_count_without_inlining_the_data_array(): void
    {
        $logs = $this->collectLogs();

        Sanctum::actingAs(User::factory()->create(), ['ios:read']);
        $this->getJson('/api/v1/mobile/integrations')->assertOk();

        $entry = $this->findMobileApiLog($logs);
        $this->assertNotNull($entry);
        $this->assertArrayHasKey('item_count', $entry['context']);
        $this->assertArrayNotHasKey('data', $entry['context']['response_body'] ?? []);
    }

    #[Test]
    public function post_devices_redacts_apns_token_in_request_summary(): void
    {
        $logs = $this->collectLogs();

        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/devices', [
            'apns_token' => str_repeat('a', 64),
            'app_environment' => 'sandbox',
            'bundle_id' => 'co.cronx.spark',
            'app_version' => '1.0.0',
            'os_version' => '17.0',
        ])->assertCreated();

        $entry = $this->findMobileApiLog($logs);
        $this->assertNotNull($entry);
        $summary = $entry['context']['request_summary'] ?? [];
        $this->assertSame('[REDACTED]', $summary['apns_token'] ?? null);
    }

    #[Test]
    public function post_health_samples_logs_sample_count_and_omits_sample_data(): void
    {
        $logs = $this->collectLogs();

        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $samples = array_map(fn ($i) => [
            'external_id' => "sample-{$i}",
            'type' => 'HKQuantityTypeIdentifierHeartRate',
            'start' => now()->subMinutes($i)->toIso8601String(),
            'value' => 72,
            'unit' => 'bpm',
        ], range(1, 3));

        $this->postJson('/api/v1/mobile/health/samples', ['samples' => $samples]);

        $entry = $this->findMobileApiLog($logs);
        $this->assertNotNull($entry);
        $summary = $entry['context']['request_summary'] ?? [];
        $this->assertSame(3, $summary['sample_count'] ?? null);
        $this->assertArrayNotHasKey('samples', $summary);
    }

    #[Test]
    public function etag_304_client_response_is_still_logged_as_the_underlying_200(): void
    {
        $logs = $this->collectLogs();

        Sanctum::actingAs(User::factory()->create(), ['ios:read']);
        $this->freezeTime();

        $first = $this->getJson('/api/v1/mobile/ping')->assertOk();
        $etag = $first->headers->get('ETag');

        // ETag middleware runs after logging middleware, so the client receives 304
        // but the logger captures the underlying 200 (the full content that was available).
        $this->getJson('/api/v1/mobile/ping', ['If-None-Match' => $etag])->assertStatus(304);

        // Two log entries should exist (one per request), both showing 200
        $mobileApiLogs = $logs->filter(fn ($e) => str_contains($e['message'], 'Mobile API:'));
        $this->assertCount(2, $mobileApiLogs);
        $this->assertSame(200, $mobileApiLogs->last()['context']['response_status']);
    }

    #[Test]
    public function query_parameters_are_captured_in_log_context(): void
    {
        $logs = $this->collectLogs();

        Sanctum::actingAs(User::factory()->create(), ['ios:read']);
        // briefing/today accepts a date param and returns a valid response without needing integrations
        $this->getJson('/api/v1/mobile/briefing/today?date=2025-01-01')->assertOk();

        $entry = $this->findMobileApiLog($logs);
        $this->assertNotNull($entry);
        $this->assertSame('2025-01-01', ($entry['context']['query'] ?? [])['date'] ?? null);
    }

    /** Collects MessageLogged events fired during the test. */
    private function collectLogs(): Collection
    {
        $logs = collect();

        app('events')->listen(MessageLogged::class, static function (MessageLogged $event) use ($logs): void {
            $logs->push(['level' => $event->level, 'message' => $event->message, 'context' => $event->context]);
        });

        return $logs;
    }

    /** Returns the first log entry whose message contains 'Mobile API:'. */
    private function findMobileApiLog(Collection $logs): ?array
    {
        return $logs->first(fn ($entry) => str_contains($entry['message'], 'Mobile API:'));
    }
}
