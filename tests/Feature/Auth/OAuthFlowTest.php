<?php

namespace Tests\Feature\Auth;

use App\Models\OAuthAuthorizationCode;
use App\Models\OAuthRefreshToken;
use App\Models\User;
use App\Support\Pkce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unauthenticated_users_are_redirected_to_login(): void
    {
        $response = $this->get('/oauth/authorize?' . http_build_query($this->validParams()));

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function authorize_screen_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $params = $this->validParams();

        $response = $this->actingAs($user)
            ->get('/oauth/authorize?' . http_build_query($params));

        $response->assertOk();
        $response->assertViewIs('auth.oauth-consent');
        $response->assertSee($params['device_name']);
        $response->assertSee($user->email);
    }

    #[Test]
    public function authorize_rejects_unknown_client_id(): void
    {
        $user = User::factory()->create();
        $params = array_merge($this->validParams(), ['client_id' => 'rogue']);

        $response = $this->actingAs($user)
            ->get('/oauth/authorize?' . http_build_query($params));

        $response->assertSessionHasErrors('client_id');
    }

    #[Test]
    public function authorize_rejects_unknown_redirect_uri(): void
    {
        $user = User::factory()->create();
        $params = array_merge($this->validParams(), ['redirect_uri' => 'https://evil.example/cb']);

        $response = $this->actingAs($user)
            ->get('/oauth/authorize?' . http_build_query($params));

        $response->assertSessionHasErrors('redirect_uri');
    }

    #[Test]
    public function authorize_rejects_plain_pkce_method(): void
    {
        $user = User::factory()->create();
        $params = array_merge($this->validParams(), ['code_challenge_method' => 'plain']);

        $response = $this->actingAs($user)
            ->get('/oauth/authorize?' . http_build_query($params));

        $response->assertSessionHasErrors('code_challenge_method');
    }

    #[Test]
    public function authorize_rejects_non_code_response_type(): void
    {
        $user = User::factory()->create();
        $params = array_merge($this->validParams(), ['response_type' => 'token']);

        $response = $this->actingAs($user)
            ->get('/oauth/authorize?' . http_build_query($params));

        $response->assertSessionHasErrors('response_type');
    }

    #[Test]
    public function authorize_requires_state_and_challenge(): void
    {
        $user = User::factory()->create();
        $params = $this->validParams();
        unset($params['state'], $params['code_challenge']);

        $response = $this->actingAs($user)
            ->get('/oauth/authorize?' . http_build_query($params));

        $response->assertSessionHasErrors(['state', 'code_challenge']);
    }

    #[Test]
    public function approving_creates_an_authorization_code_and_redirects_to_app(): void
    {
        $user = User::factory()->create();
        $params = $this->validParams();

        $response = $this->actingAs($user)
            ->post('/oauth/authorize', $params);

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('spark://auth/callback?', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query);
        $this->assertArrayHasKey('state', $query);
        $this->assertSame($params['state'], $query['state']);
        $this->assertNotEmpty($query['code']);

        $stored = OAuthAuthorizationCode::query()->first();
        $this->assertNotNull($stored);
        $this->assertSame((string) $user->getKey(), (string) $stored->user_id);
        $this->assertSame($params['client_id'], $stored->client_id);
        $this->assertSame($params['redirect_uri'], $stored->redirect_uri);
        $this->assertSame($params['code_challenge'], $stored->code_challenge);
        $this->assertSame($params['code_challenge_method'], $stored->code_challenge_method);
        $this->assertSame($params['device_name'], $stored->device_name);
        $this->assertSame($params['scope'], $stored->scope);
        $this->assertNull($stored->used_at);
        $this->assertTrue($stored->expires_at->isFuture());

        // The plain code returned to the client must be hashed (not stored verbatim).
        $this->assertSame(hash('sha256', $query['code']), $stored->code_hash);
        $this->assertNotSame($query['code'], $stored->code_hash);
    }

    #[Test]
    public function approving_requires_authentication(): void
    {
        $response = $this->post('/oauth/authorize', $this->validParams());

        $response->assertRedirect(route('login'));
        $this->assertSame(0, OAuthAuthorizationCode::query()->count());
    }

    #[Test]
    public function token_endpoint_exchanges_auth_code_for_access_and_refresh_tokens(): void
    {
        $user = User::factory()->create();
        [$code, $verifier] = $this->performAuthorize($user);

        $response = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => 'ios',
            'redirect_uri' => 'spark://auth/callback',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'refresh_token', 'scope']);
        $response->assertJson(['token_type' => 'Bearer', 'scope' => 'ios:*']);

        $body = $response->json();
        $this->assertNotEmpty($body['access_token']);
        $this->assertNotEmpty($body['refresh_token']);
        $this->assertGreaterThan(0, $body['expires_in']);

        // Auth code is now consumed.
        $this->assertNotNull(OAuthAuthorizationCode::query()->first()->used_at);

        // Refresh token is hashed at rest.
        $this->assertSame(1, OAuthRefreshToken::query()->count());
        $stored = OAuthRefreshToken::query()->first();
        $this->assertSame(hash('sha256', $body['refresh_token']), $stored->token_hash);
        $this->assertNull($stored->revoked_at);

        // Access token holds the concrete read/write abilities (Sanctum does
        // exact-match ability checks, so `ios:*` scope expands to the pair).
        $tokenId = (int) explode('|', $body['access_token'])[0];
        $personalToken = PersonalAccessToken::query()->find($tokenId);
        $this->assertNotNull($personalToken);
        $this->assertSame((string) $user->getKey(), (string) $personalToken->tokenable_id);
        $this->assertContains('ios:read', $personalToken->abilities);
        $this->assertContains('ios:write', $personalToken->abilities);
    }

    #[Test]
    public function token_endpoint_rejects_wrong_pkce_verifier(): void
    {
        $user = User::factory()->create();
        [$code] = $this->performAuthorize($user);

        $response = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => Pkce::generateCodeVerifier(), // Wrong verifier
            'client_id' => 'ios',
            'redirect_uri' => 'spark://auth/callback',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'invalid_grant']);

        // Code is NOT marked used because the transaction rolled back? Actually in our flow we
        // only update used_at *after* PKCE verification succeeds. So failure leaves the code unused.
        $this->assertNull(OAuthAuthorizationCode::query()->first()->used_at);
        $this->assertSame(0, OAuthRefreshToken::query()->count());
    }

    #[Test]
    public function token_endpoint_rejects_expired_code(): void
    {
        $user = User::factory()->create();
        [$code, $verifier] = $this->performAuthorize($user);

        OAuthAuthorizationCode::query()->update(['expires_at' => now()->subMinute()]);

        $response = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => 'ios',
            'redirect_uri' => 'spark://auth/callback',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'invalid_grant']);
    }

    #[Test]
    public function token_endpoint_rejects_replay_of_used_code(): void
    {
        $user = User::factory()->create();
        [$code, $verifier] = $this->performAuthorize($user);

        $payload = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => 'ios',
            'redirect_uri' => 'spark://auth/callback',
        ];

        $this->postJson('/api/oauth/token', $payload)->assertOk();

        $replay = $this->postJson('/api/oauth/token', $payload);

        $replay->assertStatus(400);
        $replay->assertJson(['error' => 'invalid_grant']);
        // Still only one refresh token issued — replay didn't mint a second.
        $this->assertSame(1, OAuthRefreshToken::query()->count());
    }

    #[Test]
    public function refresh_endpoint_rotates_tokens(): void
    {
        $user = User::factory()->create();
        [$code, $verifier] = $this->performAuthorize($user);

        $first = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => 'ios',
            'redirect_uri' => 'spark://auth/callback',
        ])->json();

        $rotated = $this->postJson('/api/oauth/refresh', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $first['refresh_token'],
            'client_id' => 'ios',
        ]);

        $rotated->assertOk();
        $body = $rotated->json();

        $this->assertNotSame($first['access_token'], $body['access_token']);
        $this->assertNotSame($first['refresh_token'], $body['refresh_token']);

        // Old refresh now revoked, new one stored unrevoked.
        $oldHash = hash('sha256', $first['refresh_token']);
        $newHash = hash('sha256', $body['refresh_token']);

        $this->assertNotNull(OAuthRefreshToken::query()->where('token_hash', $oldHash)->first()->revoked_at);
        $this->assertNull(OAuthRefreshToken::query()->where('token_hash', $newHash)->first()->revoked_at);

        // Old access token is gone.
        $oldAccessId = (int) explode('|', $first['access_token'])[0];
        $this->assertNull(PersonalAccessToken::query()->find($oldAccessId));
    }

    #[Test]
    public function refresh_endpoint_revokes_all_device_tokens_on_replay(): void
    {
        $user = User::factory()->create();
        [$code, $verifier] = $this->performAuthorize($user);

        $first = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => 'ios',
            'redirect_uri' => 'spark://auth/callback',
        ])->json();

        // First refresh: succeeds, rotates.
        $rotated = $this->postJson('/api/oauth/refresh', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $first['refresh_token'],
            'client_id' => 'ios',
        ])->json();

        // Replay of the now-revoked first refresh token.
        $replay = $this->postJson('/api/oauth/refresh', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $first['refresh_token'],
            'client_id' => 'ios',
        ]);

        $replay->assertStatus(401);
        $replay->assertJson(['error' => 'invalid_grant']);

        // Every refresh for this device is now revoked, including the rotated one.
        $this->assertSame(0, OAuthRefreshToken::query()->whereNull('revoked_at')->count());

        // The currently-issued access token (from the rotation) is gone.
        $rotatedAccessId = (int) explode('|', $rotated['access_token'])[0];
        $this->assertNull(PersonalAccessToken::query()->find($rotatedAccessId));
    }

    /**
     * @return array{client_id:string,redirect_uri:string,response_type:string,code_challenge:string,code_challenge_method:string,state:string,scope:string,device_name:string}
     */
    protected function validParams(?string $verifier = null): array
    {
        $verifier ??= Pkce::generateCodeVerifier();

        return [
            'client_id' => 'ios',
            'redirect_uri' => 'spark://auth/callback',
            'response_type' => 'code',
            'code_challenge' => Pkce::generateCodeChallenge($verifier),
            'code_challenge_method' => 'S256',
            'state' => 'test-state-' . bin2hex(random_bytes(8)),
            'scope' => 'ios:*',
            'device_name' => 'Will\'s iPhone',
        ];
    }

    /**
     * Helper: run the full authorize step and return [plainCode, verifier, params].
     *
     * @return array{0:string, 1:string, 2:array<string,string>}
     */
    protected function performAuthorize(User $user): array
    {
        $verifier = Pkce::generateCodeVerifier();
        $params = $this->validParams($verifier);

        $response = $this->actingAs($user)->post('/oauth/authorize', $params);
        $response->assertRedirect();

        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return [$query['code'], $verifier, $params];
    }
}
