<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Media\Index;
use App\Livewire\Media\Show;
use App\Models\EventObject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class MediaScopingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['media-library.disk_name' => 'public']);
        config(['app.enable_task_pipeline' => false]);

        $this->user = User::factory()->create();
    }

    #[Test]
    public function index_only_lists_media_owned_by_the_user(): void
    {
        $ownMedia = $this->mediaForUser($this->user);
        $otherMedia = $this->mediaForUser(User::factory()->create());

        $this->actingAs($this->user);

        $items = Livewire::test(Index::class)->instance()->media()->items();
        $ids = collect($items)->pluck('id');

        $this->assertTrue($ids->contains($ownMedia->id));
        $this->assertFalse($ids->contains($otherMedia->id));
    }

    #[Test]
    public function bulk_delete_cannot_remove_another_users_media(): void
    {
        $otherMedia = $this->mediaForUser(User::factory()->create());

        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->set('selectedItems', [$otherMedia->id])
            ->call('bulkDelete');

        $this->assertDatabaseHas('media', ['id' => $otherMedia->id]);
    }

    #[Test]
    public function bulk_delete_removes_own_media(): void
    {
        $ownMedia = $this->mediaForUser($this->user);

        $this->actingAs($this->user);

        Livewire::test(Index::class)
            ->set('selectedItems', [$ownMedia->id])
            ->call('bulkDelete');

        $this->assertDatabaseMissing('media', ['id' => $ownMedia->id]);
    }

    #[Test]
    public function show_is_forbidden_for_another_users_media(): void
    {
        $otherMedia = $this->mediaForUser(User::factory()->create());

        $this->actingAs($this->user)
            ->get(route('media.show', $otherMedia->uuid))
            ->assertForbidden();
    }

    #[Test]
    public function owner_can_open_their_media(): void
    {
        $ownMedia = $this->mediaForUser($this->user);

        $this->actingAs($this->user);

        Livewire::test(Show::class, ['media' => $ownMedia])->assertOk();
    }

    private function mediaForUser(User $user): Media
    {
        $object = EventObject::factory()->create(['user_id' => $user->id]);

        // Insert the Media row directly. The scoping logic only reads
        // model_type/model_id/custom_properties, so we skip Spatie's file
        // pipeline (which is unstable in this container) entirely.
        $media = new Media;
        $media->model_type = EventObject::class;
        $media->model_id = $object->id;
        $media->uuid = (string) Str::uuid();
        $media->collection_name = 'downloaded_documents';
        $media->name = 'fixture';
        $media->file_name = 'fixture.txt';
        $media->mime_type = 'text/plain';
        $media->disk = 'public';
        $media->size = 16;
        $media->manipulations = [];
        $media->custom_properties = ['md5_hash' => Str::random(32)];
        $media->generated_conversions = [];
        $media->responsive_images = [];
        $media->save();

        return $media;
    }
}
