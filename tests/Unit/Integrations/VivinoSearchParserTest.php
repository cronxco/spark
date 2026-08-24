<?php

namespace Tests\Unit\Integrations;

use App\Integrations\ManualLog\VivinoSearchParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the parsing/normalization logic against a constructed fixture that
 * matches VivinoSearchParser's assumed markup contract (data-testid
 * hooks). This does NOT confirm the selectors match Vivino's real, live
 * DOM - that couldn't be verified in the environment this was written in
 * (no outbound browsing access to vivino.com). It confirms the parser
 * correctly extracts and normalizes data from HTML of that assumed shape,
 * so the logic is right even if the real selectors need adjustment later.
 */
class VivinoSearchParserTest extends TestCase
{
    private const FIXTURE_HTML = <<<'HTML'
        <html><body>
            <div data-testid="wineCard">
                <a data-testid="wineNameLink" href="https://www.vivino.com/wines/1234">
                    <span data-testid="wineName">Malbec Reserva 2021</span>
                </a>
                <span data-testid="wineryName">Bodega Test</span>
                <span data-testid="vintageYear">2021</span>
                <span data-testid="regionName">Mendoza</span>
                <span data-testid="ratingStars" aria-label="Rated 4.5 out of 5"></span>
                <img data-testid="wineLabel" src="https://images.vivino.com/labels/1234.jpg" />
            </div>
            <div data-testid="wineCard">
                <a data-testid="wineNameLink" href="https://www.vivino.com/wines/5678">
                    <span data-testid="wineName">Second Result</span>
                </a>
            </div>
        </body></html>
        HTML;

    #[Test]
    public function extracts_all_fields_from_the_first_matching_card(): void
    {
        $wine = (new VivinoSearchParser)->parseFirstResult(self::FIXTURE_HTML);

        $this->assertNotNull($wine);
        $this->assertSame('Malbec Reserva 2021', $wine['title']);
        $this->assertSame('Bodega Test', $wine['winery']);
        $this->assertSame('2021', $wine['vintage']);
        $this->assertSame('Mendoza', $wine['region']);
        $this->assertSame(4.5, $wine['rating']);
        $this->assertSame('https://www.vivino.com/wines/1234', $wine['url']);
        $this->assertSame('https://images.vivino.com/labels/1234.jpg', $wine['image']);
    }

    #[Test]
    public function ignores_later_cards_and_only_returns_the_first(): void
    {
        $wine = (new VivinoSearchParser)->parseFirstResult(self::FIXTURE_HTML);

        $this->assertNotSame('Second Result', $wine['title']);
    }

    #[Test]
    public function falls_back_to_a_plain_numeric_rating_value(): void
    {
        $html = <<<'HTML'
            <html><body>
                <div data-testid="wineCard">
                    <span data-testid="wineName">Rioja Crianza</span>
                    <span data-testid="ratingValue">3.5</span>
                </div>
            </body></html>
            HTML;

        $wine = (new VivinoSearchParser)->parseFirstResult($html);

        $this->assertSame(3.5, $wine['rating']);
        $this->assertNull($wine['vintage']);
        $this->assertNull($wine['winery']);
    }

    #[Test]
    public function returns_null_when_no_cards_are_present(): void
    {
        $wine = (new VivinoSearchParser)->parseFirstResult('<html><body>No results</body></html>');

        $this->assertNull($wine);
    }

    #[Test]
    public function returns_null_for_empty_html(): void
    {
        $wine = (new VivinoSearchParser)->parseFirstResult('');

        $this->assertNull($wine);
    }

    #[Test]
    public function returns_null_when_a_card_has_no_wine_name(): void
    {
        $html = <<<'HTML'
            <html><body>
                <div data-testid="wineCard">
                    <span data-testid="wineryName">Bodega Test</span>
                </div>
            </body></html>
            HTML;

        $wine = (new VivinoSearchParser)->parseFirstResult($html);

        $this->assertNull($wine);
    }
}
