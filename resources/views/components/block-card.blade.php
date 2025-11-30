@props(['block'])

@php
use App\Integrations\PluginRegistry;

// Check if custom layout exists
$customLayoutPath = $block->getCustomCardLayoutPath();
if ($customLayoutPath && view()->exists($customLayoutPath)) {
    // Use custom layout
    echo view($customLayoutPath, ['block' => $block])->render();
    return;
}

// Get plugin info for styling
$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.grip';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);
$accentColor = $pluginClass ? $pluginClass::getAccentColor() : 'primary';
$domain = $pluginClass ? $pluginClass::getDomain() : 'knowledge';

// Get block type info
$blockTypes = $pluginClass ? $pluginClass::getBlockTypes() : [];
$blockTypeInfo = $blockTypes[$block->block_type] ?? null;
$blockIcon = $blockTypeInfo['icon'] ?? 'o-cube';
$blockDisplayName = $blockTypeInfo['display_name'] ?? str_replace('_', ' ', $block->block_type);

// Determine badge color based on domain
$badgeColorMap = [
    'health' => 'success',
    'money' => 'warning',
    'media' => 'info',
    'knowledge' => 'primary',
    'online' => 'accent',
];
$badgeColor = $badgeColorMap[$domain] ?? 'primary';

// Check if this is a value block or content block
$isValueBlock = !is_null($block->value);

// For content blocks, extract preview content
$contentPreview = null;
$imageUrl = null;
if (!$isValueBlock) {
    $metadata = $block->metadata ?? [];

    // Try to find content in common metadata fields
    if (isset($metadata['summary'])) {
        $contentPreview = $metadata['summary'];
    } elseif (isset($metadata['article_text'])) {
        $contentPreview = Str::limit($metadata['article_text'], 500);
    } elseif (isset($metadata['description'])) {
        $contentPreview = $metadata['description'];
    } elseif (isset($metadata['content'])) {
        $contentPreview = $metadata['content'];
    } elseif (isset($metadata['text'])) {
        $contentPreview = $metadata['text'];
    }

    // Check for images - use Media Library for responsive images with signed URLs
    $media = $block->getFirstMedia('downloaded_images');
    $responsiveImageHtml = null;

    if ($media) {
        // Generate responsive image HTML from Media Library
        $responsiveImageHtml = (string) $media;
        // Parse and add custom classes
        $doc = new DOMDocument;
        $libxmlFlags = defined('LIBXML_HTML_NOIMPLIED') ? LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD : 0;
        @$doc->loadHTML($responsiveImageHtml, $libxmlFlags);
        $img = $doc->getElementsByTagName('img')->item(0);
        if ($img) {
            $img->setAttribute('class', 'w-full h-full object-cover');
            $img->setAttribute('loading', 'lazy');
            $img->setAttribute('alt', $block->title);
            $responsiveImageHtml = $doc->saveHTML($img);
        }
    }
}
@endphp

@if ($isValueBlock)
    {{-- VALUE CARD VARIANT --}}
    <div class="card bg-base-200 shadow hover:shadow-lg transition-all">
        <div class="card-body p-4 gap-3">
            {{-- Header: Title and Date --}}
            <div class="flex items-center justify-between gap-2">
                <h3 class="font-semibold text-base leading-snug flex-1 line-clamp-1">
                    <a href="{{ route('blocks.show', $block) }}" wire:navigate class="hover:underline">
                        {{ $block->title }}
                    </a>
                </h3>
                <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
            </div>

            {{-- Prominent Value Display --}}
            <div class="text-center py-4">
                <div class="text-4xl font-bold text-{{ $badgeColor }}">
                    {{ $block->formatted_value }}
                </div>
                @if ($block->value_unit)
                    <div class="text-sm text-base-content/60 mt-1">
                        {{ $block->value_unit }}
                    </div>
                @endif
            </div>

            {{-- Metadata Preview --}}
            @if ($block->metadata && count($block->metadata) > 0)
                <div class="text-xs text-base-content/60 space-y-1">
                    @php
                        $displayMetadata = collect($block->metadata)->take(3);
                    @endphp
                    @foreach ($displayMetadata as $key => $value)
                        @if (!in_array($key, ['summary', 'content', 'text', 'description', 'image', 'image_url']) && !is_array($value))
                            <div class="flex justify-between gap-2">
                                <span class="font-medium">{{ ucfirst(str_replace('_', ' ', $key)) }}:</span>
                                <span class="text-right">{{ Str::limit($value, 30) }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            {{-- Footer: Block type badge and actions --}}
            <div class="flex items-center gap-2 mt-2 pt-2 border-t border-base-300">
                {{-- Block type badge --}}
                <div class="badge badge-ghost badge-sm gap-1">
                    <x-icon :name="$blockIcon" class="w-3 h-3" />
                    {{ $blockDisplayName }}
                </div>

                <div class="flex-1"></div>

                {{-- Actions dropdown --}}
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-xs btn-square">
                        <x-icon name="fas.ellipsis-vertical" class="w-4 h-4" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-[1] w-52 p-2 shadow-lg border border-base-300">
                        <li>
                            <a href="{{ route('blocks.show', $block) }}" wire:navigate>
                                <x-icon name="fas.eye" class="w-4 h-4" />
                                View Block
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('events.show', $block->event) }}" wire:navigate>
                                <x-icon name="fas.calendar" class="w-4 h-4" />
                                View Event
                            </a>
                        </li>
                        @if ($block->url)
                            <li>
                                <a href="{{ $block->url }}" target="_blank" rel="noopener noreferrer">
                                    <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                                    Open URL
                                </a>
                            </li>
                        @endif
                        <li>
                            <button onclick="navigator.clipboard.writeText('{{ $block->id }}')">
                                <x-icon name="o-clipboard" class="w-4 h-4" />
                                Copy ID
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

@else
    {{-- CONTENT CARD VARIANT --}}
    <div class="card bg-base-200 shadow hover:shadow-lg transition-all">
        <div class="card-body p-4 gap-3">
            {{-- Header: Title and Date --}}
            <div class="flex items-center justify-between gap-2 mb-1">
                <h3 class="font-semibold text-base leading-snug flex-1 line-clamp-1">
                    <a href="{{ route('blocks.show', $block) }}" wire:navigate class="hover:underline">
                        {{ $block->title }}
                    </a>
                </h3>
                <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
            </div>

            {{-- Image (if available) --}}
            @if ($responsiveImageHtml)
                <div class="w-full h-48 rounded-lg overflow-hidden bg-base-300">
                    {!! $responsiveImageHtml !!}
                </div>
            @endif

            {{-- Content Preview --}}
            @if ($contentPreview)
                <div class="prose prose-sm max-w-none text-base-content/70 line-clamp-5">
                    {!! Str::markdown($contentPreview) !!}
                </div>
            @endif

            {{-- Footer: Block type badge and actions --}}
            <div class="flex items-center gap-2 mt-2 pt-2 border-t border-base-300">
                {{-- Block type badge --}}
                <div class="badge badge-ghost badge-sm gap-1">
                    <x-icon :name="$blockIcon" class="w-3 h-3" />
                    {{ $blockDisplayName }}
                </div>

                <div class="flex-1"></div>

                {{-- Actions dropdown --}}
                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-xs btn-square">
                        <x-icon name="fas.ellipsis-vertical" class="w-4 h-4" />
                    </div>
                    <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-[1] w-52 p-2 shadow-lg border border-base-300">
                        <li>
                            <a href="{{ route('blocks.show', $block) }}" wire:navigate>
                                <x-icon name="fas.eye" class="w-4 h-4" />
                                View Block
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('events.show', $block->event) }}" wire:navigate>
                                <x-icon name="fas.calendar" class="w-4 h-4" />
                                View Event
                            </a>
                        </li>
                        @if ($block->url)
                            <li>
                                <a href="{{ $block->url }}" target="_blank" rel="noopener noreferrer">
                                    <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                                    Open URL
                                </a>
                            </li>
                        @endif
                        <li>
                            <button onclick="navigator.clipboard.writeText('{{ $block->id }}')">
                                <x-icon name="o-clipboard" class="w-4 h-4" />
                                Copy ID
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endif
