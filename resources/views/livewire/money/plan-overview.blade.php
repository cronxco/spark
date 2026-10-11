@php
    $money = fn (int $pence): string => '£'.number_format($pence / 100, $pence % 100 === 0 ? 0 : 2);
@endphp
<div class="flex flex-col gap-6">
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="stat bg-base-200 rounded-box shadow">
            <div class="stat-title">In savings pots</div>
            <div class="stat-value text-2xl">{{ $money($strip['in_pots']) }}</div>
        </div>
        <div class="stat bg-base-200 rounded-box shadow">
            <div class="stat-title">Interest {{ $strip['latest_month'] ? \Carbon\Carbon::parse($strip['latest_month'].'-01')->format('M Y') : '' }}</div>
            <div class="stat-value text-2xl">{{ $money($strip['latest_interest']) }}</div>
            <div class="stat-desc">Inferred from balance changes</div>
        </div>
        <div class="stat bg-base-200 rounded-box shadow">
            <div class="stat-title">Yearly run rate</div>
            <div class="stat-value text-2xl">{{ $money($strip['run_rate']) }}</div>
            <div class="stat-desc">{{ $strip['months_recorded'] }} months recorded, {{ $money($strip['twelve_months']) }}</div>
        </div>
        <div class="stat bg-base-200 rounded-box shadow">
            <div class="stat-title">Tax at the higher rate</div>
            <div class="stat-value text-2xl">{{ $money($strip['tax_estimate']) }}</div>
            <div class="stat-desc">Before savings allowance; joint pots not split</div>
        </div>
    </div>

    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h2 class="card-title">Pots</h2>
            @if (count($pots) === 0)
                <p class="text-sm text-base-content/70">No Monzo pots with a balance yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Pot</th>
                                <th class="text-right">Balance</th>
                                <th class="text-right">Share</th>
                                <th class="text-right">Goal</th>
                                <th class="text-right">Last interest</th>
                                <th class="text-right">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pots as $pot)
                                <tr wire:key="pot-{{ $pot['id'] }}">
                                    <td>{{ $pot['name'] }}</td>
                                    <td class="text-right">{{ $money($pot['balance']) }}</td>
                                    <td class="text-right">{{ number_format($pot['share'] * 100, 1) }}%</td>
                                    <td class="text-right">
                                        @if ($pot['goal'])
                                            {{ number_format(min(100, $pot['balance'] / $pot['goal'] * 100)) }}% of {{ $money($pot['goal']) }}
                                        @else
                                            <span class="text-base-content/50">None</span>
                                        @endif
                                    </td>
                                    <td class="text-right">{{ $pot['interest'] > 0 ? $money($pot['interest']) : '—' }}</td>
                                    <td class="text-right">{{ $pot['aer'] ? number_format($pot['aer'] * 100, 2).'%' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h2 class="card-title">Interest ledger</h2>
            @if (count($ledger) === 0)
                <p class="text-sm text-base-content/70">No interest has been recorded yet.</p>
            @else
                <div class="flex flex-col divide-y divide-base-300">
                    @foreach ($ledger as $month)
                        <details class="py-2" wire:key="ledger-{{ $month['month'] }}">
                            <summary class="flex items-center justify-between cursor-pointer">
                                <span class="font-medium">
                                    {{ \Carbon\Carbon::parse($month['month'].'-01')->format('F Y') }}
                                    @if ($month['estimated'])
                                        <span class="badge badge-ghost badge-sm">Estimated</span>
                                    @endif
                                </span>
                                <span>{{ $money($month['total']) }} <span class="text-sm text-base-content/60">from {{ $month['pots'] }} pots</span></span>
                            </summary>
                            <ul class="mt-2 text-sm flex flex-col gap-1">
                                @foreach ($month['lines'] as $line)
                                    <li class="flex justify-between">
                                        <span>{{ $line['pot'] }}@if ($line['estimated']) <span class="text-base-content/50">(estimated)</span>@endif</span>
                                        <span>{{ $money($line['amount']) }} <span class="text-base-content/50">{{ number_format($line['aer'] * 100, 2) }}%</span></span>
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
