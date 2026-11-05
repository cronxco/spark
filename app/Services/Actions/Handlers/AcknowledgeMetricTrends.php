<?php

namespace App\Services\Actions\Handlers;

use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Services\Actions\ActionFailed;
use App\Services\Actions\ActionHandler;
use App\Services\Actions\ActionOutcome;
use App\Services\Actions\Applicable;
use App\Services\Actions\Reversible;
use Illuminate\Support\Carbon;

class AcknowledgeMetricTrends implements ActionHandler, Applicable, Reversible
{
    public function applies(User $user, array $target): bool
    {
        $metric = $this->metric($user, $target['metric_id']);

        return $metric !== null && $this->pending($metric)->exists();
    }

    public function handle(User $user, array $target): ActionOutcome
    {
        $metric = $this->metric($user, $target['metric_id']) ?? throw ActionFailed::notFound('Metric');
        $trends = $this->pending($metric)->get();

        if ($trends->isEmpty()) {
            return ActionOutcome::succeeded('Nothing to acknowledge', ['metric_id' => $metric->id, 'acknowledged' => 0]);
        }

        $baselineFlag = $metric->baseline_reset_suggested_at;
        $trends->each(fn (MetricTrend $trend) => $trend->acknowledge());

        return ActionOutcome::succeeded('Trends acknowledged', [
            'metric_id' => $metric->id,
            'acknowledged' => $trends->count(),
        ], [
            'metric_id' => $metric->id,
            'trend_ids' => $trends->pluck('id')->all(),
            'baseline_reset_suggested_at' => $baselineFlag?->toIso8601String(),
        ]);
    }

    public function undo(User $user, array $undo): void
    {
        $metric = $this->metric($user, $undo['metric_id']) ?? throw ActionFailed::notFound('Metric');

        MetricTrend::query()
            ->where('metric_statistic_id', $metric->id)
            ->whereKey($undo['trend_ids'])
            ->update(['acknowledged_at' => null]);

        if ($undo['baseline_reset_suggested_at'] !== null && $metric->baseline_reset_suggested_at === null) {
            $metric->update(['baseline_reset_suggested_at' => Carbon::parse($undo['baseline_reset_suggested_at'])]);
        }
    }

    private function metric(User $user, string $id): ?MetricStatistic
    {
        return MetricStatistic::query()->where('user_id', $user->id)->find($id);
    }

    private function pending(MetricStatistic $metric)
    {
        return MetricTrend::query()
            ->where('metric_statistic_id', $metric->id)
            ->whereNull('acknowledged_at');
    }
}
