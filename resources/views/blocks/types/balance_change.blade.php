@props(['block'])

@php
$direction = $block->metadata['direction'] ?? 'unknown';
$isPositive = in_array($direction, ['positive', 'increase'], true);
$amount = format_money(abs($block->formatted_value ?? 0), $block->value_unit);
@endphp

<x-block-card.shell :block="$block">
    <div class="flex items-center gap-2">
        <x-icon :name="$isPositive ? 'fas.arrow-trend-up' : 'fas.arrow-trend-down'" class="w-5 h-5 text-base-content/70" />
        <x-block-card.value :value="($isPositive ? '+' : '−') . $amount" />
    </div>
    @if (filled($block->title))
        <x-slot:context>{{ $block->title }}</x-slot:context>
    @endif
</x-block-card.shell>
