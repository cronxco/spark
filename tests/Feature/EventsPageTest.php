<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventsPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function events_page_loads_for_authenticated_user(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        $response = $this->actingAs($user)
            ->get('/events');

        $response->assertStatus(200);
        $response->assertSee('Events — Today');
    }

    #[Test]
    public function events_page_requires_authentication(): void
    {
        $response = $this->get('/events');

        $response->assertRedirect('/login');
    }

    #[Test]
    public function events_page_shows_events_from_today(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        // Ensure events belong to the acting user via integration
        $integration = Integration::factory()->create(['user_id' => $user->id]);

        // Create an event from today
        $todayEvent = Event::factory()->create([
            'time' => now(),
            'action' => 'test_action_today',
            'integration_id' => $integration->id,
        ]);

        // Create an event from yesterday (same user)
        $yesterdayEvent = Event::factory()->create([
            'time' => now()->subDay(),
            'action' => 'test_action_yesterday',
            'integration_id' => $integration->id,
        ]);

        $response = $this->actingAs($user)
            ->get('/events');

        $response->assertStatus(200);
        $response->assertSee('Test Action Today');
        $response->assertDontSee('Test Action Yesterday');
    }

    #[Test]
    public function events_page_shows_no_events_message_when_empty(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();

        // Delete all events from today
        Event::whereDate('time', now())->delete();

        $response = $this->actingAs($user)
            ->get('/events');

        $response->assertStatus(200);
        $response->assertSee('No events for this date');
    }
}
