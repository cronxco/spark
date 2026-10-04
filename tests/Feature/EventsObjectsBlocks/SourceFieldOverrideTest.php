<?php

namespace Tests\Feature\EventsObjectsBlocks;

use App\Livewire\EditBlock;
use App\Livewire\EditObject;
use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\UpdateEntityTool;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\SourceFieldGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EOB-D3: source fields of integration-sourced items are not overridable,
 * and a source refresh never overwrites a locked object's source fields.
 */
class SourceFieldOverrideTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ios.mobile_api_enabled' => true]);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function the_web_editor_rejects_a_source_field_change_on_an_integration_object(): void
    {
        [$object] = $this->sourcedGraph();
        $this->actingAs($this->user);

        Livewire::test(EditObject::class, ['object' => $object])
            ->assertSet('sourceFieldsEditable', false)
            ->set('title', 'My nickname')
            ->call('save')
            ->assertHasErrors(['title'])
            ->assertNotDispatched('object-updated');

        $this->assertSame('Current account', $object->fresh()->title);
    }

    #[Test]
    public function the_mobile_api_rejects_source_field_changes_with_a_clear_message(): void
    {
        [$object] = $this->sourcedGraph();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);
        $etag = $this->getJson("/api/v1/mobile/objects/{$object->id}")->headers->get('ETag');

        $this->patchJson("/api/v1/mobile/objects/{$object->id}", ['title' => 'Renamed', 'url' => 'https://example.com/mine'], ['If-Match' => $etag])
            ->assertStatus(422)
            ->assertJsonPath('errors.title.0', str_replace(':field', 'title', SourceFieldGuard::SOURCED_MESSAGE))
            ->assertJsonPath('errors.url.0', str_replace(':field', 'URL', SourceFieldGuard::SOURCED_MESSAGE));

        $fresh = $object->fresh();
        $this->assertSame('Current account', $fresh->title);
        $this->assertNull($fresh->url);
    }

    #[Test]
    public function resubmitting_the_current_source_values_is_not_an_override(): void
    {
        [$object] = $this->sourcedGraph();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);
        $etag = $this->getJson("/api/v1/mobile/objects/{$object->id}")->headers->get('ETag');

        $this->patchJson("/api/v1/mobile/objects/{$object->id}", ['title' => 'Current account', 'url' => null], ['If-Match' => $etag])
            ->assertOk();
    }

    #[Test]
    public function mcp_rejects_a_source_field_change(): void
    {
        [$object] = $this->sourcedGraph();

        SparkServer::actingAs($this->user)
            ->tool(UpdateEntityTool::class, ['kind' => 'object', 'id' => $object->id, 'attributes' => ['concept' => 'person']])
            ->assertHasErrors([str_replace(':field', 'concept', SourceFieldGuard::SOURCED_MESSAGE)]);

        $this->assertSame('account', $object->fresh()->concept);
    }

    #[Test]
    public function integration_block_source_fields_are_rejected_but_values_still_save(): void
    {
        [, , $block] = $this->sourcedGraph();
        $this->actingAs($this->user);

        Livewire::test(EditBlock::class, ['block' => $block])
            ->assertSet('sourceFieldsEditable', false)
            ->set('title', 'Renamed block')
            ->call('save')
            ->assertHasErrors(['title']);

        Livewire::test(EditBlock::class, ['block' => $block->fresh()])
            ->set('value', 42)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('block-updated');

        $fresh = $block->fresh();
        $this->assertSame('Daily summary', $fresh->title);
        $this->assertEquals(42, $fresh->value);
    }

    #[Test]
    public function the_event_note_stays_editable_on_an_integration_event(): void
    {
        [, $event] = $this->sourcedGraph();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);
        $etag = $this->getJson("/api/v1/mobile/events/{$event->id}")->headers->get('ETag');

        $this->patchJson("/api/v1/mobile/events/{$event->id}/note", ['note' => 'Paid back by Sam'], ['If-Match' => $etag])
            ->assertOk()
            ->assertJsonPath('note', 'Paid back by Sam');
    }

    #[Test]
    public function an_object_the_user_authored_can_still_be_renamed(): void
    {
        $object = EventObject::factory()->create(['user_id' => $this->user->id, 'title' => 'Savings pot']);
        $integration = $this->integration('manual_account');
        Event::factory()->create(['integration_id' => $integration->id, 'service' => 'manual_account', 'target_id' => $object->id]);
        $this->actingAs($this->user);

        Livewire::test(EditObject::class, ['object' => $object])
            ->assertSet('sourceFieldsEditable', true)
            ->set('title', 'Holiday pot')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Holiday pot', $object->fresh()->title);
    }

    #[Test]
    public function a_refresh_never_overwrites_a_locked_objects_source_fields(): void
    {
        [$object] = $this->sourcedGraph();
        $object->lock();

        EventObject::query()->find($object->id)->update([
            'title' => 'Refreshed title',
            'concept' => 'refreshed',
            'type' => 'refreshed_type',
            'content' => 'Refreshed content',
            'url' => 'https://example.com/refreshed',
            'time' => now()->addDay(),
        ]);

        $fresh = $object->fresh();
        $this->assertSame('Current account', $fresh->title);
        $this->assertSame('account', $fresh->concept);
        $this->assertSame('monzo_account', $fresh->type);
        $this->assertSame('Original content', $fresh->content);
        $this->assertNull($fresh->url);
        $this->assertTrue($fresh->time->isTomorrow());
    }

    #[Test]
    public function a_refresh_updates_an_unlocked_objects_source_fields(): void
    {
        [$object] = $this->sourcedGraph();

        $object->update(['content' => 'Refreshed content', 'url' => 'https://example.com/refreshed']);

        $fresh = $object->fresh();
        $this->assertSame('Refreshed content', $fresh->content);
        $this->assertSame('https://example.com/refreshed', $fresh->url);
    }

    /** @return array{EventObject, Event, Block} */
    private function sourcedGraph(): array
    {
        $integration = $this->integration('monzo');
        $object = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'concept' => 'account',
            'type' => 'monzo_account',
            'title' => 'Current account',
            'content' => 'Original content',
            'url' => null,
        ]);
        $event = Event::factory()->create(['integration_id' => $integration->id, 'service' => 'monzo', 'actor_id' => $object->id]);
        $block = Block::factory()->create(['event_id' => $event->id, 'title' => 'Daily summary', 'block_type' => 'summary']);

        return [$object->fresh(), $event, $block];
    }

    private function integration(string $service): Integration
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => $service]);

        return Integration::factory()->create(['user_id' => $this->user->id, 'integration_group_id' => $group->id, 'service' => $service]);
    }
}
