@php
    $money = fn (int $pence): string => ($pence < 0 ? '-' : '').'£'.number_format(abs($pence) / 100, abs($pence) % 100 === 0 ? 0 : 2);
@endphp
<div class="flex flex-col gap-6">
    @if ($dataIssues > 0)
        <div class="alert alert-warning">
            <x-icon name="fas.triangle-exclamation" class="w-5 h-5" />
            <span>{{ $dataIssues }} data {{ $dataIssues === 1 ? 'issue' : 'issues' }} to review.</span>
            <a class="btn btn-sm" href="{{ route('admin.pulse.index', ['section' => 'money_data_health']) }}" wire:navigate>Review in Pulse</a>
        </div>
    @endif

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="stat bg-base-200 rounded-box shadow col-span-2">
            <div class="stat-title">Safe to spend</div>
            <div class="stat-value text-3xl">{{ $money($safe['amount']) }}</div>
            <div class="stat-desc">
                {{ $money($safe['available']) }} available, {{ $money($safe['owed_on_cards']) }} on cards, {{ $money($safe['upcoming_bills']) }} bills
                to {{ $safe['payday_known'] ? 'payday' : 'month end' }} ({{ \Carbon\Carbon::parse($safe['payday'])->format('j M') }})
            </div>
            @if (count($safe['stale_accounts']) > 0)
                <div class="stat-desc text-warning">Stale, left out: {{ implode(', ', $safe['stale_accounts']) }}</div>
            @endif
        </div>
        <div class="stat bg-base-200 rounded-box shadow">
            <div class="stat-title">In savings pots</div>
            <div class="stat-value text-2xl">{{ $money($strip['in_pots']) }}</div>
        </div>
        <div class="stat bg-base-200 rounded-box shadow">
            <div class="stat-title">Interest {{ $strip['latest_month'] ? \Carbon\Carbon::parse($strip['latest_month'].'-01')->format('M Y') : '' }}</div>
            <div class="stat-value text-2xl">{{ $money($strip['latest_interest']) }}</div>
            <div class="stat-desc"><a class="link" href="{{ route('money', ['viewMode' => 'pots']) }}" wire:navigate>See the ledger</a></div>
        </div>
    </div>

    <livewire:money.card-cover />

    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h2 class="card-title">Next bills</h2>
            @if (count($safe['bills']) === 0)
                <p class="text-sm text-base-content/70">No direct debits due before {{ $safe['payday_known'] ? 'payday' : 'month end' }}.</p>
            @else
                <ul class="flex flex-col divide-y divide-base-300">
                    @foreach ($safe['bills'] as $bill)
                        <li class="py-2 flex justify-between" wire:key="bill-{{ $bill['payee'] }}-{{ $bill['due'] }}">
                            <span>{{ $bill['payee'] }} <span class="text-sm text-base-content/60">{{ \Carbon\Carbon::parse($bill['due'])->format('j M') }}</span></span>
                            <span>{{ $money($bill['amount']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
