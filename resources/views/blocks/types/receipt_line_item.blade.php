@props(['block'])

@php
$quantity = $block->metadata['quantity'] ?? 1;
$unit = $block->metadata['unit'] ?? null;
$unitPrice = $block->metadata['unit_price'] ?? null;
$category = $block->metadata['category'] ?? null;
$sku = $block->metadata['sku'] ?? null;
$sequence = $block->metadata['sequence'] ?? null;

$currency = $block->value_unit ?? 'GBP';

$totalPrice = $block->value / ($block->value_multiplier ?? 100);
@endphp

<x-block-card.shell :block="$block">
    {{-- Receipt Line Item Display - White thermal paper style --}}
    <div class="bg-white dark:bg-neutral-900 rounded-lg p-4 font-mono text-sm border border-neutral-200 dark:border-neutral-700">
        {{-- Sequence number if available --}}
        @if ($sequence)
        <div class="text-xs text-neutral-400 dark:text-neutral-500 mb-1">#{{ $sequence }}</div>
        @endif

        {{-- Main line item row --}}
        <div class="flex justify-between items-start gap-4">
            <div class="flex-1 min-w-0">
                {{-- Item description --}}
                <div class="font-semibold text-neutral-800 dark:text-neutral-100 truncate">
                    {{ $block->title }}
                </div>

                {{-- Quantity and unit price breakdown --}}
                @if ($quantity > 1 || $unitPrice)
                <div class="text-xs text-neutral-500 dark:text-neutral-400 mt-1">
                    @if ($quantity > 1)
                        {{ $quantity }}{{ $unit ? ' ' . $unit : '' }} @
                    @endif
                    @if ($unitPrice)
                        {{ format_money($unitPrice / ($block->value_multiplier ?? 100), $currency) }} ea
                    @endif
                </div>
                @endif

                {{-- Category/SKU info --}}
                @if ($category || $sku)
                <div class="text-xs text-neutral-400 dark:text-neutral-500 mt-1">
                    @if ($category)
                        <span>{{ $category }}</span>
                    @endif
                    @if ($category && $sku)
                        <span class="mx-1">·</span>
                    @endif
                    @if ($sku)
                        <span>SKU: {{ $sku }}</span>
                    @endif
                </div>
                @endif
            </div>

            {{-- Price aligned right --}}
            <div class="text-right flex-shrink-0">
                <div class="font-bold text-neutral-800 dark:text-neutral-100 tabular-nums">
                    {{ format_money($totalPrice, $currency) }}
                </div>
            </div>
        </div>

        {{-- Dotted line separator (receipt aesthetic) --}}
        <div class="border-b border-dashed border-neutral-300 dark:border-neutral-600 mt-3 mb-1"></div>
    </div>
</x-block-card.shell>
