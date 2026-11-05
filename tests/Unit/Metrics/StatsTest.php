<?php

namespace Tests\Unit\Metrics;

use App\Services\Metrics\Stats;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StatsTest extends TestCase
{
    #[Test]
    public function median_of_an_even_and_an_odd_set(): void
    {
        $this->assertSame(2.5, Stats::median([4, 1, 3, 2]));
        $this->assertSame(3.0, Stats::median([5, 1, 3]));
        $this->assertSame(0.0, Stats::median([]));
    }

    #[Test]
    public function scaled_mad_ignores_an_outlier_that_moves_the_standard_deviation(): void
    {
        $values = [1, 2, 3, 4, 100];

        $this->assertEqualsWithDelta(1.4826, Stats::scaledMad($values), 0.0001);
        $this->assertGreaterThan(30, Stats::stddev($values));
    }

    #[Test]
    public function population_standard_deviation(): void
    {
        $this->assertEqualsWithDelta(2.0, Stats::stddev([2, 4, 4, 4, 5, 5, 7, 9]), 0.0001);
        $this->assertSame(0.0, Stats::stddev([5]));
    }

    #[Test]
    public function a_clear_split_is_significant_and_interleaved_values_are_not(): void
    {
        $weekdays = [9070, 9500, 8800, 10100, 9300, 8700, 9900, 9100];
        $weekends = [5000, 5200, 4900, 5600, 5100, 4800];

        $this->assertLessThan(0.01, Stats::mannWhitneyP($weekdays, $weekends));
        $this->assertGreaterThan(0.5, Stats::mannWhitneyP([1, 2, 3, 4, 5, 6], [1.5, 2.5, 3.5, 4.5, 5.5, 6.5]));
    }

    #[Test]
    public function identical_values_and_tiny_groups_have_no_p_value(): void
    {
        $this->assertNull(Stats::mannWhitneyP([3, 3, 3], [3, 3, 3]));
        $this->assertNull(Stats::mannWhitneyP([1], [1, 2, 3]));
    }
}
