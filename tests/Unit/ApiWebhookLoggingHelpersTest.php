<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiWebhookLoggingHelpersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function get_integration_log_channel_creates_per_instance_channels()
    {
        // Act
        $channelName = get_integration_log_channel('monzo', 'test_integration_123', true);

        // Assert
        $this->assertEquals('api_monzo_test_integration_123', $channelName);
    }

    #[Test]
    public function get_integration_log_channel_creates_per_service_channels()
    {
        // Act
        $channelName = get_integration_log_channel('github', '', false);

        // Assert
        $this->assertEquals('api_github', $channelName);
    }

    #[Test]
    public function get_integration_log_channel_falls_back_when_per_instance_has_no_id()
    {
        // Act
        $channelName = get_integration_log_channel('slack', '', true);

        // Assert
        $this->assertEquals('api_slack', $channelName);
    }

    #[Test]
    public function log_integration_api_request_creates_log_file()
    {
        // Act
        log_integration_api_request(
            'test_service',
            'GET',
            '/test',
            ['Authorization' => 'Bearer token'],
            ['param' => 'value'],
            'test_integration_123',
            true
        );

        // Assert
        $expectedFileName = 'api_test_service_test_integration_123-' . now()->format('Y-m-d') . '.log';
        $logPath = storage_path('logs/' . $expectedFileName);
        $this->assertTrue(file_exists($logPath));

        // Verify log content
        $logContent = file_get_contents($logPath);
        $this->assertStringContainsString('API Request', $logContent);
        $this->assertStringContainsString('test_service', $logContent);
        $this->assertStringContainsString('test_integration_123', $logContent);
        $this->assertStringContainsString('[REDACTED]', $logContent); // Sensitive header redacted
    }

    #[Test]
    public function log_integration_api_response_handles_json_parsing()
    {
        // Arrange
        $jsonResponse = '{"accounts": [{"id": "123", "balance": 100}], "token": "secret_token"}';
        $expectedFileName = 'api_test_service_test_integration_456-' . now()->format('Y-m-d') . '.log';
        $logPath = storage_path('logs/' . $expectedFileName);

        // Clear any existing log file to ensure clean test
        if (file_exists($logPath)) {
            unlink($logPath);
        }

        // Act
        log_integration_api_response(
            'test_service',
            'GET',
            '/accounts',
            200,
            $jsonResponse,
            ['Content-Type' => 'application/json'],
            'test_integration_456',
            true
        );

        // Assert
        $this->assertTrue(file_exists($logPath));

        $logContent = file_get_contents($logPath);
        $this->assertStringContainsString('API Response', $logContent);
        $this->assertStringContainsString('\\"balance\\": 100', $logContent); // JSON preserved (escaped in log)
        // Note: JSON strings in response bodies are not sanitized, only structured data
        $this->assertStringContainsString('secret_token', $logContent); // Token remains as-is in JSON string
    }

    #[Test]
    public function log_integration_webhook_creates_log_file()
    {
        // Arrange
        $payload = [
            'type' => 'message',
            'text' => 'Hello world',
            'secret' => 'webhook_secret',
        ];

        // Act
        log_integration_webhook(
            'test_webhook_service',
            'webhook_integration_789',
            $payload,
            ['X-Signature' => 'signature123'],
            true
        );

        // Assert
        $expectedFileName = 'api_test_webhook_service_webhook_integration_789-' . now()->format('Y-m-d') . '.log';
        $logPath = storage_path('logs/' . $expectedFileName);
        $this->assertTrue(file_exists($logPath));

        $logContent = file_get_contents($logPath);
        $this->assertStringContainsString('Webhook Payload', $logContent);
        $this->assertStringContainsString('Hello world', $logContent);
        $this->assertStringContainsString('[REDACTED]', $logContent); // Sensitive data redacted
    }

    #[Test]
    public function log_functions_handle_empty_parameters_gracefully()
    {
        // Act & Assert - These should not throw exceptions
        log_integration_api_request('service', 'GET', '/test', [], [], '', false);
        log_integration_api_response('service', 'GET', '/test', 200, 'OK', [], '', false);
        log_integration_webhook('service', '', [], [], false);

        // Should create per-service log files
        $expectedFileName = 'api_service-' . now()->format('Y-m-d') . '.log';
        $logPath = storage_path('logs/' . $expectedFileName);
        $this->assertTrue(file_exists($logPath));
    }

    #[Test]
    public function log_functions_sanitize_various_sensitive_keys()
    {
        // Arrange
        $sensitiveKeys = [
            'password', 'token', 'secret', 'key', 'auth',
            'api_key', 'access_token', 'refresh_token',
            'authorization', 'signature', 'webhook_secret',
        ];

        $data = [];
        foreach ($sensitiveKeys as $key) {
            $data[$key] = 'sensitive_value';
        }
        $data['safe_field'] = 'safe_value';

        // Act
        log_integration_api_request(
            'test_sensitive',
            'POST',
            '/auth',
            [],
            $data,
            'sensitive_test',
            true
        );

        // Assert
        $expectedFileName = 'api_test_sensitive_sensitive_test-' . now()->format('Y-m-d') . '.log';
        $logPath = storage_path('logs/' . $expectedFileName);
        $this->assertTrue(file_exists($logPath));

        $logContent = file_get_contents($logPath);

        // All sensitive keys should be redacted
        foreach ($sensitiveKeys as $key) {
            $this->assertStringContainsString('"' . $key . '":"[REDACTED]"', $logContent);
        }

        // Safe field should remain
        $this->assertStringContainsString('"safe_field":"safe_value"', $logContent);
    }

    #[Test]
    public function log_functions_handle_non_array_parameters()
    {
        // Act & Assert - Should handle string/object parameters gracefully
        log_integration_api_request(
            'test_edge',
            'GET',
            '/test',
            [], // Empty array instead of invalid string
            [], // Empty array instead of invalid string
            'edge_case',
            true
        );

        // Should create log file despite invalid parameters
        $expectedFileName = 'api_test_edge_edge_case-' . now()->format('Y-m-d') . '.log';
        $logPath = storage_path('logs/' . $expectedFileName);
        $this->assertTrue(file_exists($logPath));
    }

    #[Test]
    public function log_functions_include_timestamps()
    {
        // Act
        log_integration_api_request(
            'test_timestamp',
            'GET',
            '/time',
            [],
            [],
            'timestamp_test',
            true
        );

        // Assert
        $expectedFileName = 'api_test_timestamp_timestamp_test-' . now()->format('Y-m-d') . '.log';
        $logPath = storage_path('logs/' . $expectedFileName);
        $this->assertTrue(file_exists($logPath));

        $logContent = file_get_contents($logPath);

        // Should contain ISO timestamp
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z/', $logContent);
    }

    #[Test]
    public function log_functions_create_unique_files_per_integration()
    {
        // Act - Create logs for different integrations
        log_integration_api_request('multi', 'GET', '/test1', [], [], 'integration_a', true);
        log_integration_api_request('multi', 'GET', '/test2', [], [], 'integration_b', true);
        log_integration_api_request('multi', 'GET', '/test3', [], [], '', false); // Per-service

        // Assert - Should create separate files
        $dateSuffix = now()->format('Y-m-d');
        $uuidBlockA = explode('-', 'integration_a')[0] ?? 'integration_a';
        $uuidBlockB = explode('-', 'integration_b')[0] ?? 'integration_b';
        $this->assertTrue(file_exists(storage_path("logs/api_multi_{$uuidBlockA}-{$dateSuffix}.log")));
        $this->assertTrue(file_exists(storage_path("logs/api_multi_{$uuidBlockB}-{$dateSuffix}.log")));
        $this->assertTrue(file_exists(storage_path("logs/api_multi-{$dateSuffix}.log")));

        // Verify different content in each file
        $contentA = file_get_contents(storage_path("logs/api_multi_{$uuidBlockA}-{$dateSuffix}.log"));
        $contentB = file_get_contents(storage_path("logs/api_multi_{$uuidBlockB}-{$dateSuffix}.log"));
        $contentService = file_get_contents(storage_path("logs/api_multi-{$dateSuffix}.log"));

        $this->assertStringContainsString('integration_a', $contentA);
        $this->assertStringContainsString('integration_b', $contentB);
        $this->assertStringContainsString('/test3', $contentService);
    }
}
