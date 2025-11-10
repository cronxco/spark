@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'o-squares-2x2';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$author = $block->metadata['author'] ?? null;
$imageUrl = $block->metadata['image'] ?? null;
$direction = $block->metadata['direction'] ?? null;
$extractedAt = $block->metadata['extracted_at'] ?? null;
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

        {{-- Image Display --}}
        @if ($imageUrl)
        <div class="w-full h-32 rounded-lg overflow-hidden bg-base-300">
            <img src="{{ $imageUrl }}"
                 alt="{{ $block->title }}"
                 class="w-full h-full object-cover"
                 loading="lazy">
        </div>
        @endif

        {{-- Metadata Display --}}
        <div class="space-y-2 text-sm">
            @if ($author)
            <div class="flex items-center gap-2">
                <x-icon name="o-user" class="w-4 h-4 text-base-content/60" />
                <span>{{ $author }}</span>
            </div>
            @endif
            @if ($direction)
            <div class="flex items-center gap-2">
                <x-icon name="o-language" class="w-4 h-4 text-base-content/60" />
                <span>{{ $direction }}</span>
            </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Metadata
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
                    @if ($imageUrl)
                    <li>
                        <a href="{{ $imageUrl }}" target="_blank" rel="noopener noreferrer">
                            <x-icon name="o-photo" class="w-4 h-4" />
                            View Image
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
</div>
