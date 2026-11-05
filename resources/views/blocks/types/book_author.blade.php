@props(['block'])

@php
$book = $block->event->target;
@endphp

<x-block-card.shell :block="$block" :title="$block->title">
    @if ($book?->title)
        <x-slot:context>{{ $book->title }}</x-slot:context>
    @endif
</x-block-card.shell>
