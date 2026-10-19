<?php

namespace Tests\Unit\Integrations;

use App\Integrations\ManualLog\NameMatcher;
use PHPUnit\Framework\Attributes\Test;
use Tests\FrameworkTestCase;

class NameMatcherTest extends FrameworkTestCase
{
    #[Test]
    public function scores_the_share_of_query_words_found_ignoring_case_accents_and_punctuation(): void
    {
        $this->assertSame(1.0, NameMatcher::score('francois  ROUGE', "Domaine François-Lumpp 'Rouge'"));
        $this->assertEqualsWithDelta(0.5, NameMatcher::score('Cafe Rouge', 'Domaine Lumpp Rouge'), 0.001);
        $this->assertSame(0.0, NameMatcher::score('', 'Anything'));
        $this->assertSame(0.0, NameMatcher::score('Malbec', 'Rioja'));
    }

    #[Test]
    public function best_returns_the_highest_scoring_result_only_when_it_clears_the_threshold(): void
    {
        $results = [['title' => 'Verdant Brewing Co Pulp'], ['title' => 'Verdant Brewing Co Nebulous Sky']];

        $this->assertSame(
            'Verdant Brewing Co Nebulous Sky',
            NameMatcher::best($results, 'Verdant Nebulous Sky', fn (array $r) => $r['title'])['title'],
        );
        $this->assertNull(NameMatcher::best($results, 'Totally Different Beer', fn (array $r) => $r['title']));
    }

    #[Test]
    public function candidates_keep_partial_matches_best_first_and_respect_the_limit(): void
    {
        $results = [['t' => 'Sky Rocket'], ['t' => 'Nebulous Sky'], ['t' => 'Unrelated']];

        $candidates = NameMatcher::candidates($results, 'Nebulous Sky', fn (array $r) => $r['t']);

        $this->assertSame(['Nebulous Sky', 'Sky Rocket'], array_column($candidates, 't'));
        $this->assertCount(1, NameMatcher::candidates($results, 'Nebulous Sky', fn (array $r) => $r['t'], 1));
    }
}
