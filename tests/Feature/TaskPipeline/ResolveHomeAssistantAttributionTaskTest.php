<?php

namespace Tests\Feature\TaskPipeline;

use App\Integrations\HomeAssistant\HomeAssistantPlugin;
use App\Jobs\TaskPipeline\Tasks\ResolveHomeAssistantAttributionTask;
use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Models\Person;
use App\Services\TaskPipeline\TaskDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveHomeAssistantAttributionTaskTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function leaves_the_event_as_will_when_answered_me(): void
    {
        [$event, $block] = $this->makeWatchAndQuestion();

        $block->update(['metadata' => array_merge($block->metadata, ['answer' => 'Me'])]);

        (new ResolveHomeAssistantAttributionTask($block, $this->task()))->handle();

        $event->refresh();
        $block->refresh();

        $this->assertSame('will', $event->event_metadata['attributed_to']);
        $this->assertSame('user_confirmed', $event->event_metadata['attribution_method']);
        $this->assertNotNull($block->metadata['attribution_resolved_at']);
    }

    #[Test]
    public function reassigns_to_the_household_member_when_answered_with_their_name(): void
    {
        [$event, $block] = $this->makeWatchAndQuestion();

        $block->update(['metadata' => array_merge($block->metadata, ['answer' => 'Dan'])]);

        (new ResolveHomeAssistantAttributionTask($block, $this->task()))->handle();

        $event->refresh();

        $dan = Person::where('title', 'Dan')->first();
        $this->assertNotNull($dan);
        $this->assertSame($dan->id, $event->actor_id);
        $this->assertSame('user_confirmed', $event->event_metadata['attribution_method']);
    }

    #[Test]
    public function reassigns_correctly_when_the_household_members_name_contains_me_as_a_substring(): void
    {
        [$event, $block] = $this->makeWatchAndQuestion('James');

        $block->update(['metadata' => array_merge($block->metadata, ['answer' => 'James'])]);

        (new ResolveHomeAssistantAttributionTask($block, $this->task()))->handle();

        $event->refresh();

        $james = Person::where('title', 'James')->first();
        $this->assertNotNull($james);
        $this->assertSame($james->id, $event->actor_id);
        $this->assertArrayNotHasKey('discarded', $event->event_metadata);
    }

    #[Test]
    public function discards_the_event_when_answered_neither(): void
    {
        [$event, $block] = $this->makeWatchAndQuestion();

        $block->update(['metadata' => array_merge($block->metadata, ['answer' => 'Neither / false trigger'])]);

        (new ResolveHomeAssistantAttributionTask($block, $this->task()))->handle();

        $event->refresh();

        $this->assertTrue($event->event_metadata['discarded']);
    }

    #[Test]
    public function does_nothing_when_the_block_has_no_answer_yet(): void
    {
        [$event, $block] = $this->makeWatchAndQuestion();

        (new ResolveHomeAssistantAttributionTask($block, $this->task()))->handle();

        $event->refresh();
        $block->refresh();

        $this->assertArrayNotHasKey('attribution_method', $event->event_metadata);
        $this->assertArrayNotHasKey('attribution_resolved_at', $block->metadata);
    }

    /**
     * @return array{0: Event, 1: Block}
     */
    protected function makeWatchAndQuestion(string $householdMemberName = 'Dan'): array
    {
        $integration = Integration::factory()->create([
            'service' => 'home_assistant',
            'configuration' => ['household_member_name' => $householdMemberName],
        ]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'home_assistant',
            'action' => 'watched',
            'event_metadata' => [],
        ]);

        $digestEvent = Event::factory()->create([
            'service' => 'flint',
            'action' => 'had_summary',
        ]);

        $block = $digestEvent->createBlock([
            'block_type' => 'flint_user_question',
            'title' => 'Who was watching?',
            'time' => now(),
            'metadata' => [
                'question' => "Was this you watching \"Loki\", or was it {$householdMemberName}?",
                'topic' => 'media',
                'priority' => 'low',
                'answer_options' => ['Me', $householdMemberName, 'Neither / false trigger'],
                'answer' => null,
                'answer_note' => null,
                'answered_at' => null,
                'related_event_id' => $event->id,
                'related_service' => 'home_assistant',
            ],
        ]);

        return [$event, $block];
    }

    protected function task(): TaskDefinition
    {
        return HomeAssistantPlugin::getTaskDefinitions()[0];
    }
}
