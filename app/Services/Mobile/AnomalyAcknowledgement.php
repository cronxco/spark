<?php

namespace App\Services\Mobile;

use App\Models\MetricTrend;
use App\Models\User;

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

        return true;
    }
}
