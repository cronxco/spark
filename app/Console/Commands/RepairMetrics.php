<?php

namespace App\Console\Commands;

use App\Jobs\Metrics\CalculateMetricStatisticsJob;
use App\Models\Event;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Services\Metrics\MetricCatalogue;
use App\Services\Metrics\MetricCompleteness;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cleans up after the old metric rules. Read-only unless `--apply` is given.
 *
 * Removes unacknowledged anomalies that the current rules would not raise
 * (metrics that are not listed, values that were still provisional, moves in
 * the good direction), the retired 15% trends and the baseline-review flags,
 * then relearns the baselines. Acknowledged rows are never touched.
 */
class RepairMetrics extends Command
{
    protected $signature = 'metrics:repair
        {--user= : Limit to one owner UUID; omit for every user}
        {--dry-run : Report only (this is the default)}
        {--apply : Make the changes}
        {--merge-services : Fold statistics recorded under an alias spelling into the one to keep}';

    protected $description = 'Remove stale metric anomalies and retired trends, then relearn baselines';

    public function handle(MetricCatalogue $catalogue, MetricCompleteness $completeness): int
    {
        $apply = (bool) $this->option('apply') && ! $this->option('dry-run');
        $userId = $this->option('user');

        if ($userId && ! User::whereKey($userId)->exists()) {
            $this->error('No such user. Nothing was changed.');

            return self::FAILURE;
        }

        $this->line($apply ? 'Applying.' : 'Dry run. Nothing is changed; pass --apply to make these changes.');

        $users = User::query()->when($userId, fn ($q) => $q->whereKey($userId))->get();
        $totals = ['unlisted' => 0, 'provisional' => 0, 'good_direction' => 0, 'retired_trends' => 0, 'review_flags' => 0, 'merged' => 0];

        foreach ($users as $user) {
            $anomalies = MetricTrend::query()
                ->whereHas('metricStatistic', fn ($q) => $q->where('user_id', $user->id))
                ->anomalies()
                ->whereNull('acknowledged_at')
                ->with('metricStatistic')
                ->get();

            $timezone = $user->getTimezone() ?? 'UTC';
            $events = Event::query()
                ->whereKey($anomalies->pluck('metadata.event_id')->filter()->unique()->all())
                ->get(['id', 'time', 'updated_at', 'service', 'action'])
                ->keyBy('id');

            $remove = [];

            foreach ($anomalies as $trend) {
                $statistic = $trend->metricStatistic;

                if ($statistic === null || ! $catalogue->raisesAnomalies($statistic->service, $statistic->action)) {
                    $remove['unlisted'][] = $trend->id;

                    continue;
                }

                $event = $events->get($trend->metadata['event_id'] ?? null);
                if ($event !== null && ! $completeness->isFinal($event->service, $event->action, $event->time, $timezone, null, $event->updated_at)) {
                    $remove['provisional'][] = $trend->id;

                    continue;
                }

                $higherIsBetter = $catalogue->higherIsBetter($statistic->service, $statistic->action);
                if ($higherIsBetter !== null && ($trend->type === 'anomaly_high') === $higherIsBetter) {
                    $remove['good_direction'][] = $trend->id;
                }
            }

            $retired = MetricTrend::query()
                ->whereHas('metricStatistic', fn ($q) => $q->where('user_id', $user->id))
                ->trends()
                ->whereNull('acknowledged_at')
                ->pluck('id')
                ->all();

            $flagged = MetricStatistic::where('user_id', $user->id)->whereNotNull('baseline_reset_suggested_at');

            foreach ($remove as $reason => $ids) {
                $totals[$reason] += count($ids);
            }
            $totals['retired_trends'] += count($retired);
            $totals['review_flags'] += (clone $flagged)->count();

            if ($this->option('merge-services')) {
                $totals['merged'] += $this->mergeServices($user, $catalogue, $apply);
            }

            if (! $apply) {
                continue;
            }

            $removeIds = $remove === [] ? [] : array_merge(...array_values($remove));

            MetricTrend::whereIn('id', $removeIds)->delete();
            MetricTrend::whereIn('id', $retired)->delete();
            $flagged->update(['baseline_reset_suggested_at' => null]);

            (new CalculateMetricStatisticsJob((string) $user->id))->handle();
        }

        $this->table(['What', 'Count'], [
            ['Anomalies on metrics that are not listed', $totals['unlisted']],
            ['Anomalies raised on provisional values', $totals['provisional']],
            ['Anomalies in the good direction', $totals['good_direction']],
            ['Retired trends (15% moves)', $totals['retired_trends']],
            ['Baseline-review flags', $totals['review_flags']],
            ['Statistics merged from alias services', $totals['merged']],
        ]);

        if ($apply) {
            $this->line('Baselines relearned.');
        }

        return self::SUCCESS;
    }

    /**
     * Fold statistics under an alias service into the canonical one. Where
     * both exist the alias row is dropped (its baseline is recomputed from
     * both spellings anyway); otherwise it is renamed.
     */
    private function mergeServices(User $user, MetricCatalogue $catalogue, bool $apply): int
    {
        $count = 0;

        /** @var Collection<int, MetricStatistic> $aliased */
        $aliased = MetricStatistic::where('user_id', $user->id)
            ->get()
            ->filter(fn (MetricStatistic $s) => $catalogue->canonicalService($s->service) !== $s->service);

        foreach ($aliased as $statistic) {
            $canonical = $catalogue->canonicalService($statistic->service);
            $count++;

            if (! $apply) {
                continue;
            }

            $exists = MetricStatistic::where([
                'user_id' => $user->id,
                'service' => $canonical,
                'action' => $statistic->action,
                'value_unit' => $statistic->value_unit,
            ])->exists();

            $exists ? $statistic->delete() : $statistic->update(['service' => $canonical]);
        }

        return $count;
    }
}
