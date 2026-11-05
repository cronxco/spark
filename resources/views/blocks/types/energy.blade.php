@props(['block'])

<x-block-card.shell :block="$block">
    <x-block-card.value :value="number_format(round($block->formatted_value ?? 0))" :unit="$block->value_unit ?? 'kcal'" />
    @if (filled($block->title))
        <x-slot:context>{{ $block->title }}</x-slot:context>
    @endif
</x-block-card.shell>
