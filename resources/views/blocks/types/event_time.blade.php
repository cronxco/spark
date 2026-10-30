@props(['block'])

@php
$time = $block->metadata['time'] ?? null;
$timezone = $block->metadata['timezone'] ?? null;
@endphp

<x-block-card.shell :block="$block">
    @if ($time)
        <x-block-card.value :value="\Carbon\Carbon::parse($time)->format('H:i')" />
    @endif
    <x-slot:context>{{ collect([$block->title, $timezone])->filter()->join(' · ') }}</x-slot:context>
</x-block-card.shell>
