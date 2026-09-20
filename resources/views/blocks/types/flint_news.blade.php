@props(['block'])

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        <div class="flex items-start justify-between gap-2">
            <div class="badge badge-neutral badge-outline badge-sm gap-1">
                <x-icon name="fas.newspaper" class="w-3 h-3" />
                News Story
            </div>
            <div class="text-xs text-base-content/50">{{ $block->time?->diffForHumans() }}</div>
        </div>

        <h3 class="text-base font-semibold text-base-content">{{ $block->title }}</h3>

        @php($news = $block->metadata['news'] ?? null)
        @if (is_array($news))
            <p class="text-sm leading-relaxed text-base-content/80">{{ $news['summary'] ?? $block->getContent() }}</p>

            @if (!empty($news['sources']))
                <div class="space-y-1.5 rounded-lg bg-base-100 p-3">
                    <div class="text-xs font-semibold uppercase tracking-wider text-base-content/50">Reporting</div>
                    @foreach ($news['sources'] as $source)
                        <div class="text-sm text-base-content/75">
                            <span class="font-medium text-base-content">{{ $source['publication'] ?? 'Source' }}</span>
                            — {{ $source['position'] ?? '' }}
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                @if (!empty($news['why_it_matters']))
                    <div class="rounded-lg border border-base-300 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wider text-base-content/50">Why it matters</div>
                        <p class="mt-1 text-sm text-base-content/75">{{ $news['why_it_matters'] }}</p>
                    </div>
                @endif
                @if (!empty($news['what_to_watch']))
                    <div class="rounded-lg border border-base-300 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wider text-base-content/50">What to watch</div>
                        <p class="mt-1 text-sm text-base-content/75">{{ $news['what_to_watch'] }}</p>
                    </div>
                @endif
            </div>
        @elseif ($block->getContent())
            <div class="prose prose-sm max-w-none text-base-content/80">
                {!! $block->getContentAsHtml() !!}
            </div>
        @endif

        @php($sources = $block->metadata['referenced_event_ids'] ?? [])
        @if (count($sources))
            <div class="flex flex-wrap items-center gap-1.5 pt-1">
                <span class="text-xs text-base-content/50">Sources</span>
                @foreach ($sources as $sourceId)
                    <a
                        href="{{ route('events.show', $sourceId) }}"
                        wire:navigate
                        class="badge badge-ghost badge-sm gap-1"
                        wire:key="src-{{ $sourceId }}"
                    >
                        <x-icon name="o-document-text" class="w-3 h-3" />
                        {{ $loop->iteration }}
                    </a>
                @endforeach
            </div>
        @endif

        <div class="flex items-center justify-between gap-2 pt-2 border-t border-base-300">
            <div class="flex items-center gap-1.5">
                <x-icon name="fas.hexagon-nodes" class="w-4 h-4 text-warning" />
                <span class="text-xs font-medium text-base-content/70">Flint</span>
            </div>
            <div class="flex items-center gap-2">
                @livewire('block-feedback', ['block' => $block], key('feedback-' . $block->id))
                <a href="{{ route('blocks.show', $block) }}" wire:navigate class="btn btn-ghost btn-xs gap-1">
                    <x-icon name="o-eye" class="w-3 h-3" />
                    View
                </a>
            </div>
        </div>
    </div>
</div>
