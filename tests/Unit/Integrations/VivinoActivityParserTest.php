<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Vivino\VivinoActivityParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the parsing/normalization logic against a constructed fixture that
 * matches VivinoActivityParser's assumed markup contract (data-testid
 * hooks). This does NOT confirm the selectors match Vivino's real, live
 * DOM - that couldn't be verified in the environment this was written in
 * (no outbound browsing access to vivino.com). It confirms the parser
 * correctly extracts and normalizes data from HTML of that assumed shape,
 * so the logic is right even if the real selectors need adjustment later.
 */
class VivinoActivityParserTest extends TestCase
{
    private const FIXTURE_HTML = <<<'HTML'
        <html><body>
            <div data-testid="activityFeedItem">
                <a data-testid="wineNameLink" href="https://www.vivino.com/wines/1234">
                    <span data-testid="wineName">Malbec Reserva 2021</span>
                </a>
                <span data-testid="wineryName">Bodega Test</span>
                <span data-testid="vintageYear">2021</span>
                <span data-testid="regionName">Mendoza</span>
                <span data-testid="ratingStars" aria-label="Rated 4.5 out of 5"></span>
                <p data-testid="reviewNote">Lovely with steak.</p>
            </div>
            <div data-testid="activityFeedItem">
                <a data-testid="wineNameLink" href="https://www.vivino.com/wines/5678">
                    <span data-testid="wineName">Rioja Crianza</span>
                </a>
                <span data-testid="wineryName">Bodega Two</span>
                <span data-testid="ratingValue">3.5</span>
            </div>
            <div data-testid="activityFeedItem">
                <a data-testid="wineNameLink" href="https://www.vivino.com/wines/1234">
                    <span data-testid="wineName">Malbec Reserva 2021</span>
                </a>
            </div>
        </body></html>
        HTML;

    #[Test]
    public function extracts_all_fields_for_a_complete_entry(): void
    {
        $entries = (new VivinoActivityParser)->parse(self::FIXTURE_HTML);

        $malbec = collect($entries)->firstWhere('title', 'Malbec Reserva 2021');

        $this->assertNotNull($malbec);
        $this->assertSame('Bodega Test', $malbec['winery']);
        $this->assertSame('2021', $malbec['vintage']);
        $this->assertSame('Mendoza', $malbec['region']);
        $this->assertSame(4.5, $malbec['rating']);
        $this->assertSame('Lovely with steak.', $malbec['note']);
        $this->assertSame('https://www.vivino.com/wines/1234', $malbec['url']);
    }

    #[Test]
    public function falls_back_to_a_plain_numeric_rating_value(): void
    {
        $entries = (new VivinoActivityParser)->parse(self::FIXTURE_HTML);

        $rioja = collect($entries)->firstWhere('title', 'Rioja Crianza');

        $this->assertNotNull($rioja);
        $this->assertSame(3.5, $rioja['rating']);
        $this->assertNull($rioja['vintage']);
        $this->assertNull($rioja['note']);
    }

    #[Test]
    public function deduplicates_repeated_entries_within_one_page_fetch(): void
    {
        $entries = (new VivinoActivityParser)->parse(self::FIXTURE_HTML);

        $this->assertCount(2, $entries);
    }

    #[Test]
    public function returns_an_empty_array_for_blank_html(): void
    {
        $this->assertSame([], (new VivinoActivityParser)->parse(''));
    }

    #[Test]
    public function returns_an_empty_array_when_no_matching_cards_are_found(): void
    {
        $this->assertSame([], (new VivinoActivityParser)->parse('<html><body><p>Nothing here</p></body></html>'));
    }

    #[Test]
    public function ignores_a_card_with_no_wine_name(): void
    {
        $entries = (new VivinoActivityParser)->parse(
            '<div data-testid="activityFeedItem"><span data-testid="wineryName">Orphan Winery</span></div>'
        );

        $this->assertSame([], $entries);
    }
}
