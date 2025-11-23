<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Services\RecentlyViewedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RecentlyViewedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create(['user_id' => $this->user->id]);
    }

    // ===========================================
    // TracksViews Trait Tests
    // ===========================================

    /** @test */
    public function it_logs_view_for_event(): void
    {
        $this->actingAs($this->user);

        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event->logView();

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Event::class,
            'subject_id' => $event->id,
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
            'event' => 'viewed',
        ]);
    }

    /** @test */
    public function it_logs_view_for_event_object(): void
    {
        $this->actingAs($this->user);

        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        $object->logView();

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => EventObject::class,
            'subject_id' => $object->id,
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
            'event' => 'viewed',
        ]);
    }

    /** @test */
    public function it_logs_view_for_block(): void
    {
        $this->actingAs($this->user);

        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $block = Block::factory()->create(['event_id' => $event->id]);
        $block->logView();

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Block::class,
            'subject_id' => $block->id,
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
            'event' => 'viewed',
        ]);
    }

    /** @test */
    public function it_does_not_log_view_without_authenticated_user(): void
    {
        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event->logView();

        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => Event::class,
            'subject_id' => $event->id,
            'event' => 'viewed',
        ]);
    }

    /** @test */
    public function it_detects_recently_viewed_item(): void
    {
        $this->actingAs($this->user);

        $event = Event::factory()->create(['integration_id' => $this->integration->id]);

        $this->assertFalse($event->wasRecentlyViewed());

        $event->logView();

        $this->assertTrue($event->wasRecentlyViewed());
    }

    /** @test */
    public function it_detects_views_within_time_window(): void
    {
        $this->actingAs($this->user);

        $event = Event::factory()->create(['integration_id' => $this->integration->id]);

        // Create an activity log entry that's 10 minutes old
        Activity::create([
            'log_name' => 'changelog',
            'subject_type' => Event::class,
            'subject_id' => $event->id,
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
            'event' => 'viewed',
            'description' => 'viewed',
            'created_at' => now()->subMinutes(10),
        ]);

        // Should NOT be detected with 5-minute window
        $this->assertFalse($event->wasRecentlyViewed(5));

        // Should be detected with 15-minute window
        $this->assertTrue($event->wasRecentlyViewed(15));
    }

    /** @test */
    public function it_logs_view_only_if_not_recent(): void
    {
        $this->actingAs($this->user);

        $event = Event::factory()->create(['integration_id' => $this->integration->id]);

        // First call should log
        $this->assertTrue($event->logViewIfNotRecent(5));
        $this->assertEquals(1, Activity::where('event', 'viewed')->count());

        // Second call within window should not log
        $this->assertFalse($event->logViewIfNotRecent(5));
        $this->assertEquals(1, Activity::where('event', 'viewed')->count());
    }

    // ===========================================
    // RecentlyViewedService Tests
    // ===========================================

    /** @test */
    public function service_returns_recently_viewed_items(): void
    {
        $this->actingAs($this->user);

        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event->logView();
        $object->logView();

        $service = new RecentlyViewedService();
        $recentlyViewed = $service->getRecentlyViewed($this->user);

        $this->assertEquals(2, $recentlyViewed->count());
    }

    /** @test */
    public function service_orders_by_most_recent_first(): void
    {
        $this->actingAs($this->user);

        $event1 = Event::factory()->create(['integration_id' => $this->integration->id, 'action' => 'first']);
        $event2 = Event::factory()->create(['integration_id' => $this->integration->id, 'action' => 'second']);
        $event3 = Event::factory()->create(['integration_id' => $this->integration->id, 'action' => 'third']);

        // Log views with slight time differences
        $event1->logView();
        $this->travel(1)->seconds();
        $event2->logView();
        $this->travel(1)->seconds();
        $event3->logView();

        $service = new RecentlyViewedService();
        $recentlyViewed = $service->getRecentlyViewed($this->user);

        $this->assertEquals($event3->id, $recentlyViewed->first()->model->id);
        $this->assertEquals($event1->id, $recentlyViewed->last()->model->id);
    }

    /** @test */
    public function service_filters_by_type(): void
    {
        $this->actingAs($this->user);

        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event->logView();
        $object->logView();

        $service = new RecentlyViewedService();

        // Get only events
        $events = $service->getRecentlyViewed($this->user, 10, [Event::class]);
        $this->assertEquals(1, $events->count());
        $this->assertEquals(Event::class, $events->first()->type);

        // Get only objects
        $objects = $service->getRecentlyViewed($this->user, 10, [EventObject::class]);
        $this->assertEquals(1, $objects->count());
        $this->assertEquals(EventObject::class, $objects->first()->type);
    }

    /** @test */
    public function service_deduplicates_multiple_views_of_same_item(): void
    {
        $this->actingAs($this->user);

        $event = Event::factory()->create(['integration_id' => $this->integration->id]);

        // Log multiple views of the same item (bypassing the recent check for testing)
        Activity::create([
            'log_name' => 'changelog',
            'subject_type' => Event::class,
            'subject_id' => $event->id,
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
            'event' => 'viewed',
            'description' => 'viewed',
            'created_at' => now()->subMinutes(10),
        ]);

        Activity::create([
            'log_name' => 'changelog',
            'subject_type' => Event::class,
            'subject_id' => $event->id,
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
            'event' => 'viewed',
            'description' => 'viewed',
            'created_at' => now(),
        ]);

        $service = new RecentlyViewedService();
        $recentlyViewed = $service->getRecentlyViewed($this->user);

        // Should only return one item despite two activity records
        $this->assertEquals(1, $recentlyViewed->count());
    }

    /** @test */
    public function service_respects_limit(): void
    {
        $this->actingAs($this->user);

        // Create 5 events and log views
        for ($i = 0; $i < 5; $i++) {
            $event = Event::factory()->create(['integration_id' => $this->integration->id]);
            $event->logView();
            $this->travel(1)->seconds();
        }

        $service = new RecentlyViewedService();

        $limited = $service->getRecentlyViewed($this->user, 3);
        $this->assertEquals(3, $limited->count());
    }

    /** @test */
    public function service_returns_typed_helper_methods(): void
    {
        $this->actingAs($this->user);

        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);
        $parentEvent = Event::factory()->create(['integration_id' => $this->integration->id]);
        $block = Block::factory()->create(['event_id' => $parentEvent->id]);

        $event->logView();
        $object->logView();
        $block->logView();

        $service = new RecentlyViewedService();

        $events = $service->getRecentlyViewedEvents($this->user);
        $this->assertEquals(1, $events->count());
        $this->assertInstanceOf(Event::class, $events->first());

        $objects = $service->getRecentlyViewedObjects($this->user);
        $this->assertEquals(1, $objects->count());
        $this->assertInstanceOf(EventObject::class, $objects->first());

        $blocks = $service->getRecentlyViewedBlocks($this->user);
        $this->assertEquals(1, $blocks->count());
        $this->assertInstanceOf(Block::class, $blocks->first());
    }

    /** @test */
    public function service_returns_correct_count(): void
    {
        $this->actingAs($this->user);

        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $object = EventObject::factory()->create(['user_id' => $this->user->id]);

        $event->logView();
        $object->logView();

        $service = new RecentlyViewedService();

        $this->assertEquals(2, $service->getRecentlyViewedCount($this->user));
        $this->assertEquals(1, $service->getRecentlyViewedCount($this->user, [Event::class]));
        $this->assertEquals(1, $service->getRecentlyViewedCount($this->user, [EventObject::class]));
    }

    // ===========================================
    // Purge Old Views Tests
    // ===========================================

    /** @test */
    public function it_purges_views_beyond_retention_limit(): void
    {
        $this->actingAs($this->user);

        // Create 25 events and log views (5 more than the limit of 20)
        $events = [];
        for ($i = 0; $i < 25; $i++) {
            $event = Event::factory()->create(['integration_id' => $this->integration->id]);
            Activity::create([
                'log_name' => 'changelog',
                'subject_type' => Event::class,
                'subject_id' => $event->id,
                'causer_type' => User::class,
                'causer_id' => $this->user->id,
                'event' => 'viewed',
                'description' => 'viewed',
                'created_at' => now()->subMinutes(25 - $i), // Older events first
            ]);
            $events[] = $event;
        }

        // Verify we have 25 view records
        $this->assertEquals(25, Activity::where('event', 'viewed')->count());

        // Purge with limit of 20
        $service = new RecentlyViewedService();
        $deleted = $service->purgeOldViewsForUser($this->user, 20);

        // Should delete 5 records
        $this->assertEquals(5, $deleted);
        $this->assertEquals(20, Activity::where('event', 'viewed')->count());
    }

    /** @test */
    public function it_keeps_most_recent_views_when_purging(): void
    {
        $this->actingAs($this->user);

        // Create events with clear timestamps
        $oldEvent = Event::factory()->create(['integration_id' => $this->integration->id, 'action' => 'old']);
        $newEvent = Event::factory()->create(['integration_id' => $this->integration->id, 'action' => 'new']);

        Activity::create([
            'log_name' => 'changelog',
            'subject_type' => Event::class,
            'subject_id' => $oldEvent->id,
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
            'event' => 'viewed',
            'description' => 'viewed',
            'created_at' => now()->subHour(),
        ]);

        Activity::create([
            'log_name' => 'changelog',
            'subject_type' => Event::class,
            'subject_id' => $newEvent->id,
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
            'event' => 'viewed',
            'description' => 'viewed',
            'created_at' => now(),
        ]);

        // Purge with limit of 1
        $service = new RecentlyViewedService();
        $deleted = $service->purgeOldViewsForUser($this->user, 1);

        $this->assertEquals(1, $deleted);

        // The new event should still be there
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $newEvent->id,
            'event' => 'viewed',
        ]);

        // The old event should be gone
        $this->assertDatabaseMissing('activity_log', [
            'subject_id' => $oldEvent->id,
            'event' => 'viewed',
        ]);
    }

    /** @test */
    public function it_purges_old_views_automatically_on_log_view(): void
    {
        $this->actingAs($this->user);

        // Create 22 events and log views directly (bypassing auto-purge)
        for ($i = 0; $i < 22; $i++) {
            $event = Event::factory()->create(['integration_id' => $this->integration->id]);
            Activity::create([
                'log_name' => 'changelog',
                'subject_type' => Event::class,
                'subject_id' => $event->id,
                'causer_type' => User::class,
                'causer_id' => $this->user->id,
                'event' => 'viewed',
                'description' => 'viewed',
                'created_at' => now()->subMinutes(22 - $i),
            ]);
        }

        $this->assertEquals(22, Activity::where('event', 'viewed')->count());

        // Log a new view - this should trigger purge
        $newEvent = Event::factory()->create(['integration_id' => $this->integration->id]);
        $newEvent->logView();

        // Should have 20 views (the retention limit)
        $this->assertEquals(20, Activity::where('event', 'viewed')->count());
    }

    /** @test */
    public function it_purges_views_for_all_users(): void
    {
        $user2 = User::factory()->create();
        $integration2 = Integration::factory()->create(['user_id' => $user2->id]);

        // Create 5 views for user 1
        Auth::login($this->user);
        for ($i = 0; $i < 5; $i++) {
            $event = Event::factory()->create(['integration_id' => $this->integration->id]);
            Activity::create([
                'log_name' => 'changelog',
                'subject_type' => Event::class,
                'subject_id' => $event->id,
                'causer_type' => User::class,
                'causer_id' => $this->user->id,
                'event' => 'viewed',
                'description' => 'viewed',
                'created_at' => now()->subMinutes(5 - $i),
            ]);
        }

        // Create 5 views for user 2
        Auth::login($user2);
        for ($i = 0; $i < 5; $i++) {
            $event = Event::factory()->create(['integration_id' => $integration2->id]);
            Activity::create([
                'log_name' => 'changelog',
                'subject_type' => Event::class,
                'subject_id' => $event->id,
                'causer_type' => User::class,
                'causer_id' => $user2->id,
                'event' => 'viewed',
                'description' => 'viewed',
                'created_at' => now()->subMinutes(5 - $i),
            ]);
        }

        $this->assertEquals(10, Activity::where('event', 'viewed')->count());

        // Purge with limit of 3 per user
        $service = new RecentlyViewedService();
        $result = $service->purgeOldViewsForAllUsers(3);

        $this->assertEquals(2, $result['users_processed']);
        $this->assertEquals(4, $result['total_deleted']); // 2 deleted per user
        $this->assertEquals(6, Activity::where('event', 'viewed')->count()); // 3 per user
    }

    // ===========================================
    // Recently Viewed Item Structure Tests
    // ===========================================

    /** @test */
    public function recently_viewed_item_has_correct_structure(): void
    {
        $this->actingAs($this->user);

        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event->logView();

        $service = new RecentlyViewedService();
        $recentlyViewed = $service->getRecentlyViewed($this->user);

        $item = $recentlyViewed->first();

        $this->assertObjectHasProperty('model', $item);
        $this->assertObjectHasProperty('type', $item);
        $this->assertObjectHasProperty('type_label', $item);
        $this->assertObjectHasProperty('viewed_at', $item);
        $this->assertObjectHasProperty('id', $item);

        $this->assertInstanceOf(Event::class, $item->model);
        $this->assertEquals(Event::class, $item->type);
        $this->assertEquals('Event', $item->type_label);
        $this->assertEquals($event->id, $item->id);
    }

    /** @test */
    public function recently_viewed_handles_deleted_models_gracefully(): void
    {
        $this->actingAs($this->user);

        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $eventId = $event->id;
        $event->logView();

        // Verify we have a view
        $service = new RecentlyViewedService();
        $this->assertEquals(1, $service->getRecentlyViewed($this->user)->count());

        // Delete the event
        $event->forceDelete();

        // Should return 0 items (deleted model is filtered out)
        $recentlyViewed = $service->getRecentlyViewed($this->user);
        $this->assertEquals(0, $recentlyViewed->count());
    }

    // ===========================================
    // User Isolation Tests
    // ===========================================

    /** @test */
    public function recently_viewed_is_isolated_per_user(): void
    {
        $user2 = User::factory()->create();
        $integration2 = Integration::factory()->create(['user_id' => $user2->id]);

        // User 1 views an event
        $this->actingAs($this->user);
        $event1 = Event::factory()->create(['integration_id' => $this->integration->id]);
        $event1->logView();

        // User 2 views a different event
        $this->actingAs($user2);
        $event2 = Event::factory()->create(['integration_id' => $integration2->id]);
        $event2->logView();

        $service = new RecentlyViewedService();

        // User 1 should only see their view
        $user1Views = $service->getRecentlyViewed($this->user);
        $this->assertEquals(1, $user1Views->count());
        $this->assertEquals($event1->id, $user1Views->first()->id);

        // User 2 should only see their view
        $user2Views = $service->getRecentlyViewed($user2);
        $this->assertEquals(1, $user2Views->count());
        $this->assertEquals($event2->id, $user2Views->first()->id);
    }
}
