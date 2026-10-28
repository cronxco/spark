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

$trackName = $block->metadata['track'] ?? null;
$artist = $block->metadata['artist'] ?? null;
@endphp

<x-block-card.shell :block="$block" :title="$trackName ?: $block->title">
    <div class="w-full aspect-square rounded-lg overflow-hidden bg-domain-media/10 flex items-center justify-center">
        @if ($responsiveImageHtml)
            {!! $responsiveImageHtml !!}
        @else
            <x-icon name="fas.music" class="w-12 h-12 text-domain-media" />
        @endif
    </div>
    @if ($artist)
        <x-slot:context>{{ $artist }}</x-slot:context>
    @endif
</x-block-card.shell>
