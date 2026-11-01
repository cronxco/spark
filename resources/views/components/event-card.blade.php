@props(['card', 'size' => 'compact'])

{{--
    One event as a card, laid out by EventCardKind and shaped by
    FlintLinkedEventCards. Only the compact size exists so far: an eyebrow
    (glyph and short label), the hero value and one context line. The whole
    card links to the event; there is no footer or menu (design language §7).
--}}
@php
    $event = $card['event'];
    $layout = \App\Support\EventCardKind::for($card['kind'], $event['domain'] ?? null, $event['display_name'] ?? null);
    $domainTint = match ($event['domain'] ?? null) {
        'health' => 'text-domain-health',
        'activity' => 'text-domain-activity',
        'money' => 'text-domain-money',
        'media' => 'text-domain-media',
        'knowledge' => 'text-domain-knowledge',
        default => 'text-domain-online',
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
    $domainFill = match ($event['domain'] ?? null) {
        'health' => 'bg-domain-health',
        'activity' => 'bg-domain-activity',
        'money' => 'bg-domain-money',
        'media' => 'bg-domain-media',
        'knowledge' => 'bg-domain-knowledge',
        default => 'bg-domain-online',
    };

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

    $heroLabel = trim($display.' '.($numeric !== null ? $layout['unit'] : ''));
    $label = implode(', ', array_filter([$layout['label'], $heroLabel, $contextText]));
@endphp

<a href="{{ route('events.show', $event['id']) }}" wire:navigate
    aria-label="{{ $label }}"
    {{ $attributes->class(['flex min-h-28 flex-col justify-between gap-2 rounded-box bg-base-100 p-4 transition-colors hover:bg-base-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary']) }}>
    <div class="flex items-center gap-1.5 text-xs font-semibold text-base-content/70" aria-hidden="true">
        <x-icon :name="$layout['glyph']" class="h-3.5 w-3.5 {{ $domainTint }}" />
        <span class="truncate">{{ $layout['label'] }}</span>
    </div>

    @if ($ringShare !== null)
        {{-- §7.3: a 64px ring with the number inside and a tick at 85, Oura's "excellent". --}}
        <svg viewBox="0 0 84 84" class="mx-auto h-16 w-16 {{ $domainTint }}" aria-hidden="true">
            <circle cx="42" cy="42" r="36" fill="none" stroke="currentColor" stroke-opacity="0.18" stroke-width="8" />
            <circle cx="42" cy="42" r="36" fill="none" stroke="currentColor" stroke-width="8" stroke-linecap="round"
                stroke-dasharray="{{ round($ringShare * 226.2, 1) }} 226.2" transform="rotate(-90 42 42)" />
            <line x1="42" y1="1" x2="42" y2="11" class="stroke-base-content" stroke-width="2" stroke-linecap="round"
                transform="rotate({{ round($ringTick, 1) }} 42 42)" />
            <text x="42" y="50" text-anchor="middle" font-size="24" font-weight="700" class="fill-base-content tabular-nums">{{ $display }}</text>
        </svg>
    @else
        <div aria-hidden="true">
            {{-- §4: a 24px hero with its unit at about 55%, in the same face. --}}
            <span class="font-display text-2xl font-bold tabular-nums leading-none">{{ $display }}</span>
            @if ($layout['unit'] && $numeric !== null)
                <span class="font-display text-[13px] font-medium text-base-content/70">{{ $layout['unit'] }}</span>
            @endif
            @if ($barShare !== null)
                {{-- §8 "against the mean": the track runs to 1.5× the 30d average, so the Avg marker sits at two thirds. --}}
                <div class="relative mt-6">
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

    @if ($contextText)
        <div class="flex items-center gap-1 text-xs text-base-content/70" aria-hidden="true">
            <x-icon :name="$arrow" class="h-3 w-3 shrink-0 {{ $arrowTone }}" />
            <span class="truncate">{{ $contextText }}</span>
        </div>
    @endif
</a>
