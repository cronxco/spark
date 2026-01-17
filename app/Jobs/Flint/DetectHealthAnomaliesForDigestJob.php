<?php

namespace App\Jobs\Flint;

use App\Models\EventObject;
use App\Models\MetricTrend;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DetectHealthAnomaliesForDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300; // 5 minutes

    public function __construct(
        public User $user,
        public int $maxAnomalies = 3
    ) {}

    public function handle(): void
    {
        Log::info('[Flint] [COACHING] Starting health anomaly detection for digest', [
            'user_id' => $this->user->id,
        ]);

        // Get unacknowledged health anomalies
        $anomalies = $this->getUnaddressedHealthAnomalies();

        if ($anomalies->isEmpty()) {
            Log::info('[Flint] [COACHING] No unaddressed health anomalies found', [
                'user_id' => $this->user->id,
            ]);

            return;
        }

        Log::info('[Flint] [COACHING] Found unaddressed health anomalies', [
            'user_id' => $this->user->id,
            'count' => $anomalies->count(),
        ]);

        // Dispatch CreateCoachingSessionJob for each anomaly (up to max)
        foreach ($anomalies->take($this->maxAnomalies) as $anomaly) {
            CreateCoachingSessionJob::dispatch($this->user, $anomaly);

            Log::info('[Flint] [COACHING] Dispatched coaching session job', [
                'user_id' => $this->user->id,
                'anomaly_id' => $anomaly->id,
                'metric' => $anomaly->metricStatistic?->getIdentifier(),
            ]);
        }
    }

    /**
     * Get health anomalies that haven't been addressed in a coaching session.
     */
    protected function getUnaddressedHealthAnomalies(): Collection
    {
        // Get all unacknowledged anomalies for health-related metrics
        $anomalies = MetricTrend::unacknowledged()
            ->anomalies()
            ->with('metricStatistic')
            ->whereHas('metricStatistic', function ($query) {
                $query->where('user_id', $this->user->id)
                    // Health-related services
                    ->whereIn('service', ['oura', 'hevy', 'applehealth', 'fitbit', 'garmin', 'whoop', 'strava']);
            })
            ->where('detected_at', '>=', now()->subDays(7)) // Only recent anomalies
            ->orderBy('significance_score', 'desc')
            ->get();

        // Filter out anomalies that already have an active coaching session
        $addressedAnomalyIds = $this->getAddressedAnomalyIds();

        return $anomalies->filter(function (MetricTrend $anomaly) use ($addressedAnomalyIds) {
            return ! in_array($anomaly->id, $addressedAnomalyIds);
        });
    }

    /**
     * Get IDs of anomalies that already have coaching sessions.
     */
    protected function getAddressedAnomalyIds(): array
    {
        return EventObject::where('user_id', $this->user->id)
            ->where('concept', 'flint')
            ->where('type', 'coaching_session')
            ->whereRaw("metadata->>'status' IN ('active', 'completed')")
            ->whereNull('deleted_at')
            ->get()
            ->pluck('metadata.anomaly_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }
}
