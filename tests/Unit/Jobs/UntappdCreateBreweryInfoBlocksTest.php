<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Data\Untappd\UntappdCreateBreweryInfoBlocks;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UntappdCreateBreweryInfoBlocksTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_attaches_existing_brewery_media_to_created_brewery_detail_block(): void
    {
        $integration = Integration::factory()->create(['service' => 'untappd']);

        $brewery = EventObject::factory()->create([
            'user_id' => $integration->user_id,
            'type' => 'untappd_brewery',
            'title' => 'Test Brewery',
            'metadata' => ['description' => 'A brewery'],
        ]);
        $brewery->addMedia($this->createTestImage())
            ->withCustomProperties(['md5_hash' => 'brewery-image-hash'])
            ->toMediaCollection('downloaded_images');

        $beer = EventObject::factory()->create([
            'user_id' => $integration->user_id,
            'type' => 'untappd_beer',
            'metadata' => ['brewery_name' => 'Test Brewery'],
        ]);

        $event = Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'untappd',
            'action' => 'drank',
            'target_id' => $beer->id,
        ]);

        (new UntappdCreateBreweryInfoBlocks($integration, ['brewery_id' => $brewery->id]))->handle();

        $block = $event->blocks()->where('block_type', 'brewery_details')->first();

        $this->assertNotNull($block);
        $this->assertTrue($block->hasMedia('downloaded_images'));
        $this->assertSame(
            'brewery-image-hash',
            $block->getFirstMedia('downloaded_images')->getCustomProperty('md5_hash')
        );
    }

    protected function createTestImage(): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_img');
        $path = $tempFile . '.jpg';
        rename($tempFile, $path);

        $image = imagecreatetruecolor(1, 1);
        imagejpeg($image, $path);
        imagedestroy($image);

        return $path;
    }
}
