@props(['domain', 'variant' => 'badge', 'href' => null, 'showIcon' => true, 'showLabel' => true])

@php
// Handle null/empty domain gracefully
if (!$domain) {
    return;
}

// Domain metadata
$domainMeta = [
    'health' => [
        'label' => 'Health',
        'icon' => 'fas.heart-pulse',
        'color' => 'success',
        'description' => 'Physical health, fitness, sleep, and wellness tracking',
    ],
    'money' => [
        'label' => 'Money',
        'icon' => 'fas.sterling-sign',
        'color' => 'warning',
        'description' => 'Financial transactions, accounts, and spending',
    ],
    'media' => [
        'label' => 'Media',
        'icon' => 'fas.play',
        'color' => 'info',
        'description' => 'Music, books, podcasts, and entertainment consumption',
    ],
    'knowledge' => [
        'label' => 'Knowledge',
        'icon' => 'fas.brain',
        'color' => 'primary',
        'description' => 'Notes, bookmarks, reading, and knowledge management',
    ],
    'online' => [
        'label' => 'Online',
        'icon' => 'fas.globe',
        'color' => 'accent',
        'description' => 'Online activity, social media, and digital presence',
    ],
];

$info = $domainMeta[$domain] ?? [
    'label' => Str::headline($domain),
    'icon' => 'fas.folder',
    'color' => 'primary',
    'description' => ucfirst($domain) . ' domain',
];

$label = $info['label'];
$icon = $info['icon'];
$color = $info['color'];
$description = $info['description'];

// Generate href if not provided - link to filtered admin events
$defaultHref = '/admin/events?domain=' . urlencode($domain);
$linkHref = $href ?? $defaultHref;

// Base ID for this popover
$popoverBaseId = 'domain-ref-' . md5($domain);
@endphp

<span
    x-data="{
        open: false,
        showTimeout: null,
        hideTimeout: null,
        popoverId: '{{ $popoverBaseId }}-' + Math.random().toString(36).substring(2, 10),
        isMobile: window.innerWidth < 768,
        show() {
            if (this.isMobile) return;
            clearTimeout(this.hideTimeout);
            this.showTimeout = setTimeout(() => {
                window.dispatchEvent(new CustomEvent('popover-opening', { detail: this.popoverId }));
                this.open = true;
            }, 200);
        },
        hide() {
            clearTimeout(this.showTimeout);
            this.hideTimeout = setTimeout(() => { this.open = false; }, 150);
        },
        keepOpen() {
            clearTimeout(this.hideTimeout);
        },
        toggle() {
            if (this.isMobile) {
                window.dispatchEvent(new CustomEvent('popover-opening', { detail: this.popoverId }));
                this.open = !this.open;
            }
        },
        closeIfNotMe(event) {
            if (event.detail !== this.popoverId) {
                clearTimeout(this.showTimeout);
                this.open = false;
            }
        }
    }"
    @mouseenter="show()"
    @mouseleave="hide()"
    @keydown.escape="open = false"
    @popover-opening.window="closeIfNotMe($event)"
    class="relative inline-block"
>
    {{-- Trigger: Badge variant (default) --}}
    @if ($variant === 'badge')
        {{-- Desktop: navigable link --}}
        <a
            x-show="!isMobile"
            href="{{ $linkHref }}"
            wire:navigate
            class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-sm font-medium
                   bg-{{ $color }}/10 text-{{ $color }} hover:bg-{{ $color }}/20
                   border border-{{ $color }}/20 transition-all duration-150 cursor-pointer"
        >
            @if ($showIcon)
                <x-icon :name="$icon" class="w-3 h-3 opacity-70" />
            @endif
            @if ($showLabel)
                <span>{{ $label }}</span>
            @endif
        </a>

        {{-- Mobile: popover trigger only --}}
        <button
            x-show="isMobile"
            type="button"
            @click="toggle()"
            class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-sm font-medium
                   bg-{{ $color }}/10 text-{{ $color }} hover:bg-{{ $color }}/20
                   border border-{{ $color }}/20 transition-all duration-150 cursor-pointer"
        >
            @if ($showIcon)
                <x-icon :name="$icon" class="w-3 h-3 opacity-70" />
            @endif
            @if ($showLabel)
                <span>{{ $label }}</span>
            @endif
        </button>
    @else
        {{-- Trigger: Text variant (plain text with hover) --}}
        {{-- Desktop: navigable link --}}
        <a
            x-show="!isMobile"
            href="{{ $linkHref }}"
            wire:navigate
            class="font-medium hover:text-{{ $color }} transition-colors cursor-pointer"
        >{{ $label }}</a>

        {{-- Mobile: popover trigger only --}}
        <button
            x-show="isMobile"
            type="button"
            @click="toggle()"
            class="font-medium hover:text-{{ $color }} transition-colors cursor-pointer bg-transparent border-0 p-0"
        >{{ $label }}</button>
    @endif

    {{-- Popover Card --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 translate-y-1"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-cloak
        @mouseenter="keepOpen()"
        @click.outside="open = false"
        class="absolute z-50 mt-2 left-0"
        style="min-width: 280px; max-width: 340px;"
    >
        <div class="card bg-base-100 shadow-xl border border-{{ $color }}/30 overflow-hidden">
            {{-- Accent bar at top --}}
            <div class="h-1 bg-gradient-to-r from-{{ $color }} to-{{ $color }}/50"></div>

            <div class="card-body p-4 gap-3">
                {{-- Header with icon and domain name --}}
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl bg-{{ $color }}/10 flex items-center justify-center flex-shrink-0 ring-2 ring-{{ $color }}/20">
                        <x-icon :name="$icon" class="w-6 h-6 text-{{ $color }}" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <h4 class="font-bold text-base leading-tight">
                            {{ $label }}
                        </h4>
                        <div class="badge badge-ghost badge-xs mt-1">Domain</div>
                    </div>
                </div>

                {{-- Description --}}
                <p class="text-sm text-base-content/70 leading-relaxed">
                    {{ $description }}
                </p>

                {{-- Domain stats --}}
                @php
                    // Get services in this domain
                    $servicesInDomain = \App\Integrations\PluginRegistry::getAllPlugins()
                        ->filter(fn($plugin) => $plugin::getDomain() === $domain)
                        ->map(fn($plugin) => $plugin::getIdentifier())
                        ->toArray();

                    // Get counts for this domain
                    $eventsCount = \App\Models\Event::whereIn('service', $servicesInDomain)->count();
                @endphp

                <div class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-base-content/60">Total events</span>
                        <span class="font-medium">{{ number_format($eventsCount) }}</span>
                    </div>
                </div>

                {{-- Footer with link to filter events --}}
                <div class="pt-3 border-t border-base-300">
                    <a
                        href="{{ $linkHref }}"
                        wire:navigate
                        @click.stop
                        class="btn btn-{{ $color }} btn-sm w-full gap-1"
                    >
                        <x-icon name="fas.arrow-right" class="w-3 h-3" />
                        View {{ $label }} Events
                    </a>
                </div>
            </div>
        </div>
    </div>
</span>
