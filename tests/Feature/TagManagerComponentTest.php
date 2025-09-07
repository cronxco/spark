<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Tags\Tag;
use Tests\TestCase;

class TagManagerComponentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_adds_a_plain_tag_defaulting_to_spark_type(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $event = $this->createEventFor($user);

        $this->actingAs($user);

        $component = Volt::test('tag-manager', [
            'modelClass' => Event::class,
            'modelId' => (string) $event->id,
        ]);

        $component->call('addTag', 'important');

        $event->refresh();

        $this->assertTrue($event->hasTag('important'));
        $this->assertDatabaseHas('tags', [
            'name' => json_encode(['en' => 'important']),
            'type' => 'spark',
        ]);
    }

    #[Test]
    public function it_adds_a_tag_with_explicit_type_prefix_and_strips_prefix_for_value(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $event = $this->createEventFor($user);
        $this->actingAs($user);

        $component = Volt::test('tag-manager', [
            'modelClass' => Event::class,
            'modelId' => (string) $event->id,
        ]);

        $component->call('addTag', 'topic:Laravel');

        $event->refresh();
        $this->assertTrue($event->hasTag('Laravel'));
        $this->assertDatabaseHas('tags', [
            'name' => json_encode(['en' => 'Laravel']),
            'type' => 'topic',
        ]);
    }

    #[Test]
    public function it_adds_a_tag_with_type_argument_and_handles_prefixed_input(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $event = $this->createEventFor($user);
        $this->actingAs($user);

        $component = Volt::test('tag-manager', [
            'modelClass' => Event::class,
            'modelId' => (string) $event->id,
        ]);

        // Provide type arg; value still may contain prefix which should be stripped
        $component->call('addTag', 'topic_Livewire', 'topic');

        $event->refresh();
        $this->assertTrue($event->hasTag('Livewire'));
        $this->assertDatabaseHas('tags', [
            'name' => json_encode(['en' => 'Livewire']),
            'type' => 'topic',
        ]);
    }

    #[Test]
    public function it_infers_emoji_type_for_emoji_only_values(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $event = $this->createEventFor($user);
        $this->actingAs($user);

        $component = Volt::test('tag-manager', [
            'modelClass' => Event::class,
            'modelId' => (string) $event->id,
        ]);

        $component->call('addTag', '🔥');

        $event->refresh();
        $this->assertTrue($event->hasTag('🔥'));
        $this->assertDatabaseHas('tags', [
            'name' => json_encode(['en' => '🔥']),
            'type' => 'emoji',
        ]);
    }

    #[Test]
    public function it_removes_a_tag_with_or_without_type(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $event = $this->createEventFor($user);
        $this->actingAs($user);

        $event->attachTag(Tag::findOrCreate('Laravel', 'topic'));
        $event->refresh();

        $component = Volt::test('tag-manager', [
            'modelClass' => Event::class,
            'modelId' => (string) $event->id,
        ]);

        $component->call('removeTag', 'Laravel');

        $event->refresh();
        $this->assertFalse($event->hasTag('Laravel'));
    }

    #[Test]
    public function it_ignores_empty_values_when_adding_or_removing(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $event = $this->createEventFor($user);
        $this->actingAs($user);

        $component = Volt::test('livewire.tag-manager', [
            'modelClass' => Event::class,
            'modelId' => (string) $event->id,
        ]);

        $component->call('addTag', '   ');
        $component->call('removeTag', '   ');

        $event->refresh();
        $this->assertCount(0, $event->tags);
    }

    private function createEventFor(User $user): Event
    {
        $integration = Integration::factory()->create(['user_id' => $user->id]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $target = EventObject::factory()->create(['user_id' => $user->id]);

        return Event::factory()->create([
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);
    }
}
