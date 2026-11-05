@props(['block'])

<x-block-card.shell :block="$block">
    <x-block-card.value :value="format_duration($block->formatted_value ?? 0)" />
    @if (filled($block->title))
        <x-slot:context>{{ $block->title }}</x-slot:context>
    @endif
</x-block-card.shell>
