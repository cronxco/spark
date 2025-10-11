<?php

namespace Tests\Unit\Support;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LoggingHelpersTest extends TestCase
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
            'service' => 'test',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
        ]);
    }

    /** @test */
    public function should_log_debug_returns_true_by_default(): void
    {
        config(['logging.debug_logging_default' => true]);

        $this->assertTrue(should_log_debug($this->user));
    }

    /** @test */
    public function should_log_debug_respects_user_preference(): void
    {
        $this->user->disableDebugLogging();

        $this->assertFalse(should_log_debug($this->user));

        $this->user->enableDebugLogging();

        $this->assertTrue(should_log_debug($this->user));
    }

    /** @test */
    public function should_log_debug_falls_back_to_config(): void
    {
        config(['logging.debug_logging_default' => false]);

        $this->assertFalse(should_log_debug($this->user));

        config(['logging.debug_logging_default' => true]);

        $this->assertTrue(should_log_debug($this->user));
    }

    /** @test */
    public function log_to_user_calls_logging_service(): void
    {
        Log::spy();

        log_to_user($this->user, 'info', 'Test message', ['key' => 'value']);

        Log::shouldHaveReceived('log')->once();
    }

    /** @test */
    public function log_to_group_calls_logging_service(): void
    {
        Log::spy();

        log_to_group($this->group, 'warning', 'Group message');

        Log::shouldHaveReceived('log')->once();
    }

    /** @test */
    public function log_to_integration_calls_logging_service(): void
    {
        Log::spy();

        log_to_integration($this->integration, 'error', 'Integration error');

        Log::shouldHaveReceived('log')->once();
    }

    /** @test */
    public function log_hierarchical_calls_logging_service(): void
    {
        Log::spy();

        log_hierarchical($this->integration, 'info', 'Hierarchical log');

        // Should cascade to integration, group, and user
        Log::shouldHaveReceived('log')->times(3);
    }

    /** @test */
    public function log_integration_api_request_uses_new_system_with_valid_uuid(): void
    {
        Log::spy();

        log_integration_api_request(
            'test',
            'GET',
            '/api/endpoint',
            ['Authorization' => 'Bearer token'],
            ['param' => 'value'],
            $this->integration->id
        );

        Log::shouldHaveReceived('log')->once();
    }

    /** @test */
    public function log_integration_api_request_falls_back_with_invalid_uuid(): void
    {
        Log::spy();

        log_integration_api_request(
            'test',
            'GET',
            '/api/endpoint',
            [],
            [],
            'invalid-uuid'
        );

        // Should still log but via fallback mechanism
        Log::shouldHaveReceived('debug')->once();
    }

    /** @test */
    public function log_integration_api_response_uses_new_system_with_valid_uuid(): void
    {
        Log::spy();

        log_integration_api_response(
            'test',
            'GET',
            '/api/endpoint',
            200,
            '{"status":"ok"}',
            ['Content-Type' => 'application/json'],
            $this->integration->id
        );

        Log::shouldHaveReceived('log')->once();
    }

    /** @test */
    public function log_integration_api_response_falls_back_with_invalid_uuid(): void
    {
        Log::spy();

        log_integration_api_response(
            'test',
            'GET',
            '/api/endpoint',
            200,
            '{"status":"ok"}',
            [],
            'not-a-uuid'
        );

        // Should still log but via fallback mechanism
        Log::shouldHaveReceived('debug')->once();
    }

    /** @test */
    public function sanitize_headers_redacts_sensitive_headers(): void
    {
        $headers = [
            'Authorization' => 'Bearer secret-token',
            'X-API-Key' => 'api-key-value',
            'Content-Type' => 'application/json',
            'x-auth-token' => 'auth-token',
        ];

        $sanitized = sanitizeHeaders($headers);

        $this->assertEquals(['[REDACTED]'], $sanitized['Authorization']);
        $this->assertEquals(['[REDACTED]'], $sanitized['X-API-Key']);
        $this->assertEquals(['[REDACTED]'], $sanitized['x-auth-token']);
        $this->assertEquals(['application/json'], $sanitized['Content-Type']);
    }

    /** @test */
    public function sanitize_data_redacts_sensitive_keys(): void
    {
        $data = [
            'username' => 'test_user',
            'password' => 'secret123',
            'token' => 'auth-token',
            'api_key' => 'key123',
            'metadata' => [
                'secret' => 'hidden',
                'public_info' => 'visible',
            ],
        ];

        $sanitized = sanitizeData($data);

        $this->assertEquals('test_user', $sanitized['username']);
        $this->assertEquals('[REDACTED]', $sanitized['password']);
        $this->assertEquals('[REDACTED]', $sanitized['token']);
        $this->assertEquals('[REDACTED]', $sanitized['api_key']);
        $this->assertEquals('[REDACTED]', $sanitized['metadata']['secret']);
        $this->assertEquals('visible', $sanitized['metadata']['public_info']);
    }

    /** @test */
    public function sanitize_data_handles_nested_arrays(): void
    {
        $data = [
            'level1' => [
                'level2' => [
                    'password' => 'secret',
                    'data' => 'visible',
                ],
            ],
        ];

        $sanitized = sanitizeData($data);

        $this->assertEquals('[REDACTED]', $sanitized['level1']['level2']['password']);
        $this->assertEquals('visible', $sanitized['level1']['level2']['data']);
    }

    /** @test */
    public function generate_api_log_filename_creates_correct_format(): void
    {
        $filename = generate_api_log_filename('test_service', $this->integration->id, false);

        $this->assertEquals('api_test_service.log', $filename);
    }

    /** @test */
    public function generate_api_log_filename_includes_uuid_block_when_per_instance(): void
    {
        $uuidBlock = explode('-', $this->integration->id)[0];
        $filename = generate_api_log_filename('test_service', $this->integration->id, true);

        $this->assertEquals("api_test_service_{$uuidBlock}.log", $filename);
    }
}
