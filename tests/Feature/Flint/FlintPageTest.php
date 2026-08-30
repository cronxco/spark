<?php

namespace Tests\Feature\Flint;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Flint web page reads the digests the external Flint routine writes
 * (`flint`/`had_summary` events plus their blocks) and the Topics those
 * digests are linked to.
 */
class FlintPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);

        $this->actingAs($this->user);
    }

    private function createDigest(array $metadata = [], ?Carbon $time = null): Event
    {
        return Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => $time ?? Carbon::parse(now()->toDateString(), 'UTC'),
            'event_metadata' => array_merge([
                'period' => 'evening',
                'title' => 'Tuesday, in brief',
                'summary' => 'You ran 5k and slept badly.',
            ], $metadata),
        ]);
    }

    private function createTopic(array $metadata = [], string $title = 'Canada trip 2027'): EventObject
    {
        return EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'flint',
            'type' => 'topic',
            'title' => $title,
            'content' => 'Aiming for a three week trip in spring 2027.',
            'metadata' => array_merge([
                'kind' => 'strategic',
                'status' => 'active',
                'first_seen_at' => now()->toIso8601String(),
                'last_touched_at' => now()->toIso8601String(),
                'next_review_at' => null,
                'origin' => 'conversation',
            ], $metadata),
        ]);
    }

    #[Test]
    public function it_shows_the_latest_digest_summary(): void
    {
        $this->createDigest();

        Volt::test('flint.index')
            ->assertSee('Tuesday, in brief')
            ->assertSee('You ran 5k and slept badly.');
    }

    #[Test]
    public function it_shows_an_empty_state_when_there_are_no_digests(): void
    {
        Volt::test('flint.index')->assertSee('No digests yet');
    }

    #[Test]
    public function it_does_not_show_another_users_digest(): void
    {
        $other = User::factory()->create();
        $otherIntegration = Integration::factory()->create([
            'user_id' => $other->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);

        Event::factory()->create([
            'integration_id' => $otherIntegration->id,
            'service' => 'flint',
            'action' => 'had_summary',
            'time' => now(),
            'event_metadata' => ['period' => 'evening', 'title' => 'Someone elses digest'],
        ]);

        Volt::test('flint.index')
            ->assertDontSee('Someone elses digest')
            ->assertSee('No digests yet');
    }

    #[Test]
    public function it_renders_the_digests_question_and_editorial_blocks(): void
    {
        $digest = $this->createDigest();

        $digest->createBlock([
            'block_type' => 'flint_user_question',
            'title' => 'About that late night',
            'time' => $digest->time,
            'metadata' => ['question' => 'Why were you up so late?', 'priority' => 'medium', 'answer' => null],
        ]);
        $digest->createBlock([
            'block_type' => 'flint_editorial_note',
            'title' => 'Sleep debt is building',
            'time' => $digest->time,
            'metadata' => ['content' => 'Third short night this week.'],
        ]);

        Volt::test('flint.index')
            ->assertSee('Why were you up so late?')
            ->assertSee('Sleep debt is building');
    }

    #[Test]
    public function it_can_switch_to_an_older_digest(): void
    {
        $this->createDigest(['title' => 'Today'], Carbon::parse(now()->toDateString(), 'UTC'));
        $older = $this->createDigest(['title' => 'Yesterday'], Carbon::parse(now()->subDay()->toDateString(), 'UTC'));

        Volt::test('flint.index')
            ->call('selectDigest', $older->id)
            ->assertSee('Yesterday');
    }

    #[Test]
    public function it_lists_topics_touched_by_the_current_digest(): void
    {
        $digest = $this->createDigest();
        $topic = $this->createTopic();

        Relationship::createRelationship([
            'user_id' => $this->user->id,
            'from_type' => EventObject::class,
            'from_id' => $topic->id,
            'to_type' => Event::class,
            'to_id' => $digest->id,
            'type' => 'discussed_in',
        ]);

        Volt::test('flint.index')->assertSee('Canada trip 2027');
    }

    #[Test]
    public function the_topics_tab_lists_active_topics_and_filters_by_status(): void
    {
        $this->createTopic([], 'Canada trip 2027');
        $this->createTopic(['status' => 'resolved', 'kind' => 'tactical'], 'Boiler replacement');

        Volt::test('flint.index')
            ->assertSee('Canada trip 2027')
            ->assertDontSee('Boiler replacement')
            ->set('topicStatusFilter', 'resolved')
            ->assertSee('Boiler replacement')
            ->assertDontSee('Canada trip 2027');
    }

    #[Test]
    public function the_topics_tab_filters_by_kind(): void
    {
        $this->createTopic(['kind' => 'strategic'], 'Canada trip 2027');
        $this->createTopic(['kind' => 'thematic'], 'Getting back to running');

        Volt::test('flint.index')
            ->set('topicKindFilter', 'thematic')
            ->assertSee('Getting back to running')
            ->assertDontSee('Canada trip 2027');
    }

    #[Test]
    public function a_topic_can_be_edited_from_the_page(): void
    {
        $topic = $this->createTopic();

        Volt::test('flint.index')
            ->call('editTopic', $topic->id)
            ->assertSet('editTitle', 'Canada trip 2027')
            ->set('editTitle', 'Canada trip 2028')
            ->set('editContent', 'Pushed back a year.')
            ->set('editStatus', 'dormant')
            ->set('editNextReviewAt', '2027-03-01')
            ->call('saveTopic')
            ->assertSet('editingTopicId', null);

        $topic->refresh();

        $this->assertSame('Canada trip 2028', $topic->title);
        $this->assertSame('Pushed back a year.', $topic->content);
        $this->assertSame('dormant', $topic->metadata['status']);
        $this->assertSame('2027-03-01', $topic->metadata['next_review_at']);
    }

    #[Test]
    public function a_topic_status_can_be_changed_from_the_card(): void
    {
        $topic = $this->createTopic();

        Volt::test('flint.index')->call('setTopicStatus', $topic->id, 'resolved');

        $this->assertSame('resolved', $topic->refresh()->metadata['status']);
    }

    #[Test]
    public function a_topic_can_be_deleted(): void
    {
        $topic = $this->createTopic();

        Volt::test('flint.index')->call('deleteTopic', $topic->id);

        $this->assertSoftDeleted('objects', ['id' => $topic->id]);
    }

    #[Test]
    public function it_will_not_edit_another_users_topic(): void
    {
        $other = User::factory()->create();
        $topic = EventObject::factory()->create([
            'user_id' => $other->id,
            'concept' => 'flint',
            'type' => 'topic',
            'title' => 'Not yours',
            'metadata' => ['kind' => 'thematic', 'status' => 'active'],
        ]);

        Volt::test('flint.index')
            ->call('setTopicStatus', $topic->id, 'resolved')
            ->call('deleteTopic', $topic->id);

        $this->assertSame('active', $topic->refresh()->metadata['status']);
        $this->assertNotSoftDeleted('objects', ['id' => $topic->id]);
    }

    #[Test]
    public function saving_settings_writes_the_keys_the_dispatcher_reads(): void
    {
        Volt::test('flint.index')
            ->set('digestsEnabled', true)
            ->set('morningTimeWeekday', '06:45')
            ->set('morningTimeWeekend', '09:00')
            ->set('morningFallback', '11:30')
            ->set('eveningTime', '20:15')
            ->set('topicsTime', '21:30')
            ->call('save');

        $settings = $this->user->refresh()->settings['flint'];

        $this->assertTrue($settings['digests_enabled']);
        $this->assertSame('06:45', $settings['morning_time_weekday']);
        $this->assertSame('09:00', $settings['morning_time_weekend']);
        $this->assertSame('11:30', $settings['morning_fallback']);
        $this->assertSame('20:15', $settings['evening_time']);
        $this->assertSame('21:30', $settings['topics_time']);
    }

    #[Test]
    public function saving_settings_preserves_unrelated_flint_keys(): void
    {
        $this->user->settings = array_merge($this->user->settings ?? [], [
            'flint' => ['digests_enabled' => true, 'some_other_key' => 'keep me'],
        ]);
        $this->user->save();

        Volt::test('flint.index')->call('save');

        $this->assertSame('keep me', $this->user->refresh()->settings['flint']['some_other_key']);
    }
}
