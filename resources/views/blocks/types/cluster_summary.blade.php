@props(['block'])

@php
$photoCount = $block->metadata['photo_count'] ?? 0;
$videoCount = $block->metadata['video_count'] ?? 0;
$thumbnailUrls = array_slice($block->metadata['thumbnail_urls'] ?? [], 0, 6);
$hiddenCount = max(0, $photoCount - count($thumbnailUrls));
@endphp

<x-block-card.shell :block="$block" :title="$block->metadata['location_name'] ?? null">
    @if (count($thumbnailUrls) > 0)
        <div class="grid grid-cols-3 gap-1 rounded-lg overflow-hidden">
            @foreach ($thumbnailUrls as $url)
                <div class="aspect-square bg-base-300 relative overflow-hidden">
                    <img src="{{ $url }}" alt="" class="w-full h-full object-cover" loading="lazy">
                    @if ($loop->last && $hiddenCount > 0)
                        <div class="absolute inset-0 bg-black/50 flex items-center justify-center text-white font-semibold">
                            +{{ $hiddenCount }}
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <x-slot:context>
        {{ collect([
            number_format($photoCount) . ' ' . Str::plural('photo', $photoCount),
            $videoCount > 0 ? number_format($videoCount) . ' ' . Str::plural('video', $videoCount) : null,
            $block->metadata['time_range'] ?? null,
        ])->filter()->join(' · ') }}
    </x-slot:context>
</x-block-card.shell>
