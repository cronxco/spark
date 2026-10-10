<?php

namespace App\Integrations\Untappd;

use App\Models\Event;

/**
 * UK alcohol units for a check-in: ABV% × volume in ml / 1000.
 *
 * Untappd doesn't record the volume drunk, so it's estimated from the serving style.
 */
class AlcoholUnits
{
    /** Typical UK serving volumes in ml. */
    public const SERVING_ML = [
        'draft' => 568,
        'cask' => 568,
        'can' => 440,
        'bottle' => 330,
        'crowler' => 946,
        'growler' => 1893,
        'taster' => 150,
    ];

    /** Used when the serving style is unknown or unrecognised. */
    public const DEFAULT_ML = 440;

    public static function estimate(float|int|string|null $abv, ?string $servingStyle): ?array
    {
        if (! is_numeric($abv) || (float) $abv <= 0) {
            return null;
        }

        $style = strtolower(trim((string) $servingStyle));
        $millilitres = self::SERVING_ML[$style] ?? self::DEFAULT_ML;

        return [
            'alcohol_units' => round((float) $abv * $millilitres / 1000, 1),
            'alcohol_units_basis' => [
                'abv' => (float) $abv,
                'serving_ml' => $millilitres,
                'serving_ml_assumed' => ! isset(self::SERVING_ML[$style]),
            ],
        ];
    }

    /**
     * Store the estimate on a check-in event when its beer's ABV is known.
     */
    public static function applyTo(Event $event): void
    {
        $metadata = $event->event_metadata ?? [];
        $estimate = self::estimate($event->target?->metadata['abv'] ?? null, $metadata['serving_style'] ?? null);
        if (! $estimate) {
            return;
        }

        $event->update(['event_metadata' => array_merge($metadata, $estimate)]);
    }
}
