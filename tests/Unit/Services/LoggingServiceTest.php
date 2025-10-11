<?php

namespace Tests\Unit\Services;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\LoggingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LoggingServiceTest extends TestCase
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
    public function user_log_channel_has_correct_path_and_configuration(): void
    {
        $uuidBlock = $this->user->getUuidBlock();
        $date = now()->format('Y-m-d');

        $path = LoggingService::getUserLogPath($this->user, $date);

        $this->assertStringContainsString("user_{$uuidBlock}_{$date}.log", $path);
        $this->assertStringContainsString(storage_path('logs'), $path);
    }

    /** @test */
    public function group_log_channel_has_correct_path(): void
    {
        $uuidBlock = $this->group->getUuidBlock();
        $date = now()->format('Y-m-d');

        $path = LoggingService::getGroupLogPath($this->group, $date);

        $this->assertStringContainsString("group_{$uuidBlock}_{$date}.log", $path);
        $this->assertStringContainsString(storage_path('logs'), $path);
    }

    /** @test */
    public function integration_log_channel_has_correct_path(): void
    {
        $uuidBlock = $this->integration->getUuidBlock();
        $date = now()->format('Y-m-d');

        $path = LoggingService::getIntegrationLogPath($this->integration, $date);

        $this->assertStringContainsString("integration_{$uuidBlock}_{$date}.log", $path);
        $this->assertStringContainsString(storage_path('logs'), $path);
    }

    /** @test */
    public function log_to_user_writes_to_user_log_file(): void
    {
        Log::spy();

        LoggingService::logToUser($this->user, 'info', 'Test message', ['foo' => 'bar']);

        Log::shouldHaveReceived('log')
            ->once()
            ->with('info', 'Test message', ['foo' => 'bar']);
    }

    /** @test */
    public function log_to_group_writes_to_group_log_file(): void
    {
        Log::spy();

        LoggingService::logToGroup($this->group, 'warning', 'Group test', ['baz' => 'qux']);

        Log::shouldHaveReceived('log')
            ->once()
            ->with('warning', 'Group test', ['baz' => 'qux']);
    }

    /** @test */
    public function log_to_integration_respects_debug_logging_disabled(): void
    {
        $this->user->disableDebugLogging();
        Log::spy();

        LoggingService::logToIntegration($this->integration, 'debug', 'Debug message');

        Log::shouldNotHaveReceived('log');
    }

    /** @test */
    public function log_to_integration_allows_debug_when_enabled(): void
    {
        $this->user->enableDebugLogging();
        Log::spy();

        LoggingService::logToIntegration($this->integration, 'debug', 'Debug message');

        Log::shouldHaveReceived('log')
            ->once()
            ->with('debug', 'Debug message', []);
    }

    /** @test */
    public function log_to_integration_always_allows_info_and_above(): void
    {
        $this->user->disableDebugLogging();
        Log::spy();

        LoggingService::logToIntegration($this->integration, 'info', 'Info message');
        LoggingService::logToIntegration($this->integration, 'warning', 'Warning message');
        LoggingService::logToIntegration($this->integration, 'error', 'Error message');

        Log::shouldHaveReceived('log')->times(3);
    }

    /** @test */
    public function hierarchical_logging_cascades_info_levels(): void
    {
        Log::spy();

        LoggingService::logHierarchical($this->integration, 'info', 'Test cascade');

        // Should log to integration, group, and user (3 times)
        Log::shouldHaveReceived('log')->times(3);
    }

    /** @test */
    public function hierarchical_logging_keeps_debug_at_integration_level(): void
    {
        $this->user->enableDebugLogging();
        Log::spy();

        LoggingService::logHierarchical($this->integration, 'debug', 'Debug test');

        // Should only log to integration (1 time)
        Log::shouldHaveReceived('log')->once();
    }

    /** @test */
    public function hierarchical_logging_without_group_still_logs_to_user(): void
    {
        // Create integration without group
        $integrationWithoutGroup = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => null,
            'service' => 'test',
        ]);

        Log::spy();

        LoggingService::logHierarchical($integrationWithoutGroup, 'warning', 'Warning test');

        // Should log to integration and user (2 times)
        Log::shouldHaveReceived('log')->times(2);
    }

    /** @test */
    public function get_user_log_files_returns_array(): void
    {
        $files = LoggingService::getUserLogFiles($this->user);

        $this->assertIsArray($files);
    }

    /** @test */
    public function get_group_log_files_returns_array(): void
    {
        $files = LoggingService::getGroupLogFiles($this->group);

        $this->assertIsArray($files);
    }

    /** @test */
    public function get_integration_log_files_returns_array(): void
    {
        $files = LoggingService::getIntegrationLogFiles($this->integration);

        $this->assertIsArray($files);
    }

    /** @test */
    public function log_paths_include_date_when_provided(): void
    {
        $date = '2025-01-15';

        $userPath = LoggingService::getUserLogPath($this->user, $date);
        $groupPath = LoggingService::getGroupLogPath($this->group, $date);
        $integrationPath = LoggingService::getIntegrationLogPath($this->integration, $date);

        $this->assertStringContainsString($date, $userPath);
        $this->assertStringContainsString($date, $groupPath);
        $this->assertStringContainsString($date, $integrationPath);
    }

    /** @test */
    public function log_paths_use_current_date_when_not_provided(): void
    {
        $date = now()->format('Y-m-d');

        $userPath = LoggingService::getUserLogPath($this->user);
        $groupPath = LoggingService::getGroupLogPath($this->group);
        $integrationPath = LoggingService::getIntegrationLogPath($this->integration);

        $this->assertStringContainsString($date, $userPath);
        $this->assertStringContainsString($date, $groupPath);
        $this->assertStringContainsString($date, $integrationPath);
    }
}
