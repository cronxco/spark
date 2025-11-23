<?php

use function Livewire\Volt\{state, computed};

state(['search' => '', 'statusFilter' => 'all', 'sortBy' => ['column' => 'time', 'direction' => 'desc'], 'perPage' => 25]);

?>

<div>
    <x-header title="Receipts" separator>
        <x-slot:middle class="!justify-end">
            <x-input placeholder="Search receipts..." wire:model.live.debounce="search" icon="fas.search" clearable />
        </x-slot:middle>
        <x-slot:actions>
            <x-button label="Clear Filters" icon="fas.xmark" wire:click="clearFilters" class="btn-ghost btn-sm" />
        </x-slot:actions>
    </x-header>

    {{-- Stats Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="stats shadow">
            <div class="stat">
                <div class="stat-figure text-primary">
                    <x-icon name="fas.receipt" class="w-8 h-8" />
                </div>
                <div class="stat-title">Total Receipts</div>
                <div class="stat-value text-primary">{{ $receipts->total() }}</div>
            </div>
        </div>

        <div class="stats shadow">
            <div class="stat">
                <div class="stat-figure text-success">
                    <x-icon name="fas.circle-check" class="w-8 h-8" />
                </div>
                <div class="stat-title">Matched</div>
                <div class="stat-value text-success">
                    {{ \App\Models\Event::where('service', 'receipt')
                        ->whereHas('target', fn($q) => $q->whereJsonContains('metadata->is_matched', true))
                        ->count() }}
                </div>
            </div>
        </div>

        <div class="stats shadow">
            <div class="stat">
                <div class="stat-figure text-warning">
                    <x-icon name="fas.triangle-exclamation" class="w-8 h-8" />
                </div>
                <div class="stat-title">Needs Review</div>
                <div class="stat-value text-warning">
                    {{ \App\Models\Event::where('service', 'receipt')
                        ->whereHas('target', fn($q) => $q->whereJsonContains('metadata->needs_review', true))
                        ->count() }}
                </div>
            </div>
        </div>

        <div class="stats shadow">
            <div class="stat">
                <div class="stat-figure text-info">
                    <x-icon name="fas.clock" class="w-8 h-8" />
                </div>
                <div class="stat-title">Unmatched</div>
                <div class="stat-value text-info">
                    {{ \App\Models\Event::where('service', 'receipt')
                        ->whereHas('target', function($q) {
                            $q->where(function($sub) {
                                $sub->whereJsonContains('metadata->is_matched', false)
                                    ->orWhereNull('metadata->is_matched');
                            })->where(function($sub) {
                                $sub->whereJsonContains('metadata->needs_review', false)
                                    ->orWhereNull('metadata->needs_review');
                            });
                        })
                        ->count() }}
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="flex gap-2 mb-4">
        <x-button label="All" wire:click="$set('statusFilter', 'all')"
            class="btn-sm {{ $statusFilter === 'all' ? 'btn-primary' : 'btn-ghost' }}" />
        <x-button label="Matched" wire:click="$set('statusFilter', 'matched')"
            class="btn-sm {{ $statusFilter === 'matched' ? 'btn-success' : 'btn-ghost' }}"
            icon="fas.circle-check" />
        <x-button label="Needs Review" wire:click="$set('statusFilter', 'review')"
            class="btn-sm {{ $statusFilter === 'review' ? 'btn-warning' : 'btn-ghost' }}"
            icon="fas.triangle-exclamation" />
        <x-button label="Unmatched" wire:click="$set('statusFilter', 'unmatched')"
            class="btn-sm {{ $statusFilter === 'unmatched' ? 'btn-info' : 'btn-ghost' }}"
            icon="fas.clock" />
    </div>

    {{-- Receipts Table --}}
    <div class="card bg-base-100 shadow-xl">
        <div class="card-body p-0">
            <div class="overflow-x-auto">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th>
                                <button wire:click="sortByColumn('time')" class="flex items-center gap-1">
                                    Date
                                    @if ($sortBy['column'] === 'time')
                                        <x-icon name="fas.chevron-{{ $sortBy['direction'] === 'asc' ? 'up' : 'down' }}" class="w-4 h-4" />
                                    @endif
                                </button>
                            </th>
                            <th>Merchant</th>
                            <th>
                                <button wire:click="sortByColumn('value')" class="flex items-center gap-1">
                                    Amount
                                    @if ($sortBy['column'] === 'value')
                                        <x-icon name="fas.chevron-{{ $sortBy['direction'] === 'asc' ? 'up' : 'down' }}" class="w-4 h-4" />
                                    @endif
                                </button>
                            </th>
                            <th>Status</th>
                            <th>Items</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($receipts as $receipt)
                            @php
                                $merchant = $receipt->target;
                                $metadata = $merchant?->metadata ?? [];
                                $isMatched = $metadata['is_matched'] ?? false;
                                $needsReview = $metadata['needs_review'] ?? false;
                                $extractedData = $metadata['extracted_data'] ?? [];
                                $lineItems = $extractedData['line_items'] ?? [];
                            @endphp
                            <tr class="hover">
                                <td>
                                    <div class="flex flex-col">
                                        <span class="font-semibold">{{ $receipt->time->format('M d, Y') }}</span>
                                        <span class="text-xs text-base-content/60">{{ $receipt->time->format('H:i') }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <x-icon name="fas.store" class="w-4 h-4 text-base-content/60" />
                                        <a href="{{ route('objects.show', $merchant) }}" class="link link-hover font-medium">
                                            {{ $merchant?->title ?? 'Unknown Merchant' }}
                                        </a>
                                    </div>
                                </td>
                                <td>
                                    <span class="font-mono font-semibold">
                                        {{ $receipt->value_unit }} {{ number_format($receipt->value / ($receipt->value_multiplier ?: 1), 2) }}
                                    </span>
                                </td>
                                <td>
                                    @if ($isMatched)
                                        <div class="badge badge-success gap-1">
                                            <x-icon name="fas.circle-check" class="w-3 h-3" />
                                            Matched
                                        </div>
                                    @elseif ($needsReview)
                                        <div class="badge badge-warning gap-1">
                                            <x-icon name="fas.triangle-exclamation" class="w-3 h-3" />
                                            Review
                                        </div>
                                    @else
                                        <div class="badge badge-info gap-1">
                                            <x-icon name="fas.clock" class="w-3 h-3" />
                                            Unmatched
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div class="badge badge-ghost">{{ count($lineItems) }} items</div>
                                </td>
                                <td class="text-right">
                                    <div class="flex gap-1 justify-end">
                                        <x-button icon="fas.eye" link="{{ route('receipts.show', $receipt->id) }}"
                                            class="btn-ghost btn-xs" tooltip="View Details" />

                                        @if ($needsReview)
                                            <x-button icon="fas.link" wire:click="openMatchModal('{{ $receipt->id }}')"
                                                class="btn-warning btn-xs" tooltip="Review Matches" />
                                        @endif

                                        @if ($isMatched)
                                            <x-button icon="fas.xmark" wire:click="removeMatch('{{ $receipt->id }}')"
                                                class="btn-ghost btn-xs" tooltip="Remove Match"
                                                wire:confirm="Are you sure you want to remove this match?" />
                                        @else
                                            <x-button icon="fas.link" wire:click="openMatchModal('{{ $receipt->id }}')"
                                                class="btn-ghost btn-xs" tooltip="Manual Match" />
                                        @endif

                                        <x-button icon="fas.trash" wire:click="deleteReceipt('{{ $receipt->id }}')"
                                            class="btn-error btn-xs" tooltip="Delete Receipt"
                                            wire:confirm="Are you sure you want to delete this receipt? This cannot be undone." />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-8">
                                    <div class="flex flex-col items-center gap-2">
                                        <x-icon name="fas.inbox" class="w-12 h-12 text-base-content/30" />
                                        <p class="text-base-content/60">No receipts found</p>
                                        @if ($search || $statusFilter !== 'all')
                                            <x-button label="Clear Filters" wire:click="clearFilters" class="btn-sm btn-ghost" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Pagination --}}
    <div class="mt-4">
        {{ $receipts->links() }}
    </div>

    {{-- Manual Match Modal --}}
    @if ($showMatchModal && $selectedReceiptId)
        <x-modal wire:model="showMatchModal" title="Match Receipt to Transaction" class="backdrop-blur">
            <div class="space-y-4">
                @php
                    $selectedReceipt = \App\Models\Event::find($selectedReceiptId);
                    $merchant = $selectedReceipt?->target;
                    $metadata = $merchant?->metadata ?? [];
                    $candidates = $metadata['match_candidates'] ?? [];
                @endphp

                {{-- Receipt Summary --}}
                <div class="card bg-base-200">
                    <div class="card-body">
                        <h3 class="card-title text-base">Receipt Details</h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-base-content/60">Merchant:</span>
                                <span class="font-semibold">{{ $merchant?->title }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-base-content/60">Amount:</span>
                                <span class="font-mono font-semibold">
                                    {{ $selectedReceipt?->value_unit }} {{ number_format($selectedReceipt?->value / ($selectedReceipt?->value_multiplier ?: 1), 2) }}
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-base-content/60">Date:</span>
                                <span>{{ $selectedReceipt?->time->format('M d, Y H:i') }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Candidate Transactions --}}
                @if (count($candidates) > 0)
                    <div>
                        <h3 class="font-semibold mb-2">Suggested Matches</h3>
                        <div class="space-y-2">
                            @foreach ($candidates as $candidate)
                                @php
                                    $transaction = \App\Models\Event::find($candidate['transaction_id']);
                                    $confidence = $candidate['confidence'] ?? 0;
                                @endphp
                                @if ($transaction)
                                    <div class="card bg-base-100 border border-base-300 hover:border-primary cursor-pointer"
                                        wire:click="createManualMatch('{{ $selectedReceiptId }}', '{{ $transaction->id }}')">
                                        <div class="card-body p-3">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <div class="font-semibold">{{ $transaction->target?->title ?? 'Transaction' }}</div>
                                                    <div class="text-sm text-base-content/60">
                                                        {{ $transaction->time->format('M d, Y H:i') }}
                                                    </div>
                                                </div>
                                                <div class="text-right">
                                                    <div class="font-mono font-semibold">
                                                        {{ $transaction->value_unit }} {{ number_format($transaction->value / ($transaction->value_multiplier ?: 1), 2) }}
                                                    </div>
                                                    <div class="badge badge-sm {{ $confidence >= 0.8 ? 'badge-success' : 'badge-warning' }}">
                                                        {{ round($confidence * 100) }}% match
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="text-center py-4 text-base-content/60">
                        <x-icon name="fas.search" class="w-8 h-8 mx-auto mb-2" />
                        <p>No matching transactions found</p>
                        <p class="text-sm">You can manually search for a transaction below</p>
                    </div>
                @endif

                {{-- Manual Search --}}
                <div>
                    <h3 class="font-semibold mb-2">Search Transactions</h3>
                    <x-input placeholder="Search by merchant, amount, or date..." icon="fas.search" />
                    <p class="text-xs text-base-content/60 mt-1">Feature coming soon</p>
                </div>
            </div>

            <x-slot:actions>
                <x-button label="Cancel" wire:click="closeMatchModal" />
            </x-slot:actions>
        </x-modal>
    @endif
</div>
