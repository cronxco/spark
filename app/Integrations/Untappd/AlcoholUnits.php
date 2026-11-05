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

    /**
     * @param  int|null  $servingMl  Exact volume of one serving, when the user said it (e.g. half a pint = 284)
     * @param  float  $quantity  Number of servings drunk
     */
    public static function estimate(float|int|string|null $abv, ?string $servingStyle, ?int $servingMl = null, float $quantity = 1.0): ?array
    {
        if (! is_numeric($abv) || (float) $abv <= 0) {
            return null;
        }

        $style = strtolower(trim((string) $servingStyle));
        $known = $servingMl !== null || isset(self::SERVING_ML[$style]);
        $millilitres = $servingMl ?? self::SERVING_ML[$style] ?? self::DEFAULT_ML;
        $quantity = $quantity > 0 ? $quantity : 1.0;

        return [
            'alcohol_units' => round((float) $abv * $millilitres * $quantity / 1000, 1),
            'alcohol_units_basis' => [
                'abv' => (float) $abv,
                'serving_ml' => $millilitres,
                'serving_ml_assumed' => ! $known,
            ] + ($quantity !== 1.0 ? ['quantity' => $quantity] : []),
        ];
    }

    /**
     * Store the estimate on a check-in event when its beer's ABV is known.
     */
    public static function applyTo(Event $event): void
    {
        $metadata = $event->event_metadata ?? [];
        $estimate = self::estimate(
            $event->target?->metadata['abv'] ?? null,
            $metadata['serving_style'] ?? null,
            isset($metadata['serving_ml']) ? (int) $metadata['serving_ml'] : null,
            (float) ($metadata['quantity'] ?? 1),
        );
        if (! $estimate) {
            return;
        }

        $event->update(['event_metadata' => array_merge($metadata, $estimate)]);
    }
}
