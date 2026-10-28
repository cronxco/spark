@props(['block'])

@php
// Check if custom layout exists
$customLayoutPath = $block->getCustomCardLayoutPath();
if ($customLayoutPath && view()->exists($customLayoutPath)) {
    // Use custom layout
    echo view($customLayoutPath, ['block' => $block])->render();
    return;
}

// Check if this is a value block or content block
$isValueBlock = !is_null($block->value);

// For content blocks, extract preview content
$contentPreview = null;
$imageUrl = null;
if (!$isValueBlock) {
    $metadata = $block->metadata ?? [];

    // Try to find content in common metadata fields
    if (isset($metadata['summary'])) {
        $contentPreview = $metadata['summary'];
    } elseif (isset($metadata['article_text'])) {
        $contentPreview = Str::limit($metadata['article_text'], 500);
    } elseif (isset($metadata['description'])) {
        $contentPreview = $metadata['description'];
    } elseif (isset($metadata['content'])) {
        $contentPreview = $metadata['content'];
    } elseif (isset($metadata['text'])) {
        $contentPreview = $metadata['text'];
    }

    // Check for images - use Media Library for responsive images with signed URLs
    $responsiveImageHtml = render_media_responsive(
        $block,
        'downloaded_images',
        [
            'class' => 'w-full h-full object-cover',
            'loading' => 'lazy',
            'alt' => $block->title,
        ]
    );
}
@endphp

@if ($isValueBlock)
    <x-block-card.shell :block="$block">
        <div class="text-3xl font-bold tabular-nums">
            {{ format_block_value_display($block->formatted_value, $block->value_unit, $block->value_multiplier) }}
        </div>
        @if (filled($block->title))
            <x-slot:context>{{ $block->title }}</x-slot:context>
        @endif
    </x-block-card.shell>
@else
    <x-block-card.shell :block="$block" :title="$block->title">
        @if ($responsiveImageHtml)
            <div class="w-full h-48 rounded-lg overflow-hidden bg-base-300">
                {!! $responsiveImageHtml !!}
            </div>
        @endif

        @if ($contentPreview)
            <div class="prose prose-sm max-w-none text-base-content/70 line-clamp-5">
                {!! render_markdown($contentPreview) !!}
            </div>
        @endif
    </x-block-card.shell>
@endif
