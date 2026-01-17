<?php

namespace Tests\Feature;

use App\Integrations\Monzo\MonzoPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonzoTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    private MonzoPlugin $plugin;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock config values needed for token refresh
        config([
            'services.monzo.client_id' => 'test-client-id',
            'services.monzo.client_secret' => 'test-client-secret',
        ]);

        $this->plugin = new MonzoPlugin;
        $this->user = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'monzo',
            'access_token' => 'old-access-token',
            'refresh_token' => 'valid-refresh-token',
            'expiry' => now()->subHour(), // Expired token
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'monzo',
            'instance_type' => 'transactions',
        ]);
    }

    /** @test */
    public function it_refreshes_token_when_expired_during_api_call(): void
    {
        // Mock token refresh endpoint
        Http::fake([
            'api.monzo.com/oauth2/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 21600, // 6 hours
            ]),
            'api.monzo.com/accounts' => Http::response([
                'accounts' => [
                    [
                        'id' => 'acc-123',
                        'type' => 'uk_retail',
                        'description' => 'Test Account',
                    ],
                ],
            ]),
        ]);

        // Make API call that should trigger token refresh
        $accounts = $this->plugin->listAccounts($this->integration);

        // Verify accounts were returned
        $this->assertNotEmpty($accounts);
        $this->assertEquals('acc-123', $accounts[0]['id']);

        // Verify token was refreshed
        $this->group->refresh();
        $this->assertEquals('new-access-token', $this->group->access_token);
        $this->assertEquals('new-refresh-token', $this->group->refresh_token);
        $this->assertNotNull($this->group->expiry);
        $this->assertTrue($this->group->expiry->isFuture());

        // Verify token refresh endpoint was called
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.monzo.com/oauth2/token' &&
                $request['grant_type'] === 'refresh_token' &&
                $request['refresh_token'] === 'valid-refresh-token';
        });
    }

    /** @test */
    public function it_handles_401_response_by_refreshing_token(): void
    {
        // Start with a non-expired token but mock 401 response
        $this->group->update(['expiry' => now()->addHour()]);

        Http::fake([
            'api.monzo.com/accounts' => Http::sequence()
                ->push(['error' => 'unauthorized'], 401) // First call returns 401
                ->push([
                    'accounts' => [
                        [
                            'id' => 'acc-456',
                            'type' => 'uk_retail',
                            'description' => 'Test Account 2',
                        ],
                    ],
                ]), // Second call succeeds
            'api.monzo.com/oauth2/token' => Http::response([
                'access_token' => 'refreshed-token',
                'refresh_token' => 'refreshed-refresh-token',
                'expires_in' => 21600,
            ]),
        ]);

        // Make API call that should trigger token refresh after 401
        $accounts = $this->plugin->listAccounts($this->integration);

        // Verify accounts were returned
        $this->assertNotEmpty($accounts);
        $this->assertEquals('acc-456', $accounts[0]['id']);

        // Verify token was refreshed
        $this->group->refresh();
        $this->assertEquals('refreshed-token', $this->group->access_token);
        $this->assertEquals('refreshed-refresh-token', $this->group->refresh_token);

        // Verify both API calls were made
        Http::assertSentCount(3); // 2 account calls + 1 token refresh
    }

    /** @test */
    public function it_handles_refresh_token_failure_gracefully(): void
    {
        Http::fake([
            'api.monzo.com/oauth2/token' => Http::response([
                'error' => 'invalid_token',
                'error_description' => 'Refresh token expired',
            ], 400),
            'api.monzo.com/accounts' => Http::response([
                'error' => 'unauthorized',
            ], 401),
        ]);

        // Make API call that should attempt token refresh but fail
        $accounts = $this->plugin->listAccounts($this->integration);

        // Should return empty array when token refresh fails
        $this->assertEmpty($accounts);

        // Token should remain unchanged
        $this->group->refresh();
        $this->assertEquals('old-access-token', $this->group->access_token);
        $this->assertEquals('valid-refresh-token', $this->group->refresh_token);
    }

    /** @test */
    public function it_skips_refresh_when_missing_credentials(): void
    {
        // Remove config to simulate missing credentials
        config([
            'services.monzo.client_id' => null,
            'services.monzo.client_secret' => null,
        ]);

        // Create group with refresh token but missing client credentials
        $groupWithoutCredentials = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'monzo',
            'access_token' => 'old-token',
            'refresh_token' => 'valid-refresh-token', // Has refresh token but no client credentials
            'expiry' => now()->subHour(), // Expired
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $groupWithoutCredentials->id,
            'service' => 'monzo',
            'instance_type' => 'transactions',
        ]);

        Http::fake([
            'api.monzo.com/accounts' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        // Make API call with expired token but no client credentials
        $accounts = $this->plugin->listAccounts($integration);

        // Should return empty array when refresh fails
        $this->assertEmpty($accounts);

        // Expected requests:
        // 1. Token refresh attempt in authHeaders (fails due to missing credentials)
        // 2. Initial accounts call (fails with 401)
        // 3. Token refresh attempt in 401 retry logic (also fails due to missing credentials)
        // The accounts retry doesn't happen because the token refresh failed
        Http::assertSentCount(3);
    }
}
