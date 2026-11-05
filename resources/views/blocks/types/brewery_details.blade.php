@props(['block'])

@php
$description = $block->metadata['description'] ?? null;
$rating = $block->value ?? $block->metadata['aggregate_rating'] ?? null;
$reviewCount = $block->metadata['review_count'] ?? null;
$imageUrl = get_media_url($block, 'downloaded_images', 'medium');
$fullAddress = $block->metadata['address'] ?? null;
if (! $fullAddress) {
    $fullAddress = collect([
        $block->metadata['street_address'] ?? null,
        $block->metadata['locality'] ?? null,
        $block->metadata['region'] ?? null,
    ])->filter()->join(', ');
}
@endphp

<x-block-card.shell :block="$block" :title="$block->title">
    <div class="flex items-start gap-3">
        @if ($imageUrl)
            <img src="{{ $imageUrl }}" alt="" class="w-20 h-20 rounded-lg object-cover shrink-0" loading="lazy">
        @endif
        <div class="min-w-0 space-y-2">
            <x-block-card.stats class="!grid-cols-2" :stats="[
                'Rating' => is_numeric($rating) ? number_format($rating, 2) : null,
                'Reviews' => is_numeric($reviewCount) ? number_format($reviewCount) : null,
            ]" />
            @if ($description)
                <p class="text-sm text-base-content/70 line-clamp-3">{{ $description }}</p>
            @endif
        </div>
    </div>
    @if (filled($fullAddress))
        <x-slot:context>{{ $fullAddress }}</x-slot:context>
    @endif
</x-block-card.shell>
