<?php

namespace Tests\Feature\Livewire;

use App\Livewire\LogViewer;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\LoggingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LogViewerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

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
    public function component_mounts_with_user_logs(): void
    {
        $component = Livewire::test(LogViewer::class, [
            'type' => 'user',
            'entityId' => $this->user->id,
        ]);

        $component->assertSet('type', 'user')
            ->assertSet('entityId', $this->user->id);
    }

    /** @test */
    public function component_mounts_with_group_logs(): void
    {
        $component = Livewire::test(LogViewer::class, [
            'type' => 'group',
            'entityId' => $this->group->id,
        ]);

        $component->assertSet('type', 'group')
            ->assertSet('entityId', $this->group->id);
    }

    /** @test */
    public function component_mounts_with_integration_logs(): void
    {
        $component = Livewire::test(LogViewer::class, [
            'type' => 'integration',
            'entityId' => $this->integration->id,
        ]);

        $component->assertSet('type', 'integration')
            ->assertSet('entityId', $this->integration->id);
    }

    /** @test */
    public function component_uses_current_date_when_not_provided(): void
    {
        $component = Livewire::test(LogViewer::class, [
            'type' => 'user',
            'entityId' => $this->user->id,
        ]);

        $component->assertSet('date', now()->format('Y-m-d'));
    }

    /** @test */
    public function date_filter_updates_logs(): void
    {
        $component = Livewire::test(LogViewer::class, [
            'type' => 'user',
            'entityId' => $this->user->id,
        ]);

        $component->set('date', '2025-01-15')
            ->assertSet('date', '2025-01-15');
    }

    /** @test */
    public function level_filter_updates_logs(): void
    {
        $component = Livewire::test(LogViewer::class, [
            'type' => 'user',
            'entityId' => $this->user->id,
        ]);

        $component->set('levelFilter', 'error')
            ->assertSet('levelFilter', 'error');
    }

    /** @test */
    public function search_filter_updates_logs(): void
    {
        $component = Livewire::test(LogViewer::class, [
            'type' => 'user',
            'entityId' => $this->user->id,
        ]);

        $component->set('search', 'test query')
            ->assertSet('search', 'test query');
    }

    /** @test */
    public function refresh_logs_method_works(): void
    {
        $component = Livewire::test(LogViewer::class, [
            'type' => 'user',
            'entityId' => $this->user->id,
        ]);

        $component->call('refreshLogs')
            ->assertOk();
    }

    /** @test */
    public function get_level_badge_class_returns_correct_classes(): void
    {
        $component = Livewire::test(LogViewer::class, [
            'type' => 'user',
            'entityId' => $this->user->id,
        ]);

        $this->assertEquals('badge-ghost', $component->instance()->getLevelBadgeClass('debug'));
        $this->assertEquals('badge-info', $component->instance()->getLevelBadgeClass('info'));
        $this->assertEquals('badge-primary', $component->instance()->getLevelBadgeClass('notice'));
        $this->assertEquals('badge-warning', $component->instance()->getLevelBadgeClass('warning'));
        $this->assertEquals('badge-error', $component->instance()->getLevelBadgeClass('error'));
        $this->assertEquals('badge-error', $component->instance()->getLevelBadgeClass('critical'));
    }

    /** @test */
    public function empty_log_file_displays_correctly(): void
    {
        $component = Livewire::test(LogViewer::class, [
            'type' => 'user',
            'entityId' => $this->user->id,
        ]);

        $component->assertSet('logLines', []);
    }

    /** @test */
    public function available_dates_are_loaded(): void
    {
        $component = Livewire::test(LogViewer::class, [
            'type' => 'user',
            'entityId' => $this->user->id,
        ]);

        $component->assertSet('availableDates', []);
    }

    /** @test */
    public function component_renders_successfully(): void
    {
        Livewire::test(LogViewer::class, [
            'type' => 'user',
            'entityId' => $this->user->id,
        ])->assertStatus(200);
    }

    /** @test */
    public function component_with_logs_parses_correctly(): void
    {
        // Write a sample log file
        $date = now()->format('Y-m-d');
        $logPath = LoggingService::getUserLogPath($this->user, $date);
        $logDir = dirname($logPath);

        if (! file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $sampleLog = "[{$date} 10:00:00] testing.INFO: Test message\n";
        file_put_contents($logPath, $sampleLog);

        $component = Livewire::test(LogViewer::class, [
            'type' => 'user',
            'entityId' => $this->user->id,
            'date' => $date,
        ]);

        $this->assertNotEmpty($component->get('logLines'));

        // Clean up
        if (file_exists($logPath)) {
            unlink($logPath);
        }
    }

    /** @test */
    public function level_filter_filters_log_lines(): void
    {
        // Write multiple log levels
        $date = now()->format('Y-m-d');
        $logPath = LoggingService::getUserLogPath($this->user, $date);
        $logDir = dirname($logPath);

        if (! file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $sampleLog = "[{$date} 10:00:00] testing.INFO: Info message\n";
        $sampleLog .= "[{$date} 10:01:00] testing.ERROR: Error message\n";
        $sampleLog .= "[{$date} 10:02:00] testing.WARNING: Warning message\n";
        file_put_contents($logPath, $sampleLog);

        $component = Livewire::test(LogViewer::class, [
            'type' => 'user',
            'entityId' => $this->user->id,
            'date' => $date,
        ]);

        // Filter by error level
        $component->set('levelFilter', 'error');
        $logLines = $component->get('logLines');

        $this->assertCount(1, $logLines);
        $this->assertEquals('error', $logLines[0]['level']);

        // Clean up
        if (file_exists($logPath)) {
            unlink($logPath);
        }
    }

    /** @test */
    public function search_filter_filters_log_lines(): void
    {
        // Write logs with different messages
        $date = now()->format('Y-m-d');
        $logPath = LoggingService::getUserLogPath($this->user, $date);
        $logDir = dirname($logPath);

        if (! file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $sampleLog = "[{$date} 10:00:00] testing.INFO: API request started\n";
        $sampleLog .= "[{$date} 10:01:00] testing.INFO: Database query executed\n";
        $sampleLog .= "[{$date} 10:02:00] testing.INFO: API request completed\n";
        file_put_contents($logPath, $sampleLog);

        $component = Livewire::test(LogViewer::class, [
            'type' => 'user',
            'entityId' => $this->user->id,
            'date' => $date,
        ]);

        // Search for "API"
        $component->set('search', 'API');
        $logLines = $component->get('logLines');

        $this->assertCount(2, $logLines);

        // Clean up
        if (file_exists($logPath)) {
            unlink($logPath);
        }
    }
}
