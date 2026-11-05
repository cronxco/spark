<?php

namespace App\Support;

use App\Models\Event;

/**
 * A flight, read from the calendar entry a flight tracker wrote for it.
 *
 * Flighty (and trackers like it) write each flight into the calendar as
 * "✈ LGW→ACE • BA 2704", with a description that gives the cities, the local
 * departure and arrival times and the flight time:
 *
 *     British Airways 2704
 *     London to Lanzarote
 *     ↗ 8:10 GMT
 *     ↘ 12:25 WET
 *     Flight time 4 hrs, 15 min
 *
 * The title alone is enough to recognise a flight: two airport codes joined
 * by an arrow. Everything else is optional and left null when missing.
 */
final class CalendarFlight
{
    /**
     * @return array{flight: string|null, from: string, to: string, route: string|null, departs_at: string|null, arrives_at: string|null, departs_local: string|null, departs_zone: string|null, arrives_local: string|null, arrives_zone: string|null, duration_minutes: int|null}|null
     */
    public static function from(Event $event): ?array
    {
        if ($event->action !== 'had_event') {
            return null;
        }

        $title = self::clean((string) ($event->target?->title ?? ''));

        if (preg_match('/([A-Z]{3})\s*(?:→|->|–|—|to)\s*([A-Z]{3})\b/u', $title, $codes) !== 1) {
            return null;
        }

        if (! str_contains($title, '✈') && ! str_contains($title, '→')) {
            return null;
        }

        $content = self::clean((string) ($event->target?->content ?? ''));

        $flight = preg_match('/(?:•|\|)\s*([A-Z0-9]{2})\s?(\d{1,4})\b/u', $title, $number) === 1
            ? $number[1] . $number[2]
            : null;

        $departs = self::clock($content, '↗');
        $arrives = self::clock($content, '↘');
        $duration = self::duration($event, $content);

        $route = null;
        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            if (preg_match('/^\s*(\p{L}[\p{L} .\'-]*?)\s+to\s+(\p{L}[\p{L} .\'-]*?)\s*$/u', $line, $places) === 1) {
                $route = "{$places[1]} to {$places[2]}";
                break;
            }
        }

        return [
            'flight' => $flight,
            'from' => $codes[1],
            'to' => $codes[2],
            'route' => $route,
            'departs_at' => $event->time?->toIso8601String(),
            'arrives_at' => $event->time && $duration ? $event->time->copy()->addMinutes($duration)->toIso8601String() : null,
            'departs_local' => $departs['time'] ?? null,
            'departs_zone' => $departs['zone'] ?? null,
            'arrives_local' => $arrives['time'] ?? null,
            'arrives_zone' => $arrives['zone'] ?? null,
            'duration_minutes' => $duration,
        ];
    }

    /**
     * Zero-width spaces and no-break spaces become plain spaces, so the
     * patterns above don't need to know about them.
     */
    private static function clean(string $text): string
    {
        return trim((string) preg_replace(['/[\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]/u', '/[\x{00A0}\x{202F}]/u'], ['', ' '], $text));
    }

    /**
     * "↗ 8:10 GMT" → 08:10, GMT.
     *
     * @return array{time: string, zone: string|null}|null
     */
    private static function clock(string $content, string $marker): ?array
    {
        if (preg_match('/' . preg_quote($marker, '/') . '\s*(\d{1,2}):(\d{2})\s*([A-Z]{2,5})?/u', $content, $match) !== 1) {
            return null;
        }

        return [
            'time' => sprintf('%02d:%s', (int) $match[1], $match[2]),
            'zone' => ($match[3] ?? '') !== '' ? $match[3] : null,
        ];
    }

    /**
     * Minutes in the air: "Flight time 4 hrs, 15 min" when the tracker says,
     * otherwise the calendar entry's own length.
     */
    private static function duration(Event $event, string $content): ?int
    {
        if (preg_match('/Flight time\s*(?:(\d+)\s*h\w*)?[,\s]*(?:(\d+)\s*m\w*)?/iu', $content, $match) === 1
            && (($match[1] ?? '') !== '' || ($match[2] ?? '') !== '')) {
            return (int) ($match[1] ?? 0) * 60 + (int) ($match[2] ?? 0);
        }

        if (is_numeric($event->formatted_value) && $event->value_unit === 'minutes') {
            return (int) round((float) $event->formatted_value);
        }

        return null;
    }
}
