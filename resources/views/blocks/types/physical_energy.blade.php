@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.grip';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$value = $block->formatted_value ?? 0;
$maxValue = 5;
$percentage = ($value / $maxValue) * 100;
$period = $block->metadata['period'] ?? null;
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
            <x-uk-date :date="$block->time" :show-time="false" class="text-xs flex-shrink-0" />
        </div>

        {{-- Circular Progress Display --}}
        <div class="flex items-center justify-center py-3">
            <div class="relative w-32 h-32">
                {{-- Background circle --}}
                <svg class="w-full h-full transform -rotate-90">
                    <circle
                        cx="64"
                        cy="64"
                        r="56"
                        stroke="currentColor"
                        stroke-width="8"
                        fill="none"
                        class="text-base-300"
                    />
                    <circle
                        cx="64"
                        cy="64"
                        r="56"
                        stroke="currentColor"
                        stroke-width="8"
                        fill="none"
                        class="text-success transition-all"
                        stroke-dasharray="351.858"
                        stroke-dashoffset="{{ 351.858 - (351.858 * $percentage / 100) }}"
                        stroke-linecap="round"
                    />
                </svg>
                {{-- Center text --}}
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                    <div class="text-3xl font-bold">{{ $value }}</div>
                    <div class="text-xs text-base-content/60">out of {{ $maxValue }}</div>
                </div>
            </div>
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="fas-bolt" class="w-3 h-3" />
                Physical Energy
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
                    <li>
                        <a href="{{ route('events.show', $block->event) }}" wire:navigate>
                            <x-icon name="fas-calendar" class="w-4 h-4" />
                            View Event
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
