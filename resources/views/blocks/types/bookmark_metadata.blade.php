@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.grip';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$title = $block->metadata['title'] ?? $block->title;
$description = $block->metadata['description'] ?? '';
$imageUrl = $block->media_url ?? $block->metadata['image'] ?? $block->metadata['image_url'] ?? null;
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header: Title and Date --}}
        <div class="flex items-center justify-between gap-2">
            <h3 class="font-semibold text-base leading-snug flex-1 line-clamp-1">
                @if ($block->url)
                    <a href="{{ $block->url }}" target="_blank" rel="noopener noreferrer" class="hover:underline">
                        {{ $title }}
                    </a>
                @else
                    <a href="{{ route('blocks.show', $block) }}" wire:navigate class="hover:underline">
                        {{ $title }}
                    </a>
                @endif
            </h3>
            <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
        </div>

        {{-- Large image preview (taller than default) --}}
        @if ($imageUrl)
            <div class="w-full h-56 rounded-lg overflow-hidden bg-base-300">
                <img src="{{ $imageUrl }}"
                     alt="{{ $title }}"
                     class="w-full h-full object-cover"
                     loading="lazy">
            </div>
        @endif

        {{-- Description --}}
        @if ($description)
            <p class="text-sm text-base-content/70 line-clamp-3 leading-relaxed">
                {{ $description }}
            </p>
        @endif

        {{-- Additional metadata --}}
        @if (isset($block->metadata['author']) || isset($block->metadata['site_name']))
            <div class="flex items-center gap-2 text-xs text-base-content/60">
                @if (isset($block->metadata['author']))
                    <div class="flex items-center gap-1">
                        <x-icon name="fas-user" class="w-3 h-3" />
                        {{ $block->metadata['author'] }}
                    </div>
                @endif
                @if (isset($block->metadata['site_name']))
                    <div class="flex items-center gap-1">
                        <x-icon name="fas-globe" class="w-3 h-3" />
                        {{ $block->metadata['site_name'] }}
                    </div>
                @endif
            </div>
        @endif

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="fas-bookmark" class="w-3 h-3" />
                Preview Card
            </div>

            <div class="flex-1"></div>

            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-xs btn-square">
                    <x-icon name="fas-ellipsis-vertical" class="w-4 h-4" />
                </div>
                <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-[1] w-52 p-2 shadow-lg border border-base-300">
                    <li>
                        <a href="{{ route('blocks.show', $block) }}" wire:navigate>
                            <x-icon name="fas-eye" class="w-4 h-4" />
                            View Block
                        </a>
                    </li>
                    @if ($block->url)
                        <li>
                            <a href="{{ $block->url }}" target="_blank" rel="noopener noreferrer">
                                <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                                Open URL
                            </a>
                        </li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
</div>
