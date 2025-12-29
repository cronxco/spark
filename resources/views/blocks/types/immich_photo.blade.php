@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.images';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : 'Immich';

$fileName = $block->metadata['type'] === 'VIDEO' ? '🎥 ' . $block->title : $block->title;
$timestamp = $block->metadata['timestamp'] ?? $block->time;
$thumbnailUrl = $block->metadata['thumbnail_url'] ?? null;
$viewUrl = $block->metadata['view_url'] ?? null;
$isFavorite = $block->metadata['is_favorite'] ?? false;
$people = $block->metadata['people'] ?? [];

// Camera metadata - null-safe access
$cameraMake = $block->metadata['camera_make'] ?? null;
$cameraModel = $block->metadata['camera_model'] ?? null;
$camera = $cameraMake && $cameraModel ? $cameraMake . ' ' . $cameraModel : null;

$settings = [];
$fNumber = $block->metadata['f_number'] ?? null;
$exposureTime = $block->metadata['exposure_time'] ?? null;
$iso = $block->metadata['iso'] ?? null;
$focalLength = $block->metadata['focal_length'] ?? null;

if ($fNumber) $settings[] = 'f/' . $fNumber;
if ($exposureTime) $settings[] = $exposureTime;
if ($iso) $settings[] = 'ISO ' . $iso;
if ($focalLength) $settings[] = $focalLength . 'mm';
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header: Filename and Date --}}
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 flex-1 min-w-0">
                @if ($isFavorite)
                    <x-icon name="fas.star" class="w-3 h-3 text-warning flex-shrink-0" />
                @endif
                <h3 class="font-semibold text-sm leading-snug truncate">
                    @if ($viewUrl)
                        <a href="{{ $viewUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">
                            {{ $fileName }}
                        </a>
                    @else
                        {{ $fileName }}
                    @endif
                </h3>
            </div>
            <x-uk-date :date="$timestamp" :show-time="true" class="text-xs flex-shrink-0" />
        </div>

        {{-- Photo thumbnail --}}
        @if ($thumbnailUrl)
            <div class="w-full h-48 rounded-lg overflow-hidden bg-base-300">
                <a href="{{ $viewUrl }}" target="_blank" rel="noopener noreferrer">
                    <img src="{{ $thumbnailUrl }}" alt="{{ $fileName }}" class="w-full h-full object-cover hover:scale-105 transition-transform" loading="lazy">
                </a>
            </div>
        @endif

        {{-- People tags --}}
        @if (count($people) > 0)
            <div class="flex flex-wrap gap-1">
                @foreach ($people as $person)
                    <div class="badge badge-sm gap-1">
                        <x-icon name="fas.user" class="w-2.5 h-2.5" />
                        {{ $person }}
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Camera/EXIF details (collapsible) --}}
        @if ($camera || count($settings) > 0)
            <div class="collapse collapse-arrow bg-base-300 rounded-lg">
                <input type="checkbox" />
                <div class="collapse-title text-xs font-medium flex items-center gap-1">
                    <x-icon name="fas.camera" class="w-3 h-3" />
                    Camera Details
                </div>
                <div class="collapse-content text-xs space-y-1">
                    @if ($camera)
                        <div class="flex items-center gap-1">
                            <span class="font-semibold">Camera:</span>
                            <span>{{ $camera }}</span>
                        </div>
                    @endif
                    @if ($block->metadata['lens_model'])
                        <div class="flex items-center gap-1">
                            <span class="font-semibold">Lens:</span>
                            <span>{{ $block->metadata['lens_model'] }}</span>
                        </div>
                    @endif
                    @if (count($settings) > 0)
                        <div class="flex items-center gap-1">
                            <span class="font-semibold">Settings:</span>
                            <span>{{ implode(' · ', $settings) }}</span>
                        </div>
                    @endif
                    @if ($block->metadata['latitude'] && $block->metadata['longitude'])
                        <div class="flex items-center gap-1">
                            <span class="font-semibold">Location:</span>
                            <span class="font-mono text-xs">{{ round($block->metadata['latitude'], 4) }}, {{ round($block->metadata['longitude'], 4) }}</span>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                {{ $block->metadata['type'] === 'VIDEO' ? 'Video' : 'Photo' }}
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
                    @if ($viewUrl)
                        <li>
                            <a href="{{ $viewUrl }}" target="_blank" rel="noopener noreferrer">
                                <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                                View in Immich
                            </a>
                        </li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
</div>
