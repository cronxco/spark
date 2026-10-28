{{-- The ring pattern (DESIGN_LANGUAGE.md §8): a ratio with a known maximum. The track is the same fill at 15%. --}}
@props(['value', 'max', 'label' => null, 'tint' => 'text-domain-health'])

@php
    $fraction = $max > 0 ? min(1, max(0, $value / $max)) : 0;
    $circumference = 2 * M_PI * 26;
@endphp

<div {{ $attributes->class('flex items-center gap-3') }}>
    <svg viewBox="0 0 64 64" class="w-16 h-16 -rotate-90 shrink-0 {{ $tint }}" aria-hidden="true">
        <circle cx="32" cy="32" r="26" fill="none" stroke="currentColor" stroke-width="7" stroke-opacity="0.15" />
        <circle cx="32" cy="32" r="26" fill="none" stroke="currentColor" stroke-width="7" stroke-linecap="round"
            stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $circumference * (1 - $fraction) }}" />
    </svg>
    <div>
        <span class="text-3xl font-bold tabular-nums">{{ $value }}</span>
        <span class="sr-only">out of {{ $max }}</span>
        @if (filled($label))
            <div class="text-xs text-base-content/70">{{ $label }}</div>
        @endif
    </div>
</div>
