<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\GetEventsByFilterTool;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GetEventsByFilterToolTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'monzo',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
        ]);
    }

    #[Test]
    public function filters_events_by_service(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => 10,
            'value_multiplier' => 1,
            'value_unit' => 'GBP',
            'time' => Carbon::today()->setHour(12),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetEventsByFilterTool::class, [
            'service' => 'monzo',
        ]);

        $response->assertOk();
        $response->assertSee('"service": "monzo"');
        $response->assertSee('"total_count": 1');
    }

    #[Test]
    public function events_carry_their_local_time_alongside_utc(): void
    {
        Carbon::setTestNow('2026-09-25 06:00:00 UTC');
        $this->user->update(['settings' => ['timezone' => 'Europe/London']]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => 599,
            'value_multiplier' => 1,
            'value_unit' => 'GBP',
            'time' => Carbon::parse('2026-09-25T00:06:53Z'),
            'actor_id' => EventObject::factory()->create(['user_id' => $this->user->id])->id,
            'target_id' => EventObject::factory()->create(['user_id' => $this->user->id])->id,
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetEventsByFilterTool::class, [
            'service' => 'monzo',
        ]);

        $response->assertOk();
        $response->assertSee('"time": "2026-09-25T00:06:53+00:00"');
        $response->assertSee('"local_time": "2026-09-25T01:06:53+01:00"');
        $response->assertSee('"timezone": "Europe/London"');

        Carbon::setTestNow();
    }

    #[Test]
    public function filters_events_by_service_and_action(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => 10,
            'value_multiplier' => 1,
            'value_unit' => 'GBP',
            'time' => Carbon::today()->setHour(12),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'bank_transfer_to',
            'value' => 500,
            'value_multiplier' => 1,
            'value_unit' => 'GBP',
            'time' => Carbon::today()->setHour(14),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        $response = SparkServer::actingAs($this->user)->tool(GetEventsByFilterTool::class, [
            'service' => 'monzo',
            'action' => 'card_payment_to',
        ]);

        $response->assertOk();
        $response->assertSee('"total_count": 1');
    }

    #[Test]
    public function requires_service_parameter(): void
    {
        $response = SparkServer::actingAs($this->user)->tool(GetEventsByFilterTool::class, []);

        $response->assertHasErrors(['service']);
    }

    #[Test]
    public function respects_limit_parameter(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        foreach (range(1, 5) as $i) {
            Event::factory()->create([
                'integration_id' => $this->integration->id,
                'service' => 'monzo',
                'domain' => 'money',
                'action' => 'card_payment_to',
                'value' => $i * 10,
                'value_multiplier' => 1,
                'value_unit' => 'GBP',
                'time' => Carbon::today()->setHour($i),
                'actor_id' => $actor->id,
                'target_id' => $target->id,
            ]);
        }

        $response = SparkServer::actingAs($this->user)->tool(GetEventsByFilterTool::class, [
            'service' => 'monzo',
            'limit' => 2,
        ]);

        $response->assertOk();
        $response->assertSee('"total_count": 5');
        $response->assertSee('"returned_count": 2');
    }
}
