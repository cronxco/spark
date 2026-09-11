<?php

namespace App\Services\Mobile;

use App\Models\MetricTrend;
use App\Models\User;
use Carbon\Carbon;

/**
 * Acknowledges a single MetricTrend anomaly on behalf of a user. Used both by
 * the iOS `POST /anomalies/{id}/acknowledge` endpoint and the MCP
 * `AcknowledgeAnomalyTool` so both surfaces apply the same ownership check
 * and side effects.
 */
class AnomalyAcknowledgement
{
    public function acknowledge(User $user, string $anomalyId, array $metadata = []): bool
    {
        $anomaly = MetricTrend::query()
            ->with('metricStatistic')
            ->whereKey($anomalyId)
            ->first();

        if ($anomaly === null) {
            return false;
        }

        if ($anomaly->metricStatistic?->user_id !== $user->id) {
            return false;
        }

        if ($anomaly->acknowledged_at !== null) {
            return true;
        }

        $anomaly->acknowledged_at = now();

        if ($metadata !== []) {
            $anomaly->metadata = array_merge($anomaly->metadata ?? [], $metadata);
        }

        $anomaly->save();

        $this->propagateSuppression($anomaly, $metadata);

        return true;
    }

    /**
     * Returns an acknowledged anomaly to the feed: clears `acknowledged_at`,
     * drops any suppression note, and lifts the directional suppression window
     * this anomaly set on its MetricStatistic.
     *
     * This is the recovery path for a mis-tapped "Not worth flagging" — without
     * it, dismissing an anomaly is permanent, because acknowledgement (not the
     * caught_up activity row) is what removes it from the queue.
     *
     * Returns true when something was actually released.
     */
    public function unacknowledge(User $user, string $anomalyId): bool
    {
        $anomaly = MetricTrend::query()
            ->with('metricStatistic')
            ->whereKey($anomalyId)
            ->first();

        if ($anomaly === null || $anomaly->metricStatistic?->user_id !== $user->id) {
            return false;
        }

        $metadata = $anomaly->metadata ?? [];
        $wasSuppressed = array_key_exists('suppress_until', $metadata);

        if ($anomaly->acknowledged_at === null && ! $wasSuppressed) {
            return false;
        }

        unset($metadata['suppress_until']);

        $anomaly->acknowledged_at = null;
        $anomaly->metadata = $metadata;
        $anomaly->save();

        $this->clearSuppression($anomaly);

        return true;
    }

    /**
     * Lift the directional suppression window on the parent MetricStatistic.
     * Only clears a window that is still in the future — an already-elapsed one
     * is inert, and blanking it would discard unrelated history.
     */
    private function clearSuppression(MetricTrend $anomaly): void
    {
        if (! in_array($anomaly->type, ['anomaly_high', 'anomaly_low'], true)) {
            return;
        }

        $statistic = $anomaly->metricStatistic;
        if (! $statistic) {
            return;
        }

        $column = $anomaly->type === 'anomaly_high'
            ? 'anomaly_high_suppressed_until'
            : 'anomaly_low_suppressed_until';

        $existing = $statistic->{$column};
        if ($existing !== null && Carbon::parse($existing)->isFuture()) {
            $statistic->update([$column => null]);
        }
    }

    /**
     * When suppress_until is provided, write it to the directional suppression
     * column on MetricStatistic so detection jobs can skip record creation.
     */
    private function propagateSuppression(MetricTrend $anomaly, array $metadata): void
    {
        $suppressUntil = $metadata['suppress_until'] ?? null;

        if (! $suppressUntil || ! in_array($anomaly->type, ['anomaly_high', 'anomaly_low'], true)) {
            return;
        }

        $statistic = $anomaly->metricStatistic;
        if (! $statistic) {
            return;
        }

        $suppressDate = Carbon::parse($suppressUntil)->endOfDay();

        $column = $anomaly->type === 'anomaly_high'
            ? 'anomaly_high_suppressed_until'
            : 'anomaly_low_suppressed_until';

        // Take the later of any existing suppression and the new one.
        $existing = $statistic->{$column};
        if ($existing === null || $suppressDate->isAfter($existing)) {
            $statistic->update([$column => $suppressDate]);
        }
    }
}
