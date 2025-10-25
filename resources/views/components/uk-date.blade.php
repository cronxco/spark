@props(['date', 'showTime' => true])
@php
$class = $attributes->get('class');
$user = auth()->guard('web')->user();
$userDate = $date ? to_user_timezone($date, $user) : null;
$utcDate = $date ? $date->copy()->setTimezone('UTC') : null;
$userTimezone = $user?->getTimezone() ?? 'UTC';
// Check if the actual formatted times are different
$isDifferentFromUtc = $userDate && $utcDate && (
    $userDate->format('Y-m-d H:i:s') !== $utcDate->format('Y-m-d H:i:s')
);
@endphp
@if ($userDate)
<x-popover>
    <x-slot:trigger>
        <span {{ $attributes->merge(['class' => 'cursor-help']) }}>
            {{ $userDate->format($showTime ? 'j M Y, H:i' : 'j M Y') }}
        </span>
    </x-slot:trigger>
    <x-slot:content class="bg-base-100">
        <div class="text-sm whitespace-nowrap">
            <div class="font-medium">{{ $userDate->diffForHumans() }}</div>
            <div class="text-base-content/70 mt-1">
                @if ($isDifferentFromUtc)
                <div class="mb-2">
                    <div class="text-xs text-base-content/50 uppercase">{{ $userTimezone }}</div>
                    <div>{{ $userDate->format('l, j F Y') }}</div>
                    @if ($showTime)
                    <div>{{ $userDate->format('H:i:s') }}</div>
                    @endif
                </div>
                <div class="pt-2 border-t border-base-300">
                    <div class="text-xs text-base-content/50 uppercase">UTC</div>
                    <div>{{ $utcDate->format('l, j F Y') }}</div>
                    @if ($showTime)
                    <div>{{ $utcDate->format('H:i:s') }}</div>
                    @endif
                </div>
                @else
                <div>
                    <div>{{ $userDate->format('l, j F Y') }}</div>
                    @if ($showTime)
                    <div>{{ $userDate->format('H:i:s') }}</div>
                    @endif
                </div>
                @endif
            </div>
        </div>
    </x-slot:content>
</x-popover>
@else
<span class="text-base-content/50">-</span>
@endif