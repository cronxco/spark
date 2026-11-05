<?php

namespace Tests\Feature\Console;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * PSEC-08 / INT-01, the audit trail half.
 *
 * Spatie's logFillable() reads attributes through their casts, so every
 * changelog entry for an Integration or an IntegrationGroup could carry an
 * api_key, an access token or a session cookie in plaintext — in a table that
 * is neither encrypted nor rotated. The RedactsLoggedProperties trait stops new
 * ones; this command cleans up the ones already written.
 */
class RedactActivityLogCredentialsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_redacts_a_credential_and_keeps_the_rest_of_the_diff(): void
    {
        $id = $this->seedActivityRow(Integration::class, [
            'attributes' => [
                'name' => 'My Hevy',
                'configuration' => [
                    'api_key' => 'hev_live_secret',
                    'days_back' => 30,
                    'key' => 'display-key',
                    'auth' => 'basic',
                    'server_url' => 'https://hevy.example.com',
                ],
            ],
            'old' => [
                'configuration' => ['api_key' => 'hev_live_previous', 'days_back' => 7],
            ],
        ]);

        $this->artisan('activity-log:redact-credentials')->assertSuccessful();

        $properties = $this->properties($id);

        $this->assertSame('[REDACTED]', $properties['attributes']['configuration']['api_key']);
        $this->assertSame('[REDACTED]', $properties['old']['configuration']['api_key']);
        $this->assertSame('My Hevy', $properties['attributes']['name']);
        $this->assertSame(30, $properties['attributes']['configuration']['days_back']);
        $this->assertSame(7, $properties['old']['configuration']['days_back']);
        $this->assertSame('display-key', $properties['attributes']['configuration']['key']);
        $this->assertSame('basic', $properties['attributes']['configuration']['auth']);
        $this->assertSame('https://hevy.example.com', $properties['attributes']['configuration']['server_url']);
    }

    #[Test]
    public function it_redacts_nested_group_credentials(): void
    {
        $id = $this->seedActivityRow(IntegrationGroup::class, [
            'attributes' => [
                'account_id' => 'acc_123',
                'access_token' => 'tok_live_secret',
                'auth_metadata' => [
                    'server_url' => 'https://immich.example.com',
                    'api_key' => 'immich_secret',
                ],
            ],
        ]);

        $this->artisan('activity-log:redact-credentials')->assertSuccessful();

        $properties = $this->properties($id);

        $this->assertSame('[REDACTED]', $properties['attributes']['access_token']);
        $this->assertSame('[REDACTED]', $properties['attributes']['auth_metadata']['api_key']);
        $this->assertSame('acc_123', $properties['attributes']['account_id']);
        $this->assertSame('https://immich.example.com', $properties['attributes']['auth_metadata']['server_url']);
    }

    #[Test]
    public function it_recursively_redacts_historical_cookie_values(): void
    {
        $canary = 'historical-private-cookie';
        $id = $this->seedActivityRow(IntegrationGroup::class, [
            'attributes' => [
                'auth_metadata' => [
                    'cookies' => [
                        'sid' => $canary,
                        'nested' => ['arbitrary_name' => $canary],
                    ],
                ],
            ],
        ]);

        $this->artisan('activity-log:redact-credentials')->assertSuccessful();

        $properties = $this->properties($id);
        $this->assertSame('[REDACTED]', $properties['attributes']['auth_metadata']['cookies']['sid']);
        $this->assertSame('[REDACTED]', $properties['attributes']['auth_metadata']['cookies']['nested']['arbitrary_name']);
        $this->assertStringNotContainsString($canary, json_encode($properties));
    }

    #[Test]
    public function it_leaves_a_clean_row_untouched(): void
    {
        $id = $this->seedActivityRow(Integration::class, [
            'attributes' => ['name' => 'My Hevy', 'configuration' => ['days_back' => 30]],
        ]);

        $before = DB::table(config('activitylog.table_name'))->where('id', $id)->value('updated_at');

        $this->artisan('activity-log:redact-credentials')->assertSuccessful();

        $this->assertSame($before, DB::table(config('activitylog.table_name'))->where('id', $id)->value('updated_at'));
        $this->assertSame(30, $this->properties($id)['attributes']['configuration']['days_back']);
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $id = $this->seedActivityRow(Integration::class, [
            'attributes' => ['configuration' => ['api_key' => 'hev_live_secret']],
        ]);

        $this->artisan('activity-log:redact-credentials')->assertSuccessful();
        $first = $this->properties($id);

        $this->artisan('activity-log:redact-credentials')->assertSuccessful();

        $this->assertSame($first, $this->properties($id));
    }

    #[Test]
    public function it_is_a_no_op_when_there_is_nothing_to_process(): void
    {
        $this->artisan('activity-log:redact-credentials')
            ->expectsOutput('No integration activity log rows to process.')
            ->assertSuccessful();
    }

    #[Test]
    public function updating_an_integration_no_longer_logs_its_api_key(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'configuration' => ['api_key' => 'hev_live_secret', 'days_back' => 7],
        ]);

        $integration->update(['configuration' => ['api_key' => 'hev_live_rotated', 'days_back' => 30]]);

        $activity = Activity::query()
            ->where('subject_type', Integration::class)
            ->where('subject_id', $integration->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity, 'The changelog entry should still be written.');

        $encoded = json_encode($activity->properties->toArray());

        $this->assertStringNotContainsString('hev_live_rotated', $encoded);
        $this->assertStringNotContainsString('hev_live_secret', $encoded);
        // The diff itself must survive — logExcept() would have thrown it away.
        $this->assertStringContainsString('days_back', $encoded);
    }

    #[Test]
    public function updating_an_integration_does_not_log_arbitrary_cookie_values(): void
    {
        $canary = 'new-private-cookie';
        $integration = Integration::factory()->create([
            'user_id' => User::factory()->create()->id,
            'configuration' => ['cookies' => ['sid' => 'old-cookie'], 'days_back' => 7],
        ]);

        $integration->update([
            'configuration' => [
                'cookies' => ['sid' => $canary],
                'days_back' => 30,
                'key' => 'display-key',
                'auth' => 'basic',
                'server_url' => 'https://service.example.com',
            ],
        ]);

        $activity = Activity::query()
            ->where('subject_type', Integration::class)
            ->where('subject_id', $integration->id)
            ->latest('id')
            ->firstOrFail();
        $properties = $activity->properties->toArray();
        $encoded = json_encode($properties);

        $this->assertStringNotContainsString($canary, $encoded);
        $this->assertSame('[REDACTED]', $properties['attributes']['configuration']['cookies']['sid']);
        $this->assertSame('display-key', $properties['attributes']['configuration']['key']);
        $this->assertSame('basic', $properties['attributes']['configuration']['auth']);
        $this->assertSame('https://service.example.com', $properties['attributes']['configuration']['server_url']);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function seedActivityRow(string $subjectType, array $properties): int
    {
        return DB::table(config('activitylog.table_name'))->insertGetId([
            'log_name' => 'changelog',
            'description' => 'updated',
            'subject_type' => $subjectType,
            'subject_id' => (string) Str::uuid(),
            'event' => 'updated',
            'properties' => json_encode($properties),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(int $id): array
    {
        return json_decode(
            (string) DB::table(config('activitylog.table_name'))->where('id', $id)->value('properties'),
            true,
        );
    }
}
