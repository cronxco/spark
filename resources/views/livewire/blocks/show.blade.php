<?php

use App\Models\Block;
use App\Models\Event;
use Livewire\Volt\Component;
use App\Integrations\PluginRegistry;

new class extends Component {
    public Block $block;

    public function mount(Block $block): void
    {
        $this->block = $block->load(['event']);
    }

    public function getRelatedBlocks()
    {
        // Find blocks from the same event
        return Block::where('event_id', $this->block->event_id)
            ->where('id', '!=', $this->block->id)
            ->orderBy('time', 'desc')
            ->limit(5)
            ->get();
    }

    public function formatJson($data)
    {
        if (is_array($data) || is_object($data)) {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        return $data;
    }

    public function getBlockIcon($blockType, $service = null)
    {
        // Try to get icon from plugin configuration first if service is available
        if ($service) {
            $pluginClass = PluginRegistry::getPlugin($service);
            if ($pluginClass) {
                $blockTypes = $pluginClass::getBlockTypes();
                if (isset($blockTypes[$blockType]) && isset($blockTypes[$blockType]['icon'])) {
                    return $blockTypes[$blockType]['icon'];
                }
            }
        }

        // Fallback to default icon if plugin doesn't have this block type
        return 'o-squares-2x2';
    }
};

?>

<div>
    @if ($this->block)
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center gap-4">
                <x-button href="{{ route('events.index') }}" class="btn-ghost">
                    <x-icon name="o-arrow-left" class="w-4 h-4" />
                    Back to Events
                </x-button>
                <div class="flex-1">
                    <h1 class="text-2xl font-bold text-base-content">
                        Block Details
                    </h1>
                </div>
            </div>

                            <!-- Block Overview Card -->
                <x-card>
                    <div class="flex flex-col sm:flex-row items-start gap-4">
                        <!-- Block Icon -->
                        <div class="flex-shrink-0 self-center sm:self-start">
                            <div class="w-12 h-12 rounded-full bg-info/10 flex items-center justify-center">
                                <x-icon name="{{ $this->getBlockIcon($this->block->block_type, $this->block->event?->service) }}"
                                       class="w-6 h-6 text-info" />
                            </div>
                        </div>

                    <!-- Block Info -->
                    <div class="flex-1">
                        <div class="flex items-center gap-2 mb-3">
                            <h2 class="text-xl font-semibold text-base-content">
                                {{ $this->block->title }}
                            </h2>
                            @if ($this->block->value)
                                <x-badge :value="$this->block->formatted_value . ($this->block->value_unit ? ' ' . $this->block->value_unit : '')" class="badge-info" />
                            @endif
                        </div>

                        @php $text = is_array($this->block->metadata ?? null) ? ($this->block->metadata['text'] ?? null) : null; @endphp
                        @if ($text)
                            <p class="text-base-content/70 mb-4">{{ $text }}</p>
                        @endif

                        <!-- Block Metadata -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 lg:gap-4 text-sm">
                            @if ($this->block->time)
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-clock" class="w-4 h-4 text-base-content/60" />
                                    <span class="text-base-content/70">Time:</span>
                                    <span class="font-medium">{{ $this->block->time->format('F j, Y g:i A') }}</span>
                                </div>
                            @endif
                            @if ($this->block->url)
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-link" class="w-4 h-4 text-base-content/60" />
                                    <span class="text-base-content/70">URL:</span>
                                    <a href="{{ $this->block->url }}" target="_blank" class="font-medium text-primary hover:underline">
                                        View
                                    </a>
                                </div>
                            @endif
                            @if ($this->block->media_url)
                                <div class="flex items-center gap-2">
                                    <x-icon name="o-photo" class="w-4 h-4 text-base-content/60" />
                                    <span class="text-base-content/70">Media:</span>
                                    <a href="{{ $this->block->media_url }}" target="_blank" class="font-medium text-primary hover:underline">
                                        View
                                    </a>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </x-card>

            <!-- Related Event -->
            @if ($this->block->event)
                <x-card>
                    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                        <x-icon name="o-bolt" class="w-5 h-5 text-primary" />
                        Related Event
                    </h3>
                    <div class="border border-base-300 rounded-lg p-4 hover:bg-base-50 transition-colors">
                        <a href="{{ route('events.show', $this->block->event->id) }}"
                           class="block hover:text-primary transition-colors">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                                    <x-icon name="o-bolt" class="w-4 h-4 text-primary" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-medium">{{ format_action_title($this->block->event->action) }}</span>
                                        <x-badge :value="$this->block->event->service" class="badge-xs" />
                                        @if ($this->block->event->domain)
                                            <x-badge :value="$this->block->event->domain" class="badge-xs badge-outline" />
                                        @endif
                                    </div>
                                    <div class="text-sm text-base-content/70">
                                        {{ $this->block->event->time->format('M j, Y g:i A') }}
                                    </div>
                                    @if ($this->block->event->value)
                                        <div class="text-xs text-base-content/60 mt-1">
                                            Value: {{ $this->block->event->formatted_value }}
                                            @if ($this->block->event->value_unit)
                                                {{ $this->block->event->value_unit }}
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                <x-icon name="o-chevron-right" class="w-4 h-4 text-base-content/40" />
                            </div>
                        </a>
                    </div>
                </x-card>
            @endif

            <!-- Related Blocks -->
                                    @if ($this->getRelatedBlocks()->isNotEmpty())
                <x-card>
                                            <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                            <x-icon name="o-squares-2x2" class="w-5 h-5 text-info" />
                            Other Blocks from Same Event ({{ $this->getRelatedBlocks()->count() }})
                        </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach ($this->getRelatedBlocks() as $relatedBlock)
                            <div class="border border-base-300 rounded-lg p-3 hover:bg-base-50 transition-colors">
                                <div class="flex items-start justify-between mb-2">
                                    <a href="{{ route('blocks.show', $relatedBlock->id) }}"
                                       class="font-medium text-base-content hover:text-primary transition-colors text-sm">
                                        {{ $relatedBlock->title }}
                                    </a>
                                    @if ($relatedBlock->value)
                                        <x-badge :value="$relatedBlock->formatted_value . ($relatedBlock->value_unit ? ' ' . $relatedBlock->value_unit : '')" class="badge-xs" />
                                    @endif
                                </div>
                                @php $relText = is_array($relatedBlock->metadata ?? null) ? ($relatedBlock->metadata['text'] ?? null) : null; @endphp
                                @if ($relText)
                                    <p class="text-xs text-base-content/70 mb-2 line-clamp-2">{{ Str::limit($relText, 80) }}</p>
                                @endif
                                <div class="flex items-center gap-2 text-xs text-base-content/60">
                                    @if ($relatedBlock->time)
                                        <div class="flex items-center gap-1">
                                            <x-icon name="o-clock" class="w-3 h-3" />
                                            {{ $relatedBlock->time->format('g:i A') }}
                                        </div>
                                    @endif
                                    @if ($relatedBlock->url)
                                        <div class="flex items-center gap-1">
                                            <x-icon name="o-link" class="w-3 h-3" />
                                            <a href="{{ $relatedBlock->url }}" target="_blank" class="text-primary hover:underline">
                                                View
                                            </a>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif
        </div>
    @else
        <div class="text-center py-8">
            <x-icon name="o-exclamation-triangle" class="w-12 h-12 text-warning mx-auto mb-4" />
            <h3 class="text-lg font-semibold text-base-content mb-2">Block Not Found</h3>
            <p class="text-base-content/70">The requested block could not be found.</p>
            <x-button href="{{ route('events.index') }}" class="mt-4">
                Back to Events
            </x-button>
        </div>
    @endif
</div>
