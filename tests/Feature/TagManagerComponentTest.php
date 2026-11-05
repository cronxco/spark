<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
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
        $this->assertTrue(
            Tag::query()
                ->where('type', 'spark')
                ->whereRaw("name->>'en' = ?", ['important'])
                ->exists()
        );
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
        $this->assertTrue(
            Tag::query()
                ->where('type', 'topic')
                ->whereRaw("name->>'en' = ?", ['Laravel'])
                ->exists()
        );
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
        $this->assertTrue(
            Tag::query()
                ->where('type', 'topic')
                ->whereRaw("name->>'en' = ?", ['Livewire'])
                ->exists()
        );
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
        $this->assertTrue(
            Tag::query()
                ->where('type', 'emoji')
                ->whereRaw("name->>'en' = ?", ['🔥'])
                ->exists()
        );
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

        $component->call('removeTag', 'Laravel', 'topic');

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

        $component = Volt::test('tag-manager', [
            'modelClass' => Event::class,
            'modelId' => (string) $event->id,
        ]);

        $component->call('addTag', '   ');
        $component->call('removeTag', '   ');

        $event->refresh();
        $this->assertCount(0, $event->tags);
    }

    #[Test]
    public function it_logs_tag_addition_to_activity_log(): void
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

        $activity = Activity::query()
            ->where('subject_type', Event::class)
            ->where('subject_id', $event->id)
            ->where('event', 'tag_added')
            ->latest()
            ->first();

        $this->assertNotNull($activity);
        $this->assertStringContainsString('added tag "important"', $activity->description);
        $this->assertEquals('important', $activity->properties->get('tag_name'));
        $this->assertEquals('spark', $activity->properties->get('tag_type'));
    }

    #[Test]
    public function it_logs_typed_tag_addition_to_activity_log(): void
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

        $activity = Activity::query()
            ->where('subject_type', Event::class)
            ->where('subject_id', $event->id)
            ->where('event', 'tag_added')
            ->latest()
            ->first();

        $this->assertNotNull($activity);
        $this->assertStringContainsString('added tag "topic:Laravel"', $activity->description);
        $this->assertEquals('Laravel', $activity->properties->get('tag_name'));
        $this->assertEquals('topic', $activity->properties->get('tag_type'));
    }

    #[Test]
    public function it_logs_tag_removal_to_activity_log(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $event = $this->createEventFor($user);
        $this->actingAs($user);

        $event->attachTag(Tag::findOrCreate('important', 'spark'));
        $event->refresh();

        $component = Volt::test('tag-manager', [
            'modelClass' => Event::class,
            'modelId' => (string) $event->id,
        ]);

        $component->call('removeTag', 'important', 'spark');

        $activity = Activity::query()
            ->where('subject_type', Event::class)
            ->where('subject_id', $event->id)
            ->where('event', 'tag_removed')
            ->latest()
            ->first();

        $this->assertNotNull($activity);
        $this->assertStringContainsString('removed tag "important"', $activity->description);
        $this->assertEquals('important', $activity->properties->get('tag_name'));
        $this->assertEquals('spark', $activity->properties->get('tag_type'));
    }

    #[Test]
    public function it_logs_activity_for_event_objects_too(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $eventObject = EventObject::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        $component = Volt::test('tag-manager', [
            'modelClass' => EventObject::class,
            'modelId' => (string) $eventObject->id,
        ]);

        $component->call('addTag', 'project:MyApp');

        $activity = Activity::query()
            ->where('subject_type', EventObject::class)
            ->where('subject_id', $eventObject->id)
            ->where('event', 'tag_added')
            ->latest()
            ->first();

        $this->assertNotNull($activity);
        $this->assertStringContainsString('added tag "project:MyApp"', $activity->description);
        $this->assertEquals('MyApp', $activity->properties->get('tag_name'));
        $this->assertEquals('project', $activity->properties->get('tag_type'));
    }

    #[Test]
    public function it_logs_activity_when_tags_attached_directly_on_model(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $event = $this->createEventFor($user);
        $this->actingAs($user);

        // Attach tag directly on the model (not through component)
        $tag = Tag::findOrCreate('urgent', 'priority');
        $event->attachTag($tag);

        $activity = Activity::query()
            ->where('subject_type', Event::class)
            ->where('subject_id', $event->id)
            ->where('event', 'tag_added')
            ->latest()
            ->first();

        $this->assertNotNull($activity);
        $this->assertStringContainsString('added tag "priority:urgent"', $activity->description);
        $this->assertEquals('urgent', $activity->properties->get('tag_name'));
        $this->assertEquals('priority', $activity->properties->get('tag_type'));
    }

    #[Test]
    public function it_logs_activity_when_tags_detached_directly_on_model(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $event = $this->createEventFor($user);
        $this->actingAs($user);

        // Attach and then detach tag directly on the model
        $tag = Tag::findOrCreate('urgent', 'priority');
        $event->attachTag($tag);

        // Clear previous activity logs for cleaner test
        Activity::query()->delete();

        $event->detachTag($tag);

        $activity = Activity::query()
            ->where('subject_type', Event::class)
            ->where('subject_id', $event->id)
            ->where('event', 'tag_removed')
            ->latest()
            ->first();

        $this->assertNotNull($activity);
        $this->assertStringContainsString('removed tag "priority:urgent"', $activity->description);
        $this->assertEquals('urgent', $activity->properties->get('tag_name'));
        $this->assertEquals('priority', $activity->properties->get('tag_type'));
    }

    #[Test]
    public function it_does_not_log_activity_when_attaching_already_attached_tag(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $event = $this->createEventFor($user);
        $this->actingAs($user);

        // Attach a tag for the first time
        $tag = Tag::findOrCreate('important', 'spark');
        $event->attachTag($tag);

        // Verify the first attachment was logged
        $initialActivityCount = Activity::query()
            ->where('subject_type', Event::class)
            ->where('subject_id', $event->id)
            ->where('event', 'tag_added')
            ->count();

        $this->assertEquals(1, $initialActivityCount);

        // Attach the same tag again
        $event->attachTag($tag);

        // Verify no additional activity was logged
        $finalActivityCount = Activity::query()
            ->where('subject_type', Event::class)
            ->where('subject_id', $event->id)
            ->where('event', 'tag_added')
            ->count();

        $this->assertEquals(1, $finalActivityCount, 'Re-attaching an existing tag should not create duplicate activity logs');
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
