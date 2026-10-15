@php
    $money = fn (int $pence): string => '£'.number_format($pence / 100, $pence % 100 === 0 ? 0 : 2);
    $day = fn (string $date): string => \Carbon\Carbon::parse($date)->format('j M');
@endphp
<div class="card bg-base-200 shadow">
    <div class="card-body gap-4">
        <h2 class="card-title">Card cover</h2>

        @if ($cover['status'] === 'no_buffer')
            <div class="alert alert-warning"><span>No buffer configured. Choose one on the Pots view.</span></div>
        @elseif ($cover['status'] === 'covered')
            <div class="alert alert-success"><span>Covered. {{ $cover['pot']['name'] }} holds {{ $money($cover['pot']['balance']) }} against {{ $money($cover['owed']) }} owed.</span></div>
        @else
            <div class="alert alert-error"><span>Short by {{ $money($cover['shortfall']) }}. {{ $cover['pot']['name'] }} holds {{ $money($cover['pot']['balance']) }} against {{ $money($cover['owed']) }} owed.</span></div>
        @endif

        @error('billing') <p class="text-error text-sm">{{ $message }}</p> @enderror

        @if (count($cover['cards']) > 0)
            <ul class="flex flex-col divide-y divide-base-300">
                @foreach ($cover['cards'] as $card)
                    <li class="py-2 flex flex-col gap-2" wire:key="cover-{{ $card['id'] }}">
                        <div class="flex items-center justify-between">
                            <span>
                                {{ $card['name'] }}
                                <span class="text-sm text-base-content/60">
                                    @if ($card['statement_closed'])
                                        @if ($card['statement_owed'] > 0)
                                            {{ $card['statement_basis'] === 'confirmed' ? 'statement' : 'est. statement' }} {{ $day($card['statement_closed']) }} {{ $money($card['statement_owed']) }}{{ $card['direct_debit'] ? ', direct debit '.$day($card['direct_debit']) : '' }}, then {{ $money($card['spent_since_statement']) }} since
                                        @else
                                            statement paid, {{ $money($card['owed']) }} since {{ $day($card['statement_closed']) }}
                                        @endif
                                    @else
                                        since {{ $day($card['since']) }}, {{ $card['transactions'] }} {{ $card['transactions'] === 1 ? 'transaction' : 'transactions' }}
                                    @endif
                                </span>
                            </span>
                            <span>{{ $money($card['owed']) }} <span class="text-sm text-base-content/60">{{ $money($card['buffered']) }} buffered</span></span>
                        </div>
                        <details>
                            <summary class="text-sm text-base-content/60 cursor-pointer">Billing days{{ $card['statement_day'] ? ': closes '.$card['statement_day'].($card['payment_day'] ? ', paid '.$card['payment_day'] : '') : '' }}</summary>
                            <form class="flex flex-wrap items-end gap-2 pt-2"
                                x-data="{ statement: '{{ $card['statement_day'] }}', payment: '{{ $card['payment_day'] }}' }"
                                @submit.prevent="$wire.saveBilling('{{ $card['id'] }}', statement, payment)">
                                <label class="form-control">
                                    <span class="label-text">Statement closes</span>
                                    <input type="number" min="1" max="31" class="input input-bordered input-sm w-24" x-model="statement" placeholder="Day">
                                </label>
                                <label class="form-control">
                                    <span class="label-text">Payment taken</span>
                                    <input type="number" min="1" max="31" class="input input-bordered input-sm w-24" x-model="payment" placeholder="Day">
                                </label>
                                <button type="submit" class="btn btn-sm">Save</button>
                            </form>
                        </details>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
