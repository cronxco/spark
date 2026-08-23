<?php

namespace Tests\Unit\Services;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Person;
use App\Models\User;
use App\Services\HomeAssistant\HomeAssistantAttributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HomeAssistantAttributionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected HomeAssistantAttributionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new HomeAssistantAttributionService;
    }

    #[Test]
    public function reassign_to_household_member_creates_a_person_when_none_exists(): void
    {
        $integration = Integration::factory()->create([
            'service' => 'home_assistant',
            'configuration' => ['household_member_name' => 'Dan'],
        ]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);

        $this->assertSame(0, Person::where('user_id', $integration->user_id)->count());

        $this->service->reassignToHouseholdMember($event, $integration);

        $person = Person::where('user_id', $integration->user_id)->where('title', 'Dan')->first();
        $this->assertNotNull($person);

        $event->refresh();
        $this->assertSame($person->id, $event->actor_id);
        $this->assertSame('dan', $event->event_metadata['attributed_to']);
        $this->assertSame('presence', $event->event_metadata['attribution_method']);
    }

    #[Test]
    public function reassign_to_household_member_reuses_an_existing_person(): void
    {
        $integration = Integration::factory()->create([
            'service' => 'home_assistant',
            'configuration' => ['household_member_name' => 'Dan'],
        ]);
        $existingDan = Person::create([
            'user_id' => $integration->user_id,
            'type' => 'immich_person',
            'title' => 'Dan',
            'time' => now(),
            'metadata' => ['immich_person_id' => 'abc123'],
        ]);
        $event = Event::factory()->create(['integration_id' => $integration->id]);

        $this->service->reassignToHouseholdMember($event, $integration);

        $this->assertSame(1, Person::where('user_id', $integration->user_id)->where('title', 'Dan')->count());
        $event->refresh();
        $this->assertSame($existingDan->id, $event->actor_id);
    }

    #[Test]
    public function reassign_respects_a_custom_attribution_method(): void
    {
        $integration = Integration::factory()->create(['service' => 'home_assistant']);
        $event = Event::factory()->create(['integration_id' => $integration->id]);

        $this->service->reassignToHouseholdMember($event, $integration, 'user_confirmed');

        $event->refresh();
        $this->assertSame('user_confirmed', $event->event_metadata['attribution_method']);
    }

    #[Test]
    public function ask_who_was_watching_creates_a_flint_digest_and_question_block(): void
    {
        $user = User::factory()->create(['name' => 'Will']);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'home_assistant',
            'configuration' => ['household_member_name' => 'Dan'],
        ]);
        $target = EventObject::factory()->create(['user_id' => $user->id, 'title' => 'Loki']);
        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'target_id' => $target->id,
        ]);

        $this->service->askWhoWasWatching($event, $integration);

        $digestEvent = Event::where('service', 'flint')->where('action', 'had_summary')->first();
        $this->assertNotNull($digestEvent);

        $question = Block::where('event_id', $digestEvent->id)
            ->where('block_type', 'flint_user_question')
            ->first();

        $this->assertNotNull($question);
        $this->assertStringContainsString('Loki', $question->metadata['question']);
        $this->assertStringContainsString('Dan', $question->metadata['question']);
        $this->assertSame($event->id, $question->metadata['related_event_id']);
        $this->assertSame('home_assistant', $question->metadata['related_service']);
        $this->assertSame(['Me', 'Dan', 'Neither / false trigger'], $question->metadata['answer_options']);
        $this->assertNull($question->metadata['answer']);
    }

    #[Test]
    public function ask_who_was_watching_reuses_todays_digest_event_for_a_second_question(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'home_assistant']);
        $eventOne = Event::factory()->create(['integration_id' => $integration->id]);
        $eventTwo = Event::factory()->create(['integration_id' => $integration->id]);

        $this->service->askWhoWasWatching($eventOne, $integration);
        $this->service->askWhoWasWatching($eventTwo, $integration);

        $this->assertSame(1, Event::where('service', 'flint')->where('action', 'had_summary')->count());
        $this->assertSame(2, Block::where('block_type', 'flint_user_question')->count());
    }
}
