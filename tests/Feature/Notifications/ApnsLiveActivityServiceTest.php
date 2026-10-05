<?php

namespace Tests\Feature\Notifications;

use App\Models\LiveActivityToken;
use App\Models\User;
use App\Services\ApnsLiveActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NOTIF-04: a Live Activity whose device token APNs has permanently rejected
 * used to stay "live" forever, because the terminal response was only logged.
 */
class ApnsLiveActivityServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $keyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $this->keyPath = tempnam(sys_get_temp_dir(), 'apns') . '.p8';
        file_put_contents($this->keyPath, $pem);

        config([
            'broadcasting.connections.apn.key_id' => 'KEYID12345',
            'broadcasting.connections.apn.team_id' => 'TEAMID1234',
            'broadcasting.connections.apn.private_key_path' => $this->keyPath,
        ]);
        Cache::forget('apns:la:jwt');
    }

    protected function tearDown(): void
    {
        @unlink($this->keyPath);

        parent::tearDown();
    }

    #[Test]
    public function an_unregistered_device_token_ends_the_activity(): void
    {
        Http::fake(['*' => Http::response(['reason' => 'Unregistered'], 410)]);
        $token = $this->activity();

        app(ApnsLiveActivityService::class)->startOrUpdate($token, ['phase' => 'deep']);

        $this->assertNotNull($token->fresh()->ends_at);
    }

    #[Test]
    public function a_bad_device_token_ends_the_activity(): void
    {
        Http::fake(['*' => Http::response(['reason' => 'BadDeviceToken'], 400)]);
        $token = $this->activity();

        app(ApnsLiveActivityService::class)->startOrUpdate($token, ['phase' => 'deep']);

        $this->assertNotNull($token->fresh()->ends_at);
    }

    #[Test]
    public function an_expired_signing_token_leaves_the_activity_running(): void
    {
        Http::fake(['*' => Http::response(['reason' => 'ExpiredProviderToken'], 403)]);
        $token = $this->activity();

        app(ApnsLiveActivityService::class)->startOrUpdate($token, ['phase' => 'deep']);

        $this->assertNull($token->fresh()->ends_at);
    }

    #[Test]
    public function a_transient_failure_leaves_the_activity_running(): void
    {
        Http::fake(['*' => Http::response(['reason' => 'TooManyRequests'], 429)]);
        $token = $this->activity();

        app(ApnsLiveActivityService::class)->startOrUpdate($token, ['phase' => 'deep']);

        $this->assertNull($token->fresh()->ends_at);
    }

    #[Test]
    public function a_successful_push_records_when_it_was_sent(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $token = $this->activity();

        app(ApnsLiveActivityService::class)->startOrUpdate($token, ['phase' => 'deep']);

        $this->assertNotNull($token->fresh()->last_pushed_at);
        $this->assertNull($token->fresh()->ends_at);
    }

    private function activity(): LiveActivityToken
    {
        return LiveActivityToken::create([
            'user_id' => User::factory()->create()->id,
            'activity_id' => fake()->uuid(),
            'activity_type' => 'sleep',
            'push_token' => str_repeat('a', 64),
            'starts_at' => now(),
        ]);
    }
}
