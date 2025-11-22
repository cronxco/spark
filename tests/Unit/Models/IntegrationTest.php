<?php

namespace Tests\Unit\Models;

use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private IntegrationGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);
    }

    #[Test]
    public function it_generates_uuid_on_creation(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
        ]);

        $this->assertNotNull($integration->id);
        $this->assertTrue(Str::isUuid($integration->id));
    }

    #[Test]
    public function it_belongs_to_a_user(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
        ]);

        $this->assertInstanceOf(User::class, $integration->user);
        $this->assertEquals($this->user->id, $integration->user->id);
    }

    #[Test]
    public function it_belongs_to_a_group(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
        ]);

        $this->assertInstanceOf(IntegrationGroup::class, $integration->group);
        $this->assertEquals($this->group->id, $integration->group->id);
    }

    #[Test]
    public function it_returns_default_update_frequency_minutes(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => [],
        ]);

        $this->assertEquals(15, $integration->getUpdateFrequencyMinutes());
    }

    #[Test]
    public function it_returns_custom_update_frequency_minutes(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => ['update_frequency_minutes' => 30],
        ]);

        $this->assertEquals(30, $integration->getUpdateFrequencyMinutes());
    }

    #[Test]
    public function it_detects_task_instance(): void
    {
        $taskIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'task',
        ]);

        $otherIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'other',
        ]);

        $this->assertTrue($taskIntegration->isTaskInstance());
        $this->assertFalse($otherIntegration->isTaskInstance());
    }

    #[Test]
    public function it_detects_paused_state(): void
    {
        $pausedIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => ['paused' => true],
        ]);

        $activeIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => ['paused' => false],
        ]);

        $this->assertTrue($pausedIntegration->isPaused());
        $this->assertFalse($activeIntegration->isPaused());
    }

    #[Test]
    public function it_detects_schedule_mode(): void
    {
        $scheduleIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => ['use_schedule' => true],
        ]);

        $frequencyIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => ['use_schedule' => false],
        ]);

        $this->assertTrue($scheduleIntegration->useSchedule());
        $this->assertFalse($frequencyIntegration->useSchedule());
    }

    #[Test]
    public function it_returns_schedule_times(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => [
                'schedule_times' => ['04:10', '10:10', '16:10', '22:10'],
            ],
        ]);

        $times = $integration->getScheduleTimes();

        $this->assertCount(4, $times);
        $this->assertContains('04:10', $times);
        $this->assertContains('22:10', $times);
    }

    #[Test]
    public function it_filters_invalid_schedule_times(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => [
                'schedule_times' => ['04:10', 'invalid', '16:10', null, 123],
            ],
        ]);

        $times = $integration->getScheduleTimes();

        $this->assertCount(2, $times);
        $this->assertContains('04:10', $times);
        $this->assertContains('16:10', $times);
    }

    #[Test]
    public function it_returns_schedule_timezone(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => [
                'schedule_timezone' => 'Europe/London',
            ],
        ]);

        $this->assertEquals('Europe/London', $integration->getScheduleTimezone());
    }

    #[Test]
    public function it_returns_default_timezone_when_not_configured(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => [],
        ]);

        $this->assertEquals(config('app.timezone', 'UTC'), $integration->getScheduleTimezone());
    }

    #[Test]
    public function it_calculates_next_update_time_with_frequency(): void
    {
        $lastUpdate = Carbon::now()->subMinutes(10);

        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => ['update_frequency_minutes' => 15],
            'last_successful_update_at' => $lastUpdate,
        ]);

        $nextUpdate = $integration->getNextUpdateTime();

        $this->assertNotNull($nextUpdate);
        $this->assertEquals(
            $lastUpdate->copy()->addMinutes(15)->format('Y-m-d H:i'),
            $nextUpdate->format('Y-m-d H:i')
        );
    }

    #[Test]
    public function it_returns_null_for_next_update_when_paused(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => ['paused' => true],
            'last_successful_update_at' => now(),
        ]);

        $this->assertNull($integration->getNextUpdateTime());
    }

    #[Test]
    public function it_detects_when_update_is_due_frequency_based(): void
    {
        $dueIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => ['update_frequency_minutes' => 15],
            'last_successful_update_at' => Carbon::now()->subMinutes(20),
        ]);

        $notDueIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => ['update_frequency_minutes' => 15],
            'last_successful_update_at' => Carbon::now()->subMinutes(5),
        ]);

        $this->assertTrue($dueIntegration->isDue());
        $this->assertFalse($notDueIntegration->isDue());
    }

    #[Test]
    public function it_is_due_when_never_updated(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'last_successful_update_at' => null,
        ]);

        $this->assertTrue($integration->isDue());
    }

    #[Test]
    public function it_is_not_due_when_paused(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => ['paused' => true],
            'last_successful_update_at' => Carbon::now()->subHours(1),
        ]);

        $this->assertFalse($integration->isDue());
    }

    #[Test]
    public function it_detects_throttle_state(): void
    {
        $throttledIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => ['update_frequency_minutes' => 15],
            'last_triggered_at' => Carbon::now()->subMinutes(2),
        ]);

        $notThrottledIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => ['update_frequency_minutes' => 15],
            'last_triggered_at' => Carbon::now()->subMinutes(20),
        ]);

        $this->assertTrue($throttledIntegration->shouldThrottle());
        $this->assertFalse($notThrottledIntegration->shouldThrottle());
    }

    #[Test]
    public function it_marks_as_triggered(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'last_triggered_at' => null,
        ]);

        $integration->markAsTriggered();
        $integration->refresh();

        $this->assertNotNull($integration->last_triggered_at);
    }

    #[Test]
    public function it_marks_as_successfully_updated(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'last_successful_update_at' => null,
            'last_triggered_at' => null,
        ]);

        $integration->markAsSuccessfullyUpdated();
        $integration->refresh();

        $this->assertNotNull($integration->last_successful_update_at);
        $this->assertNotNull($integration->last_triggered_at);
    }

    #[Test]
    public function it_marks_as_failed(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'last_triggered_at' => Carbon::now(),
        ]);

        $integration->markAsFailed();
        $integration->refresh();

        $this->assertNull($integration->last_triggered_at);
    }

    #[Test]
    public function it_detects_processing_state(): void
    {
        $processingIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'last_triggered_at' => Carbon::now()->subMinutes(2),
            'last_successful_update_at' => Carbon::now()->subMinutes(30),
        ]);

        $notProcessingIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'last_triggered_at' => Carbon::now()->subMinutes(2),
            'last_successful_update_at' => Carbon::now()->subMinutes(1),
        ]);

        $this->assertTrue($processingIntegration->isProcessing());
        $this->assertFalse($notProcessingIntegration->isProcessing());
    }

    #[Test]
    public function it_gets_last_event_time(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
        ]);

        $eventTime = Carbon::now()->subHours(2);
        Event::factory()->create([
            'integration_id' => $integration->id,
            'time' => $eventTime,
        ]);

        $lastEventTime = $integration->getLastEventTime();

        $this->assertNotNull($lastEventTime);
        $this->assertEquals(
            $eventTime->format('Y-m-d H:i'),
            $lastEventTime->format('Y-m-d H:i')
        );
    }

    #[Test]
    public function it_returns_null_for_last_event_time_when_no_events(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
        ]);

        $this->assertNull($integration->getLastEventTime());
    }

    #[Test]
    public function it_generates_schedule_summary(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => [
                'use_schedule' => true,
                'schedule_times' => ['04:10', '10:10', '16:10', '22:10'],
                'schedule_timezone' => 'Europe/London',
            ],
        ]);

        $summary = $integration->getScheduleSummary();

        $this->assertStringContainsString('4', $summary);
        $this->assertStringContainsString('daily', $summary);
        $this->assertStringContainsString('Europe/London', $summary);
    }

    #[Test]
    public function it_returns_null_schedule_summary_when_not_using_schedule(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => ['use_schedule' => false],
        ]);

        $this->assertNull($integration->getScheduleSummary());
    }

    #[Test]
    public function it_gets_uuid_block(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
        ]);

        $uuidBlock = $integration->getUuidBlock();

        // UUID block should be the first part of the UUID
        $this->assertEquals(explode('-', $integration->id)[0], $uuidBlock);
    }

    #[Test]
    public function it_uses_soft_deletes(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
        ]);

        $integration->delete();

        $this->assertSoftDeleted('integrations', ['id' => $integration->id]);
    }

    #[Test]
    public function it_casts_configuration_to_array(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
            'configuration' => ['key' => 'value', 'nested' => ['a' => 1]],
        ]);

        $this->assertIsArray($integration->configuration);
        $this->assertEquals('value', $integration->configuration['key']);
        $this->assertEquals(1, $integration->configuration['nested']['a']);
    }
}
