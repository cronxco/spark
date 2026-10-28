@props(['block'])

<x-block-card.shell :block="$block">
    <x-block-card.summary :text="$block->metadata['content'] ?? ''" />
</x-block-card.shell>
