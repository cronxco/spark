@props(['block'])

<x-block-card.shell :block="$block">
    <x-block-card.takeaways :items="$block->metadata['content'] ?? []" />
</x-block-card.shell>
