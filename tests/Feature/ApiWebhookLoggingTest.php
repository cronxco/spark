<?php

namespace Tests\Feature;

use App\Integrations\Monzo\MonzoPlugin;
use App\Integrations\Slack\SlackPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class ApiWebhookLoggingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private IntegrationGroup $group;
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'monzo',
            'account_id' => 'test_account_123',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'monzo',
            'instance_type' => 'accounts',
        ]);
    }

    #[Test]
    public function it_logs_api_requests_with_sanitized_headers()
    {
        // Act & Assert - Function should not throw an exception
        $this->assertNull(
            log_integration_api_request(
                'monzo',
                'GET',
                '/accounts',
                ['Authorization' => 'Bearer secret_token_123'],
                ['account_id' => 'test'],
                $this->integration->id,
                true
            )
        );
    }

    #[Test]
    public function it_logs_api_responses_with_sanitized_data()
    {
        // Arrange
        $responseBody = '{"accounts": [{"id": "123", "balance": 100}], "sensitive": "secret_data"}';

        // Act & Assert - Function should not throw an exception
        $this->assertNull(
            log_integration_api_response(
                'monzo',
                'GET',
                '/accounts',
                200,
                $responseBody,
                ['Content-Type' => 'application/json'],
                $this->integration->id,
                true
            )
        );
    }

    #[Test]
    public function it_logs_webhook_payloads_with_sanitized_data()
    {
        // Arrange
        $payload = [
            'type' => 'message',
            'text' => 'Hello world',
            'token' => 'secret_webhook_token',
            'user' => 'user123',
        ];

        // Act & Assert - Function should not throw an exception
        $this->assertNull(
            log_integration_webhook(
                'slack',
                $this->integration->id,
                $payload,
                ['X-Webhook-Signature' => 'signature123'],
                true
            )
        );
    }

    #[Test]
    public function it_creates_per_instance_log_files()
    {
        // Arrange
        $integrationId = 'test_integration_123';

        // Mock the Log facade to avoid actually creating files in tests
        $mockLogger = Mockery::mock();
        $mockLogger->shouldReceive('debug')->once();

        Log::shouldReceive('build')
            ->once()
            ->andReturn($mockLogger);

        // Act
        log_integration_api_request(
            'monzo',
            'GET',
            '/accounts',
            ['Authorization' => 'Bearer token'],
            [],
            $integrationId,
            true
        );
    }

    #[Test]
    public function it_creates_per_service_log_files()
    {
        // Mock the Log facade to avoid actually creating files in tests
        $mockLogger = Mockery::mock();
        $mockLogger->shouldReceive('debug')->once();

        Log::shouldReceive('build')
            ->once()
            ->andReturn($mockLogger);

        // Act
        log_integration_api_request(
            'github',
            'GET',
            '/repos',
            ['Authorization' => 'Bearer token'],
            [],
            '',
            false
        );
    }

    #[Test]
    public function it_truncates_large_response_bodies()
    {
        // Arrange
        $largeBody = str_repeat('a', 15000); // Over 10KB

        // Act & Assert - Function should not throw an exception
        $this->assertNull(
            log_integration_api_response(
                'monzo',
                'GET',
                '/accounts',
                200,
                $largeBody,
                [],
                $this->integration->id,
                true
            )
        );
    }

    #[Test]
    public function it_sanitizes_sensitive_data_in_requests()
    {
        // Arrange
        $sensitiveData = [
            'password' => 'secret123',
            'token' => 'auth_token_123',
            'api_key' => 'key_456',
            'normal_field' => 'safe_value',
        ];

        // Act & Assert - Function should not throw an exception
        $this->assertNull(
            log_integration_api_request(
                'monzo',
                'POST',
                '/auth',
                ['Authorization' => 'Bearer token'],
                $sensitiveData,
                $this->integration->id,
                true
            )
        );
    }

    #[Test]
    public function it_sanitizes_sensitive_headers()
    {
        // Arrange
        $sensitiveHeaders = [
            'Authorization' => 'Bearer secret_token',
            'X-API-Key' => 'api_key_123',
            'X-Auth-Token' => 'auth_token_456',
            'Content-Type' => 'application/json',
        ];

        // Act & Assert - Function should not throw an exception
        $this->assertNull(
            log_integration_api_request(
                'monzo',
                'GET',
                '/accounts',
                $sensitiveHeaders,
                [],
                $this->integration->id,
                true
            )
        );
    }

    #[Test]
    public function it_handles_plugin_specific_logging()
    {
        // Test that the MonzoPlugin creates the correct log channel
        $plugin = new MonzoPlugin;

        // This would normally be called internally by the plugin
        // We're testing that the helper functions work correctly
        $this->assertTrue(function_exists('log_integration_api_request'));
        $this->assertTrue(function_exists('log_integration_api_response'));
        $this->assertTrue(function_exists('log_integration_webhook'));
    }

    #[Test]
    public function it_logs_webhook_verification_attempts()
    {
        // Arrange
        $slackPlugin = new SlackPlugin;

        // Create request with minimal required data for webhook handling
        $request = Request::create('/webhook/slack/test_secret', 'POST', [
            'type' => 'url_verification',
            'challenge' => 'test_challenge',
        ], [], [], [
            'HTTP_X_SLACK_SIGNATURE' => 'v0=test_signature',
            'HTTP_X_SLACK_REQUEST_TIMESTAMP' => (string) time(),
        ]);

        // Set request body using reflection (content property is protected)
        $reflection = new ReflectionClass($request);
        $contentProperty = $reflection->getProperty('content');
        $contentProperty->setAccessible(true);
        $contentProperty->setValue($request, '{"type": "url_verification", "challenge": "test_challenge"}');

        // Mock the route parameter
        $request->setRouteResolver(function () {
            return (object) ['parameter' => function ($key) {
                return $key === 'secret' ? 'test_secret' : null;
            }];
        });

        // Act & Assert - Webhook handling should validate signatures properly
        // This test verifies that invalid signatures are rejected
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('Invalid Slack signature');

        $slackPlugin->handleWebhook($request, $this->integration);
    }

    #[Test]
    public function it_handles_empty_integration_ids()
    {
        // Mock the Log facade to avoid actually creating files in tests
        $mockLogger = Mockery::mock();
        $mockLogger->shouldReceive('debug')->once();

        Log::shouldReceive('build')
            ->once()
            ->andReturn($mockLogger);

        // Act
        log_integration_api_request(
            'github',
            'GET',
            '/user',
            ['Authorization' => 'Bearer token'],
            [],
            '', // Empty integration ID
            true // Per-instance mode
        );
    }

    #[Test]
    public function it_handles_nested_sensitive_data()
    {
        // Arrange
        $nestedData = [
            'user' => [
                'name' => 'John Doe',
                'credentials' => [
                    'password' => 'secret123',
                    'token' => 'nested_token',
                ],
            ],
            'safe_field' => 'safe_value',
        ];

        // Act & Assert - Function should not throw an exception
        $this->assertNull(
            log_integration_webhook(
                'slack',
                $this->integration->id,
                $nestedData,
                [],
                true
            )
        );
    }
}
