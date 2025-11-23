<?php

namespace Tests\Feature\Livewire;

use App\Livewire\EditEvent;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EditEventTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'test',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'test',
        ]);

        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        $this->event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
            'action' => 'test_action',
            'value' => 10000,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => now(),
        ]);
    }

    #[Test]
    public function component_renders_successfully(): void
    {
        Livewire::test(EditEvent::class, ['event' => $this->event])
            ->assertStatus(200);
    }

    #[Test]
    public function component_mounts_with_event_data(): void
    {
        $component = Livewire::test(EditEvent::class, ['event' => $this->event]);

        $component->assertSet('action', 'test_action')
            ->assertSet('value', 10000)
            ->assertSet('value_multiplier', 100)
            ->assertSet('value_unit', 'GBP');
    }

    #[Test]
    public function unauthorized_user_cannot_access_event(): void
    {
        $otherUser = User::factory()->create();
        $otherGroup = IntegrationGroup::factory()->create([
            'user_id' => $otherUser->id,
            'service' => 'test',
        ]);
        $otherIntegration = Integration::factory()->create([
            'user_id' => $otherUser->id,
            'integration_group_id' => $otherGroup->id,
            'service' => 'test',
        ]);

        $otherEvent = Event::factory()->create([
            'integration_id' => $otherIntegration->id,
            'action' => 'other_action',
        ]);

        // The component aborts with 403 for unauthorized access
        Livewire::test(EditEvent::class, ['event' => $otherEvent])
            ->assertForbidden();
    }

    #[Test]
    public function action_can_be_updated(): void
    {
        $component = Livewire::test(EditEvent::class, ['event' => $this->event]);

        $component->set('action', 'updated_action')
            ->call('save');

        $this->event->refresh();
        $this->assertEquals('updated_action', $this->event->action);
    }

    #[Test]
    public function value_can_be_updated(): void
    {
        $component = Livewire::test(EditEvent::class, ['event' => $this->event]);

        $component->set('value', 25050)
            ->call('save');

        $this->event->refresh();
        $this->assertEquals(25050, $this->event->value);
    }

    #[Test]
    public function value_unit_can_be_updated(): void
    {
        $component = Livewire::test(EditEvent::class, ['event' => $this->event]);

        $component->set('value_unit', 'USD')
            ->call('save');

        $this->event->refresh();
        $this->assertEquals('USD', $this->event->value_unit);
    }

    #[Test]
    public function value_multiplier_can_be_updated(): void
    {
        $component = Livewire::test(EditEvent::class, ['event' => $this->event]);

        $component->set('value_multiplier', 100)
            ->call('save');

        $this->event->refresh();
        $this->assertEquals(100, $this->event->value_multiplier);
    }

    #[Test]
    public function time_can_be_updated(): void
    {
        $newTime = '2024-06-15T14:30';

        $component = Livewire::test(EditEvent::class, ['event' => $this->event]);

        $component->set('time', $newTime)
            ->call('save');

        $this->event->refresh();
        $this->assertEquals('2024-06-15 14:30:00', $this->event->time->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function save_validates_action_is_required(): void
    {
        $component = Livewire::test(EditEvent::class, ['event' => $this->event]);

        $component->set('action', '')
            ->call('save')
            ->assertHasErrors(['action' => 'required']);
    }

    #[Test]
    public function save_validates_action_max_length(): void
    {
        $component = Livewire::test(EditEvent::class, ['event' => $this->event]);

        $component->set('action', str_repeat('a', 256))
            ->call('save')
            ->assertHasErrors(['action' => 'max']);
    }

    // Note: Testing non-numeric value validation isn't possible with typed
    // properties (PHP 8) as Livewire throws TypeError before validation runs

    #[Test]
    public function save_validates_value_unit_max_length(): void
    {
        $component = Livewire::test(EditEvent::class, ['event' => $this->event]);

        $component->set('value_unit', str_repeat('a', 51))
            ->call('save')
            ->assertHasErrors(['value_unit' => 'max']);
    }

    #[Test]
    public function save_dispatches_event_updated_event(): void
    {
        $component = Livewire::test(EditEvent::class, ['event' => $this->event]);

        $component->set('action', 'new_action')
            ->call('save')
            ->assertDispatched('event-updated');
    }

    #[Test]
    public function save_dispatches_close_modal_event(): void
    {
        $component = Livewire::test(EditEvent::class, ['event' => $this->event]);

        $component->set('action', 'new_action')
            ->call('save')
            ->assertDispatched('close-modal');
    }

    #[Test]
    public function nullable_value_can_be_set_to_null(): void
    {
        $component = Livewire::test(EditEvent::class, ['event' => $this->event]);

        $component->set('value', null)
            ->call('save');

        $this->event->refresh();
        $this->assertNull($this->event->value);
    }

    #[Test]
    public function nullable_value_unit_can_be_set_to_null(): void
    {
        $component = Livewire::test(EditEvent::class, ['event' => $this->event]);

        $component->set('value_unit', null)
            ->call('save');

        $this->event->refresh();
        $this->assertNull($this->event->value_unit);
    }
}
