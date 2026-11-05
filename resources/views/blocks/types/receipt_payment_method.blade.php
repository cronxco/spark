@props(['block'])

@php
$paymentMethod = $block->metadata['payment_method'] ?? null;
$cardLast4 = $block->metadata['card_last_4'] ?? null;
$receiptNumber = $block->metadata['receipt_number'] ?? null;
$terminalId = $block->metadata['terminal_id'] ?? null;

// Determine payment icon based on method
$paymentIcon = match (strtolower($paymentMethod ?? '')) {
    'card', 'credit', 'debit', 'credit_card', 'debit_card' => 'fas.credit-card',
    'cash' => 'fas.money-bill-wave',
    'contactless', 'apple_pay', 'google_pay' => 'fas.wifi',
    'bank_transfer', 'transfer' => 'fas.building-columns',
    default => 'fas.credit-card',
};

// Format payment method for display
$paymentMethodDisplay = match (strtolower($paymentMethod ?? '')) {
    'card', 'credit_card' => 'Credit Card',
    'debit_card' => 'Debit Card',
    'cash' => 'Cash',
    'contactless' => 'Contactless',
    'apple_pay' => 'Apple Pay',
    'google_pay' => 'Google Pay',
    'bank_transfer', 'transfer' => 'Bank Transfer',
    default => $paymentMethod ? ucwords(str_replace('_', ' ', $paymentMethod)) : 'Card',
};
@endphp

<x-block-card.shell :block="$block">
    {{-- Receipt Payment Display - White thermal paper style --}}
    <div class="bg-white dark:bg-neutral-900 rounded-lg p-4 font-mono text-sm border border-neutral-200 dark:border-neutral-700">
        {{-- Payment method with icon --}}
        <div class="flex items-center gap-3 mb-3">
            <div class="w-10 h-10 rounded-lg bg-neutral-100 dark:bg-neutral-800 flex items-center justify-center">
                <x-icon name="{{ $paymentIcon }}" class="w-5 h-5 text-neutral-600 dark:text-neutral-300" />
            </div>
            <div>
                <div class="font-semibold text-neutral-800 dark:text-neutral-100">
                    {{ $paymentMethodDisplay }}
                </div>
                @if ($cardLast4)
                <div class="text-xs text-neutral-500 dark:text-neutral-400 tracking-wider">
                    •••• {{ $cardLast4 }}
                </div>
                @endif
            </div>
        </div>

        {{-- Receipt details --}}
        @if ($receiptNumber || $terminalId)
        <div class="border-t border-dashed border-neutral-300 dark:border-neutral-600 pt-3 space-y-1">
            @if ($receiptNumber)
            <div class="flex justify-between text-xs text-neutral-500 dark:text-neutral-400">
                <span>RECEIPT #</span>
                <span class="tabular-nums">{{ $receiptNumber }}</span>
            </div>
            @endif
            @if ($terminalId)
            <div class="flex justify-between text-xs text-neutral-500 dark:text-neutral-400">
                <span>TERMINAL</span>
                <span class="tabular-nums">{{ $terminalId }}</span>
            </div>
            @endif
        </div>
        @endif

    </div>
</x-block-card.shell>
