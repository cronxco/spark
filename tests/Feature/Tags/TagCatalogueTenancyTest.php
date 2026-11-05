<?php

namespace Tests\Feature\Tags;

use App\Models\EventObject;
use App\Models\User;
use App\Support\OwnedTagQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PSEC-02b / EOB-01.
 *
 * Spatie tags are global rows with no user_id, so ownership has to be derived
 * from the tagged records. The web catalogue joined tags to taggables with no
 * user predicate: it listed every tag in the installation and aggregated usage
 * counts across all tenants. The mobile TagsController was already correct;
 * both now share OwnedTagQuery.
 */
class TagCatalogueTenancyTest extends TestCase
{
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
    public function the_catalogue_excludes_tags_only_another_user_has(): void
    {
        $this->taggedObject($this->bob, 'bobs-private-tag');
        $this->taggedObject($this->alice, 'alices-tag');

        $names = OwnedTagQuery::for($this->alice)->get()->map(fn ($tag) => (string) $tag->name);

        $this->assertContains('alices-tag', $names->all());
        $this->assertNotContains('bobs-private-tag', $names->all());
    }

    #[Test]
    public function usage_counts_are_not_aggregated_across_tenants(): void
    {
        // Both users use the same tag name, which resolves to one shared row.
        $this->taggedObject($this->alice, 'shared-tag');
        $this->taggedObject($this->bob, 'shared-tag');
        $this->taggedObject($this->bob, 'shared-tag');

        $tag = OwnedTagQuery::for($this->alice)->get()->firstWhere(
            fn ($tag) => (string) $tag->name === 'shared-tag',
        );

        $this->assertNotNull($tag);
        $this->assertSame(1, (int) $tag->objects_count, 'Counts must reflect the viewing user only.');
    }

    #[Test]
    public function searching_the_catalogue_stays_scoped(): void
    {
        $this->taggedObject($this->bob, 'zzz-bob-only');

        $results = OwnedTagQuery::for($this->alice, 'zzz')->get();

        $this->assertCount(0, $results);
    }

    #[Test]
    public function the_detail_page_rejects_a_tag_the_user_does_not_use(): void
    {
        $bobsObject = $this->taggedObject($this->bob, 'bobs-private-tag');
        $tag = $bobsObject->tags->first();

        $this->actingAs($this->alice)
            ->get("/tags/{$tag->type}/{$tag->slug}/{$tag->id}")
            ->assertNotFound();
    }

    private function taggedObject(User $user, string $tag): EventObject
    {
        $object = EventObject::factory()->create(['user_id' => $user->id]);
        $object->attachTag($tag);

        return $object;
    }
}
