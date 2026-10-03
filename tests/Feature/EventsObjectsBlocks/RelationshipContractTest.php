<?php

namespace Tests\Feature\EventsObjectsBlocks;

use App\Models\EventObject;
use App\Models\User;
use App\Services\RelationshipTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EOB-03: native relationship create/delete could not keep its versions straight.
 */
class RelationshipContractTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ios.mobile_api_enabled' => true]);
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);
    }

    #[Test]
    public function relationship_types_come_from_the_registry(): void
    {
        $types = $this->getJson('/api/v1/mobile/relationship-types')
            ->assertOk()
            ->json('data');

        $this->assertEqualsCanonicalizing(RelationshipTypeRegistry::getTypeKeys(), array_column($types, 'type'));
        $this->assertArrayHasKey('supports_value', $types[0]);
    }

    #[Test]
    public function creating_returns_the_new_source_version_so_the_next_write_succeeds(): void
    {
        [$source, $target] = $this->objects();
        $etag = $this->etagFor($source);
        $this->travel(5)->seconds();

        $created = $this->postJson("/api/v1/mobile/objects/{$source->id}/relationships", [
            'to_kind' => 'object', 'to_id' => $target->id, 'type' => 'related_to',
        ], ['If-Match' => $etag])->assertCreated()->assertHeader('ETag');

        $this->assertNotEmpty($created->json('etag'));
        $sourceVersion = collect($created->json('versions'))->firstWhere('id', $source->id);
        $this->assertSame('object', $sourceVersion['kind']);
        $this->assertNotSame($etag, $sourceVersion['etag']);

        $this->patchJson("/api/v1/mobile/objects/{$source->id}", ['url' => 'https://example.com'], ['If-Match' => $etag])
            ->assertStatus(412);

        $this->patchJson("/api/v1/mobile/objects/{$source->id}", ['url' => 'https://example.com'], ['If-Match' => $sourceVersion['etag']])
            ->assertOk();
    }

    #[Test]
    public function the_list_carries_each_edges_version_and_delete_uses_it(): void
    {
        [$source, $target] = $this->objects();

        $this->postJson("/api/v1/mobile/objects/{$source->id}/relationships", [
            'to_kind' => 'object', 'to_id' => $target->id, 'type' => 'related_to',
        ], ['If-Match' => $this->etagFor($source)])->assertCreated();

        $edge = $this->getJson("/api/v1/mobile/objects/{$source->id}/relationships")->assertOk()->json('data.0');
        $this->assertNotEmpty($edge['etag']);

        $this->deleteJson("/api/v1/mobile/relationships/{$edge['id']}", [], ['If-Match' => $this->etagFor($source)])
            ->assertStatus(412);

        $this->deleteJson("/api/v1/mobile/relationships/{$edge['id']}", [], ['If-Match' => $edge['etag']])
            ->assertNoContent();
    }

    #[Test]
    public function the_plural_kind_the_old_app_sent_is_still_rejected(): void
    {
        [$source, $target] = $this->objects();

        $this->postJson("/api/v1/mobile/objects/{$source->id}/relationships", [
            'to_kind' => 'objects', 'to_id' => $target->id, 'type' => 'related_to',
        ], ['If-Match' => $this->etagFor($source)])->assertStatus(422);
    }

    /** @return array{EventObject, EventObject} */
    private function objects(): array
    {
        return [
            EventObject::factory()->create(['user_id' => $this->user->id]),
            EventObject::factory()->create(['user_id' => $this->user->id]),
        ];
    }

    private function etagFor(EventObject $object): string
    {
        return $this->getJson("/api/v1/mobile/objects/{$object->id}")->assertOk()->headers->get('ETag');
    }
}
