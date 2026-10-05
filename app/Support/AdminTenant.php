<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Whose data the admin pages show (decision D-API-2).
 *
 * Admin stays tenant-local by default: every page is scoped to the signed-in
 * admin. A global operator (an admin listed in spark.admin.global_operators)
 * can step into one other user's data for a limited time, after confirming
 * their password and giving a reason. Starting, ending and every admin page
 * viewed in that context go to the `security` activity log, which is kept
 * for a year. Nothing is stored outside the session and the activity log.
 */
final class AdminTenant
{
    public const SESSION_KEY = 'admin.operator_context';

    /** The user id admin pages are scoped to right now. */
    public static function id(): int|string|null
    {
        return self::context()['user_id'] ?? Auth::id();
    }

    /**
     * The active operator context, or null when the admin is working in their
     * own data.
     *
     * @return array{user_id: int|string, reason: string, expires_at: string}|null
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

    public static function hasRecentlyConfirmedPassword(): bool
    {
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        return $confirmedAt > 0 && time() - $confirmedAt <= (int) config('spark.admin.operator_reauth_minutes', 15) * 60;
    }

    /**
     * @throws InvalidArgumentException when the operator may not start this context.
     */
    public static function start(User $operator, User $target, string $reason): void
    {
        if (! self::isGlobalOperator($operator)) {
            throw new InvalidArgumentException('Only a global operator can view another user\'s data.');
        }

        if (! self::hasRecentlyConfirmedPassword()) {
            throw new InvalidArgumentException('Confirm your password again before viewing another user\'s data.');
        }

        if ($operator->is($target)) {
            throw new InvalidArgumentException('You are already viewing your own data.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new InvalidArgumentException('Give a reason of at least 10 characters.');
        }

        self::end('replaced');

        $expiresAt = now()->addMinutes((int) config('spark.admin.operator_context_minutes', 30));
        session([self::SESSION_KEY => [
            'user_id' => $target->getKey(),
            'reason' => $reason,
            'expires_at' => $expiresAt->toJSON(),
        ]]);

        self::audit('operator_context_started', $operator, $target, ['reason' => $reason, 'expires_at' => $expiresAt->toJSON()]);
    }

    public static function end(string $why = 'ended'): void
    {
        $context = session(self::SESSION_KEY);
        session()->forget(self::SESSION_KEY);

        $operator = Auth::user();
        if (! is_array($context) || ! $operator instanceof User) {
            return;
        }

        $target = User::find($context['user_id']);
        if ($target !== null) {
            self::audit('operator_context_ended', $operator, $target, ['reason' => $context['reason'], 'ended_because' => $why]);
        }
    }

    /**
     * Record an admin page viewed inside an operator context.
     */
    public static function recordAccess(string $page): void
    {
        $context = self::context();
        $operator = Auth::user();
        $target = $context ? User::find($context['user_id']) : null;

        if ($operator instanceof User && $target !== null) {
            self::audit('operator_context_viewed', $operator, $target, ['reason' => $context['reason'], 'page' => $page]);
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function audit(string $description, User $operator, User $target, array $properties): void
    {
        activity('security')
            ->causedBy($operator)
            ->performedOn($target)
            ->event($description)
            ->withProperties([...$properties, 'ip' => request()->ip(), 'user_agent' => (string) request()->userAgent()])
            ->log($description);
    }
}
