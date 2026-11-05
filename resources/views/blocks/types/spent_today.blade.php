@props(['block'])

<x-block-card.shell :block="$block">
    <x-block-card.value :value="format_money($block->formatted_value ?? 0, $block->value_unit)" />
    @if (filled($block->title))
        <x-slot:context>{{ $block->title }}</x-slot:context>
    @endif
</x-block-card.shell>
