@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.images';

$photoCount = $block->metadata['photo_count'] ?? 0;
$videoCount = $block->metadata['video_count'] ?? 0;
$timeRange = $block->metadata['time_range'] ?? null;
$locationName = $block->metadata['location_name'] ?? null;
$thumbnailUrls = $block->metadata['thumbnail_urls'] ?? [];
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header --}}
        <div class="flex items-center justify-between gap-2">
            <h3 class="font-semibold text-base leading-snug flex-1">
                <a href="{{ route('events.show', $block->event) }}" wire:navigate class="hover:underline">
                    Cluster Summary
                </a>
            </h3>
            <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
        </div>

        {{-- Location and time --}}
        @if ($locationName || $timeRange)
            <div class="flex flex-col gap-1 text-sm">
                @if ($locationName)
                    <div class="flex items-center gap-1.5 text-base-content/70">
                        <x-icon name="o-map-pin" class="w-4 h-4" />
                        <span class="font-medium">{{ $locationName }}</span>
                    </div>
                @endif
                @if ($timeRange)
                    <div class="flex items-center gap-1.5 text-base-content/70">
                        <x-icon name="o-clock" class="w-4 h-4" />
                        <span>{{ $timeRange }}</span>
                    </div>
                @endif
            </div>
        @endif

        {{-- Photo grid --}}
        @if (count($thumbnailUrls) > 0)
            <div class="grid grid-cols-3 gap-1 rounded-lg overflow-hidden">
                @foreach (array_slice($thumbnailUrls, 0, 6) as $index => $url)
                    <div class="aspect-square bg-base-300 relative overflow-hidden">
                        <img src="{{ $url }}" alt="Photo {{ $index + 1 }}" class="w-full h-full object-cover" loading="lazy">
                        @if ($loop->last && $photoCount > 6)
                            <div class="absolute inset-0 bg-black/50 flex items-center justify-center text-white font-semibold">
                                +{{ $photoCount - 6 }}
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Stats --}}
        <div class="stats shadow bg-base-300">
            <div class="stat py-3 px-4">
                <div class="stat-title text-xs">Photos</div>
                <div class="stat-value text-2xl">{{ $photoCount }}</div>
            </div>
            @if ($videoCount > 0)
                <div class="stat py-3 px-4">
                    <div class="stat-title text-xs">Videos</div>
                    <div class="stat-value text-2xl">{{ $videoCount }}</div>
                </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Photo Cluster
            </div>

            <div class="flex-1"></div>

            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-xs btn-square">
                    <x-icon name="fas.ellipsis-vertical" class="w-4 h-4" />
                </div>
                <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-[1] w-52 p-2 shadow-lg border border-base-300">
                    <li>
                        <a href="{{ route('blocks.show', $block) }}" wire:navigate>
                            <x-icon name="fas.eye" class="w-4 h-4" />
                            View Block
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('events.show', $block->event) }}" wire:navigate>
                            <x-icon name="fas.images" class="w-4 h-4" />
                            View All Photos
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
