{{-- The stat grid pattern (DESIGN_LANGUAGE.md §8): 2–8 fields of one record on one row, wrapping to a second when they do not fit, label above value at full size, tabular figures, no dividers. Empty values are skipped. --}}
@props(['stats'])

@php($stats = collect($stats)->reject(fn ($value) => blank($value)))

@if ($stats->isNotEmpty())
    <dl {{ $attributes->class('grid grid-cols-[repeat(auto-fit,minmax(4rem,1fr))] gap-3') }}>
        @foreach ($stats as $label => $value)
            <div>
                <dt class="text-xs text-base-content/70">{{ $label }}</dt>
                <dd class="text-lg font-semibold tabular-nums">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
@endif
