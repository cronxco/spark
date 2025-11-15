<?php

namespace Tests\Feature\Services\Media;

use App\Models\EventObject;
use App\Models\User;
use App\Services\Media\MediaDownloadHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class MediaDownloadHelperTest extends TestCase
{
    use RefreshDatabase;

    protected MediaDownloadHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->helper = app(MediaDownloadHelper::class);

        // Use fake storage
        Storage::fake('public');
        config(['media-library.disk_name' => 'public']);
    }

    /**
     * @test
     */
    public function downloads_and_attaches_media_from_url(): void
    {
        $imageContent = $this->createTestImageContent();

        Http::fake([
            'https://example.com/image.jpg' => Http::response($imageContent, 200),
        ]);

        $user = User::factory()->create();
        $eventObject = EventObject::factory()->create(['user_id' => $user->id]);

        $media = $this->helper->downloadAndAttachMedia(
            'https://example.com/image.jpg',
            $eventObject,
            'downloaded_images'
        );

        $this->assertNotNull($media);
        $this->assertInstanceOf(Media::class, $media);
        $this->assertEquals('downloaded_images', $media->collection_name);
        $this->assertNotNull($media->getCustomProperty('md5_hash'));
        $this->assertEquals('https://example.com/image.jpg', $media->getCustomProperty('source_url'));
    }

    /**
     * @test
     */
    public function deduplicates_identical_images(): void
    {
        $imageContent = $this->createTestImageContent();
        $hash = md5($imageContent);

        Http::fake([
            'https://example.com/image1.jpg' => Http::response($imageContent, 200),
            'https://example.com/image2.jpg' => Http::response($imageContent, 200),
        ]);

        $user = User::factory()->create();
        $obj1 = EventObject::factory()->create(['user_id' => $user->id]);
        $obj2 = EventObject::factory()->create(['user_id' => $user->id]);

        // Download first image
        $media1 = $this->helper->downloadAndAttachMedia(
            'https://example.com/image1.jpg',
            $obj1,
            'downloaded_images'
        );

        // Download second image (same content, different URL)
        $media2 = $this->helper->downloadAndAttachMedia(
            'https://example.com/image2.jpg',
            $obj2,
            'downloaded_images'
        );

        $this->assertNotNull($media1);
        $this->assertNotNull($media2);

        // Both should have the same MD5 hash
        $this->assertEquals($hash, $media1->getCustomProperty('md5_hash'));
        $this->assertEquals($hash, $media2->getCustomProperty('md5_hash'));

        // Should have created 2 media records (one for each model)
        $this->assertEquals(2, Media::count());

        // But they share the same hash
        $this->assertEquals(2, Media::where('custom_properties->md5_hash', $hash)->count());
    }

    /**
     * @test
     */
    public function handles_failed_download_gracefully(): void
    {
        Http::fake([
            'https://example.com/missing.jpg' => Http::response('Not Found', 404),
        ]);

        $user = User::factory()->create();
        $eventObject = EventObject::factory()->create(['user_id' => $user->id]);

        $media = $this->helper->downloadAndAttachMedia(
            'https://example.com/missing.jpg',
            $eventObject,
            'downloaded_images'
        );

        $this->assertNull($media);
        $this->assertEquals(0, $eventObject->media()->count());
    }

    /**
     * @test
     */
    public function handles_empty_response_gracefully(): void
    {
        Http::fake([
            'https://example.com/empty.jpg' => Http::response('', 200),
        ]);

        $user = User::factory()->create();
        $eventObject = EventObject::factory()->create(['user_id' => $user->id]);

        $media = $this->helper->downloadAndAttachMedia(
            'https://example.com/empty.jpg',
            $eventObject,
            'downloaded_images'
        );

        $this->assertNull($media);
    }

    /**
     * @test
     */
    public function attaches_media_from_base64(): void
    {
        $imageContent = $this->createTestImageContent();
        $base64 = base64_encode($imageContent);

        $user = User::factory()->create();
        $eventObject = EventObject::factory()->create(['user_id' => $user->id]);

        $media = $this->helper->attachMediaFromBase64(
            $base64,
            $eventObject,
            'screenshot.png',
            'screenshots'
        );

        $this->assertNotNull($media);
        $this->assertEquals('screenshots', $media->collection_name);
        $this->assertEquals('screenshot.png', $media->file_name);
        $this->assertNotNull($media->getCustomProperty('md5_hash'));
    }

    /**
     * @test
     */
    public function deduplicates_base64_images(): void
    {
        $imageContent = $this->createTestImageContent();
        $base64 = base64_encode($imageContent);
        $hash = md5($imageContent);

        $user = User::factory()->create();
        $obj1 = EventObject::factory()->create(['user_id' => $user->id]);
        $obj2 = EventObject::factory()->create(['user_id' => $user->id]);

        $media1 = $this->helper->attachMediaFromBase64($base64, $obj1, 'screenshot1.png', 'screenshots');
        $media2 = $this->helper->attachMediaFromBase64($base64, $obj2, 'screenshot2.png', 'screenshots');

        $this->assertNotNull($media1);
        $this->assertNotNull($media2);
        $this->assertEquals($hash, $media1->getCustomProperty('md5_hash'));
        $this->assertEquals($hash, $media2->getCustomProperty('md5_hash'));
    }

    /**
     * @test
     */
    public function stores_custom_properties(): void
    {
        $imageContent = $this->createTestImageContent();

        Http::fake([
            'https://example.com/image.jpg' => Http::response($imageContent, 200),
        ]);

        $user = User::factory()->create();
        $eventObject = EventObject::factory()->create(['user_id' => $user->id]);

        $media = $this->helper->downloadAndAttachMedia(
            'https://example.com/image.jpg',
            $eventObject,
            'downloaded_images',
            ['custom_field' => 'custom_value']
        );

        $this->assertNotNull($media);
        $this->assertEquals('custom_value', $media->getCustomProperty('custom_field'));
    }

    /**
     * Create test image content (tiny 1x1 JPEG).
     */
    protected function createTestImageContent(): string
    {
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagejpeg($image);
        $content = ob_get_clean();
        imagedestroy($image);

        return $content;
    }
}
