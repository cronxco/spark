@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$displayName = $pluginClass ? $pluginClass::getDisplayName() : 'Untappd';

// Get brewery information
$breweryName = $block->title;
$description = $block->metadata['description'] ?? null;
$address = $block->metadata['address'] ?? null;
$streetAddress = $block->metadata['street_address'] ?? null;
$locality = $block->metadata['locality'] ?? null;
$region = $block->metadata['region'] ?? null;
$rating = $block->value ?? $block->metadata['aggregate_rating'] ?? null;
$reviewCount = $block->metadata['review_count'] ?? null;
$breweryUrl = $block->url ?? $block->metadata['brewery_url'] ?? null;

// Get brewery logo
$imageUrl = get_media_url($block, 'downloaded_images', 'medium');

// Build full address if components available
$fullAddress = $address;
if (! $fullAddress && ($streetAddress || $locality || $region)) {
    $parts = array_filter([$streetAddress, $locality, $region]);
    $fullAddress = implode(', ', $parts);
}
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header --}}
        <div class="flex items-center justify-between gap-2">
            <div class="badge badge-warning badge-outline badge-sm gap-1">
                <x-icon name="fas.industry" class="w-3 h-3" />
                Brewery Details
            </div>
            <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
        </div>

        {{-- Brewery Content --}}
        <div class="flex items-start gap-4">
            {{-- Brewery Logo --}}
            @if ($imageUrl)
            <div class="flex-shrink-0">
                <img src="{{ $imageUrl }}" alt="{{ $breweryName }}" class="w-20 h-20 rounded-lg shadow-sm object-cover">
            </div>
            @endif

            {{-- Brewery Info --}}
            <div class="flex-1 min-w-0">
                <h3 class="font-semibold text-lg leading-snug mb-2">
                    @if ($breweryUrl)
                        <a href="{{ $breweryUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">
                            {{ $breweryName }}
                        </a>
                    @else
                        {{ $breweryName }}
                    @endif
                </h3>

                {{-- Address --}}
                @if ($fullAddress)
                <div class="flex items-start gap-2 mb-2">
                    <x-icon name="fas.location-dot" class="w-4 h-4 text-warning mt-0.5 flex-shrink-0" />
                    <span class="text-sm text-base-content/70">{{ $fullAddress }}</span>
                </div>
                @endif

                {{-- Stats --}}
                @if ($rating || $reviewCount)
                <div class="flex items-center gap-3 mb-2">
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
                @endif

                {{-- Description --}}
                @if ($description)
                <p class="text-sm text-base-content/70 line-clamp-3 mt-2">{{ $description }}</p>
                @endif
            </div>
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="fas.industry" class="w-3 h-3" />
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
                    @if ($breweryUrl)
                    <li>
                        <a href="{{ $breweryUrl }}" target="_blank" rel="noopener noreferrer">
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
