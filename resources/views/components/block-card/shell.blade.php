{{--
    The chrome every block card shares (docs/UI and UX/DESIGN_LANGUAGE.md §7.1):
    an eyebrow with the type's glyph, label and time; an optional title; the
    layout's own hero and body in the default slot; and an optional context
    line. The whole card links to the block. Custom layouts in
    `blocks/types/*.blade.php` supply only what goes inside.

    Links and buttons inside the slot need `relative z-10` to sit above the
    card-wide link.
--}}
@props([
    'block',
    'title' => null,
    'href' => null,
    'label' => null,
    'icon' => null,
    // False for cards with their own controls (Flint's answer form), where a
    // card-wide link would swallow the clicks.
    'linked' => true,
    // The secondary-action menu at the end of the eyebrow (§7.1).
    'menu' => true,
])

@php
    $presentation = \App\Support\BlockPresentation::for($block);
    $href ??= route('blocks.show', $block);
    // Generated text says so in words as well as with the glyph (§3.4).
    $label ??= $presentation->label;
    if ($presentation->generatedBy && ! str_contains(strtolower($label), strtolower($presentation->generatedBy))) {
        $label = $presentation->generatedBy . ' · ' . $label;
    }
    $external = ! str_starts_with($href, url('/'));
    $linkAttributes = $external ? 'target="_blank" rel="noopener noreferrer"' : 'wire:navigate';
    $icon ??= $presentation->icon;
    $sourceUrl = filled($block->url) ? $block->url : null;
    $copyText = $block->value !== null
        ? format_block_value_display($block->formatted_value, $block->value_unit)
        : (trim(strip_tags((string) $block->getContent())) ?: (string) $block->title);
    $glyphClass = match ($presentation->domain) {
        'health' => 'text-domain-health',
        'activity' => 'text-domain-activity',
        'money' => 'text-domain-money',
        'media' => 'text-domain-media',
        'online' => 'text-domain-online',
        'anomaly' => 'text-domain-anomaly',
        default => 'text-domain-knowledge',
    };
@endphp

<article {{ $attributes->class([
    'card relative transition-colors',
    'hover:bg-base-300' => $linked,
    'bg-base-200' => ! $presentation->aiWritten,
    // Generated text sits on the generated surface (§3.5).
    'surface-generated' => $presentation->aiWritten,
]) }}>
    <div class="card-body p-4 gap-3">
        <div class="flex items-center gap-2 text-xs font-semibold text-base-content/70">
            <span class="shrink-0 {{ $glyphClass }}" aria-hidden="true"><x-icon :name="$icon" class="w-3.5 h-3.5" /></span>
            <span class="truncate">{{ $label }}</span>
            @if ($block->time)
                <x-relative-time :time="$block->time" class="ms-auto shrink-0 font-normal" />
            @endif
            @if ($menu)
                <div data-block-menu @class(['dropdown dropdown-end relative z-10 shrink-0', 'ms-auto' => ! $block->time])>
                    <button type="button" tabindex="0" class="btn btn-ghost btn-xs btn-square min-h-6 min-w-6 text-base-content/70" aria-label="More actions">
                        <x-icon name="fas.ellipsis-vertical" class="w-3.5 h-3.5" />
                    </button>
                    <ul tabindex="0" class="dropdown-content menu z-20 mt-1 w-48 rounded-box bg-base-100 p-1 font-normal text-sm shadow">
                        @if ($block->event)
                            <li><a href="{{ route('events.show', $block->event) }}" wire:navigate>Open event</a></li>
                        @endif
                        <li><a href="{{ route('blocks.show', $block) }}" wire:navigate>Open block</a></li>
                        @if ($sourceUrl)
                            <li><a href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">Open source</a></li>
                        @endif
                        @if (filled($copyText))
                            <li><button type="button" x-data x-on:click="$dispatch('copy-to-clipboard', { url: {{ \Illuminate\Support\Js::from($copyText) }} })">Copy</button></li>
                        @endif
                    </ul>
                </div>
            @endif
        </div>

        @if (filled($title))
            <h3 class="text-base font-semibold leading-snug line-clamp-2">
                <a href="{{ $href }}" {!! $linkAttributes !!} @class(["after:absolute after:inset-0 after:content-['']" => $linked, 'hover:underline' => ! $linked])>{{ $title }}</a>
            </h3>
        @elseif ($linked)
            <a href="{{ $href }}" {!! $linkAttributes !!} class="absolute inset-0" aria-label="{{ $block->title ?: $label }}"></a>
        @endif

        {{ $slot }}

        @isset($context)
            <div class="text-xs text-base-content/70">{{ $context }}</div>
        @endisset
    </div>
</article>
