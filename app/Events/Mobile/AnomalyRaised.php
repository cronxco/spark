<?php

namespace App\Events\Mobile;

use App\Models\MetricTrend;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a MetricTrend with type=anomaly_high|anomaly_low is created.
 * Powers iOS push + in-app banner so the user can acknowledge anomalies live.
 */
class AnomalyRaised implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $userId,
        public string $metricTrendId,
        public string $metricStatisticId,
        public string $type,
        public float $currentValue,
        public float $baselineValue,
        public float $deviation,
    ) {}

    public static function fromTrend(MetricTrend $trend, string $userId): self
    {
        return new self(
            userId: $userId,
            metricTrendId: (string) $trend->id,
            metricStatisticId: (string) $trend->metric_statistic_id,
            type: (string) $trend->type,
            currentValue: (float) $trend->current_value,
            baselineValue: (float) $trend->baseline_value,
            deviation: (float) $trend->deviation,
        );
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.' . $this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'anomaly.raised';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->metricTrendId,
            'metric_statistic_id' => $this->metricStatisticId,
            'type' => $this->type,
            'current_value' => $this->currentValue,
            'baseline_value' => $this->baselineValue,
            'deviation' => $this->deviation,
        ];
    }
}
