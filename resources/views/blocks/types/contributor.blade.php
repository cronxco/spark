@props(['block'])

<x-block-card.shell :block="$block">
    <x-block-card.bar :percent="$block->formatted_value ?? 0" :label="$block->title" />
</x-block-card.shell>
