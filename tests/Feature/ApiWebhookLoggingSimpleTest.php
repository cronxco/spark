<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiWebhookLoggingSimpleTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_per_instance_log_files_automatically()
    {
        // Act - Use the logging helper function directly
        log_integration_api_request(
            'test_auto_instance',
            'GET',
            '/accounts',
            ['Authorization' => 'Bearer token'],
            ['account_id' => 'test'],
            'auto_integration_123',
            true
        );

        // Assert - File should be created automatically
        $expectedFileName = 'api_test_auto_instance_auto_integration_123-' . now()->format('Y-m-d') . '.log';
        $logPath = storage_path('logs/' . $expectedFileName);
        $this->assertTrue(file_exists($logPath));

        // Verify log content structure
        $logContent = file_get_contents($logPath);
        $this->assertStringContainsString('API Request', $logContent);
        $this->assertStringContainsString('test_auto_instance', $logContent);
        $this->assertStringContainsString('auto_integration_123', $logContent);
        $this->assertStringContainsString('[REDACTED]', $logContent); // Sensitive data redacted
    }

    /** @test */
    public function it_creates_per_service_log_files_automatically()
    {
        // Act - Use per-service logging
        log_integration_api_request(
            'test_auto_service',
            'GET',
            '/repos',
            ['Authorization' => 'Bearer token'],
            [],
            '',
            false
        );

        // Assert - File should be created automatically
        $expectedFileName = 'api_test_auto_service-' . now()->format('Y-m-d') . '.log';
        $logPath = storage_path('logs/' . $expectedFileName);
        $this->assertTrue(file_exists($logPath));

        // Verify log content structure
        $logContent = file_get_contents($logPath);
        $this->assertStringContainsString('API Request', $logContent);
        $this->assertStringContainsString('test_auto_service', $logContent);
    }

    /** @test */
    public function it_handles_empty_integration_ids_gracefully()
    {
        // Act - Empty integration ID should fall back to per-service
        log_integration_api_request(
            'test_fallback',
            'GET',
            '/user',
            ['Authorization' => 'Bearer token'],
            [],
            '', // Empty integration ID
            true // Per-instance mode requested
        );

        // Assert - Should create per-service file
        $expectedFileName = 'api_test_fallback-' . now()->format('Y-m-d') . '.log';
        $logPath = storage_path('logs/' . $expectedFileName);
        $this->assertTrue(file_exists($logPath));
    }

    /** @test */
    public function it_sanitizes_sensitive_data_automatically()
    {
        // Act - Include various sensitive data
        log_integration_api_request(
            'test_sanitize',
            'POST',
            '/auth',
            ['Authorization' => 'Bearer secret_token', 'X-API-Key' => 'secret_key'],
            [
                'password' => 'secret123',
                'token' => 'auth_token_456',
                'api_key' => 'key_789',
                'normal_field' => 'safe_value',
            ],
            'sanitize_integration_999',
            true
        );

        // Assert - File created and content sanitized
        $expectedFileName = 'api_test_sanitize_sanitize_integration_999-' . now()->format('Y-m-d') . '.log';
        $logPath = storage_path('logs/' . $expectedFileName);
        $this->assertTrue(file_exists($logPath));

        $logContent = file_get_contents($logPath);

        // Sensitive headers should be redacted
        $this->assertStringContainsString('"Authorization":["[REDACTED]"]', $logContent);
        $this->assertStringContainsString('"X-API-Key":["[REDACTED]"]', $logContent);

        // Sensitive data fields should be redacted
        $this->assertStringContainsString('"password":"[REDACTED]"', $logContent);
        $this->assertStringContainsString('"token":"[REDACTED]"', $logContent);
        $this->assertStringContainsString('"api_key":"[REDACTED]"', $logContent);

        // Safe fields should remain
        $this->assertStringContainsString('"normal_field":"safe_value"', $logContent);
    }

    /** @test */
    public function it_logs_different_message_types_correctly()
    {
        // Act - Test all three logging functions
        $integrationId = 'multi_test_integration_777';

        log_integration_api_request('multi_test', 'GET', '/test', [], [], $integrationId, true);
        log_integration_api_response('multi_test', 'GET', '/test', 200, 'OK', [], $integrationId, true);
        log_integration_webhook('multi_test', $integrationId, ['type' => 'test'], [], true);

        // Assert - All log entries in the same file
        $uuidBlock = explode('-', $integrationId)[0] ?? $integrationId;
        $expectedFileName = 'api_multi_test_' . $uuidBlock . '-' . now()->format('Y-m-d') . '.log';
        $logPath = storage_path('logs/' . $expectedFileName);
        $this->assertTrue(file_exists($logPath));

        $logContent = file_get_contents($logPath);

        // Should contain all three message types
        $this->assertStringContainsString('API Request', $logContent);
        $this->assertStringContainsString('API Response', $logContent);
        $this->assertStringContainsString('Webhook Payload', $logContent);
    }

    /** @test */
    public function it_handles_helper_functions_exist()
    {
        // Test that all required helper functions exist
        $this->assertTrue(function_exists('log_integration_api_request'));
        $this->assertTrue(function_exists('log_integration_api_response'));
        $this->assertTrue(function_exists('log_integration_webhook'));
        $this->assertTrue(function_exists('sanitizeHeaders'));
        $this->assertTrue(function_exists('sanitizeData'));
        $this->assertTrue(function_exists('get_integration_log_channel'));
    }

    /** @test */
    public function it_creates_multiple_unique_log_files()
    {
        // Act - Create logs for multiple different integrations
        log_integration_api_request('service_a', 'GET', '/test1', [], [], 'integration_111', true);
        log_integration_api_request('service_a', 'GET', '/test2', [], [], 'integration_222', true);
        log_integration_api_request('service_b', 'GET', '/test3', [], [], 'integration_333', true);
        log_integration_api_request('service_b', 'GET', '/test4', [], [], '', false); // Per-service

        // Assert - Should create separate files
        $dateSuffix = now()->format('Y-m-d');
        $uuidBlock111 = explode('-', 'integration_111')[0] ?? 'integration_111';
        $uuidBlock222 = explode('-', 'integration_222')[0] ?? 'integration_222';
        $uuidBlock333 = explode('-', 'integration_333')[0] ?? 'integration_333';
        $this->assertTrue(file_exists(storage_path("logs/api_service_a_{$uuidBlock111}-{$dateSuffix}.log")));
        $this->assertTrue(file_exists(storage_path("logs/api_service_a_{$uuidBlock222}-{$dateSuffix}.log")));
        $this->assertTrue(file_exists(storage_path("logs/api_service_b_{$uuidBlock333}-{$dateSuffix}.log")));
        $this->assertTrue(file_exists(storage_path("logs/api_service_b-{$dateSuffix}.log")));

        // Verify different content in each file
        $content111 = file_get_contents(storage_path("logs/api_service_a_{$uuidBlock111}-{$dateSuffix}.log"));
        $content222 = file_get_contents(storage_path("logs/api_service_a_{$uuidBlock222}-{$dateSuffix}.log"));
        $content333 = file_get_contents(storage_path("logs/api_service_b_{$uuidBlock333}-{$dateSuffix}.log"));
        $contentService = file_get_contents(storage_path("logs/api_service_b-{$dateSuffix}.log"));

        $this->assertStringContainsString('integration_111', $content111);
        $this->assertStringContainsString('integration_222', $content222);
        $this->assertStringContainsString('integration_333', $content333);
        $this->assertStringContainsString('/test4', $contentService);
    }
}
