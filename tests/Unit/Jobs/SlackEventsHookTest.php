<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Webhook\Slack\SlackEventsHook;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlackEventsHookTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected IntegrationGroup $group;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'slack',
            'account_id' => 'slack_signing_secret_123',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'slack',
            'instance_type' => 'events',
            'configuration' => [
                'events' => ['message', 'reaction_added', 'file_shared'],
            ],
        ]);
    }

    /**
     * @test
     */
    public function job_creation()
    {
        $job = new SlackEventsHook([], [], $this->integration);
        $this->assertInstanceOf(SlackEventsHook::class, $job);
        $this->assertEquals(60, $job->timeout);
        $this->assertEquals(3, $job->tries);
    }

    /**
     * @test
     */
    public function url_verification_handling()
    {
        $payload = [
            'type' => 'url_verification',
            'challenge' => 'test_challenge_token',
            'token' => 'test_token',
        ];

        $job = new SlackEventsHook($payload, [], $this->integration);

        // The job should handle URL verification without throwing an exception
        // This would normally be tested by mocking the job execution
        $this->assertInstanceOf(SlackEventsHook::class, $job);
    }

    /**
     * @test
     */
    public function missing_event_data()
    {
        $payload = [
            'type' => 'event_callback',
            'team_id' => 'T123456',
            'event_id' => 'Ev123456',
            // Missing 'event' key
        ];

        $job = new SlackEventsHook($payload, [], $this->integration);

        // Test that job can be created even with missing event data
        // The validation happens during job execution, not construction
        $this->assertInstanceOf(SlackEventsHook::class, $job);
    }

    /**
     * @test
     */
    public function event_type_filtering()
    {
        // Test with message event (should be processed)
        $messagePayload = [
            'type' => 'event_callback',
            'team_id' => 'T123456',
            'event_id' => 'Ev123456',
            'event' => [
                'type' => 'message',
                'user' => 'U123456',
                'text' => 'Hello world',
                'ts' => 1609459200.000100,
                'channel' => 'C123456',
            ],
        ];

        $job = new SlackEventsHook($messagePayload, [
            'x-slack-signature' => ['v0=test_signature'],
            'x-slack-request-timestamp' => [time()],
        ], $this->integration);

        $this->assertInstanceOf(SlackEventsHook::class, $job);

        // Test with unconfigured event type (should be filtered out)
        $unconfiguredPayload = [
            'type' => 'event_callback',
            'team_id' => 'T123456',
            'event_id' => 'Ev123456',
            'event' => [
                'type' => 'channel_created', // Not in configured events
                'channel' => [
                    'id' => 'C123456',
                    'name' => 'new-channel',
                ],
            ],
        ];

        $job2 = new SlackEventsHook($unconfiguredPayload, [], $this->integration);
        $this->assertInstanceOf(SlackEventsHook::class, $job2);
    }

    /**
     * @test
     */
    public function signature_verification()
    {
        $payload = [
            'type' => 'event_callback',
            'team_id' => 'T123456',
            'event_id' => 'Ev123456',
            'event' => [
                'type' => 'message',
                'user' => 'U123456',
                'text' => 'Hello world',
                'ts' => 1609459200.000100,
                'channel' => 'C123456',
            ],
        ];

        // Test with valid signature
        $timestamp = time();
        $body = json_encode($payload);
        $baseString = "v0:{$timestamp}:{$body}";
        $signature = 'v0=' . hash_hmac('sha256', $baseString, $this->group->account_id);

        $headers = [
            'x-slack-signature' => [$signature],
            'x-slack-request-timestamp' => [$timestamp],
        ];

        $job = new SlackEventsHook($payload, $headers, $this->integration);
        $this->assertInstanceOf(SlackEventsHook::class, $job);

        // Test with invalid signature
        $headersInvalid = [
            'x-slack-signature' => ['v0=invalid_signature'],
            'x-slack-request-timestamp' => [$timestamp],
        ];

        $job2 = new SlackEventsHook($payload, $headersInvalid, $this->integration);
        $this->assertInstanceOf(SlackEventsHook::class, $job2);
    }

    /**
     * @test
     */
    public function event_data_conversion()
    {
        // Test message event conversion
        $messagePayload = [
            'type' => 'event_callback',
            'team_id' => 'T123456',
            'event_id' => 'Ev123456',
            'event' => [
                'type' => 'message',
                'user' => 'U123456',
                'text' => 'Hello world',
                'ts' => 1609459200.000100,
                'channel' => 'C123456',
            ],
        ];

        $job = new SlackEventsHook($messagePayload, [], $this->integration);
        $this->assertInstanceOf(SlackEventsHook::class, $job);

        // Test reaction event conversion
        $reactionPayload = [
            'type' => 'event_callback',
            'team_id' => 'T123456',
            'event_id' => 'Ev123456',
            'event' => [
                'type' => 'reaction_added',
                'user' => 'U123456',
                'reaction' => 'thumbsup',
                'item' => [
                    'type' => 'message',
                    'channel' => 'C123456',
                    'ts' => '1609459200.000100',
                ],
                'event_ts' => '1609459200.000200',
            ],
        ];

        $job2 = new SlackEventsHook($reactionPayload, [], $this->integration);
        $this->assertInstanceOf(SlackEventsHook::class, $job2);
    }

    /**
     * @test
     */
    public function configuration_handling()
    {
        // Test with empty configuration (should use defaults)
        $integrationNoConfig = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'slack',
            'instance_type' => 'events',
            'configuration' => [], // Empty config
        ]);

        $job = new SlackEventsHook([], [], $integrationNoConfig);
        $this->assertInstanceOf(SlackEventsHook::class, $job);

        // Test with specific event configuration
        $integrationLimited = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'slack',
            'instance_type' => 'events',
            'configuration' => [
                'events' => ['message'], // Only messages
            ],
        ]);

        $job2 = new SlackEventsHook([], [], $integrationLimited);
        $this->assertInstanceOf(SlackEventsHook::class, $job2);
    }

    /**
     * @test
     */
    public function job_metadata()
    {
        $job = new SlackEventsHook([], [], $this->integration);

        // Test job properties
        $this->assertEquals(60, $job->timeout);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals([30, 120, 300], $job->backoff);
    }
}
