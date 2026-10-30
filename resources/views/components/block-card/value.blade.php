{{-- The value pattern (DESIGN_LANGUAGE.md §3.6, §4, §8): the number at hero size in the hero colour, its unit at about 55% of it in the same face, secondary, on the same baseline. --}}
@props(['value', 'unit' => null])

<div {{ $attributes->class('flex items-baseline gap-1.5 font-display') }}>
    <span class="text-5xl font-bold tabular-nums text-hero">{{ $value }}</span>
    @if (filled($unit))
        <span class="text-2xl font-bold text-base-content/70">{{ $unit }}</span>
    @endif
</div>
