@props(['block'])

@php
$trackName = $block->metadata['track'] ?? $block->title;
$artists = $block->metadata['artists'] ?? [];
$artists = is_array($artists) ? implode(', ', $artists) : $artists;
$album = $block->metadata['album'] ?? null;
$duration = $block->metadata['duration'] ?? null;
$popularity = $block->metadata['popularity'] ?? $block->formatted_value ?? null;
@endphp

<x-block-card.shell :block="$block" :title="$trackName">
    @if (filled($artists) || $album)
        <div class="text-sm text-base-content/70 line-clamp-2">
            {{ collect([$artists, $album])->filter()->join(' · ') }}
        </div>
    @endif

    @if (is_numeric($popularity))
        <x-block-card.bar :percent="$popularity" label="Popularity" tint="bg-domain-media" />
    @endif

    @if ($duration && is_numeric($duration))
        <x-slot:context>{{ format_duration($duration / 1000) }}</x-slot:context>
    @endif
</x-block-card.shell>
