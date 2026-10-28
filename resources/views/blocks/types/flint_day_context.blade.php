@props(['block'])

@php
    $context = $block->metadata['day_context'] ?? [];
    $calendar = $context['calendar'] ?? [];
    $birthdays = $context['birthdays'] ?? [];
    $weather = $context['weather'] ?? null;

    $entryTime = function (array $entry): ?string {
        if ($entry['all_day'] ?? false) {
            return 'All day';
        }

        return isset($entry['start'])
            ? \Illuminate\Support\Carbon::parse($entry['start'])->format('H:i')
            : null;
    };
@endphp

<x-block-card.shell :block="$block" :title="$block->title">
    @if ($weather)
        <div class="flex items-center gap-2 text-sm text-base-content/80">
            <x-icon name="fas.cloud-sun" class="w-4 h-4 text-base-content/70 shrink-0" />
            <span>
                @if (! empty($weather['location'])){{ $weather['location'] }} · @endif
                {{ $weather['condition'] ?? '—' }}
                @isset($weather['temp_high_c']) · {{ $weather['temp_high_c'] }}°C @endisset
                @isset($weather['rain_probability_pct']) · {{ $weather['rain_probability_pct'] }}% rain @endisset
            </span>
        </div>
    @endif

    @if (count($birthdays))
        <div class="flex flex-wrap gap-1.5">
            @foreach ($birthdays as $birthday)
                <span class="badge badge-ghost badge-sm gap-1" wire:key="bday-{{ $loop->index }}">
                    <x-icon name="fas.cake-candles" class="w-3 h-3" />
                    {{ $birthday['title'] ?? '' }}
                </span>
            @endforeach
        </div>
    @endif

    @if (count($calendar))
        <ul class="flex flex-col gap-1.5">
            @foreach ($calendar as $entry)
                <li class="flex items-baseline gap-2 text-sm" wire:key="cal-{{ $loop->index }}">
                    <span class="text-xs text-base-content/70 w-14 shrink-0 tabular-nums">
                        {{ $entryTime($entry) ?? '' }}
                    </span>
                    <span class="text-base-content/90 grow">{{ $entry['title'] ?? '' }}</span>
                    @if (($entry['person'] ?? null) === 'dan')
                        <span class="badge badge-ghost badge-xs shrink-0">Dan</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if (! count($calendar) && ! count($birthdays) && ! $weather)
        <p class="text-sm text-base-content/70">No day context recorded.</p>
    @endif
</x-block-card.shell>
