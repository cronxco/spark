@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.beer-mug-empty';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : 'Untappd';

// Get brewery information
$breweryName = $block->title;

// Get beer information from the target EventObject
$beer = $block->event->target;
$beerName = $beer->title ?? 'Unknown Beer';
$beerUrl = $beer->url ?? null;
$venue = $beer->metadata['venue'] ?? null;
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header: Date --}}
        <div class="flex items-center justify-between gap-2">
            <div class="badge badge-warning badge-outline badge-sm gap-1">
                <x-icon name="fas.beer-mug-empty" class="w-3 h-3" />
                Beer Check-in
            </div>
            <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
        </div>

        {{-- Beer and Brewery Info --}}
        <div class="space-y-3">
            {{-- Beer Name --}}
            <div>
                <div class="text-xs text-base-content/60 mb-1">Beer</div>
                <h3 class="font-semibold text-base leading-snug">
                    @if ($beerUrl)
                        <a href="{{ $beerUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">
                            {{ $beerName }}
                        </a>
                    @else
                        {{ $beerName }}
                    @endif
                </h3>
            </div>

            {{-- Brewery Icon and Name --}}
            <div class="flex items-center gap-3 p-3 bg-warning/10 rounded-lg border border-warning/30">
                <div class="flex-shrink-0 w-12 h-12 rounded-full bg-warning/20 flex items-center justify-center">
                    <x-icon name="fas.industry" class="w-6 h-6 text-warning" />
                </div>
                <div class="flex-1">
                    <div class="text-xs text-base-content/60 mb-1">Brewery</div>
                    <div class="font-semibold text-base text-base-content">
                        {{ $breweryName }}
                    </div>
                </div>
            </div>

            {{-- Venue (if present) --}}
            @if ($venue)
            <div class="flex items-center gap-2 text-sm text-base-content/70">
                <x-icon name="fas.location-dot" class="w-4 h-4 text-warning" />
                <span>{{ $venue }}</span>
            </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Brewery Info
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
                </ul>
            </div>
        </div>
    </div>
</div>
