@props(['block'])

@php
$exerciseName = $block->metadata['exercise_name'] ?? $block->title;
$totalVolume = $block->formatted_value ?? $block->metadata['total_volume'] ?? 0;
$setsCount = $block->metadata['sets_count'] ?? null;
@endphp

<x-block-card.shell :block="$block" :title="$exerciseName">
    <x-block-card.value :value="number_format($totalVolume)" :unit="$block->value_unit ?? $block->metadata['unit'] ?? 'kg'" />
    <x-slot:context>Total volume{{ $setsCount ? ' · ' . $setsCount . ' sets' : '' }}</x-slot:context>
</x-block-card.shell>
