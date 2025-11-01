<?php

namespace App\Livewire\Charts;

use App\Models\Event;
use App\Models\MetricStatistic;
use Livewire\Component;

class MetricChart extends Component
{
    public MetricStatistic $metric;

    public int $timeRange = 90; // days

    public bool $showNormalRange = true;

    public bool $showAnomalies = false;

    public bool $showMovingAverage = false;

    public function mount(): void
    {
        // Default time range can be overridden
    }

    public function render()
    {
        $startDate = now()->subDays($this->timeRange);

        // Get all events for this metric within time range
        $events = Event::whereHas('integration', function ($q) {
            $userId = optional(auth()->guard('web')->user())->id;
            if ($userId) {
                $q->where('user_id', $userId);
            } else {
                $q->whereRaw('1 = 0');
            }
        })
            ->where('service', $this->metric->service)
            ->where('action', $this->metric->action)
            ->where('value_unit', $this->metric->value_unit)
            ->where('time', '>=', $startDate)
            ->whereNotNull('value')
            ->orderBy('time')
            ->get(['time', 'value', 'value_multiplier', 'id']);

        // Group by date and calculate daily average (using formatted values)
        $chartData = $events
            ->groupBy(fn ($e) => $e->time->format('Y-m-d'))
            ->map(fn ($dailyEvents) => [
                'date' => $dailyEvents->first()->time->format('Y-m-d'),
                'value' => $dailyEvents->avg('formatted_value'),
                'count' => $dailyEvents->count(),
                'min' => $dailyEvents->min('formatted_value'),
                'max' => $dailyEvents->max('formatted_value'),
            ])
            ->values();

        // Calculate moving average if needed
        $movingAverage = [];
        if ($this->showMovingAverage && $chartData->count() >= 7) {
            $window = 7; // 7-day moving average
            foreach ($chartData as $index => $item) {
                if ($index >= $window - 1) {
                    $slice = $chartData->slice($index - $window + 1, $window);
                    $movingAverage[] = [
                        'date' => $item['date'],
                        'value' => $slice->avg('value'),
                    ];
                } else {
                    $movingAverage[] = [
                        'date' => $item['date'],
                        'value' => null,
                    ];
                }
            }
        }

        return view('livewire.charts.metric-chart', [
            'chartData' => $chartData,
            'movingAverage' => collect($movingAverage),
            'normalLowerBound' => $this->metric->normal_lower_bound,
            'normalUpperBound' => $this->metric->normal_upper_bound,
            'meanValue' => $this->metric->mean_value,
        ]);
    }
}
