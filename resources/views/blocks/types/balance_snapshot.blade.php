@props(['block'])

@php
$balanceType = $block->metadata['balance_type'] ?? null;
@endphp

<x-block-card.shell :block="$block">
    <x-block-card.value :value="format_money($block->formatted_value ?? 0, $block->value_unit)" />
    <x-slot:context>{{ collect([$block->title, $balanceType ? ucfirst(str_replace('_', ' ', $balanceType)) : null])->filter()->unique()->join(' · ') }}</x-slot:context>
</x-block-card.shell>
