<?php

namespace Tests\Feature;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class GoCardlessAccessTokenCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config([
            'services.gocardless.secret_id' => 'secret-id',
            'services.gocardless.secret_key' => 'secret-key',
        ]);
    }

    #[Test]
    public function separate_plugin_instances_share_one_token(): void
    {
        Http::fake([
            '*/token/new/' => Http::response($this->tokenPair('access-1', 'refresh-1')),
        ]);

        $this->assertSame('access-1', (new GoCardlessBankPlugin)->getAccessToken());
        $this->assertSame('access-1', (new GoCardlessBankPlugin)->getAccessToken());
        $this->assertSame('access-1', (new GoCardlessBankPlugin)->getAccessToken());

        Http::assertSentCount(1);
    }

    #[Test]
    public function tokens_are_not_stored_in_plain_text(): void
    {
        Http::fake([
            '*/token/new/' => Http::response($this->tokenPair('access-1', 'refresh-1')),
        ]);

        (new GoCardlessBankPlugin)->getAccessToken();

        $key = 'gocardless_token_access_' . sha1('secret-id');
        $this->assertNotNull(Cache::get($key));
        $this->assertStringNotContainsString('access-1', Cache::get($key));
    }

    #[Test]
    public function an_expired_access_token_is_renewed_with_the_refresh_token(): void
    {
        Http::fake([
            '*/token/new/' => Http::response($this->tokenPair('access-1', 'refresh-1')),
            '*/token/refresh/' => Http::response(['access' => 'access-2', 'access_expires' => 86400]),
        ]);

        (new GoCardlessBankPlugin)->getAccessToken();
        $this->travel(86400 - 299)->seconds();

        $this->assertSame('access-2', (new GoCardlessBankPlugin)->getAccessToken());

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/token/refresh/')
            && $request['refresh'] === 'refresh-1');
        Http::assertSentCount(2);
    }

    #[Test]
    public function a_rejected_refresh_token_falls_back_to_a_new_token(): void
    {
        Http::fake([
            '*/token/new/' => Http::sequence()
                ->push($this->tokenPair('access-1', 'refresh-1'))
                ->push($this->tokenPair('access-2', 'refresh-2')),
            '*/token/refresh/' => Http::response(['summary' => 'Invalid token'], 401),
        ]);

        (new GoCardlessBankPlugin)->getAccessToken();
        $this->travel(86400)->seconds();

        $this->assertSame('access-2', (new GoCardlessBankPlugin)->getAccessToken());
        Http::assertSentCount(3);
    }

    #[Test]
    public function a_rate_limited_refresh_keeps_the_refresh_token_and_skips_a_new_token(): void
    {
        Http::fake([
            '*/token/new/' => Http::response($this->tokenPair('access-1', 'refresh-1')),
            '*/token/refresh/' => Http::sequence()
                ->push(['summary' => 'Rate limit exceeded'], 429)
                ->push(['access' => 'access-2', 'access_expires' => 86400]),
        ]);

        (new GoCardlessBankPlugin)->getAccessToken();
        $this->travel(86400)->seconds();

        try {
            (new GoCardlessBankPlugin)->getAccessToken();
            $this->fail('Expected the rate-limited refresh to throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to refresh access token', $e->getMessage());
        }

        $this->assertSame('access-2', (new GoCardlessBankPlugin)->getAccessToken());
        Http::assertSentCount(3);
    }

    #[Test]
    public function rotating_credentials_requests_a_new_token(): void
    {
        Http::fake([
            '*/token/new/' => Http::sequence()
                ->push($this->tokenPair('access-1', 'refresh-1'))
                ->push($this->tokenPair('access-2', 'refresh-2')),
        ]);

        (new GoCardlessBankPlugin)->getAccessToken();
        config(['services.gocardless.secret_id' => 'rotated-id']);

        $this->assertSame('access-2', (new GoCardlessBankPlugin)->getAccessToken());
    }

    #[Test]
    public function a_failed_token_request_is_not_cached(): void
    {
        Http::fake([
            '*/token/new/' => Http::sequence()
                ->push(['summary' => 'Rate limit exceeded', 'status_code' => 429], 429)
                ->push($this->tokenPair('access-1', 'refresh-1')),
        ]);

        try {
            (new GoCardlessBankPlugin)->getAccessToken();
            $this->fail('Expected the rate-limited token request to throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Rate limit exceeded', $e->getMessage());
        }

        $this->assertSame('access-1', (new GoCardlessBankPlugin)->getAccessToken());
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenPair(string $access, string $refresh): array
    {
        return [
            'access' => $access,
            'access_expires' => 86400,
            'refresh' => $refresh,
            'refresh_expires' => 2592000,
        ];
    }
}
