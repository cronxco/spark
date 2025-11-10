@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'o-squares-2x2';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$takeaways = $block->metadata['takeaways'] ?? [];
if (is_string($takeaways)) {
    // Parse if stored as string with line breaks or bullets
    $takeaways = array_filter(array_map('trim', preg_split('/[\n\r]+/', $takeaways)));
}
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header --}}
        <div class="flex items-center justify-between gap-2">
            <div class="badge badge-primary badge-outline badge-sm gap-1">
                <x-icon name="o-light-bulb" class="w-3 h-3" />
                Key Takeaways
            </div>
            <x-uk-date :date="$block->time" :show-time="true" class="text-xs" />
        </div>

        {{-- Title --}}
        <h3 class="font-semibold text-base leading-snug">
            <a href="{{ route('blocks.show', $block) }}" wire:navigate class="hover:underline">
                {{ $block->title }}
            </a>
        </h3>

        {{-- Takeaways list --}}
        @if(count($takeaways) > 0)
            <div class="space-y-2">
                @foreach($takeaways as $index => $takeaway)
                    <div class="flex gap-2 items-start">
                        <div class="flex-shrink-0 w-6 h-6 rounded-full bg-primary/10 flex items-center justify-center mt-0.5">
                            <x-icon name="o-check" class="w-4 h-4 text-primary" />
                        </div>
                        <p class="text-sm text-base-content/80 leading-relaxed flex-1">
                            {{ is_string($takeaway) ? $takeaway : ($takeaway['text'] ?? $takeaway['content'] ?? '') }}
                        </p>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-base-content/60 italic">No takeaways available</p>
        @endif

        {{-- Stats --}}
        <div class="flex items-center gap-4 text-xs text-base-content/60">
            <div class="flex items-center gap-1">
                <x-icon name="o-list-bullet" class="w-3 h-3" />
                {{ count($takeaways) }} takeaways
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
            <div class="badge badge-primary badge-xs gap-1">
                <x-icon :name="$icon" class="w-2.5 h-2.5" />
                {{ $displayName }}
            </div>

            <a href="{{ route('events.show', $block->event) }}"
               wire:navigate
               class="text-xs text-base-content/50 hover:text-base-content/80 transition-colors flex-1 truncate">
                {{ Str::limit($block->event->action, 30) }}
            </a>

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
