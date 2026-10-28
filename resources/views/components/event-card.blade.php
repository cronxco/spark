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

    $atDigest = $card['at_digest'] ?? null;
    $context = $card['context'] ?? null;
    $contextText = $atDigest
        ? __('Updated from :value', ['value' => \App\Support\EventCardKind::heroValue($layout, $atDigest['value'], $event['unit'] ?? null, $atDigest['display_value'])])
        : ($context['text'] ?? null);
    $arrow = match (true) {
        $atDigest !== null => 'fas.clock-rotate-left',
        ($context['within_usual_range'] ?? true) || ($context['direction'] ?? 'flat') === 'flat' => 'fas.arrow-right',
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

    $label = implode(', ', array_filter([$layout['label'], $display, $contextText]));
@endphp

<a href="{{ route('events.show', $event['id']) }}" wire:navigate
    aria-label="{{ $label }}"
    {{ $attributes->class(['flex min-h-28 flex-col justify-between gap-2 rounded-box bg-base-100 p-4 transition-colors hover:bg-base-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary']) }}>
    <div class="flex items-center gap-1.5 text-xs font-semibold text-base-content/70" aria-hidden="true">
        <x-icon :name="$layout['glyph']" class="h-3.5 w-3.5 {{ $domainTint }}" />
        <span class="truncate">{{ $layout['label'] }}</span>
    </div>

    <div class="flex items-center gap-3" aria-hidden="true">
        @if ($ringShare !== null)
            <svg viewBox="0 0 36 36" class="h-9 w-9 shrink-0 -rotate-90 {{ $domainTint }}">
                <circle cx="18" cy="18" r="15.5" fill="none" stroke="currentColor" stroke-opacity="0.15" stroke-width="4" />
                <circle cx="18" cy="18" r="15.5" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"
                    pathLength="100" stroke-dasharray="{{ round($ringShare * 100, 1) }} 100" />
            </svg>
        @endif
        <span class="text-3xl font-bold tabular-nums leading-none">{{ $display }}</span>
    </div>

    @if ($contextText)
        <div class="flex items-center gap-1 text-xs text-base-content/70" aria-hidden="true">
            <x-icon :name="$arrow" class="h-3 w-3 shrink-0 {{ $arrowTone }}" />
            <span class="truncate">{{ $contextText }}</span>
        </div>
    @endif
</a>
