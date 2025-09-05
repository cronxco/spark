<?php

namespace Tests\Feature\Integrations\Reddit;

use App\Integrations\Reddit\RedditPlugin;
use App\Jobs\Data\Reddit\RedditSavedData;
use App\Jobs\OAuth\Reddit\RedditSavedPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RedditPluginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function oauth_flow_saves_tokens_and_account_id(): void
    {
        $user = User::factory()->create();
        $plugin = new RedditPlugin;
        $group = $plugin->initializeGroup($user);

        // Stub token exchange
        Http::fake([
            'https://www.reddit.com/api/v1/access_token' => Http::response([
                'access_token' => 'access',
                'refresh_token' => 'refresh',
                'expires_in' => 3600,
                'token_type' => 'bearer',
                'scope' => 'identity',
            ], 200),
            'https://oauth.reddit.com/api/v1/me' => Http::response([
                'name' => 'testuser',
                'id' => 'abc123',
                'subreddit' => [
                    'display_name_prefixed' => 'u/testuser',
                ],
            ], 200),
        ]);

        $request = request()->merge([
            'code' => 'code123',
            'state' => encrypt([
                'group_id' => $group->id,
                'user_id' => $user->id,
                'csrf_token' => 'token',
                'code_verifier' => 'verifier',
            ]),
        ]);

        // Bypass CSRF check by seeding the expected session key
        session()->put('oauth_csrf_' . session_id() . '_' . $group->id, 'token');

        $plugin->handleOAuthCallback($request, $group);

        $group->refresh();
        $this->assertNotNull($group->access_token);
        $this->assertEquals('testuser', $group->account_id);
    }

    /**
     * @test
     */
    public function pull_dispatches_processing_and_stores_cursor(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'reddit',
            'instance_type' => 'saved',
        ]);
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'reddit',
            'access_token' => 'access',
            'account_id' => 'testuser',
        ]);
        $integration->integration_group_id = $group->id;
        $integration->save();

        Http::fake([
            'https://oauth.reddit.com/user/testuser/saved*' => Http::response([
                'data' => [
                    'children' => [
                        [
                            'kind' => 't3',
                            'data' => [
                                'id' => 'xyz',
                                'created_utc' => now()->subHour()->timestamp,
                                'title' => 'A post',
                                'permalink' => '/r/test/comments/xyz/a_post/',
                                'url' => 'https://example.com',
                                'subreddit' => 'test',
                                'preview' => [
                                    'images' => [['source' => ['url' => 'https://img.test/image.jpg']]],
                                ],
                            ],
                        ],
                    ],
                    'after' => 't3_xyz',
                ],
            ], 200),
            'https://oauth.reddit.com/api/v1/me' => Http::response([
                'name' => 'testuser',
                'id' => 'abc123',
                'subreddit' => [
                    'display_name_prefixed' => 'u/testuser',
                ],
            ], 200),
        ]);

        (new RedditSavedPull($integration))->handle();

        Bus::assertDispatched(RedditSavedData::class);

        $integration->refresh();
        $this->assertEquals('t3_xyz', data_get($integration->configuration, 'reddit.after'));
    }
}
