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
        $this->assertArrayNotHasKey('response_body', $entry['context']);
    }

    #[Test]
    public function post_devices_logs_no_request_body_content(): void
    {
        $logs = $this->collectLogs();

        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $canary = str_repeat('a', 64);

        $this->postJson('/api/v1/mobile/devices', [
            'apns_token' => $canary,
            'app_environment' => 'sandbox',
            'bundle_id' => 'co.cronx.spark',
            'app_version' => '1.0.0',
            'os_version' => '17.0',
        ])->assertCreated();

        $entry = $this->findMobileApiLog($logs);
        $this->assertNotNull($entry);
        $this->assertArrayNotHasKey('request_summary', $entry['context']);
        $this->assertSame(5, $entry['context']['request_field_count'] ?? null);
        $this->assertCanaryAbsent($canary, $entry);
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
        $this->assertSame(3, $entry['context']['sample_count'] ?? null);
        $this->assertArrayNotHasKey('request_summary', $entry['context']);
        $this->assertCanaryAbsent('HKQuantityTypeIdentifierHeartRate', $entry);
    }

    #[Test]
    public function etag_304_response_is_logged_as_304(): void
    {
        $logs = $this->collectLogs();

        Sanctum::actingAs(User::factory()->create(), ['ios:read']);
        $this->freezeTime();

        $first = $this->getJson('/api/v1/mobile/ping')->assertOk();
        $etag = $first->headers->get('ETag');

        // The logging middleware now wraps the ETag middleware, so it sees the
        // final 304 that the client receives rather than the underlying 200.
        $this->getJson('/api/v1/mobile/ping', ['If-None-Match' => $etag])->assertStatus(304);

        $mobileApiLogs = $logs->filter(fn ($e) => str_contains($e['message'], 'Mobile API:'));
        $this->assertCount(2, $mobileApiLogs);
        $this->assertSame(304, $mobileApiLogs->last()['context']['response_status']);
    }

    #[Test]
    public function unauthenticated_request_is_logged_with_401_status(): void
    {
        $logs = $this->collectLogs();

        // No Sanctum::actingAs — request carries no credentials.
        $this->getJson('/api/v1/mobile/ping')->assertUnauthorized();

        $entry = $this->findMobileApiLog($logs);
        $this->assertNotNull($entry, 'Expected a Mobile API log entry for the unauthenticated request.');
        $this->assertSame(401, $entry['context']['response_status']);
    }

    #[Test]
    public function query_parameters_are_not_captured_in_log_context(): void
    {
        $logs = $this->collectLogs();

        Sanctum::actingAs(User::factory()->create(), ['ios:read']);
        $this->getJson('/api/v1/mobile/briefing/today?date=2025-01-01')->assertOk();

        $entry = $this->findMobileApiLog($logs);
        $this->assertNotNull($entry);
        // A query value can be a search term, a note fragment or an identifier.
        // Telemetry records the route template, never what the user typed.
        $this->assertArrayNotHasKey('query', $entry['context']);
        $this->assertCanaryAbsent('2025-01-01', $entry);
    }

    #[Test]
    public function a_minted_token_plaintext_never_reaches_the_log(): void
    {
        $logs = $this->collectLogs();

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ios:read', 'tokens:manage', 'data:read']);

        $response = $this->postJson('/api/v1/mobile/api-tokens', [
            'name' => 'Canary',
            'abilities' => ['data:read'],
        ])->assertStatus(201);

        $plaintext = $response->json('plaintext');
        $this->assertNotEmpty($plaintext);

        $entry = $this->findMobileApiLog($logs);
        $this->assertNotNull($entry);
        // The api-tokens response has no `data` key and is well under the old
        // 4 KB body limit, so it used to be logged whole — bearer token included.
        $this->assertCanaryAbsent($plaintext, $entry);
        $this->assertArrayNotHasKey('response_body', $entry['context']);
    }

    #[Test]
    public function log_context_is_limited_to_the_metadata_allowlist(): void
    {
        $logs = $this->collectLogs();

        Sanctum::actingAs(User::factory()->create(), ['ios:read']);
        $this->getJson('/api/v1/mobile/ping')->assertOk();

        $entry = $this->findMobileApiLog($logs);
        $this->assertNotNull($entry);

        $permitted = [
            'route', 'method', 'response_status', 'response_size_bytes', 'duration_ms',
            'request_field_count', 'sample_count', 'item_count', 'has_more', 'next_cursor',
        ];

        $this->assertSame(
            [],
            array_diff(array_keys($entry['context']), $permitted),
            'Unexpected key in mobile API telemetry — every logged field must be a bounded, non-identifying enumeration.',
        );
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

    /** Fails if the canary string appears anywhere in the log entry. */
    private function assertCanaryAbsent(string $canary, array $entry): void
    {
        $this->assertStringNotContainsString(
            $canary,
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'Sensitive value leaked into mobile API telemetry.',
        );
    }

    /** Returns the first log entry whose message contains 'Mobile API:'. */
    private function findMobileApiLog(Collection $logs): ?array
    {
        return $logs->first(fn ($entry) => str_contains($entry['message'], 'Mobile API:'));
    }
}
