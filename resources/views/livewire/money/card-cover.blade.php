@php
    $money = fn (int $pence): string => '£'.number_format($pence / 100, $pence % 100 === 0 ? 0 : 2);
@endphp
<div class="card bg-base-200 shadow">
    <div class="card-body gap-4">
        <div>
            <h2 class="card-title">Card cover</h2>
            <p class="text-sm text-base-content/70">Is your buffer pot enough to pay off what your credit cards owe since their last repayment? Nothing here moves money.</p>
        </div>

        @if ($cover['status'] === 'no_buffer')
            <div class="alert alert-warning"><span>No buffer pot is set. Choose one on the Pots view to see whether your cards are covered.</span></div>
        @elseif ($cover['status'] === 'covered')
            <div class="alert alert-success"><span>Covered. {{ $cover['pot']['name'] }} holds {{ $money($cover['pot']['balance']) }} against {{ $money($cover['owed']) }} owed.</span></div>
        @else
            <div class="alert alert-error"><span>Short by {{ $money($cover['shortfall']) }}. {{ $cover['pot']['name'] }} holds {{ $money($cover['pot']['balance']) }} against {{ $money($cover['owed']) }} owed.</span></div>
        @endif

        @if (count($cover['cards']) > 0)
            <ul class="flex flex-col divide-y divide-base-300">
                @foreach ($cover['cards'] as $card)
                    <li class="py-2 flex items-center justify-between" wire:key="cover-{{ $card['id'] }}">
                        <span>
                            {{ $card['name'] }}
                            <span class="text-sm text-base-content/60">since {{ \Carbon\Carbon::parse($card['since'])->format('j M') }}, {{ $card['transactions'] }} transactions</span>
                        </span>
                        <span>{{ $money($card['owed']) }} <span class="text-sm text-base-content/60">{{ $money($card['buffered']) }} buffered</span></span>
                    </li>
                @endforeach
            </ul>
        @endif

    </div>
</div>
