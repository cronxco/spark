@props(['block'])

@php
$rawValue = $block->formatted_value ?? 0;
$isDuration = str_contains(strtolower($block->title), 'duration');
@endphp

<x-block-card.shell :block="$block">
    {{-- The old layout dropped the unit on purpose; a number without one doesn't say what it measures. --}}
    <x-block-card.value :value="$isDuration ? format_duration($rawValue) : round($rawValue, 1)" :unit="$isDuration ? null : $block->value_unit" />
    @if (filled($block->title))
        <x-slot:context>{{ $block->title }}</x-slot:context>
    @endif
</x-block-card.shell>
