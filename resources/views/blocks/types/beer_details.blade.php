@props(['block'])

@php
$style = $block->metadata['style'] ?? null;
$abv = $block->metadata['abv'] ?? null;
$ibu = $block->metadata['ibu'] ?? null;
$rating = $block->value ?? $block->metadata['aggregate_rating'] ?? null;
$reviewCount = $block->metadata['review_count'] ?? null;
$description = $block->metadata['description'] ?? null;
$imageUrl = get_media_url($block, 'downloaded_images', 'medium');
@endphp

<x-block-card.shell :block="$block" :title="$block->title">
    <div class="flex items-start gap-3">
        @if ($imageUrl)
            <img src="{{ $imageUrl }}" alt="" class="w-20 h-20 rounded-lg object-cover shrink-0" loading="lazy">
        @endif
        <div class="min-w-0 space-y-2">
            @if ($style)
                <div class="text-sm text-base-content/70">{{ $style }}</div>
            @endif
            <x-block-card.stats class="!grid-cols-2" :stats="[
                'ABV' => $abv ? $abv . '%' : null,
                'IBU' => $ibu,
                'Rating' => is_numeric($rating) ? number_format($rating, 2) : null,
                'Reviews' => is_numeric($reviewCount) ? number_format($reviewCount) : null,
            ]" />
        </div>
    </div>
    @if ($description)
        <p class="text-sm text-base-content/70 line-clamp-3">{{ $description }}</p>
    @endif
</x-block-card.shell>
