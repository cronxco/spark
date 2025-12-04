@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$displayName = $pluginClass ? $pluginClass::getDisplayName() : 'Untappd';

// Get beer information
$beerName = $block->title;
$description = $block->metadata['description'] ?? null;
$style = $block->metadata['style'] ?? null;
$abv = $block->metadata['abv'] ?? null;
$ibu = $block->metadata['ibu'] ?? null;
$rating = $block->value ?? $block->metadata['aggregate_rating'] ?? null;
$reviewCount = $block->metadata['review_count'] ?? null;
$beerUrl = $block->url ?? $block->metadata['beer_url'] ?? null;

// Get beer label image
$imageUrl = get_media_url($block, 'downloaded_images', 'medium');
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header --}}
        <div class="flex items-center justify-between gap-2">
            <div class="badge badge-warning badge-outline badge-sm gap-1">
                <x-icon name="fas.beer-mug-empty" class="w-3 h-3" />
                Beer Details
            </div>
            <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
        </div>

        {{-- Beer Content --}}
        <div class="flex items-start gap-4">
            {{-- Beer Label --}}
            @if ($imageUrl)
            <div class="flex-shrink-0">
                <img src="{{ $imageUrl }}" alt="{{ $beerName }}" class="w-20 h-20 rounded-lg shadow-sm object-cover">
            </div>
            @endif

            {{-- Beer Info --}}
            <div class="flex-1 min-w-0">
                <h3 class="font-semibold text-lg leading-snug mb-2">
                    @if ($beerUrl)
                        <a href="{{ $beerUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">
                            {{ $beerName }}
                        </a>
                    @else
                        {{ $beerName }}
                    @endif
                </h3>

                {{-- Style --}}
                @if ($style)
                <div class="text-sm text-base-content/70 mb-2">{{ $style }}</div>
                @endif

                {{-- Stats Grid --}}
                <div class="grid grid-cols-2 gap-2 mb-2">
                    @if ($abv)
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-base-content/50">ABV:</span>
                        <span class="badge badge-sm badge-warning">{{ $abv }}%</span>
                    </div>
                    @endif
                    @if ($ibu)
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-base-content/50">IBU:</span>
                        <span class="badge badge-sm badge-ghost">{{ $ibu }}</span>
                    </div>
                    @endif
                    @if ($rating)
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-base-content/50">Rating:</span>
                        <span class="badge badge-sm badge-accent">{{ number_format($rating, 2) }}/5</span>
                    </div>
                    @endif
                    @if ($reviewCount)
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-base-content/50">Reviews:</span>
                        <span class="text-xs">{{ number_format($reviewCount) }}</span>
                    </div>
                    @endif
                </div>

                {{-- Description --}}
                @if ($description)
                <p class="text-sm text-base-content/70 line-clamp-3 mt-2">{{ $description }}</p>
                @endif
            </div>
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="fas.beer-mug-empty" class="w-3 h-3" />
                {{ $displayName }}
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
                    @if ($beerUrl)
                    <li>
                        <a href="{{ $beerUrl }}" target="_blank" rel="noopener noreferrer">
                            <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                            View on Untappd
                        </a>
                    </li>
                    @endif
                    <li>
                        <a href="{{ route('events.show', $block->event) }}" wire:navigate>
                            <x-icon name="fas.calendar-check" class="w-4 h-4" />
                            View Event
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
