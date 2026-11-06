<?php

namespace App\Services\Metrics;

/**
 * Plain statistics for metric baselines. No framework, no state.
 */
final class Stats
{
    /**
     * Scales a median absolute deviation to match a standard deviation for
     * normally distributed data.
     */
    public const MAD_SCALE = 1.4826;

    /**
     * @param  array<int, float|int>  $values
     */
    public static function mean(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    /**
     * Population standard deviation.
     *
     * @param  array<int, float|int>  $values
     */
    public static function stddev(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        $mean = self::mean($values);

        return sqrt(self::mean(array_map(fn ($v) => ($v - $mean) ** 2, $values)));
    }

    /**
     * @param  array<int, float|int>  $values
     */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * Median absolute deviation, scaled to a standard deviation.
     *
     * @param  array<int, float|int>  $values
     */
    public static function scaledMad(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        $median = self::median($values);

        return self::MAD_SCALE * self::median(array_map(fn ($v) => abs($v - $median), $values));
    }

    /**
     * Two-sided p-value of a Mann-Whitney U test (normal approximation with
     * tie and continuity corrections). Null when either group is too small.
     *
     * @param  array<int, float|int>  $a
     * @param  array<int, float|int>  $b
     */
    public static function mannWhitneyP(array $a, array $b): ?float
    {
        $n1 = count($a);
        $n2 = count($b);

        if ($n1 < 2 || $n2 < 2) {
            return null;
        }

        $all = array_merge(
            array_map(fn ($v) => [(float) $v, 0], array_values($a)),
            array_map(fn ($v) => [(float) $v, 1], array_values($b)),
        );
        usort($all, fn ($x, $y) => $x[0] <=> $y[0]);

        $n = $n1 + $n2;
        $rankSumA = 0.0;
        $tieTerm = 0.0;
        $i = 0;

        while ($i < $n) {
            $j = $i;
            while ($j + 1 < $n && $all[$j + 1][0] === $all[$i][0]) {
                $j++;
            }

            $rank = ($i + $j) / 2 + 1;
            $ties = $j - $i + 1;
            $tieTerm += $ties ** 3 - $ties;

            for ($k = $i; $k <= $j; $k++) {
                if ($all[$k][1] === 0) {
                    $rankSumA += $rank;
                }
            }

            $i = $j + 1;
        }

        $u = $rankSumA - $n1 * ($n1 + 1) / 2;
        $mu = $n1 * $n2 / 2;
        $variance = ($n1 * $n2 / 12) * (($n + 1) - $tieTerm / ($n * ($n - 1)));

        if ($variance <= 0) {
            return null;
        }

        $z = (abs($u - $mu) - 0.5) / sqrt($variance);

        return min(1.0, 2 * (1 - self::normalCdf(max(0.0, $z))));
    }

    public static function normalCdf(float $z): float
    {
        $t = 1 / (1 + 0.2316419 * abs($z));
        $d = 0.3989423 * exp(-$z * $z / 2);
        $p = $d * $t * (0.3193815 + $t * (-0.3565638 + $t * (1.781478 + $t * (-1.821256 + $t * 1.330274))));

        return $z >= 0 ? 1 - $p : $p;
    }
}
