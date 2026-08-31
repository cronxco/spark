<?php

namespace App\Services\Flint\Routines;

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

    public function driverName(string $routine): string
    {
        $configured = config("services.flint_routine.routines.{$routine}.driver")
            ?: config('services.flint_routine.driver', 'webhook');

        return is_string($configured) && $configured !== '' ? $configured : 'webhook';
    }

    public function for(string $routine): RoutineDriver
    {
        $name = $this->driverName($routine);

        if (! isset(self::DRIVERS[$name])) {
            throw new RuntimeException("Unknown Flint routine driver: {$name}");
        }

        return app(self::DRIVERS[$name]);
    }
}
