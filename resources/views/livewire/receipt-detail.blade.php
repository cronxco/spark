<?php

use function Livewire\Volt\{state};

?>

<div>
    @php
        $merchant = $receipt->target;
        $metadata = $merchant?->metadata ?? [];
        $isMatched = $matchingStatus === 'matched';
        $needsReview = $matchingStatus === 'suggestions';
        $extractedData = $receipt->event_metadata['raw_extraction'] ?? [];
        $lineItems = $extractedData['line_items'] ?? [];
        $taxBreakdown = $extractedData['tax_breakdown'] ?? [];
        $paymentInfo = $extractedData['payment_info'] ?? [];
        $matchingHints = $receipt->event_metadata['matching_hints'] ?? [];
    @endphp

    <x-header :title="$merchant?->title ?? 'Receipt'" separator>
        <x-slot:subtitle>
            {{ $receipt->time->format('F j, Y \a\t g:i A') }}
        </x-slot:subtitle>
        <x-slot:actions>
            <x-button label="Back to Receipts" icon="fas.arrow-left" link="{{ route('receipts.index') }}" class="btn-ghost" />

            @if ($isMatched)
                <x-button label="Remove Match" icon="fas.xmark" wire:click="removeMatch" class="btn-warning"
                    wire:confirm="Are you sure you want to remove this match?" />
            @else
                <x-button label="Match Transaction" icon="fas.link" wire:click="openMatchModal" class="btn-primary" />
                <x-button label="Find match again" icon="fas.rotate" wire:click="retryMatch" class="btn-ghost" />
            @endif

            <div class="dropdown dropdown-end">
                <label tabindex="0" class="btn btn-ghost btn-circle">
                    <x-icon name="fas.ellipsis-vertical" class="w-5 h-5" />
                </label>
                <ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-52">
                    <li>
                        <a wire:click="downloadOriginalEmail">
                            <x-icon name="fas.download" class="w-4 h-4" />
                            Download Original Email
                        </a>
                    </li>
                    <li>
                        <a wire:click="deleteReceipt" wire:confirm="Are you sure you want to delete this receipt? This cannot be undone." class="text-error">
                            <x-icon name="fas.trash" class="w-4 h-4" />
                            Delete Receipt
                        </a>
                    </li>
                </ul>
            </div>
        </x-slot:actions>
    </x-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Main Content --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Receipt Summary --}}
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <h2 class="card-title">
                        Receipt Summary
                        @if ($isMatched)
                            <div class="badge badge-success gap-1">
                                <x-icon name="fas.circle-check" class="w-3 h-3" />
                                Matched
                            </div>
                        @elseif ($needsReview)
                            <div class="badge badge-warning gap-1">
                                <x-icon name="fas.triangle-exclamation" class="w-3 h-3" />
                                Needs Review
                            </div>
                        @else
                            <div class="badge badge-info gap-1">
                                <x-icon name="fas.clock" class="w-3 h-3" />
                                {{ match ($matchingStatus) { 'searching' => 'Searching', 'no_candidate' => 'No match found', 'needs_details' => 'Needs details', 'no_match' => 'Marked unmatched', default => 'Unmatched' } }}
                            </div>
                        @endif
                    </h2>

                    <div class="grid grid-cols-2 gap-4 mt-4">
                        <div>
                            <div class="text-sm text-base-content/60">Merchant</div>
                            <div class="font-semibold">{{ $merchant?->title ?? 'Unknown' }}</div>
                        </div>
                        <div>
                            <div class="text-sm text-base-content/60">Total Amount</div>
                            <div class="font-mono text-2xl font-bold text-primary">
                                {{ $receipt->value_unit }} {{ number_format($receipt->value / ($receipt->value_multiplier ?: 1), 2) }}
                            </div>
                        </div>
                        <div>
                            <div class="text-sm text-base-content/60">Date & Time</div>
                            <div>{{ $receipt->time->format('M d, Y') }}</div>
                            <div class="text-sm text-base-content/60">{{ $receipt->time->format('H:i') }}</div>
                        </div>
                        <div>
                            <div class="text-sm text-base-content/60">Receipt ID</div>
                            <div class="font-mono text-xs">{{ $receipt->id }}</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Line Items --}}
            @if (count($lineItems) > 0)
                <div class="card bg-base-100 shadow-xl">
                    <div class="card-body">
                        <h2 class="card-title">Line Items</h2>

                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-right">Qty</th>
                                        <th class="text-right">Price</th>
                                        <th class="text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($lineItems as $item)
                                        <tr>
                                            <td>
                                                <div class="font-medium">{{ $item['description'] ?? 'Unknown Item' }}</div>
                                                @if (!empty($item['category']))
                                                    <div class="badge badge-sm badge-ghost">{{ $item['category'] }}</div>
                                                @endif
                                            </td>
                                            <td class="text-right">{{ $item['quantity'] ?? 1 }}</td>
                                            <td class="text-right font-mono">
                                                {{ $receipt->value_unit }} {{ number_format(($item['unit_price'] ?? 0) / 100, 2) }}
                                            </td>
                                            <td class="text-right font-mono font-semibold">
                                                {{ $receipt->value_unit }} {{ number_format(($item['total_price'] ?? 0) / 100, 2) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Tax Breakdown --}}
            @if (count($taxBreakdown) > 0)
                <div class="card bg-base-100 shadow-xl">
                    <div class="card-body">
                        <h2 class="card-title">Tax Breakdown</h2>

                        <div class="space-y-2">
                            @foreach ($taxBreakdown as $tax)
                                <div class="flex justify-between items-center">
                                    <div>
                                        <span class="font-medium">{{ $tax['description'] ?? 'Tax' }}</span>
                                        @if (!empty($tax['rate']))
                                            <span class="text-sm text-base-content/60">({{ number_format($tax['rate'], 1) }}%)</span>
                                        @endif
                                    </div>
                                    <span class="font-mono font-semibold">
                                        {{ $receipt->value_unit }} {{ number_format(($tax['amount'] ?? 0) / 100, 2) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            {{-- Payment Information --}}
            @if (!empty($paymentInfo))
                <div class="card bg-base-100 shadow-xl">
                    <div class="card-body">
                        <h2 class="card-title">Payment Information</h2>

                        <div class="grid grid-cols-2 gap-4">
                            @if (!empty($paymentInfo['method']))
                                <div>
                                    <div class="text-sm text-base-content/60">Payment Method</div>
                                    <div class="font-medium">{{ $paymentInfo['method'] }}</div>
                                </div>
                            @endif

                            @if (!empty($paymentInfo['last_four']))
                                <div>
                                    <div class="text-sm text-base-content/60">Card</div>
                                    <div class="font-mono">•••• {{ $paymentInfo['last_four'] }}</div>
                                </div>
                            @endif

                            @if (!empty($paymentInfo['card_type']))
                                <div>
                                    <div class="text-sm text-base-content/60">Card Type</div>
                                    <div>{{ $paymentInfo['card_type'] }}</div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            {{-- Match Status --}}
            @if ($matchedTransaction)
                <div class="card bg-success/10 border border-success">
                    <div class="card-body">
                        <h3 class="card-title text-success">
                            <x-icon name="fas.circle-check" class="w-5 h-5" />
                            Matched Transaction
                        </h3>

                        <div class="space-y-3 mt-2">
                            <div>
                                <div class="text-sm text-base-content/60">Transaction</div>
                                <a href="{{ route('events.show', $matchedTransaction) }}" class="link link-hover font-semibold">
                                    {{ $matchedTransaction->target?->title ?? 'View Transaction' }}
                                </a>
                            </div>

                            <div>
                                <div class="text-sm text-base-content/60">Amount</div>
                                <div class="font-mono font-semibold">
                                    {{ $matchedTransaction->value_unit }} {{ number_format($matchedTransaction->value / ($matchedTransaction->value_multiplier ?: 1), 2) }}
                                </div>
                            </div>

                            <div>
                                <div class="text-sm text-base-content/60">Date</div>
                                <div>{{ $matchedTransaction->time->format('M d, Y H:i') }}</div>
                            </div>

                            <div>
                                <div class="text-sm text-base-content/60">Service</div>
                                <div class="badge badge-sm">{{ ucfirst($matchedTransaction->service) }}</div>
                            </div>

                            @if (!empty($matchedRelationship?->metadata['match_confidence']))
                                <div>
                                    <div class="text-sm text-base-content/60">Confidence</div>
                                    <div class="flex items-center gap-2">
                                        <progress class="progress progress-success w-full"
                                            value="{{ $matchedRelationship->metadata['match_confidence'] * 100 }}" max="100"></progress>
                                        <span class="text-sm font-semibold">{{ round($matchedRelationship->metadata['match_confidence'] * 100) }}%</span>
                                    </div>
                                </div>
                            @endif

                            @if (!empty($matchedRelationship?->metadata['match_method']))
                                <div>
                                    <div class="text-sm text-base-content/60">Match Type</div>
                                    <div class="badge badge-sm badge-success">{{ ucfirst($matchedRelationship->metadata['match_method']) }}</div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @elseif ($needsReview && count($candidateMatches) > 0)
                <div class="card bg-warning/10 border border-warning">
                    <div class="card-body">
                        <h3 class="card-title text-warning">
                            <x-icon name="fas.triangle-exclamation" class="w-5 h-5" />
                            Needs Review
                        </h3>

                        <p class="text-sm text-base-content/60 mt-2">
                            Found {{ count($candidateMatches) }} possible matches. Review and select the correct transaction.
                        </p>

                        <div class="space-y-2 mt-4">
                            @foreach ($candidateMatches as $candidate)
                                @php
                                    $transaction = \App\Models\Event::forUser(\Illuminate\Support\Facades\Auth::id())->find($candidate['transaction_id']);
                                    $confidence = $candidate['confidence'] ?? 0;
                                @endphp
                                @if ($transaction)
                                    <div class="card bg-base-100 border border-base-300 hover:border-warning cursor-pointer"
                                        wire:click="createManualMatch('{{ $transaction->id }}')">
                                        <div class="card-body p-3">
                                            <div class="flex justify-between items-start">
                                                <div class="flex-1">
                                                    <div class="font-semibold text-sm">{{ $transaction->target?->title }}</div>
                                                    <div class="text-xs text-base-content/60">
                                                        {{ $transaction->time->format('M d, H:i') }}
                                                    </div>
                                                </div>
                                                <div class="text-right">
                                                    <div class="font-mono text-sm font-semibold">
                                                        {{ $transaction->value_unit }} {{ number_format($transaction->value / ($transaction->value_multiplier ?: 1), 2) }}
                                                    </div>
                                                    <div class="badge badge-xs badge-warning">
                                                        {{ round($confidence * 100) }}%
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        <x-button label="Match Manually" icon="fas.link" wire:click="openMatchModal" class="btn-warning btn-sm mt-2" />
                    </div>
                </div>
            @else
                <div class="card bg-info/10 border border-info">
                    <div class="card-body">
                        <h3 class="card-title text-info">
                            <x-icon name="fas.clock" class="w-5 h-5" />
                            Not Matched
                        </h3>

                        <p class="text-sm text-base-content/60 mt-2">
                            {{ $matchingStatus === 'needs_details' ? 'This receipt needs an amount or time before automatic matching can work.' : 'No transaction is linked to this receipt. You can search for one or run matching again.' }}
                        </p>

                        @if (!empty($matchingState['attempted_at']))
                            <p class="text-xs text-base-content/60">Last searched {{ \Carbon\Carbon::parse($matchingState['attempted_at'])->diffForHumans() }}</p>
                        @endif

                        <x-button label="Match Transaction" icon="fas.link" wire:click="openMatchModal" class="btn-primary btn-sm mt-4" />
                        <x-button label="Find match again" icon="fas.rotate" wire:click="retryMatch" class="btn-ghost btn-sm mt-4" />
                        <x-button label="No matching transaction" wire:click="markNoMatch" wire:confirm="Mark this receipt as having no matching transaction?" class="btn-ghost btn-sm mt-4" />
                    </div>
                </div>
            @endif

            {{-- Matching Hints --}}
            @if (!empty($matchingHints))
                <div class="card bg-base-100 shadow-xl">
                    <div class="card-body">
                        <h3 class="card-title text-sm">Matching Hints</h3>

                        <div class="space-y-2 text-sm">
                            @if (!empty($matchingHints['suggested_amount']))
                                <div>
                                    <div class="text-base-content/60">Suggested Amount</div>
                                    <div class="font-mono font-semibold">
                                        {{ $receipt->value_unit }} {{ number_format($matchingHints['suggested_amount'] / 100, 2) }}
                                    </div>
                                </div>
                            @endif

                            @if (!empty($matchingHints['suggested_merchant_names']))
                                <div>
                                    <div class="text-base-content/60">Merchant Aliases</div>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($matchingHints['suggested_merchant_names'] as $name)
                                            <div class="badge badge-sm badge-ghost">{{ $name }}</div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if (!empty($matchingHints['card_last_four']))
                                <div>
                                    <div class="text-base-content/60">Card Last 4</div>
                                    <div class="font-mono">•••• {{ $matchingHints['card_last_four'] }}</div>
                                </div>
                            @endif

                            @if (!empty($matchingHints['time_window_minutes']))
                                <div>
                                    <div class="text-base-content/60">Time Window</div>
                                    <div>± {{ $matchingHints['time_window_minutes'] }} minutes</div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- Integration Info --}}
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <h3 class="card-title text-sm">Receipt Details</h3>

                    <div class="space-y-2 text-sm">
                        <div>
                            <div class="text-base-content/60">Integration</div>
                            <div class="badge badge-sm">{{ $receipt->integration?->name ?? 'Receipt' }}</div>
                        </div>

                        <div>
                            <div class="text-base-content/60">Received</div>
                            <div>{{ $receipt->created_at->diffForHumans() }}</div>
                        </div>

                        @if (!empty($metadata['original_language']) && $metadata['original_language'] !== 'en')
                            <div>
                                <div class="text-base-content/60">Original Language</div>
                                <div>{{ strtoupper($metadata['original_language']) }}</div>
                                <div class="text-xs text-base-content/60">Translated to English</div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Manual Match Modal --}}
    @if ($showMatchModal)
        <x-modal wire:model="showMatchModal" title="Match Receipt to Transaction" class="backdrop-blur">
            <div class="space-y-4">
                {{-- Search Transactions --}}
                <div>
                    <h3 class="font-semibold mb-2">Search Transactions</h3>
                    <x-input placeholder="Search merchant, amount or YYYY-MM-DD..." icon="fas.search" wire:model.live.debounce.300ms="transactionSearch" />
                    <div class="max-h-80 overflow-y-auto space-y-2 mt-3">
                        @forelse ($searchTransactions as $transaction)
                            <button type="button" class="card bg-base-200 w-full text-left" wire:click="createManualMatch('{{ $transaction->id }}')">
                                <span class="card-body p-3 flex-row justify-between gap-3">
                                    <span><strong>{{ $transaction->target?->title ?? 'Transaction' }}</strong><br><small>{{ $transaction->time->format('j M Y H:i') }}</small></span>
                                    <span>{{ $transaction->value_unit }} {{ number_format($transaction->formatted_value, 2) }}</span>
                                </span>
                            </button>
                        @empty
                            <p class="text-sm text-base-content/60">No transactions found. Try another merchant or amount.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <x-slot:actions>
                <x-button label="Cancel" wire:click="closeMatchModal" />
            </x-slot:actions>
        </x-modal>
    @endif
</div>
