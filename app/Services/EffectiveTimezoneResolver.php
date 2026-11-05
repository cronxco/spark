<?php

namespace App\Services;

use App\Integrations\DailyCheckin\DailyCheckinPlugin;
use App\Models\User;
use Carbon\Carbon;
use Throwable;

/**
 * Resolves a user's effective timezone — the latest acknowledged "time travel"
 * timezone (CRX-723), falling back to the profile timezone and finally UTC.
 *
 * Wraps {@see DailyCheckinPlugin::resolveEffectiveTimezone()} with per-process
 * memoization so the rewired time helpers don't issue a query on every call.
 * Registered as a singleton so the memo lives for the lifetime of the request
 * or queue worker, and is naturally fresh between tests.
 *
 * Precedence: request context (explicit) → acknowledged time_travel → users.timezone → UTC.
 */
class EffectiveTimezoneResolver
{
    /** @var array<string, string> Memoized [userId => IANA timezone]. */
    private array $memo = [];

    /** @var array<string, string> Memoized [userId@unixTimestamp => IANA timezone] for point-in-time lookups. */
    private array $pointInTimeMemo = [];

    public function __construct(private DailyCheckinPlugin $plugin) {}

    /**
     * The user's effective IANA timezone identifier, or 'UTC' when no user.
     */
    public function timezoneFor(?User $user): string
    {
        if ($user === null) {
            return 'UTC';
        }

        $key = (string) $user->id;

        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->plugin->resolveEffectiveTimezone($user)['timezone'] ?? 'UTC';
        }

        return $this->memo[$key];
    }

    /**
     * The user's effective IANA timezone identifier as of a given instant — the
     * timezone acknowledged at-or-before `$instant`, falling back to the profile
     * timezone and finally UTC. Used to render a historical day in the timezone
     * that was in effect on that date.
     *
     * For the present instant this returns the same value as {@see timezoneFor()};
     * it only diverges when an acknowledgement was made after `$instant`.
     */
    public function timezoneForAt(?User $user, Carbon $instant): string
    {
        if ($user === null) {
            return 'UTC';
        }

        $key = $user->id . '@' . $instant->getTimestamp();

        if (! array_key_exists($key, $this->pointInTimeMemo)) {
            $event = $this->plugin->getLatestTimezoneEventAt($user->id, $instant);
            $this->pointInTimeMemo[$key] = ($event?->event_metadata['timezone'] ?? null) ?? $user->getTimezone();
        }

        return $this->pointInTimeMemo[$key];
    }

    /**
     * The IANA timezone a given local calendar date belongs to.
     *
     * The current and future days always use the live effective timezone, so
     * "today" never regresses (CRX-682). Past days use point-in-time resolution —
     * the timezone that was acknowledged on that date — probed at noon UTC of the
     * target date to break the tz/day-boundary circularity at extreme offsets.
     *
     * @param  string  $date  Local calendar date (Y-m-d)
     */
    public function timezoneForDate(?User $user, string $date): string
    {
        if ($user === null) {
            return 'UTC';
        }

        try {
            $isPast = $date < $this->today($user)->toDateString();
            $probe = Carbon::parse($date, 'UTC')->setTime(12, 0);
        } catch (Throwable) {
            return $this->timezoneFor($user);
        }

        if (! $isPast) {
            return $this->timezoneFor($user);
        }

        return $this->timezoneForAt($user, $probe);
    }

    /**
     * The current time in the user's effective timezone.
     */
    public function now(?User $user): Carbon
    {
        return Carbon::now($this->timezoneFor($user));
    }

    /**
     * Today (midnight) in the user's effective timezone.
     */
    public function today(?User $user): Carbon
    {
        return Carbon::today($this->timezoneFor($user));
    }

    /**
     * Convert a datetime into the user's effective timezone. Returns the value
     * unchanged when no user is provided.
     */
    public function convert(Carbon $datetime, ?User $user): Carbon
    {
        if ($user === null) {
            return $datetime;
        }

        return $datetime->copy()->setTimezone($this->timezoneFor($user));
    }

    /**
     * Forget memoized timezone(s). Pass a user to bust a single entry, or null
     * to clear everything (e.g. after acknowledging a new timezone in a test).
     */
    public function forget(?User $user = null): void
    {
        if ($user === null) {
            $this->memo = [];
            $this->pointInTimeMemo = [];

            return;
        }

        unset($this->memo[(string) $user->id]);

        foreach (array_keys($this->pointInTimeMemo) as $key) {
            if (str_starts_with($key, $user->id . '@')) {
                unset($this->pointInTimeMemo[$key]);
            }
        }
    }
}
