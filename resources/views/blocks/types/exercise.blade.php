@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas-grip';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$exerciseName = $block->metadata['exercise_name'] ?? 'Exercise';
$setNumber = $block->metadata['set_number'] ?? 1;
$reps = $block->metadata['reps'] ?? null;
$weight = $block->formatted_value ?? $block->metadata['weight'] ?? 0;
$unit = $block->value_unit ?? $block->metadata['unit'] ?? 'kg';
$rpe = $block->metadata['rpe'] ?? null;
$type = $block->metadata['type'] ?? 'normal';
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

        {{-- Set Display --}}
        <div class="grid grid-cols-3 gap-3">
            <div class="text-center p-2 bg-base-300 rounded-lg">
                <div class="text-xs text-base-content/60">Set</div>
                <div class="text-lg font-bold">{{ $setNumber }}</div>
            </div>
            @if ($reps)
            <div class="text-center p-2 bg-base-300 rounded-lg">
                <div class="text-xs text-base-content/60">Reps</div>
                <div class="text-lg font-bold">{{ $reps }}</div>
            </div>
            @endif
            <div class="text-center p-2 bg-base-300 rounded-lg">
                <div class="text-xs text-base-content/60">Weight</div>
                <div class="text-lg font-bold">{{ number_format($weight, 0) }} {{ $unit }}</div>
            </div>
            @if ($rpe)
            <div class="text-center p-2 bg-base-300 rounded-lg">
                <div class="text-xs text-base-content/60">RPE</div>
                <div class="text-lg font-bold">{{ $rpe }}</div>
            </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Exercise
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