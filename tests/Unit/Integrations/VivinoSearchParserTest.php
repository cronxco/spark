<?php

namespace Tests\Unit\Integrations;

use App\Integrations\ManualLog\VivinoSearchParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\FrameworkTestCase;

/**
 * Runs against a saved real Vivino search page (three cards, from the query
 * "Cafe Francois Rouge", 19 Oct 2026). If Vivino changes its markup, replace
 * tests/Fixtures/ManualLog/vivino-search-results.html with a fresh capture.
 */
class VivinoSearchParserTest extends FrameworkTestCase
{
    #[Test]
    public function extracts_every_field_from_a_real_card(): void
    {
        $wine = (new VivinoSearchParser)->parseFirstResult($this->fixture());

        $this->assertNotNull($wine);
        $this->assertSame('François Mikulski Les Toisieres Monthelie Rouge', $wine['title']);
        $this->assertSame('Les Toisieres Monthelie Rouge', $wine['wine']);
        $this->assertSame('François Mikulski', $wine['winery']);
        $this->assertSame('2023', $wine['vintage']);
        $this->assertSame('Monthélie, France', $wine['region']);
        $this->assertSame(4.2, $wine['rating']);
        $this->assertSame(178, $wine['rating_count']);
        $this->assertSame('https://www.vivino.com/en/francois-mikulski-les-toisieres-monthelie-rouge/w/11084178?year=2023', $wine['url']);
        $this->assertSame('https://images.vivino.com/thumbs/tPGSQcFcQMeHkcT_gKye8Q_pb_x300.png', $wine['image']);
    }

    #[Test]
    public function parses_every_card_on_the_page(): void
    {
        $results = (new VivinoSearchParser)->parseResults($this->fixture());

        $this->assertCount(3, $results);
        $this->assertSame('Domaine François Raquillet Vieilles Vignes Mercurey Rouge', $results[2]['title']);
    }

    #[Test]
    public function respects_the_result_limit(): void
    {
        $this->assertCount(1, (new VivinoSearchParser)->parseResults($this->fixture(), 1));
    }

    #[Test]
    public function best_match_rejects_an_unrelated_first_card(): void
    {
        $this->assertNull((new VivinoSearchParser)->parseBestMatch($this->fixture(), 'Cafe Francois Rouge'));
    }

    #[Test]
    public function best_match_finds_the_card_that_covers_the_query_regardless_of_position_and_accents(): void
    {
        $wine = (new VivinoSearchParser)->parseBestMatch($this->fixture(), 'raquillet mercurey vieilles vignes');

        $this->assertSame('Domaine François Raquillet Vieilles Vignes Mercurey Rouge', $wine['title']);
    }

    #[Test]
    public function candidates_are_the_partial_matches_best_first(): void
    {
        $candidates = (new VivinoSearchParser)->parseCandidates($this->fixture(), 'Francois Rouge');

        $this->assertCount(3, $candidates);
    }

    #[Test]
    public function match_score_is_the_share_of_query_words_found(): void
    {
        $parser = new VivinoSearchParser;

        $this->assertSame(1.0, $parser->matchScore('François Rouge', 'Domaine Francois Lumpp Rouge'));
        $this->assertEqualsWithDelta(0.5, $parser->matchScore('Cafe Rouge', 'Domaine Lumpp Rouge'), 0.001);
        $this->assertSame(0.0, $parser->matchScore('', 'Anything'));
    }

    #[Test]
    public function returns_nothing_for_pages_without_cards_or_empty_html(): void
    {
        $parser = new VivinoSearchParser;

        $this->assertNull($parser->parseFirstResult('<html><body>No results</body></html>'));
        $this->assertNull($parser->parseFirstResult(''));
        $this->assertNull($parser->parseBestMatch('', 'Malbec'));
    }

    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/ManualLog/vivino-search-results.html'));
    }
}
