@props(['block'])

<x-block-card.shell :block="$block">
    <x-block-card.value :value="number_format($block->formatted_value ?? 0, 1)" :unit="$block->value_unit" />
    @if (filled($block->title))
        <x-slot:context>{{ $block->title }}</x-slot:context>
    @endif
</x-block-card.shell>
