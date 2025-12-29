@props(['block'])

@php
use App\Integrations\PluginRegistry;

$metadata = $block->metadata ?? [];
$action = $metadata['action'] ?? 'maintain';
$routine = $metadata['routine'] ?? 'Routine';
$exercise = $metadata['exercise'] ?? 'Exercise';
$narrative = $metadata['narrative'] ?? '';
$reason = $metadata['reason'] ?? '';

$actionColors = [
    'increase_weight' => 'success',
    'increase_reps' => 'info',
    'deload' => 'warning',
    'maintain' => 'neutral',
];
$badgeColor = $actionColors[$action] ?? 'neutral';

// Map badge colors to full Tailwind class names (for JIT compatibility)
$borderColorMap = [
    'success' => 'border-success',
    'info' => 'border-info',
    'warning' => 'border-warning',
    'neutral' => 'border-neutral',
];
$bgColorMap = [
    'success' => 'bg-success/10',
    'info' => 'bg-info/10',
    'warning' => 'bg-warning/10',
    'neutral' => 'bg-neutral/10',
];
$borderClass = $borderColorMap[$badgeColor] ?? 'border-neutral';
$bgClass = $bgColorMap[$badgeColor] ?? 'bg-neutral/10';

$actionIcons = [
    'increase_weight' => 'fas.weight-hanging',
    'increase_reps' => 'fas.hashtag',
    'deload' => 'fas.arrow-down',
    'maintain' => 'fas.equals',
];
$actionIcon = $actionIcons[$action] ?? 'fas.dumbbell';

$currentWeight = $metadata['current_weight'] ?? null;
$newWeight = $metadata['new_weight'] ?? null;
$currentReps = $metadata['current_reps'] ?? null;
$newReps = $metadata['new_reps'] ?? null;
$currentRpe = $metadata['current_rpe'] ?? null;
$unit = $metadata['current_unit'] ?? 'kg';
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all border-l-4 {{ $borderClass }}">
    <div class="card-body p-4 gap-3">
        {{-- Header --}}
        <div class="flex items-center justify-between gap-2">
            <div class="badge badge-{{ $badgeColor }} badge-outline gap-1">
                <x-icon :name="$actionIcon" class="w-3 h-3" />
                {{ ucfirst(str_replace('_', ' ', $action)) }}
            </div>
            <x-uk-date :date="$block->time" class="text-xs" />
        </div>

        {{-- Routine + Exercise name --}}
        <div>
            <div class="text-xs text-base-content/60">{{ $routine }}</div>
            <h3 class="font-bold text-lg">{{ $exercise }}</h3>
        </div>

        {{-- Narrative indicator --}}
        <div class="bg-base-100 rounded-lg p-3 border border-base-300">
            <p class="text-lg font-semibold">{{ $narrative }}</p>
        </div>

        {{-- Reason --}}
        <p class="text-sm text-base-content/70">{{ $reason }}</p>

        {{-- Current vs New Stats --}}
        @if ($currentWeight !== null && $newWeight !== null)
        <div class="grid grid-cols-2 gap-2 text-xs">
            <div class="stat bg-base-300 rounded p-2">
                <div class="stat-title text-xs">Current</div>
                <div class="stat-value text-sm">{{ $currentWeight }}{{ $unit }}</div>
                @if ($currentReps !== null && $currentRpe !== null)
                <div class="stat-desc">{{ $currentReps }} reps @ RPE {{ $currentRpe }}</div>
                @endif
            </div>
            <div class="stat {{ $bgClass }} rounded p-2">
                <div class="stat-title text-xs">New Target</div>
                <div class="stat-value text-sm">{{ $newWeight }}{{ $unit }}</div>
                @if ($newReps !== null)
                <div class="stat-desc">{{ $newReps }} reps</div>
                @endif
            </div>
        </div>
        @endif

        {{-- Footer actions --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            {{-- Integration badge --}}
            @if ($block->event && $block->event->integration)
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="fas.dumbbell" class="w-3 h-3" />
                {{ $block->event->integration->name }}
            </div>
            @endif

            <div class="ml-auto flex gap-2">
                <button
                    class="btn btn-xs btn-success gap-1"
                    onclick="Livewire.dispatch('apply-coach-recommendation', { blockId: '{{ $block->id }}' })"
                >
                    <x-icon name="fas.check" class="w-3 h-3" />
                    Apply
                </button>
                <button
                    class="btn btn-xs btn-ghost gap-1"
                    onclick="Livewire.dispatch('dismiss-coach-recommendation', { blockId: '{{ $block->id }}' })"
                >
                    <x-icon name="fas.times" class="w-3 h-3" />
                    Dismiss
                </button>
            </div>
        </div>
    </div>
</div>
