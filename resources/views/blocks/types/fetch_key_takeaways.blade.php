@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'o-squares-2x2';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$takeaways = $block->metadata['content'] ?? [];
if (is_string($takeaways)) {
// Parse if stored as string with line breaks or bullets
$takeaways = array_filter(array_map('trim', preg_split('/[\n\r]+/', $takeaways)));
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

        {{-- Takeaways list --}}
        @if (count($takeaways) > 0)
        {{-- AI Summary Display --}}
        <div class="relative">
            <div class="bg-gradient-to-br from-warning/5 to-warning/25 rounded-lg p-3 border border-warning/50">
                <div class="space-y-2">
                    @foreach ($takeaways as $index => $takeaway)
                    <div class="flex gap-2 items-start">
                        <div class="flex-shrink-0 w-6 h-6 rounded-full bg-warning/10 flex items-center justify-center mt-0.5">
                            <x-icon name="o-check" class="w-4 h-4 text-warning" />
                        </div>
                        <p class="text-sm text-base-content/80 leading-relaxed flex-1">
                            {!! str(is_string($takeaway) ? $takeaway : ($takeaway['text'] ?? $takeaway['content'] ?? ''))->markdown() !!}
                        </p>
                    </div>
                    @endforeach
                </div>
            </div>
            {{-- AI Badge --}}
            <div class="absolute -top-2 -right-2 bg-warning rounded-full p-1.5 shadow">
                <x-icon name="o-sparkles" class="w-3 h-3 text-warning-content" />
            </div>
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
            @if (isset($block->metadata['model']))
            <div class="flex items-center gap-1">
                <x-icon name="o-cpu-chip" class="w-3 h-3" />
                {{ $block->metadata['model'] }}
            </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="o-light-bulb" class="w-3 h-3" />
                Key Takeaways
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