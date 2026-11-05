@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.receipt';

$taxRate = $block->metadata['tax_rate'] ?? null;
$subtotal = $block->metadata['subtotal'] ?? null;
$discountTotal = $block->metadata['discount_total'] ?? 0;
$tipAmount = $block->metadata['tip_amount'] ?? 0;

$currency = $block->value_unit ?? 'GBP';
$currencySymbol = match ($currency) {
    'GBP' => '£',
    'USD' => '$',
    'EUR' => '€',
    default => $currency . ' ',
};

$multiplier = $block->value_multiplier ?? 100;
$taxAmount = $block->value / $multiplier;
$subtotalFormatted = $subtotal ? $subtotal / $multiplier : null;
$discountFormatted = $discountTotal ? $discountTotal / $multiplier : null;
$tipFormatted = $tipAmount ? $tipAmount / $multiplier : null;
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

        {{-- Receipt Tax Summary Display - White thermal paper style --}}
        <div class="bg-white dark:bg-neutral-900 rounded-lg p-4 font-mono text-sm border border-neutral-200 dark:border-neutral-700 shadow-inner">
            {{-- Summary lines --}}
            <div class="space-y-2">
                @if ($subtotalFormatted)
                <div class="flex justify-between items-center text-neutral-600 dark:text-neutral-300">
                    <span>SUBTOTAL</span>
                    <span class="tabular-nums">{{ $currencySymbol }}{{ number_format($subtotalFormatted, 2) }}</span>
                </div>
                @endif

                @if ($discountFormatted && $discountFormatted > 0)
                <div class="flex justify-between items-center text-success">
                    <span>DISCOUNT</span>
                    <span class="tabular-nums">-{{ $currencySymbol }}{{ number_format($discountFormatted, 2) }}</span>
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
                    <span class="tabular-nums">{{ $currencySymbol }}{{ number_format($taxAmount, 2) }}</span>
                </div>

                @if ($tipFormatted && $tipFormatted > 0)
                <div class="flex justify-between items-center text-neutral-600 dark:text-neutral-300">
                    <span>TIP</span>
                    <span class="tabular-nums">{{ $currencySymbol }}{{ number_format($tipFormatted, 2) }}</span>
                </div>
                @endif
            </div>

            {{-- Double line separator (receipt total aesthetic) --}}
            <div class="border-t-2 border-double border-neutral-300 dark:border-neutral-600 mt-3 pt-1"></div>
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Tax Summary
            </div>

            @if ($taxRate)
            <div class="badge badge-outline badge-sm">
                {{ $taxRate }}% VAT
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
