@props(['block'])

<x-block-card.shell :block="$block" :title="$block->metadata['location'] ?? $block->title" />
