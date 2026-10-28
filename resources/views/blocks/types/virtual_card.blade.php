@props(['block'])

@php
$name = $block->metadata['name'] ?? $block->title;
$pan = $block->metadata['card_details']['last_digits'] ?? $block->metadata['last_digits'] ?? null;
@endphp

<x-block-card.shell :block="$block" :title="$name">
    @if ($pan)
        <x-slot:context><span class="font-mono">Virtual card ••{{ substr($pan, -4) }}</span></x-slot:context>
    @endif
</x-block-card.shell>
