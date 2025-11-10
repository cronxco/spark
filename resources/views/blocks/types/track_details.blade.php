@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'o-squares-2x2';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$trackName = $block->metadata['track'] ?? 'Unknown Track';
$artists = $block->metadata['artists'] ?? [];
$album = $block->metadata['album'] ?? null;
$duration = $block->metadata['duration'] ?? null;
$popularity = $block->metadata['popularity'] ?? $block->formatted_value ?? 0;
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

        {{-- Track Info --}}
        <div class="space-y-2">
            <div>
                <div class="font-semibold">{{ $trackName }}</div>
                @if (!empty($artists))
                <div class="text-sm text-base-content/70">
                    {{ is_array($artists) ? implode(', ', $artists) : $artists }}
                </div>
                @endif
                @if ($album)
                <div class="text-xs text-base-content/60">{{ $album }}</div>
                @endif
            </div>

            {{-- Popularity Bar --}}
            <div class="space-y-1">
                <div class="flex items-center justify-between text-xs text-base-content/60">
                    <span>Popularity</span>
                    <span>{{ round($popularity) }}/100</span>
                </div>
                <div class="w-full bg-base-300 rounded-full h-2 overflow-hidden">
                    <div
                        class="bg-success h-full rounded-full transition-all"
                        style="width: {{ $popularity }}%"
                    ></div>
                </div>
            </div>

            @if ($duration && is_numeric($duration))
            <div class="flex items-center gap-2 text-xs text-base-content/60">
                <x-icon name="o-clock" class="w-3 h-3" />
                {{ format_duration($duration / 1000) }}
            </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Track
            </div>

            <div class="flex-1"></div>

            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-xs btn-square">
                    <x-icon name="o-ellipsis-vertical" class="w-4 h-4" />
                </div>
                <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-[1] w-52 p-2 shadow-lg border border-base-300">
                    <li>
                        <a href="{{ route('blocks.show', $block) }}" wire:navigate>
                            <x-icon name="o-eye" class="w-4 h-4" />
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
                </ul>
            </div>
        </div>
    </div>
</div>
