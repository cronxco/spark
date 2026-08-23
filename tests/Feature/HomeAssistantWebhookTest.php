<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HomeAssistantWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected IntegrationGroup $group;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Will']);
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'home_assistant',
            'account_id' => 'test_webhook_secret_123',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'home_assistant',
            'instance_type' => 'media_watch',
            'account_id' => 'test_webhook_secret_123',
            'configuration' => ['household_member_name' => 'Dan'],
        ]);
    }

    #[Test]
    public function rejects_an_invalid_secret(): void
    {
        $this->postJson('/webhook/home_assistant/wrong_secret', [
            'title' => 'Loki',
        ])->assertStatus(401);

        $this->assertDatabaseCount('events', 0);
    }

    #[Test]
    public function creates_a_watched_event_and_leaves_it_attributed_to_will_when_only_will_is_home(): void
    {
        $this->postJson('/webhook/home_assistant/test_webhook_secret_123', [
            'title' => 'Loki',
            'app_name' => 'Disney+',
            'media_content_type' => 'tvshow',
            'entity_id' => 'media_player.living_room_atv',
            'minutes_watched' => 15,
            'will_home' => true,
            'dan_home' => false,
        ])->assertSuccessful();

        $event = Event::where('integration_id', $this->integration->id)->first();

        $this->assertNotNull($event);
        $this->assertSame('watched', $event->action);
        $this->assertSame('Loki', $event->target->title);
        $this->assertSame('Will', $event->actor->title);

        // No attribution question should have been raised.
        $this->assertDatabaseMissing('blocks', ['block_type' => 'flint_user_question']);
    }

    #[Test]
    public function reassigns_to_the_household_member_when_only_they_are_home(): void
    {
        $this->postJson('/webhook/home_assistant/test_webhook_secret_123', [
            'title' => 'Loki',
            'entity_id' => 'media_player.living_room_atv',
            'will_home' => false,
            'dan_home' => true,
        ])->assertSuccessful();

        $event = Event::where('integration_id', $this->integration->id)->first();

        $this->assertNotNull($event);
        $this->assertSame('Dan', $event->actor->title);
        $this->assertSame('dan', $event->event_metadata['attributed_to']);
        $this->assertSame('presence', $event->event_metadata['attribution_method']);
        $this->assertDatabaseMissing('blocks', ['block_type' => 'flint_user_question']);
    }

    #[Test]
    public function asks_who_was_watching_when_presence_is_ambiguous(): void
    {
        $this->postJson('/webhook/home_assistant/test_webhook_secret_123', [
            'title' => 'Loki',
            'entity_id' => 'media_player.living_room_atv',
            'will_home' => true,
            'dan_home' => true,
        ])->assertSuccessful();

        $event = Event::where('integration_id', $this->integration->id)->first();
        $this->assertNotNull($event);

        // Still attributed to Will until answered.
        $this->assertSame('Will', $event->actor->title);

        $questionBlock = \App\Models\Block::where('block_type', 'flint_user_question')->first();
        $this->assertNotNull($questionBlock);
        $this->assertSame($event->id, $questionBlock->metadata['related_event_id']);
        $this->assertSame('home_assistant', $questionBlock->metadata['related_service']);
        $this->assertStringContainsString('Loki', $questionBlock->metadata['question']);
        $this->assertNull($questionBlock->metadata['answer']);
    }

    #[Test]
    public function does_not_duplicate_the_same_watch_event_on_replay(): void
    {
        $payload = [
            'title' => 'Loki',
            'entity_id' => 'media_player.living_room_atv',
            'will_home' => true,
            'dan_home' => false,
        ];

        $this->postJson('/webhook/home_assistant/test_webhook_secret_123', $payload)->assertSuccessful();
        $this->postJson('/webhook/home_assistant/test_webhook_secret_123', $payload)->assertSuccessful();

        $this->assertSame(
            1,
            Event::where('integration_id', $this->integration->id)->count()
        );
    }

    #[Test]
    public function household_member_name_is_configurable(): void
    {
        $this->integration->update(['configuration' => ['household_member_name' => 'Sam']]);

        $this->postJson('/webhook/home_assistant/test_webhook_secret_123', [
            'title' => 'Loki',
            'entity_id' => 'media_player.living_room_atv',
            'will_home' => false,
            'dan_home' => true,
        ])->assertSuccessful();

        $this->assertTrue(Person::where('title', 'Sam')->exists());
        $this->assertFalse(Person::where('title', 'Dan')->exists());
    }
}
