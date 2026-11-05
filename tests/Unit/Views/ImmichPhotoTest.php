<?php

namespace Tests\Unit\Views;

use App\Models\Block;
use App\Models\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\FrameworkTestCase;

class ImmichPhotoTest extends FrameworkTestCase
{
    public static function timestamps(): array
    {
        return [
            'metadata timestamp' => ['2026-11-02T08:27:28Z', '2 Nov 2026, 08:27'],
            'offset timestamp' => ['2026-11-02T10:27:28+02:00', '2 Nov 2026, 10:27'],
            'block time fallback' => [null, '1 Nov 2026, 12:00'],
        ];
    }

    #[Test]
    #[DataProvider('timestamps')]
    public function it_renders_the_photo_timestamp(?string $timestamp, string $expected): void
    {
        $block = new Block([
            'title' => 'Photo.jpg',
            'time' => '2026-11-01T12:00:00Z',
            'metadata' => ['type' => 'IMAGE', 'timestamp' => $timestamp],
        ]);
        $block->id = 'photo-block';
        $block->setRelation('event', new Event(['service' => 'immich']));

        $this->view('blocks.types.immich_photo', ['block' => $block])
            ->assertSee($expected)
            ->assertSee('Photo.jpg');
    }
}
