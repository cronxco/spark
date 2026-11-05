@props(['block'])

<x-block-card.shell :block="$block">
    <x-block-card.ring :value="$block->formatted_value ?? 0" :max="5" :label="$block->title" />
</x-block-card.shell>
