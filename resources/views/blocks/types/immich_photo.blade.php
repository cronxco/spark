@props(['block'])

@php
$isVideo = ($block->metadata['type'] ?? null) === 'VIDEO';
$thumbnailUrl = $block->metadata['thumbnail_url'] ?? null;
$isFavorite = $block->metadata['is_favorite'] ?? false;
$people = $block->metadata['people'] ?? [];
$camera = collect([$block->metadata['camera_make'] ?? null, $block->metadata['camera_model'] ?? null])->filter()->join(' ');
@endphp

<x-block-card.shell :block="$block" :label="$isVideo ? 'Video' : 'Photo'">
    <div class="w-full h-48 rounded-lg overflow-hidden bg-domain-media/10 flex items-center justify-center">
        @if ($thumbnailUrl)
            <img src="{{ $thumbnailUrl }}" alt="{{ $block->title }}" class="w-full h-full object-cover" loading="lazy">
        @else
            <x-icon :name="$isVideo ? 'fas.video' : 'fas.image'" class="w-10 h-10 text-domain-media" />
        @endif
    </div>

    @if (count($people) > 0)
        <div class="flex flex-wrap gap-1">
            @foreach ($people as $person)
                <span class="badge badge-ghost badge-sm">{{ is_array($person) ? ($person['name'] ?? '') : $person }}</span>
            @endforeach
        </div>
    @endif

    @if ($isFavorite || $camera !== '')
        <x-slot:context>{{ collect([$isFavorite ? 'Favourite' : null, $camera ?: null])->filter()->join(' · ') }}</x-slot:context>
    @endif
</x-block-card.shell>
