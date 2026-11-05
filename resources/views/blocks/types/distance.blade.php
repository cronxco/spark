@props(['block'])

<x-block-card.shell :block="$block">
    <x-block-card.value :value="number_format($block->formatted_value ?? 0, 2)" :unit="$block->value_unit ?? 'km'" />
    @if (filled($block->title))
        <x-slot:context>{{ $block->title }}</x-slot:context>
    @endif
</x-block-card.shell>
