<?php

namespace Tests\Unit\Integrations;

use App\Integrations\ManualLog\TvdbClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TvdbClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.tvdb.api_key' => 'test-key', 'services.tvdb.base_url' => 'https://tvdb.test/v4']);
        Cache::forget('tvdb.token');
    }

    #[Test]
    public function is_only_configured_with_an_api_key(): void
    {
        $this->assertTrue((new TvdbClient)->isConfigured());

        config(['services.tvdb.api_key' => null]);
        $this->assertFalse((new TvdbClient)->isConfigured());
    }

    #[Test]
    public function logs_in_once_and_searches_for_series(): void
    {
        Http::fake([
            'tvdb.test/v4/login' => Http::response(['data' => ['token' => 'abc']]),
            'tvdb.test/v4/search*' => Http::response(['data' => [
                ['tvdb_id' => '371572', 'name' => 'The Diplomat', 'year' => '2023', 'overview' => 'A diplomat.', 'image_url' => 'https://img/1.jpg'],
                ['name' => 'Malformed'],
            ]]),
        ]);

        $client = new TvdbClient;
        $first = $client->searchSeries('the diplomat');
        $client->searchSeries('the diplomat');

        $this->assertSame([['id' => 371572, 'name' => 'The Diplomat', 'year' => '2023', 'overview' => 'A diplomat.', 'image' => 'https://img/1.jpg']], $first);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/login') && $request['apikey'] === 'test-key');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/search') && $request->hasHeader('Authorization', 'Bearer abc') && $request['type'] === 'series');
    }

    #[Test]
    public function lists_official_aired_seasons_without_specials(): void
    {
        Http::fake([
            'tvdb.test/v4/login' => Http::response(['data' => ['token' => 'abc']]),
            'tvdb.test/v4/series/371572/extended*' => Http::response(['data' => ['seasons' => [
                ['number' => 0, 'type' => ['type' => 'official']],
                ['number' => 2, 'type' => ['type' => 'official']],
                ['number' => 1, 'type' => ['type' => 'official']],
                ['number' => 1, 'type' => ['type' => 'dvd']],
                ['number' => 3, 'type' => ['type' => 'official']],
            ]]]),
        ]);

        $this->assertSame([1, 2, 3], (new TvdbClient)->seasons(371572));
    }

    #[Test]
    public function lists_the_episodes_of_one_season_in_order(): void
    {
        Http::fake([
            'tvdb.test/v4/login' => Http::response(['data' => ['token' => 'abc']]),
            'tvdb.test/v4/series/371572/episodes/default*' => Http::response(['data' => ['episodes' => [
                ['id' => 11, 'number' => 2, 'seasonNumber' => 2, 'name' => 'Two', 'aired' => '2024-10-31', 'runtime' => 50, 'image' => 'https://img/2.jpg'],
                ['id' => 10, 'number' => 1, 'seasonNumber' => 2, 'name' => 'One', 'aired' => '2024-10-31', 'runtime' => 48],
                ['id' => 99, 'number' => 1, 'seasonNumber' => 1, 'name' => 'Other season'],
            ]]]),
        ]);

        $episodes = (new TvdbClient)->episodes(371572, 2);

        $this->assertSame([1, 2], array_column($episodes, 'number'));
        $this->assertSame('One', $episodes[0]['name']);
        $this->assertNull($episodes[0]['image']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'season=2'));
    }

    #[Test]
    public function refreshes_the_token_once_on_a_401(): void
    {
        Http::fake([
            'tvdb.test/v4/login' => Http::sequence()
                ->push(['data' => ['token' => 'stale']])
                ->push(['data' => ['token' => 'fresh']]),
            'tvdb.test/v4/search*' => Http::sequence()
                ->push([], 401)
                ->push(['data' => []]),
        ]);

        $this->assertSame([], (new TvdbClient)->searchSeries('x'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/search') && $request->hasHeader('Authorization', 'Bearer fresh'));
    }

    #[Test]
    public function throws_when_the_api_keeps_failing(): void
    {
        Http::fake([
            'tvdb.test/v4/login' => Http::response(['data' => ['token' => 'abc']]),
            'tvdb.test/v4/search*' => Http::response([], 500),
        ]);

        $this->expectException(RequestException::class);

        (new TvdbClient)->searchSeries('x');
    }
}
