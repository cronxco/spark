@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'o-squares-2x2';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$type = $block->metadata['type'] ?? '';
$field = $block->metadata['field'] ?? '';
$value = round($block->formatted_value ?? 0, 1);
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

        {{-- Value Display (no unit per requirements) --}}
        <div class="text-center py-2">
            <div class="text-4xl font-bold text-info">
                {{ $value }}
            </div>
            @if ($field)
            <div class="text-sm text-base-content/60 mt-1">
                {{ $field }}
            </div>
            @endif
        </div>

        @if ($type)
        <div class="flex items-center justify-center gap-2 text-xs text-base-content/60">
            <x-icon name="o-beaker" class="w-3 h-3" />
            {{ $type }}
        </div>
        @endif

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Biometric
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
                    <li>
                        <a href="{{ route('events.show', $block->event) }}" wire:navigate>
                            <x-icon name="o-calendar" class="w-4 h-4" />
                            View Event
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
