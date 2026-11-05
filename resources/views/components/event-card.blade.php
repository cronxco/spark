@props(['card', 'size' => 'compact', 'wide' => false])

{{--
    One event as a card, shaped by FlintLinkedEventCards. `layout` picks what
    the card shows (design language §7.3):

    - metric: eyebrow, hero value (a ring for scores, a bar against the mean
      for durations and counts) and one comparison line.
    - journey: a flight, its airport codes as the hero, local times under
      each and a route line.
    - calendar: a calendar entry's title and time span.
    - payment: amount, merchant, account and pending state, with where it sits
      in the week's spending.
    - reading: publication, the article's own headline, read time and up to
      four takeaways.

    Every layout sits on its domain's tinted fill, and a card the reader has
    answered a Flint question about ends with that answer. The whole card
    links to the event; there is no footer or menu (§7).
--}}
@php
    $event = $card['event'];
    $layoutName = $card['layout'] ?? 'metric';
    $details = $card['details'] ?? [];
    $answer = $card['answer'] ?? null;
    $domain = in_array($event['domain'] ?? null, ['health', 'activity', 'money', 'media', 'knowledge'], true) ? $event['domain'] : 'online';
    $layout = \App\Support\EventCardKind::for($card['kind'], $event['domain'] ?? null, $event['display_name'] ?? null);
    $wide = $wide || $layoutName !== 'metric';

    $domainTint = "text-domain-{$domain}";
    $domainInk = "text-domain-{$domain}-ink";
    $domainFill = "bg-domain-{$domain}";
    $tz = auth()->user()?->getTimezone() ?? config('app.timezone');
    $clock = fn (?string $iso): ?string => $iso ? \Carbon\Carbon::parse($iso)->timezone($tz)->format('H:i') : null;
    $minutes = function (?int $total): ?string {
        if (! $total) {
            return null;
        }
        $hours = intdiv($total, 60);
        $rest = $total % 60;

        return $hours === 0 ? "{$rest}m" : ($rest === 0 ? "{$hours}h" : "{$hours}h{$rest}m");
    };

    $glyph = match ($layoutName) {
        'journey' => 'fas.plane',
        'calendar' => 'fas.calendar',
        'payment' => 'fas.sterling-sign',
        'reading' => 'fas.newspaper',
        default => $layout['glyph'],
    };
    $label = match ($layoutName) {
        'journey' => $details['flight'] ? __('Flight :number', ['number' => $details['flight']]) : __('Flight'),
        'calendar' => __('Calendar'),
        'payment' => ($details['refund'] ?? false) ? __('Refund') : __('Payment'),
        'reading' => $details['publication'] ?? __('Article'),
        default => $layout['label'],
    };
    $eyebrowTime = match ($layoutName) {
        'journey', 'calendar' => \Carbon\Carbon::parse($event['time'])->timezone($tz)->isToday() ? __('Today') : \Carbon\Carbon::parse($event['time'])->timezone($tz)->format('D j M'),
        'payment' => \Carbon\Carbon::parse($event['time'])->timezone($tz)->format('D H:i'),
        'reading' => \Carbon\Carbon::parse($event['time'])->timezone($tz)->format('H:i'),
        default => null,
    };

    $display = \App\Support\EventCardKind::heroValue($layout, $event['value'] ?? null, $event['unit'] ?? null, $event['display_value'] ?? null);
    $numeric = is_numeric($event['value'] ?? null) ? (float) $event['value'] : null;
    $ringShare = $layout['pattern'] === 'ring' && $numeric !== null && $layout['max']
        ? max(0, min(1, $numeric / $layout['max']))
        : null;
    $ringTick = $layout['max'] ? 85 / $layout['max'] * 360 : null;

    $context = $card['context'] ?? null;
    $mean = is_numeric($context['mean'] ?? null) ? (float) $context['mean'] : null;
    $barShare = $layout['pattern'] === 'bar' && $numeric !== null && $mean > 0
        ? max(0, min(1, $numeric / ($mean * 1.5)))
        : null;

    $atDigest = $card['at_digest'] ?? null;
    $contextText = $atDigest
        ? __('Updated from :value', ['value' => \App\Support\EventCardKind::heroValue($layout, $atDigest['value'], $event['unit'] ?? null, $atDigest['display_value'])])
        : ($context['text'] ?? null);
    $arrow = match (true) {
        $atDigest !== null => 'fas.clock-rotate-left',
        ($context['direction'] ?? 'flat') === 'flat' => 'fas.arrow-right',
        $context['direction'] === 'up' => 'fas.arrow-up',
        default => 'fas.arrow-down',
    };
    $arrowTone = match ($context['valence'] ?? 'neutral') {
        'good' => 'text-success-content dark:text-success',
        'bad' => 'text-error',
        default => 'text-base-content/60',
    };
    if ($atDigest !== null) {
        $arrowTone = 'text-base-content/60';
    }

    $week = $details['week'] ?? null;
    $weekMax = $week ? max(1, max($week['amounts'])) : 1;

    $heroLabel = trim($display.' '.($numeric !== null ? $layout['unit'] : ''));
    $ariaLabel = implode(', ', array_filter(match ($layoutName) {
        'journey' => [$label, __(':from to :to', ['from' => $details['from'], 'to' => $details['to']]), $details['departs_local'] ? __('departs :time', ['time' => $details['departs_local']]) : null, $details['arrives_local'] ? __('arrives :time', ['time' => $details['arrives_local']]) : null],
        'calendar' => [$label, $details['title'] ?? null, $clock($details['starts_at'] ?? null)],
        'payment' => [$label, $display, $details['merchant'] ?? null, ($details['pending'] ?? false) ? __('pending') : null, $week['caption'] ?? null],
        'reading' => [$details['publication'] ?? null, $details['headline'] ?? null, ($details['read_minutes'] ?? null) ? __(':count min read', ['count' => $details['read_minutes']]) : null],
        default => [$layout['label'], $heroLabel, $contextText],
    }));
    if ($answer) {
        $ariaLabel .= ', '.__('you answered :answer', ['answer' => $answer['note'] ?? $answer['answer']]);
    }
@endphp

<a href="{{ route('events.show', $event['id']) }}" wire:navigate
    aria-label="{{ $ariaLabel }}"
    style="--card-tint: var(--domain-{{ $domain }})"
    {{ $attributes->class([
        'surface-domain-tint flex min-h-28 flex-col gap-2 rounded-box p-4 transition-[filter] hover:brightness-[.97] dark:hover:brightness-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary',
        'col-span-full' => $wide,
        'justify-between' => ! $wide,
    ]) }}>
    <div class="flex items-center gap-2 text-xs font-semibold text-base-content/70" aria-hidden="true">
        @if ($layoutName === 'reading' && filled($details['publication'] ?? null))
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-md font-serif text-[11px] font-bold {{ $domainInk }}" style="background: color-mix(in oklab, var(--card-tint) 18%, transparent)">{{ mb_strtoupper(mb_substr($details['publication'], 0, 1)) }}</span>
        @else
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-md" style="background: color-mix(in oklab, var(--card-tint) 20%, transparent)">
                <x-icon :name="$glyph" class="h-3 w-3 {{ $domainInk }}" />
            </span>
        @endif
        <span class="truncate">{{ $label }}</span>
        @if ($eyebrowTime)
            <span class="ml-auto shrink-0 font-medium tabular-nums">{{ $eyebrowTime }}</span>
        @endif
    </div>

    @switch ($layoutName)
        @case ('journey')
            <div class="grid grid-cols-[auto_1fr_auto] items-center gap-3" aria-hidden="true">
                <div>
                    <div class="font-display text-3xl font-bold leading-none tracking-wide">{{ $details['from'] }}</div>
                    @if ($details['departs_local'])
                        <div class="mt-1.5 text-xs text-base-content/70 tabular-nums">{{ trim($details['departs_local'].' '.($details['departs_zone'] ?? '')) }}</div>
                    @endif
                </div>
                <div class="relative h-4 {{ $domainTint }}">
                    <div class="absolute inset-x-0 top-1/2 border-t-2 border-dotted border-current opacity-50"></div>
                    <x-icon name="fas.plane" class="absolute left-1/2 top-1/2 h-4 w-4 -translate-x-1/2 -translate-y-1/2 {{ $domainInk }}" />
                </div>
                <div class="text-right">
                    <div class="font-display text-3xl font-bold leading-none tracking-wide">{{ $details['to'] }}</div>
                    @if ($details['arrives_local'])
                        <div class="mt-1.5 text-xs text-base-content/70 tabular-nums">{{ trim($details['arrives_local'].' '.($details['arrives_zone'] ?? '')) }}</div>
                    @endif
                </div>
            </div>
            <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-base-content/70" aria-hidden="true">
                @if ($minutes($details['duration_minutes'] ?? null))
                    <span>{{ __(':duration flight', ['duration' => $minutes($details['duration_minutes'])]) }}</span>
                @endif
                @if ($details['route'] ?? null)
                    <span>{{ $details['route'] }}</span>
                @endif
            </div>
            @break

        @case ('calendar')
            <div class="text-base font-semibold leading-snug" aria-hidden="true">{{ $details['title'] ?? __('Calendar entry') }}</div>
            <div class="flex flex-wrap gap-x-3 text-xs text-base-content/70 tabular-nums" aria-hidden="true">
                @if ($details['all_day'] ?? false)
                    <span>{{ __('All day') }}</span>
                @else
                    <span>{{ collect([$clock($details['starts_at'] ?? null), $clock($details['ends_at'] ?? null)])->filter()->join(' to ') }}</span>
                    @if ($minutes($details['duration_minutes'] ?? null))
                        <span>{{ $minutes($details['duration_minutes']) }}</span>
                    @endif
                @endif
            </div>
            @break

        @case ('payment')
            <div class="flex items-end justify-between gap-4" aria-hidden="true">
                <div class="min-w-0 space-y-1">
                    <div class="font-display text-2xl font-bold leading-none tabular-nums {{ $domainInk }} dark:text-primary">{{ $display }}</div>
                    @if ($details['merchant'] ?? null)
                        <div class="truncate text-sm font-semibold">{{ $details['merchant'] }}</div>
                    @endif
                    <div class="flex flex-wrap items-center gap-2 text-xs text-base-content/70">
                        @if ($details['account'] ?? null)
                            <span>{{ $details['account'] }}</span>
                        @endif
                        @if ($details['pending'] ?? false)
                            <span class="rounded-md bg-base-content/10 px-1.5 py-0.5 text-[11px] font-semibold">{{ __('Pending') }}</span>
                        @endif
                    </div>
                </div>
                @if ($week)
                    <div class="shrink-0">
                        <div class="flex h-10 items-end gap-1">
                            @foreach ($week['amounts'] as $amount)
                                <span class="w-2 rounded-sm {{ $loop->index === $week['index'] ? $domainFill : 'bg-base-content/10' }}" style="height: {{ max(12, round($amount / $weekMax * 100)) }}%"></span>
                            @endforeach
                        </div>
                        @if ($week['caption'])
                            <div class="mt-1 text-right text-[11px] text-base-content/70">{{ $week['caption'] }}</div>
                        @endif
                    </div>
                @endif
            </div>
            @break

        @case ('reading')
            <div class="font-serif text-base font-bold leading-snug" aria-hidden="true">{{ $details['headline'] ?? __('Article') }}</div>
            @if ($details['read_minutes'] ?? null)
                <div class="text-xs text-base-content/70" aria-hidden="true">{{ __(':count min read', ['count' => $details['read_minutes']]) }}</div>
            @endif
            @if ($details['takeaways'] ?? [])
                <ul class="mt-1 grid gap-2 border-t border-base-content/10 pt-3 text-sm leading-snug">
                    @foreach ($details['takeaways'] as $takeaway)
                        <li class="grid grid-cols-[6px_1fr] gap-2.5">
                            <span class="mt-[7px] h-1.5 w-1.5 rounded-sm {{ $domainFill }}" aria-hidden="true"></span>
                            <span class="line-clamp-2">{!! \Illuminate\Support\Str::inlineMarkdown($takeaway, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @break

        @default
            <div @class(['flex items-center justify-between gap-4' => $wide]) aria-hidden="true">
                @if ($ringShare !== null)
                    @if ($wide)
                        <span class="font-display text-2xl font-bold tabular-nums leading-none">{{ $display }}</span>
                    @endif
                    {{-- §7.3: a 64px ring with the number inside and a tick at 85, Oura's "excellent". --}}
                    <svg viewBox="0 0 84 84" class="{{ $wide ? '' : 'mx-auto' }} h-16 w-16 {{ $domainTint }}" aria-hidden="true">
                        <circle cx="42" cy="42" r="36" fill="none" stroke="currentColor" stroke-opacity="0.18" stroke-width="8" />
                        <circle cx="42" cy="42" r="36" fill="none" stroke="currentColor" stroke-width="8" stroke-linecap="round"
                            stroke-dasharray="{{ round($ringShare * 226.2, 1) }} 226.2" transform="rotate(-90 42 42)" />
                        <line x1="42" y1="1" x2="42" y2="11" class="stroke-base-content" stroke-width="2" stroke-linecap="round"
                            transform="rotate({{ round($ringTick, 1) }} 42 42)" />
                        <text x="42" y="50" text-anchor="middle" font-size="24" font-weight="700" class="fill-base-content tabular-nums">{{ $display }}</text>
                    </svg>
                @else
                    <div @class(['flex-1' => $wide])>
                        {{-- §4: a 24px hero with its unit at about 55%, in the same face. --}}
                        <span class="font-display text-2xl font-bold tabular-nums leading-none {{ $domainInk }} dark:text-primary">{{ $display }}</span>
                        @if ($layout['unit'] && $numeric !== null)
                            <span class="font-display text-[13px] font-medium text-base-content/70">{{ $layout['unit'] }}</span>
                        @endif
                        @if ($barShare !== null)
                            {{-- §8 "against the mean": the track runs to 1.5× the 30d average, so the Avg marker sits at two thirds. --}}
                            <div class="relative mt-6 {{ $wide ? 'max-w-64' : '' }}">
                                <div class="h-1.5 overflow-hidden rounded-full bg-base-content/10">
                                    <div class="h-full rounded-full {{ $domainFill }}" style="width: {{ round($barShare * 100, 1) }}%"></div>
                                </div>
                                <div class="absolute -top-3 -bottom-1 left-2/3 -ml-px w-0.5 rounded-full bg-base-content">
                                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 text-[11px] font-semibold leading-none text-base-content/70">{{ __('Avg') }}</span>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            @if ($contextText)
                <div class="flex items-center gap-1 text-xs text-base-content/70" aria-hidden="true">
                    <x-icon :name="$arrow" class="h-3 w-3 shrink-0 {{ $arrowTone }}" />
                    <span class="truncate">{{ $contextText }}</span>
                </div>
            @endif
    @endswitch

    @if ($answer)
        <div class="mt-1 flex items-start gap-2 border-t border-base-content/10 pt-2.5 text-sm" aria-hidden="true">
            <x-icon name="fas.check" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-success-content dark:text-success" />
            <span>
                <span class="block text-[11px] text-base-content/70">{{ __('You answered') }}</span>
                {{ $answer['note'] ?? $answer['answer'] }}
            </span>
        </div>
    @endif
</a>
