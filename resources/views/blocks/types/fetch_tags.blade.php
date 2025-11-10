@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'o-squares-2x2';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$tags = $block->metadata['tags'] ?? [];
if (is_string($tags)) {
    // Parse if stored as comma-separated string
    $tags = array_filter(array_map('trim', explode(',', $tags)));
}
@endphp

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

        {{-- Tags cloud --}}
        @if(count($tags) > 0)
            <div class="flex flex-wrap gap-2">
                @foreach($tags as $tag)
                    @php
                        $tagText = is_string($tag) ? $tag : ($tag['name'] ?? $tag['text'] ?? '');
                        // Extract emoji if present at the start (simplified regex)
                        if (preg_match('/^([\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]+)\s*(.*)$/u', $tagText, $matches)) {
                            $emoji = $matches[1];
                            $tagName = $matches[2] ?: $tagText;
                        } else {
                            $emoji = null;
                            $tagName = $tagText;
                        }
                    @endphp
                    <span class="badge badge-accent badge-lg gap-1">
                        @if($emoji)
                            <span class="text-base">{{ $emoji }}</span>
                        @endif
                        {{ $tagName }}
                    </span>
                @endforeach
            </div>
        @else
            <p class="text-sm text-base-content/60 italic">No tags available</p>
        @endif

        {{-- Stats --}}
        <div class="flex items-center gap-4 text-xs text-base-content/60">
            <div class="flex items-center gap-1">
                <x-icon name="o-hashtag" class="w-3 h-3" />
                {{ count($tags) }} tags
            </div>
            @if(isset($block->metadata['model']))
                <div class="flex items-center gap-1">
                    <x-icon name="o-cpu-chip" class="w-3 h-3" />
                    {{ $block->metadata['model'] }}
                </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="o-tag" class="w-3 h-3" />
                Tags
            </div>

            <div class="flex-1"></div>

            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-xs btn-square">
                    <x-icon name="o-ellipsis-vertical" class="w-4 h-4" />
                </div>
                <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-[1] w-52 p-2 shadow-lg border border-base-300">
                    <li>
                        <a href="{{ route('blocks.show', $block) }}" wire:navigate>
                            <x-icon name="o-eye" class="w-4 h-4" />
                            View Block
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
