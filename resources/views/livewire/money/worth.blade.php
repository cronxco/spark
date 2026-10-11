@php
    $money = fn (float $amount): string => ($amount < 0 ? '-' : '').'£'.number_format(abs($amount), 2);
    $change = $worth['comparison']['change'];
@endphp
<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="stat bg-base-200 rounded-box shadow">
            <div class="stat-title">Net worth</div>
            <div class="stat-value text-3xl">{{ $money($worth['total']) }}</div>
            <div class="stat-desc {{ $change < 0 ? 'text-error' : 'text-success' }}">
                {{ $change >= 0 ? '+' : '' }}{{ $money($change) }}
                @if ($worth['comparison']['change_pct'] !== null)
                    ({{ number_format($worth['comparison']['change_pct'], 1) }}%)
                @endif
                over {{ $windows[$worth['comparison']['window']] }}
            </div>
        </div>
        <label class="form-control">
            <span class="label-text">Compare with</span>
            <select class="select select-bordered select-sm" wire:model.live="window" aria-label="Comparison window">
                @foreach ($windows as $key => $label)
                    <option value="{{ $key }}">{{ $label }} ago</option>
                @endforeach
            </select>
        </label>
    </div>

    @if ($worth['excluded_accounts'] > 0)
        <p class="text-sm text-base-content/70">
            {{ $worth['excluded_accounts'] }} {{ $worth['excluded_accounts'] === 1 ? 'account is' : 'accounts are' }} left out of the comparison because they are in another currency or have no balance history that far back.
        </p>
    @endif

    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h2 class="card-title">Accounts</h2>
            @if (count($worth['accounts']) === 0)
                <p class="text-sm text-base-content/70">No account balances yet.</p>
            @else
                <ul class="flex flex-col divide-y divide-base-300">
                    @foreach ($worth['accounts'] as $account)
                        <li class="py-2 flex justify-between" wire:key="worth-{{ $account['id'] }}">
                            <span>{{ $account['name'] }} @if ($account['debt'])<span class="badge badge-ghost badge-sm">Debt</span>@endif</span>
                            <span class="{{ $account['debt'] ? 'text-error' : '' }}">{{ $money($account['balance']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
