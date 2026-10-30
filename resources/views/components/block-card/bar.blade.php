{{-- The bar pattern (DESIGN_LANGUAGE.md §8): progress or share, with the value as text too. --}}
@props(['percent', 'label' => null, 'tint' => 'bg-domain-health'])

@php($percent = min(100, max(0, (float) $percent)))

<div {{ $attributes->class('space-y-1.5') }}>
    <div class="flex items-baseline justify-between gap-2 text-sm">
        <span class="text-base-content/70">{{ $label }}</span>
        <span class="font-semibold tabular-nums">{{ round($percent) }}%</span>
    </div>
    <div class="w-full h-2 rounded-full bg-base-300 overflow-hidden" role="presentation">
        <div class="h-full rounded-full {{ $tint }}" style="width: {{ $percent }}%"></div>
    </div>
</div>
