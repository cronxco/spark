<?php

namespace Tests\Unit\Services\Search;

use App\Services\Search\RecencyRanking;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecencyRankingTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-10-03 12:00:00');
    }

    #[Test]
    public function recency_halves_every_half_life(): void
    {
        $ranking = new RecencyRanking(0.2, 30);

        $this->assertEqualsWithDelta(1.0, $ranking->recency($this->now, $this->now), 0.0001);
        $this->assertEqualsWithDelta(0.5, $ranking->recency($this->now->subDays(30), $this->now), 0.0001);
        $this->assertEqualsWithDelta(0.25, $ranking->recency($this->now->subDays(60), $this->now), 0.0001);
    }

    #[Test]
    public function future_times_count_as_now_and_missing_times_as_not_recent(): void
    {
        $ranking = new RecencyRanking(0.2, 30);

        $this->assertEqualsWithDelta(1.0, $ranking->recency($this->now->addDays(5), $this->now), 0.0001);
        $this->assertSame(0.0, $ranking->recency(null, $this->now));
    }

    #[Test]
    public function a_recent_result_outscores_an_older_one_with_the_same_relevance_when_weight_is_positive(): void
    {
        $ranking = new RecencyRanking(0.2, 30);

        $recent = $ranking->score(RecencyRanking::CONTAINS, $this->now->subDay(), $this->now);
        $old = $ranking->score(RecencyRanking::CONTAINS, $this->now->subYear(), $this->now);

        $this->assertGreaterThan($old, $recent);
    }

    #[Test]
    public function weight_zero_scores_by_relevance_alone(): void
    {
        $ranking = new RecencyRanking(0, 30);

        $this->assertFalse($ranking->enabled());
        $this->assertSame(RecencyRanking::CONTAINS, $ranking->score(RecencyRanking::CONTAINS, $this->now, $this->now));
        $this->assertSame(RecencyRanking::EXACT, $ranking->score(RecencyRanking::EXACT, $this->now->subYears(5), $this->now));
    }

    #[Test]
    public function the_weight_decides_whether_recency_can_beat_a_better_match(): void
    {
        $oldExact = fn (RecencyRanking $ranking): float => $ranking->score(RecencyRanking::EXACT, $this->now->subYear(), $this->now);
        $newContains = fn (RecencyRanking $ranking): float => $ranking->score(RecencyRanking::CONTAINS, $this->now, $this->now);

        $gentle = new RecencyRanking(0.2, 30);
        $strong = new RecencyRanking(0.5, 30);

        $this->assertGreaterThan($newContains($gentle), $oldExact($gentle));
        $this->assertGreaterThan($oldExact($strong), $newContains($strong));
    }

    #[Test]
    public function it_reads_and_clamps_its_settings(): void
    {
        config(['spark.search.recency.weight' => 0.35, 'spark.search.recency.half_life_days' => 14]);

        $configured = new RecencyRanking;

        $this->assertSame(0.35, $configured->weight());
        $this->assertSame(14.0, $configured->halfLifeDays());
        $this->assertSame(1.0, (new RecencyRanking(3, 30))->weight());
        $this->assertSame(0.0, (new RecencyRanking(-1, 30))->weight());
        $this->assertSame(0.0, $configured->withWeight(0)->weight());
        $this->assertSame(14.0, $configured->withWeight(0)->halfLifeDays());
    }

    #[Test]
    public function text_relevance_tiers_follow_how_the_term_matches(): void
    {
        $ranking = new RecencyRanking(0.2, 30);

        $this->assertSame(RecencyRanking::EXACT, $ranking->textRelevance('Tesco', 'tesco'));
        $this->assertSame(RecencyRanking::PREFIX, $ranking->textRelevance('Tesco Metro', 'tesco'));
        $this->assertSame(RecencyRanking::CONTAINS, $ranking->textRelevance('Big Tesco', 'tesco'));
        $this->assertSame(RecencyRanking::OTHER_FIELD, $ranking->textRelevance('Groceries', 'tesco'));
    }
}
