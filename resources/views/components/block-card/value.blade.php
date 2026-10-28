{{-- The value pattern (DESIGN_LANGUAGE.md §8): the number at hero size, its unit smaller and secondary on the same baseline. --}}
@props(['value', 'unit' => null])

<div {{ $attributes->class('flex items-baseline gap-1.5') }}>
    <span class="text-3xl font-bold tabular-nums">{{ $value }}</span>
    @if (filled($unit))
        <span class="text-sm text-base-content/70">{{ $unit }}</span>
    @endif
</div>
