@props(['event', 'label'])

@php
    $title = $event['title'] ?: 'Untitled event';
    $service = $event['service'] ? ucfirst($event['service']) : 'Unknown source';
    $amount = $event['amount'];
    $unit = $event['unit'];
    $formatter = new \NumberFormatter(app()->getLocale(), \NumberFormatter::CURRENCY);
    $value = $amount === null
        ? 'No value recorded'
        : ($unit && strlen($unit) === 3
            ? ($formatter->formatCurrency((float) $amount, strtoupper($unit)) ?: number_format((float) $amount, 2).' '.$unit)
            : number_format((float) $amount, 2).($unit ? ' '.$unit : ''));
    $timeLabel = $event['time']
        ? format_time_for_user(\Carbon\Carbon::parse($event['time']), auth()->user(), 'j M Y, H:i')
        : 'Time not recorded';
@endphp

<a href="{{ route('events.show', $event['id']) }}" wire:navigate
    aria-label="View {{ $label }}: {{ $title }}, {{ $value }}, {{ $service }}, {{ $timeLabel }}"
    class="block rounded-xl border border-base-300 bg-base-100 p-3 transition-colors hover:border-primary/50 hover:bg-base-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
        <div class="min-w-0">
            <div class="text-xs font-medium text-base-content/60">{{ $label }} · {{ $service }}</div>
            <div class="font-semibold break-words">{{ $title }}</div>
        </div>
        <div class="font-medium tabular-nums">{{ $value }}</div>
    </div>
    <div class="mt-2 text-sm text-base-content/70">{{ $timeLabel }}</div>
</a>
