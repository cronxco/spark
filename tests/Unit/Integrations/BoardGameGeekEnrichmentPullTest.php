<?php

namespace Tests\Unit\Integrations;

use App\Jobs\Data\ManualLog\BoardGameGeekEnrichmentData;
use App\Jobs\OAuth\ManualLog\BoardGameGeekEnrichmentPull;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardGameGeekEnrichmentPullTest extends TestCase
{
    use RefreshDatabase;

    private const SEARCH_XML = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <items total="1">
            <item type="boardgame" id="13">
                <name type="primary" value="Catan"/>
                <yearpublished value="1995"/>
            </item>
        </items>
        XML;

    private const THING_XML = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <items>
            <item type="boardgame" id="13">
                <thumbnail>https://cf.geekdo-images.com/thumb.jpg</thumbnail>
                <image>https://cf.geekdo-images.com/full.jpg</image>
                <name type="primary" sortindex="1" value="Catan"/>
                <name type="alternate" sortindex="1" value="Los Colonos de Catan"/>
                <description>In Catan, players try to be the dominant force.</description>
                <yearpublished value="1995"/>
                <minplayers value="3"/>
                <maxplayers value="4"/>
                <playingtime value="120"/>
                <statistics>
                    <ratings>
                        <average value="7.1"/>
                    </ratings>
                </statistics>
            </item>
        </items>
        XML;

    #[Test]
    public function searches_then_fetches_details_and_dispatches_the_data_job(): void
    {
        Queue::fake([BoardGameGeekEnrichmentData::class]);
        Http::fake([
            'boardgamegeek.com/xmlapi2/search*' => Http::response(self::SEARCH_XML, 200),
            'boardgamegeek.com/xmlapi2/thing*' => Http::response(self::THING_XML, 200),
        ]);

        $integration = Integration::factory()->create(['service' => 'manual_log']);

        BoardGameGeekEnrichmentPull::dispatchSync($integration, 'event-id', 'Catan');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/search') && $request['query'] === 'Catan');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/thing') && $request['id'] === '13' && (int) $request['stats'] === 1);
        Queue::assertPushed(BoardGameGeekEnrichmentData::class, 1);
    }

    #[Test]
    public function does_not_dispatch_when_the_search_returns_no_results(): void
    {
        Queue::fake([BoardGameGeekEnrichmentData::class]);
        Http::fake([
            'boardgamegeek.com/xmlapi2/search*' => Http::response('<?xml version="1.0"?><items total="0"></items>', 200),
        ]);

        $integration = Integration::factory()->create(['service' => 'manual_log']);

        BoardGameGeekEnrichmentPull::dispatchSync($integration, 'event-id', 'Some Obscure Prototype');

        Queue::assertNotPushed(BoardGameGeekEnrichmentData::class);
    }

    #[Test]
    public function does_not_dispatch_when_the_search_request_fails(): void
    {
        Queue::fake([BoardGameGeekEnrichmentData::class]);
        Http::fake([
            'boardgamegeek.com/xmlapi2/search*' => Http::response('', 503),
        ]);

        $integration = Integration::factory()->create(['service' => 'manual_log']);

        BoardGameGeekEnrichmentPull::dispatchSync($integration, 'event-id', 'Catan');

        Queue::assertNotPushed(BoardGameGeekEnrichmentData::class);
    }
}
