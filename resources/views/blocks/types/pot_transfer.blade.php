@props(['block'])

@php
$isDeposit = ($block->metadata['direction'] ?? null) === 'deposit';
$potName = $block->metadata['pot_name'] ?? 'Pot';
@endphp

<x-block-card.shell :block="$block">
    <div class="flex items-center gap-2 text-sm">
        <span class="text-base-content/70">{{ $isDeposit ? 'Account' : $potName }}</span>
        <x-icon name="fas.arrow-right" class="w-3.5 h-3.5 text-base-content/70" />
        <span class="font-semibold">{{ $isDeposit ? $potName : 'Account' }}</span>
    </div>
    <x-block-card.value :value="format_money($block->formatted_value ?? 0, $block->value_unit)" />
</x-block-card.shell>
