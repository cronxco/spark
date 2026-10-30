@props(['block'])

<x-block-card.shell :block="$block">
    <x-block-card.value :value="round($block->formatted_value ?? 0)" unit="bpm" />
    @if (filled($block->title))
        <x-slot:context>{{ $block->title }}</x-slot:context>
    @endif
</x-block-card.shell>
