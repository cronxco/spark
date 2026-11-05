@props(['block'])

@php
$title = $block->metadata['title'] ?? $block->title;
$description = $block->metadata['description'] ?? '';
$source = collect([$block->metadata['site_name'] ?? null, $block->metadata['author'] ?? null])->filter()->join(' · ');

$responsiveImageHtml = render_media_responsive(
    $block,
    'downloaded_images',
    [
        'class' => 'w-full h-full object-cover',
        'loading' => 'lazy',
        'alt' => $title,
    ]
);
@endphp

<x-block-card.shell :block="$block" :title="$title">
    @if ($responsiveImageHtml)
        <div class="w-full aspect-video rounded-lg overflow-hidden bg-domain-knowledge/10">
            {!! $responsiveImageHtml !!}
        </div>
    @endif

    @if ($description)
        <p class="text-sm text-base-content/70 line-clamp-3">{{ $description }}</p>
    @endif

    @if ($source !== '')
        <x-slot:context>{{ $source }}</x-slot:context>
    @endif
</x-block-card.shell>
