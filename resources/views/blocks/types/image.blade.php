@props(['block'])

@php
$responsiveImageHtml = render_media_responsive(
    $block,
    'downloaded_images',
    [
        'class' => 'w-full h-full object-cover',
        'loading' => 'lazy',
        'alt' => $block->title,
    ]
);

$subreddit = $block->metadata['subreddit'] ?? null;
@endphp

<x-block-card.shell :block="$block" :title="$block->title">
    <div class="w-full h-48 rounded-lg overflow-hidden bg-domain-media/10 flex items-center justify-center">
        @if ($responsiveImageHtml)
            {!! $responsiveImageHtml !!}
        @else
            <x-icon name="fas.image" class="w-10 h-10 text-domain-media" />
        @endif
    </div>
    @if ($subreddit)
        <x-slot:context>r/{{ $subreddit }}</x-slot:context>
    @endif
</x-block-card.shell>
