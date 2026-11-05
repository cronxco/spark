@props(['block'])

@php
$badgeName = $block->metadata['badge_name'] ?? $block->title;
$badgeLevel = $block->metadata['badge_level'] ?? null;
$badgeImageUrl = $block->metadata['image_url'] ?? null;
@endphp

<x-block-card.shell :block="$block" :title="$badgeName . ($badgeLevel ? ' · Level ' . $badgeLevel : '')">
    <div class="flex items-start gap-3">
        @if ($badgeImageUrl)
            <img src="{{ $badgeImageUrl }}" alt="" class="w-16 h-16 rounded-lg shrink-0" loading="lazy">
        @endif
        @if ($block->content)
            <p class="text-sm text-base-content/70 line-clamp-3">{{ $block->content }}</p>
        @endif
    </div>
</x-block-card.shell>
