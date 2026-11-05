<?php

namespace App\Livewire\Charts;

use App\Models\Event;
use Livewire\Component;

class EventContextChart extends Component
{
    public Event $event;

    public function render()
    {
        if (! $this->shouldShowChart()) {
            return view('livewire.charts.event-context-chart', [
                'chartData' => null,
                'metricName' => null,
            ]);
        }

        [$startDate, $endDate] = $this->calculateDateRange();

        $data = Event::whereHas('integration', function ($q) {
            $userId = optional(auth()->guard('web')->user())->id;
            if ($userId) {
                $q->where('user_id', $userId);
            } else {
                $q->whereRaw('1 = 0');
            }
        })
            ->where('service', $this->event->service)
            ->where('action', $this->event->action)
            ->where('value_unit', $this->event->value_unit)
            ->whereBetween('time', [$startDate, $endDate])
            ->whereNotNull('value')
            ->orderBy('time')
            ->get(['time', 'value', 'value_multiplier', 'id'])
            ->map(fn ($e) => [
                'date' => $e->time->format('Y-m-d'),
                'datetime' => $e->time->format('d/m/Y H:i'),
                'value' => (float) $e->formatted_value,
                'id' => (string) $e->id,
                'isCurrent' => $e->id === $this->event->id,
            ])
            ->values();

        return view('livewire.charts.event-context-chart', [
            'chartData' => $data,
            'metricName' => format_action_title($this->event->action),
            'valueUnit' => $this->event->value_unit,
        ]);
    }

    private function shouldShowChart(): bool
    {
        return $this->event->value !== null && $this->event->value_unit !== null;
    }

    private function calculateDateRange(): array
    {
        $eventDate = $this->event->time;
        $now = now();
        $daysFromNow = $now->diffInDays($eventDate, false);

        if ($daysFromNow >= 0 && $daysFromNow <= 7) {
            // Recent event (0-7 days old): Show 7 days before, up to now
            $startDate = $eventDate->copy()->subDays(7);
            $endDate = $now->copy()->addDay();
        } elseif ($daysFromNow < 0) {
            // Future event: symmetric 14-day window
            $startDate = $eventDate->copy()->subDays(14);
            $endDate = $eventDate->copy()->addDays(14);
        } else {
            // Old event (>7 days ago): symmetric 14-day window
            $startDate = $eventDate->copy()->subDays(14);
            $endDate = $eventDate->copy()->addDays(14);
        }

        return [$startDate, $endDate];
    }
}
