@props(['block'])

@php
$beer = $block->event->target;
$venue = $beer->metadata['venue'] ?? null;
@endphp

<x-block-card.shell :block="$block" :title="$beer->title ?? $block->title">
    <div class="flex items-center gap-2 text-sm text-base-content/70">
        <x-icon name="fas.industry" class="w-3.5 h-3.5" />
        <span>{{ $block->title }}</span>
    </div>
    @if ($venue)
        <x-slot:context>{{ $venue }}</x-slot:context>
    @endif
</x-block-card.shell>
