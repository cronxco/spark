@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'o-squares-2x2';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

// Get currency amounts from metadata
$fromCurrency = null;
$fromAmount = null;
$toCurrency = null;
$toAmount = null;
$rate = $block->metadata['rate'] ?? null;

foreach($block->metadata as $key => $value) {
    if(strlen($key) === 3 && ctype_alpha($key) && is_numeric($value)) {
        if(!$fromCurrency) {
            $fromCurrency = $key;
            $fromAmount = $value;
        } else {
            $toCurrency = $key;
            $toAmount = $value;
        }
    }
}
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

        {{-- FX Display --}}
        <div class="flex items-center justify-center gap-3 py-2">
            @if ($fromCurrency && $fromAmount)
            <div class="text-center">
                <div class="text-2xl font-bold">{{ number_format($fromAmount, 2) }}</div>
                <div class="text-xs text-base-content/60">{{ $fromCurrency }}</div>
            </div>
            @endif
            <x-icon name="o-arrow-right" class="w-6 h-6 text-base-content/40" />
            @if ($toCurrency && $toAmount)
            <div class="text-center">
                <div class="text-2xl font-bold">{{ number_format($toAmount, 2) }}</div>
                <div class="text-xs text-base-content/60">{{ $toCurrency }}</div>
            </div>
            @endif
        </div>

        @if ($rate)
        <div class="text-center text-xs text-base-content/60">
            Rate: {{ number_format($rate, 4) }}
        </div>
        @endif

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                FX
            </div>

            <div class="flex-1"></div>

            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-xs btn-square">
                    <x-icon name="o-ellipsis-vertical" class="w-4 h-4" />
                </div>
                <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-[1] w-52 p-2 shadow-lg border border-base-300">
                    <li>
                        <a href="{{ route('blocks.show', $block) }}" wire:navigate>
                            <x-icon name="o-eye" class="w-4 h-4" />
                            View Block
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('events.show', $block->event) }}" wire:navigate>
                            <x-icon name="o-calendar" class="w-4 h-4" />
                            View Event
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
