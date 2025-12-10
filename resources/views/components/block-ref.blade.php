@props(['block', 'showType' => true, 'text' => null])

@php
use App\Integrations\PluginRegistry;

// Handle null blocks gracefully
if (!$block) {
    return;
}

// Load event relationship if needed
$block->loadMissing('event');

// Get plugin info (through the event)
$pluginClass = $block->event ? PluginRegistry::getPlugin($block->event->service) : null;
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.grip';
$serviceName = $pluginClass ? $pluginClass::getDisplayName() : 'Unknown';
$domain = $pluginClass ? $pluginClass::getDomain() : 'knowledge';

// Get block type info
$blockTypes = $pluginClass ? $pluginClass::getBlockTypes() : [];
$blockTypeInfo = $blockTypes[$block->block_type] ?? null;
$blockIcon = $blockTypeInfo['icon'] ?? 'fas.grip';
$blockDisplayName = $blockTypeInfo['display_name'] ?? Str::headline($block->block_type ?? 'Block');

// Domain-based colors
$domainColors = [
    'health' => 'success',
    'money' => 'warning',
    'media' => 'info',
    'knowledge' => 'primary',
    'online' => 'accent',
];
$accentColor = $domainColors[$domain] ?? 'accent';

// Check for custom layout
$hasCustomLayout = $block->hasCustomCardLayout();
$customLayoutPath = $block->getCustomCardLayoutPath();

// Base ID for this popover (unique suffix added via JavaScript)
$popoverBaseId = 'block-ref-' . $block->id;
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
    @click="toggle()"
    @popover-opening.window="closeIfNotMe($event)"
    @keydown.escape="open = false"
    class="relative inline-block"
>
    {{-- Trigger: The reference link/badge --}}
    <a
        href="{{ route('blocks.show', $block) }}"
        wire:navigate
        @click.stop="if (isMobile) { $event.preventDefault(); toggle(); }"
        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-sm font-medium
               bg-{{ $accentColor }}/10 text-{{ $accentColor }} hover:bg-{{ $accentColor }}/20
               border border-{{ $accentColor }}/20 transition-all duration-150 cursor-pointer"
    >
        <x-icon :name="$blockIcon" class="w-3 h-3 opacity-70" />
        <span class="max-w-[180px] truncate">{!! $text ?? ($block->title ?? $blockDisplayName) !!}</span>
        @if ($showType && $block->block_type)
            <span class="badge badge-xs badge-ghost opacity-70">{{ $blockDisplayName }}</span>
        @endif
    </a>

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
        style="min-width: 320px; max-width: 400px;"
    >
        <div class="card bg-base-100 shadow-xl border border-{{ $accentColor }}/30 overflow-hidden">
            {{-- Accent bar --}}
            <div class="h-1 bg-gradient-to-r from-{{ $accentColor }} to-{{ $accentColor }}/50"></div>

            <div class="card-body p-0">
                @if ($hasCustomLayout && $customLayoutPath)
                    {{-- Use custom block type view (scaled down for popover) --}}
                    <div class="transform scale-95 origin-top-left p-2">
                        @include($customLayoutPath, ['block' => $block])
                    </div>
                @else
                    {{-- Default block preview --}}
                    <div class="p-4 gap-3 flex flex-col">
                        {{-- Header --}}
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <div class="badge badge-{{ $accentColor }} badge-outline gap-1">
                                    <x-icon :name="$blockIcon" class="w-3 h-3" />
                                    {{ $blockDisplayName }}
                                </div>
                            </div>
                            @if ($block->time)
                                <div class="text-xs text-base-content/60">
                                    {{ $block->time->diffForHumans() }}
                                </div>
                            @endif
                        </div>

                        {{-- Title --}}
                        @if ($block->title)
                            <h4 class="font-bold text-base leading-tight">
                                {{ $block->title }}
                            </h4>
                        @endif

                        {{-- Value display --}}
                        @if ($block->value !== null)
                            <div class="flex items-baseline gap-2">
                                <span class="text-3xl font-bold text-{{ $accentColor }}">
                                    {{ number_format($block->formatted_value, ($block->value_multiplier && $block->value_multiplier > 1) ? 2 : 0) }}
                                </span>
                                @if ($block->value_unit)
                                    <span class="text-sm text-base-content/60">{{ $block->value_unit }}</span>
                                @endif
                            </div>
                        @endif

                        {{-- Content preview --}}
                        @php
                            $contentPreview = $block->getContent();
                            if ($contentPreview) {
                                $contentPreview = Str::limit(strip_tags($contentPreview), 150);
                            }
                        @endphp
                        @if ($contentPreview)
                            <p class="text-sm text-base-content/70 line-clamp-3 leading-relaxed">
                                {{ $contentPreview }}
                            </p>
                        @endif

                        {{-- Metadata preview (select important fields) --}}
                        @if ($block->metadata && count($block->metadata) > 0)
                            @php
                                $displayMeta = collect($block->metadata)
                                    ->except(['content', 'summary', 'text', 'description', 'image', 'image_url', 'article_text'])
                                    ->filter(fn($v) => !is_array($v) && !is_null($v) && $v !== '')
                                    ->take(3);
                            @endphp
                            @if ($displayMeta->count() > 0)
                                <div class="text-xs text-base-content/60 space-y-1">
                                    @foreach ($displayMeta as $key => $value)
                                        <div class="flex justify-between gap-2">
                                            <span class="font-medium capitalize">{{ str_replace('_', ' ', $key) }}:</span>
                                            <span class="text-right truncate max-w-[150px]">{{ Str::limit($value, 30) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </div>
                @endif

                {{-- Action footer (always shown) --}}
                <div class="flex items-center gap-2 p-4 pt-0 border-t border-base-300 mt-auto">
                    <a
                        href="{{ route('blocks.show', $block) }}"
                        wire:navigate
                        @click.stop
                        class="btn btn-{{ $accentColor }} btn-sm flex-1 gap-1"
                    >
                        <x-icon name="fas.arrow-right" class="w-3 h-3" />
                        View Block
                    </a>
                    @if ($block->event)
                        <a
                            href="{{ route('events.show', $block->event) }}"
                            wire:navigate
                            @click.stop
                            class="btn btn-ghost btn-sm btn-square"
                            title="View Parent Event"
                        >
                            <x-icon name="fas.bolt" class="w-4 h-4" />
                        </a>
                    @endif
                    @if ($block->url)
                        <a
                            href="{{ $block->url }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            @click.stop
                            class="btn btn-ghost btn-sm btn-square"
                            title="Open URL"
                        >
                            <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</span>
