@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.receipt';

$quantity = $block->metadata['quantity'] ?? 1;
$unit = $block->metadata['unit'] ?? null;
$unitPrice = $block->metadata['unit_price'] ?? null;
$category = $block->metadata['category'] ?? null;
$sku = $block->metadata['sku'] ?? null;
$sequence = $block->metadata['sequence'] ?? null;

$currency = $block->value_unit ?? 'GBP';
$currencySymbol = match ($currency) {
    'GBP' => '£',
    'USD' => '$',
    'EUR' => '€',
    default => $currency . ' ',
};

$totalPrice = $block->value / ($block->value_multiplier ?? 100);
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header: Title and Date --}}
        <div class="flex items-center justify-between gap-2">
            <h3 class="font-semibold text-base leading-snug flex-1 line-clamp-1">
                <a href="{{ route('blocks.show', $block) }}" wire:navigate class="hover:underline">
                    {{ $block->title }}
                </a>
            </h3>
            <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
        </div>

        {{-- Receipt Line Item Display - White thermal paper style --}}
        <div class="bg-white dark:bg-neutral-900 rounded-lg p-4 font-mono text-sm border border-neutral-200 dark:border-neutral-700 shadow-inner">
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
                            {{ $currencySymbol }}{{ number_format($unitPrice / ($block->value_multiplier ?? 100), 2) }} ea
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
                        {{ $currencySymbol }}{{ number_format($totalPrice, 2) }}
                    </div>
                </div>
            </div>

            {{-- Dotted line separator (receipt aesthetic) --}}
            <div class="border-b border-dashed border-neutral-300 dark:border-neutral-600 mt-3 mb-1"></div>
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Line Item
            </div>

            @if ($quantity > 1)
            <div class="badge badge-outline badge-sm">
                × {{ $quantity }}
            </div>
            @endif

            <div class="flex-1"></div>

            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-xs btn-square">
                    <x-icon name="fas.ellipsis-vertical" class="w-4 h-4" />
                </div>
                <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-[1] w-52 p-2 shadow-lg border border-base-300">
                    <li>
                        <a href="{{ route('blocks.show', $block) }}" wire:navigate>
                            <x-icon name="fas.eye" class="w-4 h-4" />
                            View Block
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('events.show', $block->event) }}" wire:navigate>
                            <x-icon name="fas.calendar" class="w-4 h-4" />
                            View Event
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
