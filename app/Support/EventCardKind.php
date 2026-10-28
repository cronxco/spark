<?php

namespace App\Support;

/**
 * How an event card is laid out, looked up by `{service}.{action}`.
 *
 * The event counterpart of the block layout lookup: one registry, one
 * generic fallback, so adding a type means adding one entry here and the
 * matching case in iOS's `EventKind`. The design language (§6, §7, §8) is
 * the contract for glyphs and patterns.
 */
final class EventCardKind
{
    /**
     * `format` is how the hero reads (design language §9): `score` is a bare
     * rounded number, `duration` is "7h 55m", `count` uses grouping
     * separators, and `display` keeps the plugin's own formatter.
     *
     * @var array<string, array{pattern: string, format: string, glyph: string, label: string, max?: int}>
     */
    private const KINDS = [
        'oura.had_sleep_score' => ['pattern' => 'ring', 'format' => 'score', 'max' => 100, 'glyph' => 'fas.moon', 'label' => 'Sleep score'],
        'oura.had_readiness_score' => ['pattern' => 'ring', 'format' => 'score', 'max' => 100, 'glyph' => 'fas.gauge-high', 'label' => 'Readiness'],
        'oura.had_activity_score' => ['pattern' => 'ring', 'format' => 'score', 'max' => 100, 'glyph' => 'fas.person-running', 'label' => 'Activity score'],
        'oura.slept_for' => ['pattern' => 'value', 'format' => 'duration', 'glyph' => 'fas.moon', 'label' => 'Sleep'],
        'apple_health.had_step_count' => ['pattern' => 'value', 'format' => 'count', 'glyph' => 'fas.person-walking', 'label' => 'Steps'],
        'apple_health.had_apple_exercise_time' => ['pattern' => 'value', 'format' => 'duration', 'glyph' => 'fas.clock', 'label' => 'Exercise'],
    ];

    /**
     * @var array<string, string>
     */
    private const DOMAIN_GLYPHS = [
        'health' => 'fas.heart-pulse',
        'activity' => 'fas.person-running',
        'money' => 'fas.sterling-sign',
        'media' => 'fas.circle-play',
        'knowledge' => 'fas.book',
        'online' => 'fas.globe',
    ];

    /**
     * The layout for a kind, or the generic value card named after the
     * action's own display name.
     *
     * @return array{pattern: string, format: string, glyph: string, label: string, max: int|null}
     */
    public static function for(string $kind, ?string $domain, ?string $displayName): array
    {
        $layout = self::KINDS[$kind] ?? null;

        if ($layout !== null) {
            return $layout + ['max' => null];
        }

        return [
            'pattern' => 'value',
            'format' => 'display',
            'glyph' => self::DOMAIN_GLYPHS[$domain] ?? 'fas.circle-dot',
            'label' => $displayName ?: 'Event',
            'max' => null,
        ];
    }

    /**
     * The hero text for a value in a kind's format. A missing value is "—",
     * never zero.
     *
     * @param  array{format: string}  $layout
     */
    public static function heroValue(array $layout, mixed $value, ?string $unit, ?string $displayValue): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        $number = (float) $value;

        return match ($layout['format']) {
            'score' => (string) (int) round($number),
            'count' => number_format($number),
            'duration' => self::duration(self::seconds($number, $unit)),
            default => trim(preg_replace('/\s+/', ' ', strip_tags((string) $displayValue)) ?? '') ?: number_format($number, floor($number) === $number ? 0 : 1),
        };
    }

    /**
     * "7h 55m", "42m" or "1m 05s".
     */
    public static function duration(float $seconds): string
    {
        $seconds = (int) round(max(0, $seconds));

        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);
            $remainder = $seconds % 60;

            return $minutes === 0
                ? "{$remainder}s"
                : ($remainder === 0 || $minutes >= 10 ? "{$minutes}m" : sprintf('%dm %02ds', $minutes, $remainder));
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $minutes === 0 ? "{$hours}h" : "{$hours}h {$minutes}m";
    }

    private static function seconds(float $value, ?string $unit): float
    {
        return match (strtolower((string) $unit)) {
            'ms', 'millisecond', 'milliseconds' => $value / 1000,
            'm', 'min', 'mins', 'minute', 'minutes' => $value * 60,
            'h', 'hr', 'hrs', 'hour', 'hours' => $value * 3600,
            default => $value,
        };
    }

    public static function isRegistered(string $kind): bool
    {
        return isset(self::KINDS[$kind]);
    }
}
