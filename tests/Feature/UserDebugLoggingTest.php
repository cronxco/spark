<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\User;
use App\Services\LoggingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class UserDebugLoggingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** @test */
    public function has_debug_logging_enabled_returns_true_by_default(): void
    {
        config(['logging.debug_logging_default' => true]);

        $this->assertTrue($this->user->hasDebugLoggingEnabled());
    }

    /** @test */
    public function has_debug_logging_enabled_returns_false_when_default_is_false(): void
    {
        config(['logging.debug_logging_default' => false]);

        $user = User::factory()->create();

        $this->assertFalse($user->hasDebugLoggingEnabled());
    }

    /** @test */
    public function has_debug_logging_enabled_respects_user_preference(): void
    {
        $this->user->enableDebugLogging();

        $this->assertTrue($this->user->hasDebugLoggingEnabled());

        $this->user->disableDebugLogging();

        $this->assertFalse($this->user->hasDebugLoggingEnabled());
    }

    /** @test */
    public function enable_debug_logging_updates_settings_correctly(): void
    {
        $this->user->enableDebugLogging();

        $this->user->refresh();

        $settings = $this->user->settings;
        $this->assertIsArray($settings);
        $this->assertTrue($settings['debug_logging_enabled']);
    }

    /** @test */
    public function disable_debug_logging_updates_settings_correctly(): void
    {
        $this->user->disableDebugLogging();

        $this->user->refresh();

        $settings = $this->user->settings;
        $this->assertIsArray($settings);
        $this->assertFalse($settings['debug_logging_enabled']);
    }

    /** @test */
    public function settings_column_stores_json_correctly(): void
    {
        $this->user->update([
            'settings' => [
                'debug_logging_enabled' => true,
                'theme' => 'dark',
                'notifications' => ['email' => true, 'sms' => false],
            ],
        ]);

        $this->user->refresh();

        $settings = $this->user->settings;
        $this->assertTrue($settings['debug_logging_enabled']);
        $this->assertEquals('dark', $settings['theme']);
        $this->assertTrue($settings['notifications']['email']);
        $this->assertFalse($settings['notifications']['sms']);
    }

    /** @test */
    public function debug_logs_are_written_when_enabled(): void
    {
        $this->user->enableDebugLogging();

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);

        Log::spy();

        LoggingService::logToIntegration($integration, 'debug', 'Debug message');

        Log::shouldHaveReceived('log')->once();
    }

    /** @test */
    public function debug_logs_are_not_written_when_disabled(): void
    {
        $this->user->disableDebugLogging();

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);

        Log::spy();

        LoggingService::logToIntegration($integration, 'debug', 'Debug message');

        Log::shouldNotHaveReceived('log');
    }

    /** @test */
    public function info_and_above_logs_are_written_regardless_of_debug_setting(): void
    {
        $this->user->disableDebugLogging();

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);

        Log::spy();

        LoggingService::logToIntegration($integration, 'info', 'Info message');
        LoggingService::logToIntegration($integration, 'warning', 'Warning message');
        LoggingService::logToIntegration($integration, 'error', 'Error message');

        Log::shouldHaveReceived('log')->times(3);
    }

    /** @test */
    public function get_uuid_block_returns_first_segment(): void
    {
        $uuidBlock = $this->user->getUuidBlock();

        $this->assertNotEmpty($uuidBlock);
        $this->assertStringNotContainsString('-', $uuidBlock);
        $this->assertEquals(8, strlen($uuidBlock));
    }

    /** @test */
    public function user_preference_overrides_default_config(): void
    {
        config(['logging.debug_logging_default' => false]);

        $this->user->enableDebugLogging();

        $this->assertTrue($this->user->hasDebugLoggingEnabled());
    }

    /** @test */
    public function user_can_change_preference_multiple_times(): void
    {
        $this->user->enableDebugLogging();
        $this->assertTrue($this->user->fresh()->hasDebugLoggingEnabled());

        $this->user->disableDebugLogging();
        $this->assertFalse($this->user->fresh()->hasDebugLoggingEnabled());

        $this->user->enableDebugLogging();
        $this->assertTrue($this->user->fresh()->hasDebugLoggingEnabled());
    }

    /** @test */
    public function settings_persists_across_sessions(): void
    {
        $this->user->disableDebugLogging();

        // Simulate new session by getting fresh user from database
        $freshUser = User::find($this->user->id);

        $this->assertFalse($freshUser->hasDebugLoggingEnabled());
    }
}
