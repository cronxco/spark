{{--
    The ring pattern (DESIGN_LANGUAGE.md §8): a ratio with a known maximum,
    drawn as the hero of its card. Centred, about 128px, with the number
    inside at hero size. The track is the same fill at 15%. An optional
    threshold (the provider's "excellent" line, 85 for Oura scores) draws a
    short tick across the ring.
--}}
@props(['value', 'max', 'label' => null, 'tint' => 'text-domain-health', 'threshold' => null])

@php
    $radius = 52;
    $circumference = 2 * M_PI * $radius;
    $fraction = $max > 0 ? min(1, max(0, $value / $max)) : 0;
    $tickAngle = $threshold !== null && $max > 0 ? deg2rad(360 * min(1, max(0, $threshold / $max)) - 90) : null;
@endphp

<div {{ $attributes->class('flex flex-col items-center gap-1') }}>
    <div class="relative w-32 h-32">
        <svg viewBox="0 0 128 128" class="absolute inset-0 {{ $tint }}" aria-hidden="true">
            <g transform="rotate(-90 64 64)">
                <circle cx="64" cy="64" r="{{ $radius }}" fill="none" stroke="currentColor" stroke-width="12" stroke-opacity="0.15" />
                <circle cx="64" cy="64" r="{{ $radius }}" fill="none" stroke="currentColor" stroke-width="12" stroke-linecap="round"
                    stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $circumference * (1 - $fraction) }}" />
            </g>
            @if ($tickAngle !== null)
                <line x1="{{ 64 + 44 * cos($tickAngle) }}" y1="{{ 64 + 44 * sin($tickAngle) }}"
                    x2="{{ 64 + 60 * cos($tickAngle) }}" y2="{{ 64 + 60 * sin($tickAngle) }}"
                    class="stroke-base-content" stroke-opacity="0.7" stroke-width="2" stroke-linecap="round" />
            @endif
        </svg>
        <div class="absolute inset-0 flex items-center justify-center">
            <span class="text-3xl font-bold tabular-nums">{{ $value }}</span>
            <span class="sr-only">out of {{ $max }}</span>
        </div>
    </div>
    @if (filled($label))
        <div class="text-xs text-base-content/70">{{ $label }}</div>
    @endif
</div>
