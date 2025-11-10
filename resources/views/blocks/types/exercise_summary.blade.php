@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'o-squares-2x2';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$exerciseName = $block->metadata['exercise_name'] ?? 'Exercise';
$totalVolume = $block->formatted_value ?? $block->metadata['total_volume'] ?? 0;
$setsCount = $block->metadata['sets_count'] ?? null;
$unit = $block->value_unit ?? $block->metadata['unit'] ?? 'kg';
$formula = $block->metadata['volume_formula'] ?? null;
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header: Title and Date --}}
        <div class="flex items-center justify-between gap-2">
            <h3 class="font-semibold text-base leading-snug flex-1 line-clamp-2">
                <a href="{{ route('blocks.show', $block) }}" wire:navigate class="hover:underline">
                    {{ $exerciseName }}
                </a>
            </h3>
            <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
        </div>

        {{-- Volume Display --}}
        <div class="text-center py-2">
            <div class="text-sm text-base-content/60 mb-1">Total Volume</div>
            <div class="text-4xl font-bold text-warning">
                {{ number_format($totalVolume, 0) }}
            </div>
            <div class="text-sm text-base-content/60 mt-1">{{ $unit }}</div>
        </div>

        <div class="flex items-center justify-center gap-4 text-xs text-base-content/60">
            @if ($setsCount)
            <div class="flex items-center gap-1">
                <x-icon name="o-squares-2x2" class="w-3 h-3" />
                {{ $setsCount }} sets
            </div>
            @endif
            @if ($formula)
            <div class="flex items-center gap-1">
                <x-icon name="o-calculator" class="w-3 h-3" />
                {{ $formula }}
            </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Summary
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
