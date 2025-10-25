<div class="flex flex-col h-full p-6 overflow-y-auto">
    <div class="text-center mb-6">
        <h2 class="text-2xl font-bold mb-2">Check-in History</h2>
        <p class="text-base-content/70">Last 30 days</p>
    </div>

    <div class="flex-1">
        <div class="grid grid-cols-6 gap-1.5 mb-4">
            @foreach ($history as $day)
            @php
                $date = \Carbon\Carbon::parse($day['date']);
                $hasMorning = !is_null($day['morning']);
                $hasAfternoon = !is_null($day['afternoon']);
                $both = $hasMorning && $hasAfternoon;
                $morningTotal = $day['morning']['total'] ?? 0;
                $afternoonTotal = $day['afternoon']['total'] ?? 0;
                $avgTotal = $both ? round(($morningTotal + $afternoonTotal) / 2) : ($morningTotal ?: $afternoonTotal);

                // Color intensity based on average score
                if ($both) {
                    $colorClass = 'bg-success';
                    $opacity = $avgTotal >= 8 ? '' : ($avgTotal >= 6 ? 'opacity-80' : 'opacity-60');
                } elseif ($hasMorning || $hasAfternoon) {
                    $colorClass = 'bg-warning';
                    $opacity = 'opacity-70';
                } else {
                    $colorClass = 'bg-base-300';
                    $opacity = '';
                }

                $isToday = $date->isToday();
            @endphp
            <div
                class="aspect-square rounded {{ $colorClass }} {{ $opacity }} {{ $isToday ? 'ring-2 ring-primary' : '' }}"
                title="{{ $date->format('M j') }}: {{ $both ? 'AM & PM' : ($hasMorning ? 'AM only' : ($hasAfternoon ? 'PM only' : 'No check-in')) }} {{ $avgTotal ? '(' . $avgTotal . '/10)' : '' }}">
            </div>
            @endforeach
        </div>

        <div class="flex justify-between text-xs text-base-content/60 mb-6">
            <span>{{ \Carbon\Carbon::parse($startDate)->format('M j') }}</span>
            <span>{{ \Carbon\Carbon::parse($endDate)->format('M j') }}</span>
        </div>

        <div class="space-y-3">
            <div class="flex items-center gap-2">
                <div class="w-4 h-4 rounded bg-success"></div>
                <span class="text-sm">Both AM & PM complete</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-4 h-4 rounded bg-warning opacity-70"></div>
                <span class="text-sm">One check-in only</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-4 h-4 rounded bg-base-300"></div>
                <span class="text-sm">No check-ins</span>
            </div>
        </div>

        <div class="mt-6 text-center">
            @php
                $completeDays = collect($history)->filter(fn($d) => !is_null($d['morning']) && !is_null($d['afternoon']))->count();
                $partialDays = collect($history)->filter(fn($d) => (!is_null($d['morning']) || !is_null($d['afternoon'])) && !(! is_null($d['morning']) && !is_null($d['afternoon'])))->count();
                $streak = 0;
                foreach (array_reverse($history) as $day) {
                    if (!is_null($day['morning']) || !is_null($day['afternoon'])) {
                        $streak++;
                    } else {
                        break;
                    }
                }
            @endphp
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <div class="text-2xl font-bold text-success">{{ $completeDays }}</div>
                    <div class="text-xs text-base-content/60">Complete Days</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-primary">{{ $streak }}</div>
                    <div class="text-xs text-base-content/60">Current Streak</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-warning">{{ $partialDays }}</div>
                    <div class="text-xs text-base-content/60">Partial Days</div>
                </div>
            </div>
        </div>
    </div>
</div>
