@props(['time', 'format' => 'Y-m-d H:i:s'])

@php
    $user = auth()->guard('web')->user();
    if ($time instanceof \Carbon\Carbon) {
        $datetime = $time;
    } else {
        $datetime = \Carbon\Carbon::parse($time);
    }
    $formatted = format_time_for_user($datetime, $user, $format);
@endphp

{{ $formatted }}
