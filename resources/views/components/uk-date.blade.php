@props(['date', 'showTime' => true])
@php
$class = $attributes->get('class');
@endphp
@if ($date)
<x-popover>
    <x-slot:trigger>
        <span {{ $attributes->merge(['class' => 'cursor-help']) }}>
            {{ $date->format($showTime ? 'j M Y, H:i' : 'j M Y') }}
        </span>
    </x-slot:trigger>
    <x-slot:content class="bg-base-100">
        <div class="text-sm whitespace-nowrap">
            <div class="font-medium">{{ $date->diffForHumans() }}</div>
            <div class="text-base-content/70 mt-1">
                {{ $date->format('l, j F Y') }}<br>
                @if ($showTime)
                {{ $date->format('H:i:s') }}
                @endif
            </div>
        </div>
    </x-slot:content>
</x-popover>
@else
<span class="text-base-content/50">-</span>
@endif