<?php

namespace App\Services\Flint\Routines;

use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves which driver runs a given routine.
 *
 * A routine may name its own driver; otherwise the top-level default applies,
 * which stays "webhook" so that merging this changes no behaviour until an env
 * var is flipped.
 */
class RoutineDriverManager
{
    public const DRIVERS = ['webhook' => WebhookRoutineDriver::class, 'openai' => OpenAiRoutineDriver::class];

    public function driverName(string $routine, ?string $override = null): string
    {
        if ($override !== null) {
            if (! isset(self::DRIVERS[$override])) {
                throw new InvalidArgumentException("Unknown Flint routine driver: {$override}");
            }

            return $override;
        }

        $configured = config("services.flint_routine.routines.{$routine}.driver")
            ?: config('services.flint_routine.driver', 'webhook');

        return is_string($configured) && $configured !== '' ? $configured : 'webhook';
    }

    public function for(string $routine, ?string $override = null): RoutineDriver
    {
        $name = $this->driverName($routine, $override);

        if (! isset(self::DRIVERS[$name])) {
            throw new RuntimeException("Unknown Flint routine driver: {$name}");
        }

        return app(self::DRIVERS[$name]);
    }
}
