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

        @if ($entries->isEmpty())
            <p class="text-sm text-base-content/70">No spending this month.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr wire:key="spend-{{ $entry['id'] }}">
                                <td class="whitespace-nowrap text-base-content/60">{{ $entry['time']->format('j M') }}</td>
                                <td>
                                    {{ $entry['merchant'] }}
                                    @if ($entry['pending'])<span class="badge badge-ghost badge-sm">Pending</span>@endif
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
