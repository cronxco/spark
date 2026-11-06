<?php

namespace App\Services\Metrics;

use App\Models\Event;
use App\Models\MetricStatistic;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Money is judged as spend, never as single transactions or balances: the
 * total paid out per day, built from the payment events of the configured
 * services. It is exposed as a metric of its own (`spend.spent`) so baselines
 * and insights treat it like any other daily total.
 */
class SpendSeries
{
    public const SERVICE = 'spend';

    public const ACTION = 'spent';

    public static function isSpend(MetricStatistic $statistic): bool
    {
        return $statistic->service === self::SERVICE && $statistic->action === self::ACTION;
    }

    /**
     * The user's spend metric row, created on first use.
     */
    public static function statisticFor(User $user): MetricStatistic
    {
        return MetricStatistic::firstOrCreate([
            'user_id' => $user->id,
            'service' => self::SERVICE,
            'action' => self::ACTION,
            'value_unit' => (string) config('metrics.spend.currency', 'GBP'),
        ]);
    }

    /**
     * Whether the user has any payments to build a spend metric from.
     */
    public function hasSpend(string $userId): bool
    {
        return Event::query()
            ->withoutInternal()
            ->whereHas('integration', fn ($q) => $q->where('user_id', $userId))
            ->whereIn('service', config('metrics.spend.services', []))
            ->whereIn('action', config('metrics.spend.actions', []))
            ->where('value_unit', config('metrics.spend.currency', 'GBP'))
            ->exists();
    }

    /**
     * Money out per day, in the configured currency, with days that had no
     * payments counted as zero. Keyed by date (Y-m-d), oldest first, from the
     * first payment in the window to today.
     *
     * @return array<string, float>
     */
    public function daily(string $userId, int $days, CarbonInterface $now, string $timezone = 'UTC'): array
    {
        $events = Event::query()
            ->withoutInternal()
            ->whereHas('integration', fn ($q) => $q->where('user_id', $userId))
            ->whereIn('service', config('metrics.spend.services', []))
            ->whereIn('action', config('metrics.spend.actions', []))
            ->where('value_unit', config('metrics.spend.currency', 'GBP'))
            ->whereNotNull('value')
            ->where('time', '>=', Carbon::instance($now)->setTimezone($timezone)->subDays($days)->startOfDay()->utc())
            ->get(['id', 'time', 'value', 'value_multiplier']);

        if ($events->isEmpty()) {
            return [];
        }

        $byDay = [];

        foreach ($events as $event) {
            $day = Carbon::instance($event->time)->setTimezone($timezone)->toDateString();
            $byDay[$day] = ($byDay[$day] ?? 0.0) + abs((float) $event->formatted_value);
        }

        ksort($byDay);

        $series = [];
        $cursor = Carbon::parse(array_key_first($byDay), 'UTC');
        $last = Carbon::parse(Carbon::instance($now)->setTimezone($timezone)->toDateString(), 'UTC');

        while ($cursor->lessThanOrEqualTo($last)) {
            $date = $cursor->toDateString();
            $series[$date] = $byDay[$date] ?? 0.0;
            $cursor->addDay();
        }

        return $series;
    }
}
