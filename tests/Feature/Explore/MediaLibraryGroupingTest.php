<?php

namespace Tests\Feature\Explore;

use App\Livewire\Media\Index;
use App\Models\EventObject;
use App\Models\User;
use App\Support\OwnedMediaQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * EX-05: every unhashed file collapsed into one "duplicate" row, and the whole
 * library was loaded into memory to build each page.
 */
class MediaLibraryGroupingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['media-library.disk_name' => 'public', 'app.enable_task_pipeline' => false]);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    #[Test]
    public function files_without_a_hash_are_each_listed(): void
    {
        $first = $this->makeMedia(null, 'first');
        $second = $this->makeMedia(null, 'second');

        $ids = collect($this->library()->media()->items())->pluck('id');

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $ids->all());
    }

    #[Test]
    public function identical_files_still_collapse_to_the_newest_with_a_count(): void
    {
        $this->makeMedia('samehash', 'older', now()->subDay());
        $newer = $this->makeMedia('samehash', 'newer');

        $items = $this->library()->media()->items();

        $this->assertCount(1, $items);
        $this->assertSame($newer->id, $items[0]->id);
        $this->assertSame(2, (int) $items[0]->instances_count);
    }

    #[Test]
    public function pages_are_built_in_the_database_with_a_stable_order(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeMedia("hash-{$i}", "file-{$i}", now()->subMinutes($i));
        }

        $library = $this->library();
        $library->perPage = 2;
        $page = $library->media();

        $this->assertSame(5, $page->total());
        $this->assertSame(['file-1', 'file-2'], collect($page->items())->pluck('name')->all());
    }

    #[Test]
    public function an_unknown_sort_column_falls_back_to_date(): void
    {
        $this->makeMedia('a', 'only');

        $library = $this->library();
        $library->sortBy = ['column' => 'id; drop table media', 'direction' => 'sideways'];
        $items = $library->media()->items();

        $this->assertCount(1, $items);
    }

    #[Test]
    public function another_users_copy_of_the_same_file_is_not_counted_or_listed(): void
    {
        $mine = $this->makeMedia('sharedhash', 'mine');
        $other = User::factory()->create();
        $theirs = $this->makeMedia('sharedhash', 'theirs', null, $other);

        $items = $this->library()->media()->items();

        $this->assertCount(1, $items);
        $this->assertSame($mine->id, $items[0]->id);
        $this->assertSame(1, (int) $items[0]->instances_count);

        $instances = OwnedMediaQuery::scope(Media::query()->where('custom_properties->md5_hash', 'sharedhash'), $this->user->id)->pluck('id');
        $this->assertSame([$mine->id], $instances->all());
        $this->assertNotContains($theirs->id, $instances->all());
        $this->assertSame(0, OwnedMediaQuery::scope(Media::query(), null)->count());
    }

    /**
     * The component's query method, called directly so the test exercises the
     * listing query rather than the card markup.
     */
    private function library(): Index
    {
        return new Index;
    }

    private function makeMedia(?string $hash, string $name, $createdAt = null, ?User $owner = null): Media
    {
        $object = EventObject::factory()->create(['user_id' => ($owner ?? $this->user)->id]);

        $media = new Media;
        $media->model_type = EventObject::class;
        $media->model_id = $object->id;
        $media->uuid = (string) Str::uuid();
        $media->collection_name = 'downloaded_documents';
        $media->name = $name;
        $media->file_name = $name . '.txt';
        $media->mime_type = 'text/plain';
        $media->disk = 'public';
        $media->size = 16;
        $media->manipulations = [];
        $media->custom_properties = $hash === null ? ['source_url' => 'https://example.com/' . $name] : ['md5_hash' => $hash];
        $media->generated_conversions = [];
        $media->responsive_images = [];
        $media->save();

        if ($createdAt) {
            $media->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $media;
    }
}
