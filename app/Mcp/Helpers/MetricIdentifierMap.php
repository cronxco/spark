<?php

namespace App\Mcp\Helpers;

use App\Models\MetricStatistic;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class MetricIdentifierMap
{
    /**
     * Resolve a flexible metric identifier to a MetricStatistic.
     *
     * Accepts any of these formats:
     *   - "oura.had_sleep_score.percent"  (exact canonical)
     *   - "oura.had_sleep_score"          (omit unit — works if unambiguous)
     *   - "oura.sleep_score.percent"      (omit had_ prefix)
     *   - "oura.sleep_score"              (omit both)
     *
     * Resolution order:
     *   1. Exact match on service + action (+ unit if given)
     *   2. Prepend "had_" to action and retry
     *   3. If unit omitted and exactly one match, use it; if ambiguous, error
     */
    public static function resolve(string $identifier, User $user): ?MetricStatistic
    {
        $parts = explode('.', $identifier);

        if (count($parts) < 2) {
            return null;
        }

        $service = $parts[0];
        $action = $parts[1];
        $valueUnit = $parts[2] ?? null;

        // Try exact action first, then with had_ prefix
        $candidates = self::findCandidates($user, $service, $action, $valueUnit);

        if ($candidates->isEmpty() && ! str_starts_with($action, 'had_')) {
            $candidates = self::findCandidates($user, $service, 'had_' . $action, $valueUnit);
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        if ($candidates->count() > 1 && $valueUnit === null) {
            // Ambiguous — multiple units for this service+action
            return null;
        }

        return $candidates->first();
    }

    /**
     * Resolve and return the service/action/value_unit tuple (for tools that need the mapping without the model).
     *
     * @return array{service: string, action: string, value_unit: string}|null
     */
    public static function resolveMapping(string $identifier, User $user): ?array
    {
        $stat = self::resolve($identifier, $user);

        if (! $stat) {
            return null;
        }

        return [
            'service' => $stat->service,
            'action' => $stat->action,
            'value_unit' => $stat->value_unit,
        ];
    }

    /**
     * Resolve multiple identifiers, returning only those that matched.
     *
     * @param  array<string>  $identifiers
     * @return array<string, MetricStatistic>
     */
    public static function resolveMany(array $identifiers, User $user): array
    {
        $resolved = [];

        foreach ($identifiers as $identifier) {
            $stat = self::resolve($identifier, $user);
            if ($stat) {
                $resolved[$identifier] = $stat;
            }
        }

        return $resolved;
    }

    /**
     * Build an error hint showing available metrics for a service.
     */
    public static function availableForService(string $service, User $user): string
    {
        $stats = MetricStatistic::where('user_id', $user->id)
            ->where('service', $service)
            ->orderBy('action')
            ->get();

        if ($stats->isEmpty()) {
            return "No metrics found for service \"{$service}\".";
        }

        $identifiers = $stats->map(fn ($s) => $s->getIdentifier())->implode(', ');

        return "Available metrics for \"{$service}\": {$identifiers}";
    }

    /**
     * List all available metric identifiers for a user.
     *
     * @return array<string>
     */
    public static function availableIdentifiers(User $user): array
    {
        return MetricStatistic::where('user_id', $user->id)
            ->orderBy('service')
            ->orderBy('action')
            ->get()
            ->map(fn ($s) => $s->getIdentifier())
            ->all();
    }

    /**
     * Query MetricStatistic candidates matching the given parts.
     *
     * @return Collection<int, MetricStatistic>
     */
    protected static function findCandidates(
        User $user,
        string $service,
        string $action,
        ?string $valueUnit
    ): Collection {
        $query = MetricStatistic::where('user_id', $user->id)
            ->where('service', $service)
            ->where('action', $action);

        if ($valueUnit !== null) {
            $query->where('value_unit', $valueUnit);
        }

        return $query->get();
    }
}
