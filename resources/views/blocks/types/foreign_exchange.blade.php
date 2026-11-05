@props(['block'])

@php
// Currency amounts arrive as three-letter keys in the metadata.
$amounts = collect($block->metadata ?? [])
    ->filter(fn ($value, $key) => is_string($key) && strlen($key) === 3 && ctype_alpha($key) && is_numeric($value))
    ->take(2);
$rate = $block->metadata['rate'] ?? null;
@endphp

<x-block-card.shell :block="$block">
    <div class="flex items-center gap-3">
        @foreach ($amounts as $currency => $amount)
            @if (! $loop->first)
                <x-icon name="fas.arrow-right" class="w-4 h-4 text-base-content/70" />
            @endif
            <span class="text-2xl font-bold tabular-nums">{{ format_money($amount, $currency) }}</span>
        @endforeach
    </div>
    @if (is_numeric($rate))
        <x-slot:context>Rate {{ number_format($rate, 4) }}</x-slot:context>
    @endif
</x-block-card.shell>
