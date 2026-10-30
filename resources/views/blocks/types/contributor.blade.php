@props(['block'])

{{-- Oura contributors are scores out of 100, so they take the ring with Oura's "excellent" line at 85. --}}
<x-block-card.shell :block="$block">
    <x-block-card.ring :value="$block->formatted_value ?? 0" :max="100" :threshold="85" :label="$block->title" />
</x-block-card.shell>
