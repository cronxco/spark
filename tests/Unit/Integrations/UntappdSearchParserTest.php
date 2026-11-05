<?php

namespace Tests\Unit\Integrations;

use App\Integrations\ManualLog\UntappdSearchParser;
use PHPUnit\Framework\Attributes\Test;
use Tests\FrameworkTestCase;

/**
 * Runs against a saved real Untappd search page (query "Verdant Nebulous Sky",
 * 19 Oct 2026). If Untappd changes its markup, replace
 * tests/Fixtures/ManualLog/untappd-search-results.html with a fresh capture.
 */
class UntappdSearchParserTest extends FrameworkTestCase
{
    #[Test]
    public function extracts_every_field_from_a_real_result(): void
    {
        $results = (new UntappdSearchParser)->parseResults($this->fixture());

        $this->assertCount(1, $results);
        $this->assertSame([
            'beer_id' => '5063519',
            'name' => 'Nebulous Sky',
            'title' => 'Verdant Brewing Co Nebulous Sky',
            'brewery' => 'Verdant Brewing Co',
            'brewery_id' => '211325',
            'style' => 'IPA - Imperial / Double New England / Hazy',
            'abv' => 8.4,
            'ibu' => null,
            'rating' => 4.24,
            'url' => 'https://untappd.com/b/verdant-brewing-co-nebulous-sky/5063519',
            'image' => 'https://assets.untappd.com/site/beer_logos/beer-5063519_8e78d_sm.jpeg',
        ], $results[0]);
    }

    #[Test]
    public function best_match_covers_brewery_and_beer_name(): void
    {
        $parser = new UntappdSearchParser;

        $this->assertSame('5063519', $parser->parseBestMatch($this->fixture(), 'Verdant Nebulous Sky')['beer_id']);
        $this->assertSame('5063519', $parser->parseBestMatch($this->fixture(), 'Nebulous Sky')['beer_id']);
        $this->assertNull($parser->parseBestMatch($this->fixture(), 'Guinness Draught'));
    }

    #[Test]
    public function candidates_are_the_partial_matches(): void
    {
        $this->assertCount(1, (new UntappdSearchParser)->parseCandidates($this->fixture(), 'Verdant Brewing Pulp'));
    }

    #[Test]
    public function reads_a_numeric_ibu(): void
    {
        $html = '<div class="beer-item" data-bid="1"><div class="beer-details"><p class="name"><a href="/b/x/1">X</a></p></div><div class="details beer"><p class="abv">5% ABV</p><p class="ibu">42 IBU</p></div></div>';

        $result = (new UntappdSearchParser)->parseResults($html)[0];

        $this->assertSame(42, $result['ibu']);
        $this->assertSame(5.0, $result['abv']);
        $this->assertNull($result['rating']);
        $this->assertNull($result['brewery']);
    }

    #[Test]
    public function returns_nothing_for_the_unrendered_placeholder_or_empty_html(): void
    {
        $parser = new UntappdSearchParser;

        $this->assertSame([], $parser->parseResults('<div id="algolia-hits"></div><p>Use the Search Box above to search for a Drink.</p>'));
        $this->assertSame([], $parser->parseResults(''));
    }

    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/ManualLog/untappd-search-results.html'));
    }
}
