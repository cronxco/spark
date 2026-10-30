@props(['block'])

@php
$book = $block->event->target;
$bookTitle = $book->title ?? $block->title;
$authorName = $book->metadata['author'] ?? null;
$rating = $block->event->action === 'reviewed' && $block->event->value ? (int) $block->event->value : null;
$hasImage = $block->getFirstMedia('downloaded_images') !== null;
@endphp

<x-block-card.shell :block="$block" :title="$bookTitle">
    <div class="w-full aspect-[2/3] rounded-lg overflow-hidden bg-domain-knowledge/10 flex items-center justify-center">
        @if ($hasImage)
            {!! render_media_responsive($block, 'downloaded_images', [
                'alt' => '',
                'class' => 'w-full h-full object-cover',
                'loading' => 'lazy',
            ]) !!}
        @else
            <x-icon name="fas.book" class="w-12 h-12 text-domain-knowledge" />
        @endif
    </div>

    @if ($authorName || $rating)
        <x-slot:context>
            {{ collect([$authorName, $rating ? 'Rated ' . $rating . ' out of 5' : null])->filter()->join(' · ') }}
        </x-slot:context>
    @endif
</x-block-card.shell>
