@props(['time', 'format' => 'M j, Y g:i A', 'showRelative' => true, 'class' => ''])

@php
    $user = auth()->guard('web')->user();
    if ($time instanceof \Carbon\Carbon) {
        $datetime = $time;
    } else {
        $datetime = \Carbon\Carbon::parse($time);
    }

    $userDatetime = to_user_timezone($datetime, $user);
    $formatted = $userDatetime->format($format);
    $relative = $showRelative ? $userDatetime->diffForHumans() : null;
    $timezone = $user?->getTimezone() ?? config('app.timezone', 'UTC');
    $absoluteTime = $userDatetime->format('l, F j, Y \a\t g:i:s A');
@endphp

<span
    class="tooltip tooltip-top {{ $class }}"
    data-tip="{{ $absoluteTime }} ({{ $timezone }})">
    {{ $formatted }}
    @if($relative)
        <span class="text-base-content/60">({{ $relative }})</span>
    @endif
</span>
