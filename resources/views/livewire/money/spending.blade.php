@php
    $money = fn (int $pence): string => ($pence < 0 ? '-' : '').'£'.number_format(abs($pence) / 100, abs($pence) % 100 === 0 ? 0 : 2);
@endphp
<div class="card bg-base-200 shadow">
    <div class="card-body gap-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="card-title">Spending</h2>
                <p class="text-sm text-base-content/70">Card spending only. No pot moves, direct debits, transfers or card repayments.</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" class="btn btn-ghost btn-sm" wire:click="previousMonth" aria-label="Previous month">&lsaquo;</button>
                <span class="font-medium">{{ $month->format('F Y') }}</span>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="nextMonth" aria-label="Next month" @disabled($monthOffset === 0)>&rsaquo;</button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <select wire:model.live="category" class="select select-bordered select-sm">
                <option value="">All categories</option>
                @foreach ($categories as $name)
                    <option value="{{ $name }}">{{ str_replace('_', ' ', $name) }}</option>
                @endforeach
            </select>
            <select wire:model.live="account" class="select select-bordered select-sm">
                <option value="">All cards</option>
                @foreach ($accounts as $id => $title)
                    <option value="{{ $id }}">{{ $title }}</option>
                @endforeach
            </select>
            <span class="ml-auto text-sm">{{ $count }} transactions, <strong>{{ $money($total) }}</strong></span>
        </div>

        @if ($bufferNotice)<div class="alert alert-success"><span>{{ $bufferNotice }}</span></div>@endif
        @if ($bufferError)<div class="alert alert-error"><span>{{ $bufferError }}</span></div>@endif

        @if ($review)
            <div class="alert alert-warning flex-col items-start gap-2">
                <span class="font-medium">Move £{{ number_format($review['total'] / 100, 2) }} into {{ $review['pot']['name'] }}?</span>
                <span class="text-sm">Monzo will transfer this from your current account into the pot. It cannot be undone from Spark.</span>
                <ul class="text-sm">
                    @foreach ($review['transactions'] as $tx)
                        <li>{{ $tx['time']->format('j M') }} {{ $tx['merchant'] }} {{ $money($tx['amount']) }}</li>
                    @endforeach
                </ul>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-primary btn-sm" wire:click="confirmBuffer" wire:loading.attr="disabled">Move the money</button>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelBuffer">Cancel</button>
                </div>
            </div>
        @elseif ($bufferEnabled && count($selected) > 0)
            <button type="button" class="btn btn-outline btn-sm self-start" wire:click="reviewBuffer">Add {{ count($selected) }} to buffer</button>
        @endif

        @if ($entries->isEmpty())
            <p class="text-sm text-base-content/70">No spending this month.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr wire:key="spend-{{ $entry['id'] }}">
                                <td>
                                    @if ($bufferEnabled && $entry['source'] === 'gocardless' && $entry['amount'] > 0 && ! in_array($entry['buffer'], ['requested', 'linked'], true))
                                        <input type="checkbox" class="checkbox checkbox-sm" value="{{ $entry['id'] }}" wire:model.live="selected" aria-label="Select {{ $entry['merchant'] }}" />
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-base-content/60">{{ $entry['time']->format('j M') }}</td>
                                <td>
                                    {{ $entry['merchant'] }}
                                    @if ($entry['pending'])<span class="badge badge-ghost badge-sm">Pending</span>@endif
                                    @if ($entry['buffer'] === 'linked')<span class="badge badge-success badge-sm">Buffered</span>
                                    @elseif ($entry['buffer'] === 'requested')<span class="badge badge-info badge-sm">Buffer requested</span>
                                    @elseif ($entry['buffer'] === 'failed')<span class="badge badge-error badge-sm">Buffer failed</span>@endif
                                </td>
                                <td class="text-base-content/60">{{ str_replace('_', ' ', $entry['category']) }}</td>
                                <td class="text-base-content/60">{{ $entry['account'] }}</td>
                                <td class="text-right whitespace-nowrap {{ $entry['amount'] < 0 ? 'text-success' : '' }}">{{ $money($entry['amount']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
