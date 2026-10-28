{{-- The stat grid pattern (DESIGN_LANGUAGE.md §8): 2–4 fields of one record, label above value, tabular figures, no dividers. Empty values are skipped. --}}
@props(['stats'])

@php($stats = collect($stats)->reject(fn ($value) => blank($value)))

@if ($stats->isNotEmpty())
    <dl {{ $attributes->class('grid grid-cols-2 sm:grid-cols-4 gap-3') }}>
        @foreach ($stats as $label => $value)
            <div>
                <dt class="text-xs text-base-content/70">{{ $label }}</dt>
                <dd class="text-lg font-semibold tabular-nums">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
@endif
