<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_page_loads_for_authenticated_user(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        
        $response = $this->actingAs($user)
            ->get('/events');

        $response->assertStatus(200);
        $response->assertSee('Events — Today');
    }

    public function test_events_page_requires_authentication(): void
    {
        $response = $this->get('/events');

        $response->assertRedirect('/login');
    }

    public function test_events_page_shows_events_from_today(): void
    {
        /** @var User $user */
        $user = User::factory()->createOne();
        
        // Create an event from today
        $todayEvent = Event::factory()->create([
            'time' => now(),
            'action' => 'test_action_today',
        ]);

        // Create an event from yesterday
        $yesterdayEvent = Event::factory()->create([
            'time' => now()->subDay(),
            'action' => 'test_action_yesterday',
        ]);

        $response = $this->actingAs($user)
            ->get('/events');

        $response->assertStatus(200);
        $response->assertSee('Test_action_today');
        $response->assertDontSee('test_action_yesterday');
    }

    public function test_events_page_shows_no_events_message_when_empty(): void
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
