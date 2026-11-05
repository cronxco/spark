<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Whose data the admin pages show.
 *
 * Admin pages are scoped to the signed-in admin by default. A global operator
 * (an admin listed in spark.admin.global_operators) can switch on God Mode,
 * which makes every admin page show everything across all users. Spark signs
 * users in through Authelia, so God Mode is switched on by a fresh Authelia
 * sign-in rather than a password. It expires with a time limit, and enabling,
 * disabling, expiring and every admin page viewed or bulk action run while it
 * is on go to the `security` activity log. Nothing is stored outside the
 * session and the activity log.
 */
final class AdminTenant
{
    public const SESSION_KEY = 'admin.god_mode';

    public const PENDING_KEY = 'admin.god_mode_pending';

    /**
     * The user id admin pages are scoped to right now, or null in God Mode,
     * where no user filter applies. A call site that still filters on this
     * value returns no rows in God Mode rather than leaking another user's.
     */
    public static function id(): int|string|null
    {
        return self::isGodMode() ? null : Auth::id();
    }

    public static function isGodMode(): bool
    {
        return self::context() !== null;
    }

    /**
     * Limit a query to the admin's own rows, or leave it untouched in God Mode.
     *
     * @template TBuilder of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Relations\Relation
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function scope($query, string $column = 'user_id')
    {
        return self::isGodMode() ? $query : $query->where($column, Auth::id());
    }

    /**
     * Like scope(), for models that expose a forUser() scope.
     */
    public static function scopeForUser(Builder $query): Builder
    {
        return self::isGodMode() ? $query : $query->forUser(Auth::id());
    }

    /**
     * scope() as a callable, for whereHas() and tap().
     */
    public static function scoped(string $column = 'user_id'): Closure
    {
        return static fn ($query) => self::scope($query, $column);
    }

    /**
     * scopeForUser() as a callable, for tap().
     */
    public static function scopedForUser(): Closure
    {
        return static fn (Builder $query): Builder => self::scopeForUser($query);
    }

    /**
     * The active God Mode context, or null when the admin is working in their
     * own data.
     *
     * @return array{started_at: string, expires_at: string}|null
     */
    public static function context(): ?array
    {
        $context = session(self::SESSION_KEY);
        $operator = Auth::user();

        if (! is_array($context) || ! $operator instanceof User) {
            return null;
        }

        if (! self::isGlobalOperator($operator) || CarbonImmutable::parse($context['expires_at'])->isPast()) {
            self::end('expired');

            return null;
        }

        return $context;
    }

    public static function isGlobalOperator(User $user): bool
    {
        return $user->is_admin
            && in_array((string) $user->getKey(), array_map('strval', (array) config('spark.admin.global_operators', [])), true);
    }

    /**
     * Remember that the operator asked for God Mode, so the Authelia callback
     * knows a fresh sign-in is for this purpose.
     *
     * @throws InvalidArgumentException when the user may not use God Mode.
     */
    public static function requestReauthentication(User $operator): void
    {
        if (! self::isGlobalOperator($operator)) {
            throw new InvalidArgumentException('Only a global operator can use God Mode.');
        }

        session([self::PENDING_KEY => ['user_id' => (string) $operator->getKey(), 'expires_at' => now()->addMinutes(5)->toJSON()]]);
    }

    /**
     * Whether the sign-in that just completed for this user was started from
     * the God Mode page. Consumes the request either way.
     */
    public static function pullReauthenticationRequest(User $signedIn): bool
    {
        $pending = session()->pull(self::PENDING_KEY);

        return is_array($pending)
            && $pending['user_id'] === (string) $signedIn->getKey()
            && CarbonImmutable::parse($pending['expires_at'])->isFuture();
    }

    /**
     * @throws InvalidArgumentException when the operator may not enable God Mode.
     */
    public static function start(User $operator): void
    {
        if (! self::isGlobalOperator($operator)) {
            throw new InvalidArgumentException('Only a global operator can use God Mode.');
        }

        $now = now();
        $expiresAt = $now->copy()->addMinutes((int) config('spark.admin.god_mode_minutes', 30));
        session([self::SESSION_KEY => [
            'started_at' => $now->toJSON(),
            'expires_at' => $expiresAt->toJSON(),
        ]]);

        self::audit('god_mode_enabled', $operator, ['expires_at' => $expiresAt->toJSON()]);
    }

    public static function end(string $why = 'ended'): void
    {
        $context = session(self::SESSION_KEY);
        session()->forget(self::SESSION_KEY);

        $operator = Auth::user();
        if (is_array($context) && $operator instanceof User) {
            self::audit('god_mode_disabled', $operator, ['ended_because' => $why]);
        }
    }

    /**
     * Record an admin page viewed in God Mode.
     */
    public static function recordAccess(string $page): void
    {
        $operator = Auth::user();

        if ($operator instanceof User && self::isGodMode()) {
            self::audit('god_mode_viewed', $operator, ['page' => $page]);
        }
    }

    /**
     * Record a bulk or destructive admin action run in God Mode.
     *
     * @param  array<string, mixed>  $properties
     * @param  bool|null  $godMode  God Mode state captured by the caller, so the audit matches the work done
     */
    public static function recordAction(string $action, array $properties = [], ?bool $godMode = null): void
    {
        $operator = Auth::user();

        if ($operator instanceof User && ($godMode ?? self::isGodMode())) {
            self::audit('god_mode_action', $operator, [...$properties, 'action' => $action]);
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function audit(string $description, User $operator, array $properties): void
    {
        activity('security')
            ->causedBy($operator)
            ->event($description)
            ->withProperties([...$properties, 'ip' => request()->ip(), 'user_agent' => (string) request()->userAgent()])
            ->log($description);
    }
}
