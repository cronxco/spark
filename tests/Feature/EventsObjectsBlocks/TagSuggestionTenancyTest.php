<?php

namespace Tests\Feature\EventsObjectsBlocks;

use App\Livewire\CreateTag;
use App\Livewire\ManageEventTags;
use App\Models\EventObject;
use App\Models\User;
use App\Support\OwnedTagQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EOB-01: tag autocomplete and the tag-type list read every tenant's tags.
 */
class TagSuggestionTenancyTest extends TestCase
{
    use EntityFixtures;
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
    }

    #[Test]
    public function suggestions_only_include_the_users_own_tags(): void
    {
        EventObject::factory()->create(['user_id' => $this->bob->id])->attachTag('bobs-private-tag', 'health');
        EventObject::factory()->create(['user_id' => $this->alice->id])->attachTag('alices-tag', 'person');

        $values = array_column(OwnedTagQuery::suggestionsFor($this->alice), 'value');

        $this->assertSame(['alices-tag'], $values);
        $this->assertSame([], OwnedTagQuery::suggestionsFor(null));
    }

    #[Test]
    public function tag_types_only_include_types_the_user_uses(): void
    {
        EventObject::factory()->create(['user_id' => $this->bob->id])->attachTag('diagnosis', 'bobs_private_type');
        EventObject::factory()->create(['user_id' => $this->alice->id])->attachTag('mum', 'person');

        $this->assertSame(['person'], OwnedTagQuery::typesFor($this->alice));

        $this->actingAs($this->alice);
        $types = Livewire::test(CreateTag::class)->instance()->getExistingTypes();

        $this->assertArrayHasKey('person', $types);
        $this->assertArrayNotHasKey('bobs_private_type', $types);
    }

    #[Test]
    public function the_event_tag_editor_does_not_offer_another_users_tags(): void
    {
        EventObject::factory()->create(['user_id' => $this->bob->id])->attachTag('bobs-private-tag');
        [, $event] = $this->ownedGraph($this->alice);
        $event->attachTag('alices-tag');

        $this->actingAs($this->alice);

        Livewire::test(ManageEventTags::class, ['event' => $event])
            ->assertSee('alices-tag')
            ->assertDontSee('bobs-private-tag');
    }
}
