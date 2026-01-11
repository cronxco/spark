<?php

namespace App\Integrations\Oyster;

class OysterTransportModeDetector
{
    public const MODE_TUBE = 'london_underground';

    public const MODE_DLR = 'dlr';

    public const MODE_OVERGROUND = 'london_overground';

    public const MODE_ELIZABETH = 'elizabeth_line';

    public const MODE_TRAM = 'tram';

    public const MODE_NATIONAL_RAIL = 'national_rail';

    public const MODE_BUS = 'bus';

    public const MODE_CABLE_CAR = 'cable_car';

    public const MODE_RIVER_BUS = 'river_bus';

    public const MODE_UNKNOWN = 'unknown';

    /**
     * Get display name for a transport mode
     */
    public static function getDisplayName(string $mode): string
    {
        return match ($mode) {
            self::MODE_TUBE => 'London Underground',
            self::MODE_DLR => 'DLR',
            self::MODE_OVERGROUND => 'London Overground',
            self::MODE_ELIZABETH => 'Elizabeth line',
            self::MODE_TRAM => 'Tram',
            self::MODE_NATIONAL_RAIL => 'National Rail',
            self::MODE_BUS => 'Bus',
            self::MODE_CABLE_CAR => 'Cable Car',
            self::MODE_RIVER_BUS => 'River Bus',
            default => 'Unknown',
        };
    }

    /**
     * Detection algorithm for transport mode from journey text
     *
     * Order of detection (most specific first):
     * 1. "[Elizabeth line]" annotation
     * 2. "[London Overground" annotation
     * 3. "DLR" in text (must check before National Rail)
     * 4. "[National Rail]" annotation
     * 5. "[London Underground" annotation
     * 6. "tram stop" in text
     * 7. Bus route pattern
     * 8. Cable car / Emirates Air Line
     * 9. River bus
     * 10. Default: London Underground (if has from/to pattern)
     */
    public function detectMode(string $journeyText): string
    {
        $text = strtolower($journeyText);

        // Elizabeth line detection
        if (str_contains($text, '[elizabeth line]')) {
            return self::MODE_ELIZABETH;
        }

        // London Overground detection
        if (str_contains($text, '[london overground')) {
            return self::MODE_OVERGROUND;
        }

        // DLR detection (must check before National Rail since DLR stations often have "[DLR/National Rail]")
        if (str_contains($text, 'dlr')) {
            return self::MODE_DLR;
        }

        // National Rail detection
        if (str_contains($text, '[national rail]')) {
            return self::MODE_NATIONAL_RAIL;
        }

        // London Underground explicit annotation
        if (str_contains($text, '[london underground')) {
            return self::MODE_TUBE;
        }

        // Tram detection
        if (str_contains($text, 'tram stop')) {
            return self::MODE_TRAM;
        }

        // Bus detection (entry only, starts with route number)
        if (preg_match('/^bus route \d+/i', $journeyText)) {
            return self::MODE_BUS;
        }

        // Cable car / Emirates Air Line
        if (str_contains($text, 'emirates') || str_contains($text, 'cable car') || str_contains($text, 'royal docks') || str_contains($text, 'greenwich peninsula')) {
            return self::MODE_CABLE_CAR;
        }

        // River bus
        if (str_contains($text, 'pier') || str_contains($text, 'river bus')) {
            return self::MODE_RIVER_BUS;
        }

        // Default to Tube if it has a "to" pattern (journey with origin/destination)
        if (str_contains($text, ' to ')) {
            return self::MODE_TUBE;
        }

        return self::MODE_UNKNOWN;
    }

    /**
     * Parse journey text to extract origin and destination stations
     *
     * Patterns:
     * - "Entered {station} tram stop" -> origin only (tap-on)
     * - "{from} [National Rail] to {to} [National Rail]"
     * - "{from} to {to}"
     * - "{from} [London Underground/...] to {to}"
     */
    public function parseStations(string $journeyText): array
    {
        $text = trim($journeyText);

        // Tram: "Entered {station} tram stop"
        if (preg_match('/^Entered (.+?) tram stop$/i', $text, $matches)) {
            return [
                'origin' => trim($matches[1]),
                'destination' => null,
                'mode' => self::MODE_TRAM,
            ];
        }

        // Get the mode first (before cleaning annotations)
        $mode = $this->detectMode($text);

        // Remove mode annotations for station extraction
        $cleanText = preg_replace('/\s*\[.*?\]\s*/', ' ', $text);
        $cleanText = preg_replace('/\s+/', ' ', trim($cleanText));

        // Parse "{from} to {to}"
        if (preg_match('/^(.+?)\s+to\s+(.+)$/i', $cleanText, $matches)) {
            return [
                'origin' => $this->cleanStationName(trim($matches[1])),
                'destination' => $this->cleanStationName(trim($matches[2])),
                'mode' => $mode,
            ];
        }

        return [
            'origin' => null,
            'destination' => null,
            'mode' => $mode,
        ];
    }

    /**
     * Check if a journey action represents a non-journey entry (top-up, season ticket, etc.)
     */
    public function isNonJourney(string $journeyText): bool
    {
        $text = strtolower($journeyText);

        return str_contains($text, 'topped-up') ||
            str_contains($text, 'season ticket') ||
            str_contains($text, 'auto top-up') ||
            str_contains($text, 'refund') ||
            str_contains($text, 'fare adjustment');
    }

    /**
     * Determine the type of non-journey entry
     */
    public function getNonJourneyType(string $journeyText): ?string
    {
        $text = strtolower($journeyText);

        if (str_contains($text, 'topped-up') || str_contains($text, 'auto top-up')) {
            return 'topped_up_balance';
        }

        if (str_contains($text, 'season ticket')) {
            return 'added_season_ticket';
        }

        if (str_contains($text, 'refund')) {
            return 'received_refund';
        }

        if (str_contains($text, 'fare adjustment')) {
            return 'fare_adjustment';
        }

        return null;
    }

    /**
     * Clean up station name by removing common suffixes and annotations
     */
    private function cleanStationName(string $name): string
    {
        // Remove trailing parenthetical info like "(platforms 9-19)"
        $name = preg_replace('/\s*\(.*?\)\s*$/', '', $name);

        // Remove trailing transport mode suffixes
        $name = preg_replace('/\s+(DLR|Elizabeth line)$/i', '', $name);

        return trim($name);
    }
}
