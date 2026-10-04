<?php

namespace Tests\Feature\Explore;

use App\Livewire\Places\Show;
use App\Models\Place;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EX-03: deleting a place redirected to a route that doesn't exist.
 */
class ExploreRoutesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function deleting_a_place_lands_on_the_map(): void
    {
        config(['app.enable_task_pipeline' => false]);
        $user = User::factory()->create();
        $place = Place::factory()->create([
            'user_id' => $user->id, 'concept' => 'place', 'type' => 'discovered_place',
            'title' => 'Old cafe', 'location' => Point::makeGeodetic(51.5, -0.1),
        ]);

        $this->actingAs($user);

        Livewire::test(Show::class, ['place' => $place])
            ->call('deletePlace')
            ->assertRedirect(route('map.index'));

        $this->assertSoftDeleted($place);
    }

    #[Test]
    public function the_receipts_page_is_reachable_and_not_captured_by_the_account_route(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/money/receipts')
            ->assertOk();
    }
}
