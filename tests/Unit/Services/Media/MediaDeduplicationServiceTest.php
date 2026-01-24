<?php

namespace Tests\Unit\Services\Media;

use App\Models\EventObject;
use App\Models\User;
use App\Services\Media\MediaDeduplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class MediaDeduplicationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected MediaDeduplicationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MediaDeduplicationService;
    }

    /**
     * @test
     */
    public function calculates_file_hash(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'test content');

        $hash = $this->service->calculateFileHash($tempFile);

        $this->assertEquals(md5('test content'), $hash);

        unlink($tempFile);
    }

    /**
     * @test
     */
    public function calculates_content_hash(): void
    {
        $hash = $this->service->calculateContentHash('test content');

        $this->assertEquals(md5('test content'), $hash);
    }

    /**
     * @test
     */
    public function throws_exception_for_nonexistent_file(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->calculateFileHash('/nonexistent/file.jpg');
    }

    /**
     * @test
     */
    public function finds_existing_media_by_hash(): void
    {
        $user = User::factory()->create();
        $eventObject = EventObject::factory()->create(['user_id' => $user->id]);

        // Create media with a specific MD5 hash
        $media = $eventObject->addMedia($this->createTestImage())
            ->withCustomProperties(['md5_hash' => 'test_hash_12345'])
            ->toMediaCollection('downloaded_images');

        // Find it by hash
        $found = $this->service->findExistingMediaByHash('test_hash_12345');

        $this->assertNotNull($found);
        $this->assertEquals($media->id, $found->id);
        $this->assertEquals('test_hash_12345', $found->getCustomProperty('md5_hash'));
    }

    /**
     * @test
     */
    public function returns_null_when_no_media_found_by_hash(): void
    {
        $found = $this->service->findExistingMediaByHash('nonexistent_hash');

        $this->assertNull($found);
    }

    /**
     * @test
     */
    public function gets_correct_reference_count(): void
    {
        $user = User::factory()->create();
        $obj1 = EventObject::factory()->create(['user_id' => $user->id]);
        $obj2 = EventObject::factory()->create(['user_id' => $user->id]);

        $hash = 'shared_hash_12345';

        // Add same hash to two different objects
        $obj1->addMedia($this->createTestImage())
            ->withCustomProperties(['md5_hash' => $hash])
            ->toMediaCollection('downloaded_images');

        $obj2->addMedia($this->createTestImage())
            ->withCustomProperties(['md5_hash' => $hash])
            ->toMediaCollection('downloaded_images');

        // Get any media with this hash
        $media = Media::where('custom_properties->md5_hash', $hash)->first();

        $count = $this->service->getMediaReferenceCount($media);

        $this->assertEquals(2, $count);
    }

    /**
     * @test
     */
    public function can_delete_media_returns_false_when_references_exist(): void
    {
        $user = User::factory()->create();
        $obj1 = EventObject::factory()->create(['user_id' => $user->id]);
        $obj2 = EventObject::factory()->create(['user_id' => $user->id]);

        $hash = 'shared_hash_12345';

        $obj1->addMedia($this->createTestImage())
            ->withCustomProperties(['md5_hash' => $hash])
            ->toMediaCollection('downloaded_images');

        $obj2->addMedia($this->createTestImage())
            ->withCustomProperties(['md5_hash' => $hash])
            ->toMediaCollection('downloaded_images');

        $media = Media::where('custom_properties->md5_hash', $hash)->first();

        $canDelete = $this->service->canDeleteMedia($media);

        $this->assertFalse($canDelete);
    }

    /**
     * @test
     */
    public function can_delete_media_returns_true_for_last_reference(): void
    {
        $user = User::factory()->create();
        $obj = EventObject::factory()->create(['user_id' => $user->id]);

        $media = $obj->addMedia($this->createTestImage())
            ->withCustomProperties(['md5_hash' => 'unique_hash_12345'])
            ->toMediaCollection('downloaded_images');

        $canDelete = $this->service->canDeleteMedia($media);

        $this->assertTrue($canDelete);
    }

    /**
     * Create a simple test image file.
     */
    protected function createTestImage(): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_img');
        $extension = '.jpg';
        $newPath = $tempFile.$extension;
        rename($tempFile, $newPath);

        // Create a tiny 1x1 pixel image
        $image = imagecreatetruecolor(1, 1);
        imagejpeg($image, $newPath);
        imagedestroy($image);

        return $newPath;
    }
}
