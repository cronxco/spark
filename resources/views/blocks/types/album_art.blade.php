@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.grip';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

// Use Media Library for responsive images with signed URLs
$media = $block->getFirstMedia('downloaded_images');
$responsiveImageHtml = null;

if ($media) {
    $responsiveImageHtml = (string) $media;
    $doc = new DOMDocument;
    @$doc->loadHTML($responsiveImageHtml, LIBXML_HTML_NOIMPLIES | LIBXML_HTML_NODEFDTD);
    $img = $doc->getElementsByTagName('img')->item(0);
    if ($img) {
        $img->setAttribute('class', 'w-full h-full object-cover');
        $img->setAttribute('loading', 'lazy');
        $img->setAttribute('alt', $block->title);
        $responsiveImageHtml = $doc->saveHTML($img);
    }
}

$trackName = $block->metadata['track'] ?? null;
$artist = $block->metadata['artist'] ?? null;
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header: Title and Date --}}
        <div class="flex items-center justify-between gap-2">
            <h3 class="font-semibold text-base leading-snug flex-1 line-clamp-1">
                <a href="{{ route('blocks.show', $block) }}" wire:navigate class="hover:underline">
                    {{ $block->title }}
                </a>
            </h3>
            <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
        </div>

        {{-- Album Art Display --}}
        @if ($responsiveImageHtml)
        <div class="w-full aspect-square rounded-lg overflow-hidden bg-base-300 shadow-md">
            {!! $responsiveImageHtml !!}
        </div>
        @else
        <div class="w-full aspect-square rounded-lg overflow-hidden bg-base-300 flex items-center justify-center">
            <x-icon name="fas.music" class="w-16 h-16 text-base-content/30" />
        </div>
        @endif

        @if ($trackName || $artist)
        <div class="text-center text-sm text-base-content/70">
            @if ($trackName)
            <div class="font-medium">{{ $trackName }}</div>
            @endif
            @if ($artist)
            <div class="text-xs text-base-content/60">{{ $artist }}</div>
            @endif
        </div>
        @endif

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Album Art
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
                    @if ($block->url)
                    <li>
                        <a href="{{ $block->url }}" target="_blank" rel="noopener noreferrer">
                            <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                            Open in Spotify
                        </a>
                    </li>
                    @endif
                    @if ($imageUrl)
                    <li>
                        <a href="{{ $imageUrl }}" target="_blank" rel="noopener noreferrer">
                            <x-icon name="fas.image" class="w-4 h-4" />
                            View Full Image
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
</div>
