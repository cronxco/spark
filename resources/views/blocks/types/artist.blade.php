@props(['block'])

@php
$artistName = $block->metadata['artist'] ?? $block->title;
$genres = array_slice((array) ($block->metadata['genres'] ?? []), 0, 3);
$followers = $block->metadata['followers'] ?? null;
@endphp

<x-block-card.shell :block="$block" :title="$artistName">
    @if (! empty($genres))
        <div class="flex flex-wrap gap-1">
            @foreach ($genres as $genre)
                <span class="badge badge-ghost badge-sm">{{ $genre }}</span>
            @endforeach
        </div>
    @endif
    @if (is_numeric($followers))
        <x-slot:context>{{ number_format($followers) }} followers</x-slot:context>
    @endif
</x-block-card.shell>
