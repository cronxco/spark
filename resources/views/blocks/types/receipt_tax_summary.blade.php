@props(['block'])

@php
$taxRate = $block->metadata['tax_rate'] ?? null;
$subtotal = $block->metadata['subtotal'] ?? null;
$discountTotal = $block->metadata['discount_total'] ?? 0;
$tipAmount = $block->metadata['tip_amount'] ?? 0;

$currency = $block->value_unit ?? 'GBP';

$multiplier = $block->value_multiplier ?? 100;
$taxAmount = $block->value / $multiplier;
$subtotalFormatted = $subtotal ? $subtotal / $multiplier : null;
$discountFormatted = $discountTotal ? $discountTotal / $multiplier : null;
$tipFormatted = $tipAmount ? $tipAmount / $multiplier : null;
@endphp

<x-block-card.shell :block="$block">
    {{-- Receipt Tax Summary Display - White thermal paper style --}}
    <div class="bg-white dark:bg-neutral-900 rounded-lg p-4 font-mono text-sm border border-neutral-200 dark:border-neutral-700">
        {{-- Summary lines --}}
        <div class="space-y-2">
            @if ($subtotalFormatted)
            <div class="flex justify-between items-center text-neutral-600 dark:text-neutral-300">
                <span>SUBTOTAL</span>
                <span class="tabular-nums">{{ format_money($subtotalFormatted, $currency) }}</span>
            </div>
            @endif

            @if ($discountFormatted && $discountFormatted > 0)
            <div class="flex justify-between items-center text-success">
                <span>DISCOUNT</span>
                <span class="tabular-nums">−{{ format_money($discountFormatted, $currency) }}</span>
            </div>
            @endif

            {{-- Tax line with rate if available --}}
            <div class="flex justify-between items-center text-neutral-800 dark:text-neutral-100 font-semibold">
                <span>
                    TAX
                    @if ($taxRate)
                        <span class="font-normal text-xs text-neutral-500 dark:text-neutral-400">({{ $taxRate }}%)</span>
                    @endif
                </span>
                <span class="tabular-nums">{{ format_money($taxAmount, $currency) }}</span>
            </div>

            @if ($tipFormatted && $tipFormatted > 0)
            <div class="flex justify-between items-center text-neutral-600 dark:text-neutral-300">
                <span>TIP</span>
                <span class="tabular-nums">{{ format_money($tipFormatted, $currency) }}</span>
            </div>
            @endif
        </div>

        {{-- Double line separator (receipt total aesthetic) --}}
        <div class="border-t-2 border-double border-neutral-300 dark:border-neutral-600 mt-3 pt-1"></div>
    </div>
</x-block-card.shell>
