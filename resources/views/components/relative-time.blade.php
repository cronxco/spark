@props(['time'])

{{-- Spark Design System: relative within a day, absolute beyond it; absolute timestamps are set in mono. --}}
<time datetime="{{ $time->toIso8601String() }}" {{ $attributes->class(['font-mono' => abs(now()->diffInHours($time)) >= 24]) }}>{{ format_relative_time($time) }}</time>
