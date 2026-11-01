<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Immich\ImmichPlugin;
use App\Models\Integration;
use Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ImmichApiTest extends TestCase
{
    #[Test]
    public function it_searches_assets_and_follows_the_next_page_even_for_short_batches(): void
    {
        $plugin = $this->plugin([
            ['assets' => ['items' => [['id' => 'first']], 'nextPage' => '2']],
            ['assets' => ['items' => [['id' => 'second']], 'nextPage' => null]],
        ]);

        $result = $plugin->pullPhotoData(new Integration, 'https://photos.example.com/', 'secret', '2026-10-01T00:00:00Z');

        $this->assertSame([['id' => 'first'], ['id' => 'second']], $result['assets']);
        $this->assertSame('POST', $plugin->requests[0]['method']);
        $this->assertSame('https://photos.example.com/api/search/metadata', $plugin->requests[0]['url']);
        $this->assertSame('secret', $plugin->requests[0]['apiKey']);
        $this->assertSame([
            'size' => 250, 'page' => 1, 'withExif' => true,
            'withPeople' => true, 'takenAfter' => '2026-10-01T00:00:00Z',
        ], $plugin->requests[0]['params']);
        $this->assertSame(2, $plugin->requests[1]['params']['page']);
    }

    #[Test]
    public function it_follows_cursor_pagination(): void
    {
        $plugin = $this->plugin([
            ['assets' => ['items' => [['id' => 'first']], 'nextCursor' => 'cursor-2', 'nextPage' => null]],
            ['assets' => ['items' => [['id' => 'second']], 'nextCursor' => null, 'nextPage' => null]],
        ]);

        $result = $plugin->pullPhotoData(new Integration, 'https://photos.example.com', 'secret');

        $this->assertCount(2, $result['assets']);
        $this->assertSame('cursor-2', $plugin->requests[1]['params']['cursor']);
        $this->assertArrayNotHasKey('page', $plugin->requests[1]['params']);
    }

    #[Test]
    public function it_accepts_an_empty_search_without_a_date_filter(): void
    {
        $plugin = $this->plugin([['assets' => ['items' => [], 'nextPage' => null]]]);

        $this->assertSame([], $plugin->pullPhotoData(new Integration, 'https://photos.example.com', 'secret')['assets']);
        $this->assertArrayNotHasKey('takenAfter', $plugin->requests[0]['params']);
    }

    #[Test]
    public function it_rejects_a_malformed_search_instead_of_silently_importing_nothing(): void
    {
        $plugin = $this->plugin([['message' => 'Unexpected response']]);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid asset search response');

        $plugin->pullPhotoData(new Integration, 'https://photos.example.com', 'secret');
    }

    #[Test]
    public function it_rejects_repeated_pages(): void
    {
        $plugin = $this->plugin([['assets' => ['items' => [], 'nextPage' => '1']]]);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid next page');

        $plugin->pullPhotoData(new Integration, 'https://photos.example.com', 'secret');
    }

    #[Test]
    public function it_rejects_repeated_cursors(): void
    {
        $plugin = $this->plugin([
            ['assets' => ['items' => [], 'nextCursor' => 'same-cursor']],
            ['assets' => ['items' => [], 'nextCursor' => 'same-cursor']],
        ]);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid next cursor');

        $plugin->pullPhotoData(new Integration, 'https://photos.example.com', 'secret');
    }

    #[Test]
    public function it_fetches_all_people_pages(): void
    {
        $plugin = $this->plugin([
            ['people' => [['id' => 'first']], 'hasNextPage' => true],
            ['people' => [['id' => 'second']], 'hasNextPage' => false],
        ]);

        $result = $plugin->pullPeopleData(new Integration, 'https://photos.example.com/', 'secret');

        $this->assertSame([['id' => 'first'], ['id' => 'second']], $result['people']);
        $this->assertSame('https://photos.example.com/api/people', $plugin->requests[0]['url']);
        $this->assertSame(['withHidden' => 'true', 'page' => 2, 'size' => 500], $plugin->requests[1]['params']);
    }

    private function plugin(array $responses): ImmichPlugin
    {
        return new class($responses) extends ImmichPlugin
        {
            public array $requests = [];

            public function __construct(private array $responses) {}

            protected function makeRequest(string $method, string $url, string $apiKey, array $params = [], ?string $integrationId = null): array
            {
                $this->requests[] = [
                    'method' => $method,
                    'url' => $url,
                    'apiKey' => $apiKey,
                    'params' => $params,
                ];

                return array_shift($this->responses);
            }
        };
    }
}
