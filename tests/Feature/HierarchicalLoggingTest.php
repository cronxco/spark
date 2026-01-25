<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\LoggingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HierarchicalLoggingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->user->enableDebugLogging();

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

    protected function tearDown(): void
    {
        // Clean up any log files created during tests
        $logsPath = storage_path('logs');
        if (File::exists($logsPath)) {
            $files = File::glob($logsPath . '/*_{' . now()->format('Y-m-d') . '}.log');
            foreach ($files as $file) {
                File::delete($file);
            }
        }

        parent::tearDown();
    }

    /** @test */
    public function debug_logs_only_go_to_integration_instance_file(): void
    {
        LoggingService::logHierarchical($this->integration, 'debug', 'Debug test message');

        $date = now()->format('Y-m-d');

        // Check integration log exists
        $integrationPath = LoggingService::getIntegrationLogPath($this->integration, $date);
        $this->assertFileExists($integrationPath);
        $this->assertStringContainsString('Debug test message', file_get_contents($integrationPath));

        // Check group and user logs don't contain debug message
        $groupPath = LoggingService::getGroupLogPath($this->group, $date);
        $userPath = LoggingService::getUserLogPath($this->user, $date);

        // Group and user logs might not exist or shouldn't contain the debug message
        if (file_exists($groupPath)) {
            $this->assertStringNotContainsString('Debug test message', file_get_contents($groupPath));
        }

        if (file_exists($userPath)) {
            $this->assertStringNotContainsString('Debug test message', file_get_contents($userPath));
        }
    }

    /** @test */
    public function info_logs_cascade_from_integration_to_group_to_user(): void
    {
        LoggingService::logHierarchical($this->integration, 'info', 'Info cascade test');

        $date = now()->format('Y-m-d');

        $integrationPath = LoggingService::getIntegrationLogPath($this->integration, $date);
        $groupPath = LoggingService::getGroupLogPath($this->group, $date);
        $userPath = LoggingService::getUserLogPath($this->user, $date);

        $this->assertFileExists($integrationPath);
        $this->assertFileExists($groupPath);
        $this->assertFileExists($userPath);

        $this->assertStringContainsString('Info cascade test', file_get_contents($integrationPath));
        $this->assertStringContainsString('Info cascade test', file_get_contents($groupPath));
        $this->assertStringContainsString('Info cascade test', file_get_contents($userPath));
    }

    /** @test */
    public function warning_logs_cascade_from_integration_to_group_to_user(): void
    {
        LoggingService::logHierarchical($this->integration, 'warning', 'Warning cascade test');

        $date = now()->format('Y-m-d');

        $integrationPath = LoggingService::getIntegrationLogPath($this->integration, $date);
        $groupPath = LoggingService::getGroupLogPath($this->group, $date);
        $userPath = LoggingService::getUserLogPath($this->user, $date);

        $this->assertFileExists($integrationPath);
        $this->assertFileExists($groupPath);
        $this->assertFileExists($userPath);

        $this->assertStringContainsString('Warning cascade test', file_get_contents($integrationPath));
        $this->assertStringContainsString('Warning cascade test', file_get_contents($groupPath));
        $this->assertStringContainsString('Warning cascade test', file_get_contents($userPath));
    }

    /** @test */
    public function error_logs_cascade_from_integration_to_group_to_user(): void
    {
        LoggingService::logHierarchical($this->integration, 'error', 'Error cascade test');

        $date = now()->format('Y-m-d');

        $integrationPath = LoggingService::getIntegrationLogPath($this->integration, $date);
        $groupPath = LoggingService::getGroupLogPath($this->group, $date);
        $userPath = LoggingService::getUserLogPath($this->user, $date);

        $this->assertFileExists($integrationPath);
        $this->assertFileExists($groupPath);
        $this->assertFileExists($userPath);

        $this->assertStringContainsString('Error cascade test', file_get_contents($integrationPath));
        $this->assertStringContainsString('Error cascade test', file_get_contents($groupPath));
        $this->assertStringContainsString('Error cascade test', file_get_contents($userPath));
    }

    /** @test */
    public function logs_are_written_to_correct_daily_files(): void
    {
        $date = now()->format('Y-m-d');

        LoggingService::logToUser($this->user, 'info', 'User log test');
        LoggingService::logToGroup($this->group, 'info', 'Group log test');
        LoggingService::logToIntegration($this->integration, 'info', 'Integration log test');

        $userPath = LoggingService::getUserLogPath($this->user, $date);
        $groupPath = LoggingService::getGroupLogPath($this->group, $date);
        $integrationPath = LoggingService::getIntegrationLogPath($this->integration, $date);

        $this->assertStringContainsString($date, $userPath);
        $this->assertStringContainsString($date, $groupPath);
        $this->assertStringContainsString($date, $integrationPath);

        $this->assertFileExists($userPath);
        $this->assertFileExists($groupPath);
        $this->assertFileExists($integrationPath);
    }

    /** @test */
    public function user_with_debug_disabled_doesnt_get_debug_logs_in_integration_file(): void
    {
        $this->user->disableDebugLogging();

        LoggingService::logToIntegration($this->integration, 'debug', 'Should not be logged');

        $date = now()->format('Y-m-d');
        $integrationPath = LoggingService::getIntegrationLogPath($this->integration, $date);

        // When debug logging is disabled, the log file should not be created at all
        // This ensures that sensitive debug information is not written to disk
        $this->assertFileDoesNotExist($integrationPath, 'Debug log file should not be created when debug logging is disabled');
    }

    /** @test */
    public function integration_without_group_still_logs_to_user(): void
    {
        $integrationWithoutGroup = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => null,
            'service' => 'test',
        ]);

        LoggingService::logHierarchical($integrationWithoutGroup, 'info', 'No group test');

        $date = now()->format('Y-m-d');

        $integrationPath = LoggingService::getIntegrationLogPath($integrationWithoutGroup, $date);
        $userPath = LoggingService::getUserLogPath($this->user, $date);

        $this->assertFileExists($integrationPath);
        $this->assertFileExists($userPath);

        $this->assertStringContainsString('No group test', file_get_contents($integrationPath));
        $this->assertStringContainsString('No group test', file_get_contents($userPath));
    }

    /** @test */
    public function log_files_use_correct_uuid_block_naming(): void
    {
        LoggingService::logToUser($this->user, 'info', 'Test');
        LoggingService::logToGroup($this->group, 'info', 'Test');
        LoggingService::logToIntegration($this->integration, 'info', 'Test');

        $date = now()->format('Y-m-d');

        $userPath = LoggingService::getUserLogPath($this->user, $date);
        $groupPath = LoggingService::getGroupLogPath($this->group, $date);
        $integrationPath = LoggingService::getIntegrationLogPath($this->integration, $date);

        $userUuidBlock = $this->user->getUuidBlock();
        $groupUuidBlock = $this->group->getUuidBlock();
        $integrationUuidBlock = $this->integration->getUuidBlock();

        $this->assertStringContainsString("user_{$userUuidBlock}", $userPath);
        $this->assertStringContainsString("group_test_{$groupUuidBlock}", $groupPath);
        $this->assertStringContainsString("integration_test_default_{$integrationUuidBlock}", $integrationPath);

        $this->assertFileExists($userPath);
        $this->assertFileExists($groupPath);
        $this->assertFileExists($integrationPath);
    }

    /** @test */
    public function multiple_integrations_create_separate_log_files(): void
    {
        $integration2 = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
        ]);

        LoggingService::logToIntegration($this->integration, 'info', 'Integration 1 message');
        LoggingService::logToIntegration($integration2, 'info', 'Integration 2 message');

        $date = now()->format('Y-m-d');

        $path1 = LoggingService::getIntegrationLogPath($this->integration, $date);
        $path2 = LoggingService::getIntegrationLogPath($integration2, $date);

        // Paths should be different
        $this->assertNotEquals($path1, $path2);

        $this->assertFileExists($path1);
        $this->assertFileExists($path2);

        // Each file should contain only its own message
        $this->assertStringContainsString('Integration 1 message', file_get_contents($path1));
        $this->assertStringNotContainsString('Integration 2 message', file_get_contents($path1));

        $this->assertStringContainsString('Integration 2 message', file_get_contents($path2));
        $this->assertStringNotContainsString('Integration 1 message', file_get_contents($path2));
    }

    /** @test */
    public function log_context_is_preserved_in_files(): void
    {
        $context = [
            'integration_id' => $this->integration->id,
            'service' => 'test',
            'action' => 'fetch',
        ];

        LoggingService::logToIntegration($this->integration, 'info', 'Context test', $context);

        $date = now()->format('Y-m-d');
        $integrationPath = LoggingService::getIntegrationLogPath($this->integration, $date);

        $this->assertFileExists($integrationPath);

        $content = file_get_contents($integrationPath);
        $this->assertStringContainsString('Context test', $content);
        // Context should be serialized in the log (Laravel typically includes it as JSON)
        $this->assertStringContainsString((string) $this->integration->id, $content);
    }

    /** @test */
    public function critical_logs_cascade_to_all_levels(): void
    {
        LoggingService::logHierarchical($this->integration, 'critical', 'Critical issue');

        $date = now()->format('Y-m-d');

        $integrationPath = LoggingService::getIntegrationLogPath($this->integration, $date);
        $groupPath = LoggingService::getGroupLogPath($this->group, $date);
        $userPath = LoggingService::getUserLogPath($this->user, $date);

        $this->assertFileExists($integrationPath);
        $this->assertFileExists($groupPath);
        $this->assertFileExists($userPath);

        $this->assertStringContainsString('Critical issue', file_get_contents($integrationPath));
        $this->assertStringContainsString('Critical issue', file_get_contents($groupPath));
        $this->assertStringContainsString('Critical issue', file_get_contents($userPath));
    }
}
