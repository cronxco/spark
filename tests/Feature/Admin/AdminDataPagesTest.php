<?php

namespace Tests\Feature\Admin;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\Relationship;
use App\Models\User;
use App\Support\AdminTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatesLogs;
use Tests\TestCase;

/**
 * Admin audit PR 2: the Data page (events, objects, blocks, relationships).
 */
class AdminDataPagesTest extends TestCase
{
    use IsolatesLogs;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $this->actingAs($this->admin);
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function dataTabs(): array
    {
        return [['events'], ['objects'], ['blocks'], ['relationships']];
    }

    #[Test]
    #[DataProvider('dataTabs')]
    public function each_tab_renders_with_the_shared_tab_bar(string $tab): void
    {
        $this->get(route("admin.{$tab}.index"))
            ->assertOk()
            ->assertSee(route('admin.events.index'), false)
            ->assertSee(route('admin.relationships.index'), false)
            ->assertSee('tab-active', false);
    }

    #[Test]
    #[DataProvider('dataTabs')]
    public function unknown_sort_columns_and_huge_page_sizes_are_ignored(string $tab): void
    {
        Volt::test("admin.{$tab}")
            ->set('sortBy', ['column' => 'id; drop table users', 'direction' => 'sideways'])
            ->set('perPage', 1000000)
            ->assertSet('perPage', 25)
            ->assertOk();
    }

    #[Test]
    public function blocks_can_be_sorted_by_service(): void
    {
        $event = Event::factory()->create([
            'integration_id' => Integration::factory()->create(['user_id' => $this->admin->id])->id,
            'service' => 'monzo',
        ]);
        Block::factory()->create(['event_id' => $event->id]);

        Volt::test('admin.blocks')
            ->set('sortBy', ['column' => 'service', 'direction' => 'asc'])
            ->assertOk();
    }

    #[Test]
    public function blocks_view_switches_between_table_and_gallery_and_rejects_other_values(): void
    {
        Volt::test('admin.blocks')
            ->assertSet('view', 'table')
            ->set('view', 'gallery')
            ->assertSet('view', 'gallery')
            ->set('view', 'nonsense')
            ->assertSet('view', 'table');
    }

    #[Test]
    public function relationship_search_matches_metadata_json(): void
    {
        $a = EventObject::factory()->create(['user_id' => $this->admin->id]);
        $b = EventObject::factory()->create(['user_id' => $this->admin->id]);
        Relationship::createRelationship([
            'user_id' => $this->admin->id,
            'from_type' => EventObject::class,
            'from_id' => $a->id,
            'to_type' => EventObject::class,
            'to_id' => $b->id,
            'type' => 'linked_to',
            'metadata' => ['note' => 'findme-token'],
        ]);

        $component = Volt::test('admin.relationships')->set('search', 'findme-token');

        $this->assertSame(1, $component->instance()->getRelationships()->total());
    }

    #[Test]
    public function ref_components_escape_titles(): void
    {
        $object = EventObject::factory()->create([
            'user_id' => $this->admin->id,
            'title' => '<script>alert(1)</script>',
        ]);

        $html = view('components.object-ref', ['object' => $object])->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    #[Test]
    public function god_mode_shows_every_users_blocks_in_the_gallery(): void
    {
        config(['spark.admin.global_operators' => [(string) $this->admin->id]]);
        $other = User::factory()->create();
        $mine = Block::factory()->create([
            'event_id' => Event::factory()->create(['integration_id' => Integration::factory()->create(['user_id' => $this->admin->id])->id])->id,
            'block_type' => 'mine_type',
        ]);
        $theirs = Block::factory()->create([
            'event_id' => Event::factory()->create(['integration_id' => Integration::factory()->create(['user_id' => $other->id])->id])->id,
            'block_type' => 'theirs_type',
        ]);

        $this->withSession([AdminTenant::SESSION_KEY => [
            'started_at' => now()->toJSON(),
            'expires_at' => now()->addMinutes(30)->toJSON(),
        ]]);

        $ids = Volt::test('admin.blocks')->instance()->getGalleryBlocks()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], $ids);
    }
}
