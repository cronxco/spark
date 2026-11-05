<?php

namespace Tests\Feature\Admin;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The 17 Oct 2026 sense-check export reported names that live code writes on
 * purpose: shared object types, retired names on old rows, the internal AI
 * usage ledger and the Task plugin, which writes no events at all.
 */
class SenseCheckDeclarationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    #[Test]
    public function names_written_by_live_code_are_not_reported(): void
    {
        $this->event('apple_health', 'had_weight_body_mass', 'apple_health_user', 'apple_metric', ['heart_rate', 'distance']);
        $this->event('flint', 'task_incident_reported', 'user_profile', 'user_profile');
        $this->event('flint', 'had_summary', 'user_profile', 'morning_digest');
        $this->event('google-calendar', 'had_event', 'google_calendar', 'calendar_event', ['event_time']);
        $this->event('fetch', 'fetched', 'fetch_user', 'fetch_webpage', ['fetch_content']);
        $this->event('oura', 'did_workout', 'oura_user', 'strength_training');
        $this->event('oura', 'had_spo2', 'oura_user', 'oura_daily_spo2');
        $this->event('receipt', 'had_receipt_from', 'user', 'receipt_merchant');
        $this->event('manual_account', 'had_balance', 'monzo_account', 'day');

        $page = $this->page();

        $this->assertSame([], $page->getUndefinedActionsProperty());
        $this->assertSame([], $page->getUndefinedBlockTypesProperty());
        $this->assertSame([], $page->getUndefinedObjectTypesProperty());
        $this->assertSame([], $page->getUnknownServicesProperty());
    }

    #[Test]
    public function retired_names_on_old_rows_are_recognised(): void
    {
        $this->event('receipt', 'receipt_received_from', 'user', 'receipt_merchant');
        $this->event('flint', 'had_summary', 'user_profile', 'evening_digest', ['flint_story', 'flint_urgent_alert']);
        $this->event('oura', 'did_workout', 'oura_user', 'horsebackRiding');

        $page = $this->page();

        $this->assertSame([], $page->getUndefinedBlockTypesProperty());
        $this->assertSame([], $page->getUndefinedObjectTypesProperty());
        $this->assertNotContains('receipt', array_column($page->getUndefinedActionsProperty(), 'service'));
    }

    #[Test]
    public function an_undeclared_name_is_still_reported(): void
    {
        $this->event('apple_health', 'had_made_up_metric', 'apple_health_user', 'invented_type', ['invented_block']);

        $page = $this->page();

        $this->assertSame([['service' => 'apple_health', 'actions' => ['had_made_up_metric']]], $page->getUndefinedActionsProperty());
        $this->assertSame([['service' => 'apple_health', 'block_types' => ['invented_block']]], $page->getUndefinedBlockTypesProperty());
        $this->assertSame([['service' => 'apple_health', 'object_types' => ['invented_type']]], $page->getUndefinedObjectTypesProperty());
    }

    #[Test]
    public function the_internal_ai_usage_ledger_is_not_an_invalid_integration(): void
    {
        $this->event('openai', 'used_ai', 'user_profile', 'day', [], ['service' => 'openai', 'integration_group_id' => null]);

        $page = $this->page();

        $this->assertSame([], $page->getUnknownServicesProperty());
        $this->assertSame([], $page->getUndefinedActionsProperty());
        $this->assertSame([], $page->getInvalidIntegrationsProperty());
    }

    #[Test]
    public function the_task_plugin_is_not_flagged_for_declaring_no_actions(): void
    {
        $this->assertNotContains('task', array_column($this->page()->getPluginConfigIssuesProperty(), 'plugin'));
    }

    private function page(): object
    {
        return Volt::actingAs($this->admin)->test('admin.sense-check')->instance();
    }

    private function event(string $service, string $action, string $actorType, string $targetType, array $blockTypes = [], array $integration = []): Event
    {
        $integrationRow = Integration::factory()->create(array_merge(['user_id' => $this->admin->id, 'service' => $service], $integration));
        $actor = EventObject::factory()->create(['user_id' => $this->admin->id, 'type' => $actorType]);
        $target = EventObject::factory()->create(['user_id' => $this->admin->id, 'type' => $targetType]);

        $event = Event::factory()->create([
            'integration_id' => $integrationRow->id,
            'service' => $service,
            'action' => $action,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        foreach ($blockTypes as $blockType) {
            Block::factory()->create(['event_id' => $event->id, 'block_type' => $blockType]);
        }

        return $event;
    }
}
