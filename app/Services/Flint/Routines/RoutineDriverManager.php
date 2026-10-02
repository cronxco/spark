<?php

namespace App\Services\Flint\Routines;

use App\Models\User;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves which driver runs a given routine.
 *
 * The choice is made from the Flint settings tab: a routine may name its own
 * driver, otherwise the user's default applies. Env values are only the
 * fallback when the user has not picked one, and that fallback stays
 * "webhook" so an unconfigured install changes no behaviour.
 */
class RoutineDriverManager
{
    public const DRIVERS = ['webhook' => WebhookRoutineDriver::class, 'openai' => OpenAiRoutineDriver::class];

    /** The routines a driver can be chosen for, keyed as in services.flint_routine.routines. */
    public const ROUTINES = ['digest', 'topics', 'reading_list', 'news_roundup'];

    public function driverName(string $routine, ?string $override = null, ?User $user = null): string
    {
        if ($override !== null) {
            if (! isset(self::DRIVERS[$override])) {
                throw new InvalidArgumentException("Unknown Flint routine driver: {$override}");
            }

            return $override;
        }

        $chosen = $this->userDriver($user, $routine) ?? $this->userDriver($user, null);
        if ($chosen !== null) {
            return $chosen;
        }

        return $this->configuredDriver($routine);
    }

    /**
     * The driver a routine falls back to when the user has picked none: the
     * per-routine env value, then the env default, then "webhook".
     */
    public function configuredDriver(?string $routine = null): string
    {
        $configured = ($routine !== null ? config("services.flint_routine.routines.{$routine}.driver") : null)
            ?: config('services.flint_routine.driver', 'webhook');

        return is_string($configured) && $configured !== '' ? $configured : 'webhook';
    }

    public function for(string $routine, ?string $override = null, ?User $user = null): RoutineDriver
    {
        $name = $this->driverName($routine, $override, $user);

        if (! isset(self::DRIVERS[$name])) {
            throw new RuntimeException("Unknown Flint routine driver: {$name}");
        }

        return app(self::DRIVERS[$name]);
    }

    /**
     * A driver stored in the user's Flint settings, or null when unset or no
     * longer valid. A null routine reads the user's default driver.
     */
    private function userDriver(?User $user, ?string $routine): ?string
    {
        $settings = $user?->settings['flint'] ?? [];
        $value = $routine === null ? ($settings['driver'] ?? null) : ($settings['drivers'][$routine] ?? null);

        return is_string($value) && isset(self::DRIVERS[$value]) ? $value : null;
    }
}
