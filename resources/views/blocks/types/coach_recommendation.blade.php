@props(['block'])

@php
$metadata = $block->metadata ?? [];
$action = $metadata['action'] ?? 'maintain';
$unit = $metadata['current_unit'] ?? 'kg';
$currentWeight = $metadata['current_weight'] ?? null;
$newWeight = $metadata['new_weight'] ?? null;
$currentReps = $metadata['current_reps'] ?? null;
$currentRpe = $metadata['current_rpe'] ?? null;
$newReps = $metadata['new_reps'] ?? null;

// Status in words and a glyph, not a coloured border (DESIGN_LANGUAGE.md §12).
$actionIcon = [
    'increase_weight' => 'fas.arrow-up',
    'increase_reps' => 'fas.arrow-up',
    'deload' => 'fas.arrow-down',
    'maintain' => 'fas.equals',
][$action] ?? 'fas.dumbbell';
@endphp

<x-block-card.shell :block="$block" :title="$metadata['exercise'] ?? $block->title">
    <div class="flex items-center gap-2 text-sm font-semibold">
        <x-icon :name="$actionIcon" class="w-3.5 h-3.5 text-domain-activity" />
        <span>{{ ucfirst(str_replace('_', ' ', $action)) }}</span>
    </div>

    @if (filled($metadata['narrative'] ?? null))
        <p class="text-base">{{ $metadata['narrative'] }}</p>
    @endif

    @if ($currentWeight !== null && $newWeight !== null)
        <div class="flex items-center gap-3 text-sm">
            <div>
                <div class="text-xs text-base-content/70">Current</div>
                <div class="text-lg font-semibold tabular-nums">{{ $currentWeight }} {{ $unit }}</div>
                @if ($currentReps !== null && $currentRpe !== null)
                    <div class="text-xs text-base-content/70">{{ $currentReps }} reps at RPE {{ $currentRpe }}</div>
                @endif
            </div>
            <x-icon name="fas.arrow-right" class="w-4 h-4 text-base-content/70" />
            <div>
                <div class="text-xs text-base-content/70">New target</div>
                <div class="text-lg font-semibold tabular-nums">{{ $newWeight }} {{ $unit }}</div>
                @if ($newReps !== null)
                    <div class="text-xs text-base-content/70">{{ $newReps }} reps</div>
                @endif
            </div>
        </div>
    @endif

    @if (filled($metadata['reason'] ?? null))
        <p class="text-sm text-base-content/70">{{ $metadata['reason'] }}</p>
    @endif

    @if (filled($metadata['routine'] ?? null))
        <x-slot:context>{{ $metadata['routine'] }}</x-slot:context>
    @endif
</x-block-card.shell>
