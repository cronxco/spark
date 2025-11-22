<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Spatie\Tags\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Emoji\EmojiTransliterator;

new class extends Component {
    public array $tagsByType = [];
    public string $searchQuery = '';
    public array $collapsedSections = [];
    public array $sortBy = ['column' => 'total_count', 'direction' => 'desc'];
    public bool $showCreateModal = false;

    public function mount(): void
    {
        $this->loadTags();
    }

    public function updatedSearchQuery(): void
    {
        $this->loadTags();
    }

    public function openCreateModal(): void
    {
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
    }

    #[On('tag-created')]
    public function handleTagCreated(): void
    {
        $this->loadTags();
        $this->showCreateModal = false;
    }

    public function clearFilters(): void
    {
        $this->reset(['searchQuery']);
        $this->loadTags();
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => 'Id', 'class' => 'hidden'],
            ['key' => 'type', 'label' => 'Type', 'class' => 'hidden'],
            ['key' => 'slug', 'label' => 'Slug', 'class' => 'hidden'],
            ['key' => 'name', 'label' => 'Tag', 'sortable' => true],
            ['key' => 'events_count', 'label' => 'Events', 'sortable' => true, 'class' => 'hidden sm:table-cell text-center'],
            ['key' => 'objects_count', 'label' => 'Objects', 'sortable' => true, 'class' => 'hidden sm:table-cell text-center'],
            ['key' => 'total_count', 'label' => 'Total', 'sortable' => true, 'class' => 'text-center'],
        ];
    }

    private function loadTags(): void
    {
        // Get the table prefix for use in raw SQL
        $prefix = DB::getTablePrefix();
        $tagsTable = $prefix . 'tags';
        $taggablesTable = $prefix . 'taggables';

        $query = DB::table('tags')
            ->select([
                'tags.id',
                DB::raw("{$tagsTable}.name->>'en' as name"),
                DB::raw("{$tagsTable}.slug->>'en' as slug"),
                'tags.type',
                DB::raw("COUNT(DISTINCT CASE WHEN {$taggablesTable}.taggable_type = 'App\\Models\\Event' THEN {$taggablesTable}.taggable_id END) as events_count"),
                DB::raw("COUNT(DISTINCT CASE WHEN {$taggablesTable}.taggable_type = 'App\\Models\\EventObject' THEN {$taggablesTable}.taggable_id END) as objects_count"),
                DB::raw("COUNT(DISTINCT {$taggablesTable}.taggable_id) as total_count")
            ])
            ->leftJoin('taggables', 'tags.id', '=', 'taggables.tag_id')
            ->groupBy('tags.id', DB::raw("{$tagsTable}.name->>'en'"), DB::raw("{$tagsTable}.slug->>'en'"), 'tags.type');

        if ($this->searchQuery !== '') {
            $searchTerm = '%' . $this->searchQuery . '%';
            $query->where(function ($q) use ($searchTerm, $tagsTable) {
                $q->whereRaw("LOWER({$tagsTable}.name->>'en') LIKE ?", [strtolower($searchTerm)])
                    ->orWhereRaw("LOWER({$tagsTable}.type) LIKE ?", [strtolower($searchTerm)]);
            });
        }

        $tags = $query->get();

        // Process tags to generate slugs for emojis using Symfony Emoji
        $emojiTransliterator = EmojiTransliterator::create('en');
        $tags = $tags->map(function ($tag) use ($emojiTransliterator) {
            // If slug is null or empty, generate one from the name
            if (empty($tag->slug) && !empty($tag->name)) {
                // Use Symfony Emoji to convert emojis to text
                $transliterated = $emojiTransliterator->transliterate($tag->name);
                // Convert to slug format (lowercase, replace spaces with hyphens)
                $tag->slug = Str::slug($transliterated);
            }
            return $tag;
        });

        // Group tags by type
        $grouped = $tags->groupBy(fn($tag) => $tag->type ?? 'untyped');

        // Sort each group by the selected column and direction
        $sortColumn = $this->sortBy['column'] ?? 'total_count';
        $sortDirection = $this->sortBy['direction'] ?? 'desc';
        $this->tagsByType = $grouped->map(function ($typeTags) use ($sortColumn, $sortDirection) {
            $sorted = $sortDirection === 'desc'
                ? $typeTags->sortByDesc($sortColumn)
                : $typeTags->sortBy($sortColumn);
            return $sorted->values();
        })->sortKeys()->all();
    }

    public function getTagTypeLabel(string $type): string
    {
        return match ($type) {
            'transaction_category' => 'Transaction Categories',
            'transaction_type' => 'Transaction Types',
            'transaction_status' => 'Transaction Status',
            'transaction_scheme' => 'Transaction Schemes',
            'transaction_currency' => 'Currencies',
            'balance_type' => 'Balance Types',
            'merchant_emoji' => 'Merchant Emojis',
            'merchant_country' => 'Merchant Countries',
            'merchant_category' => 'Merchant Categories',
            'person' => 'People',
            'card_pan' => 'Cards',
            'decline_reason' => 'Decline Reasons',
            'music_artist' => 'Artists',
            'music_album' => 'Albums',
            'spotify_context' => 'Spotify Contexts',
            'emoji' => 'Emojis',
            'spark' => 'Custom Tags',
            'untyped' => 'Untyped Tags',
            default => Str::headline($type),
        };
    }

    public function getTagTypeIcon(string $type): string
    {
        return match ($type) {
            'transaction_category', 'transaction_type', 'transaction_status',
            'transaction_scheme', 'transaction_currency' => 'fas-sterling-sign',
            'balance_type' => 'fas-scale-balanced',
            'merchant_emoji', 'merchant_country', 'merchant_category' => 'fas-store',
            'person' => 'fas-user',
            'card_pan' => 'fas-credit-card',
            'decline_reason' => 'fas-circle-xmark',
            'music_artist', 'music_album', 'spotify_context' => 'fas-music',
            'emoji' => 'fas-face-smile',
            'spark' => 'fas-wand-magic-sparkles',
            'untyped' => 'fas-tag',
            default => 'fas-tag',
        };
    }

    public function getTagTypeColor(string $type): string
    {
        return match ($type) {
            'transaction_category', 'transaction_type', 'transaction_status',
            'transaction_scheme', 'transaction_currency', 'balance_type',
            'merchant_emoji', 'merchant_country', 'merchant_category',
            'person', 'card_pan', 'decline_reason' => 'text-accent',
            'music_artist', 'music_album', 'spotify_context' => 'text-secondary',
            'emoji' => 'text-primary',
            'spark' => 'text-info',
            'untyped' => 'text-neutral',
            default => 'text-base-content',
        };
    }
};
?>

<div>
    <x-header title="Tags" subtitle="Browse and manage all tags across events and objects" separator>
        <x-slot:actions>
            <button wire:click="openCreateModal" class="btn btn-primary">
                <x-icon name="fas-plus" class="w-4 h-4" />
                Create Tag
            </button>
        </x-slot:actions>
    </x-header>

    <!-- Filters -->
    <div class="hidden lg:block card bg-base-200 shadow mb-6">
        <div class="card-body">
            <div class="flex flex-row gap-4">
                <!-- Search -->
                <div class="form-control flex-1">
                    <label class="label">
                        <span class="label-text">Search</span>
                    </label>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="searchQuery"
                        placeholder="Search..."
                        class="input input-bordered w-full" />
                </div>

                <!-- Clear Filters -->
                @if (!empty($searchQuery))
                <div class="form-control content-end">
                    <label class="label">
                        <span class="label-text">&nbsp;</span>
                    </label>
                    <button wire:click="clearFilters" class="btn btn-outline">
                        <x-icon name="fas-xmark" class="w-4 h-4" />
                        Clear
                    </button>
                </div>
                @endif
            </div>
        </div>
    </div>
    <div class="lg:hidden mb-4">
        <x-collapse separator class="bg-base-200">
            <x-slot:heading>
                <div class="flex items-center gap-2">
                    <x-icon name="fas-filter" class="w-5 h-5" />
                    Filters
                    @if (!empty($searchQuery))
                    <x-badge value="Active" class="badge-primary badge-xs" />
                    @endif
                </div>
            </x-slot:heading>
            <x-slot:content>
                <div class="flex flex-col gap-4">
                    <!-- Search -->
                    <div class="form-control flex-1">
                        <label class="label">
                            <span class="label-text">Search</span>
                        </label>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="searchQuery"
                            placeholder="Search..."
                            class="input input-bordered w-full" />
                    </div>

                    <!-- Clear Filters -->
                    @if (!empty($searchQuery))
                    <button wire:click="clearFilters" class="btn btn-outline">
                        <x-icon name="fas-xmark" class="w-4 h-4" />
                        Clear Filters
                    </button>
                    @endif
                </div>
            </x-slot:content>
        </x-collapse>
    </div>

    @if (empty($tagsByType))
    <x-card>
        <div class="text-center py-8">
            <x-icon name="fas-tag" class="w-12 h-12 text-base-content/40 mx-auto mb-4" />
            <h3 class="text-lg font-semibold text-base-content mb-2">No Tags Found</h3>
            <p class="text-base-content/70">
                @if ($searchQuery !== '')
                Try a different search term
                @else
                Start tagging events and objects to see them here
                @endif
            </p>
        </div>
    </x-card>
    @else
    <!-- Tags grouped by type -->
    <div class="space-y-4">
        @foreach ($tagsByType as $type => $tags)
        @php
        $sectionId = 'collapse-' . Str::slug($type);
        @endphp
        <x-collapse wire:model="collapsedSections.{{ $type }}">
            <x-slot:heading>
                <div class="flex items-center gap-3">
                    <x-icon name="{{ $this->getTagTypeIcon($type) }}" class="w-6 h-6 {{ $this->getTagTypeColor($type) }}" />
                    <h2 class="text-xl font-bold text-base-content">{{ $this->getTagTypeLabel($type) }}</h2>
                    <x-badge :value="count($tags)" class="badge-sm" />
                </div>
            </x-slot:heading>
            <x-slot:content>
                <x-table
                    :headers="$this->headers()"
                    :rows="$tags"
                    :sort-by="$sortBy"
                    link="/tags/{type}/{slug}/{id}"
                    class="[&_table]:!static [&_td]:!static">

                    @scope('cell_name', $tag)
                    <span class="text-sm text-base-content font-bold">{{ $tag->name }}</span>
                    @endscope

                    @scope('cell_events_count', $tag)
                    <span class="text-sm text-base-content/70">{{ $tag->events_count }}</span>
                    @endscope

                    @scope('cell_objects_count', $tag)
                    <span class="text-sm text-base-content/70">{{ $tag->objects_count }}</span>
                    @endscope

                    @scope('cell_total_count', $tag)
                    <span class="text-sm font-medium">{{ $tag->total_count }}</span>
                    @endscope

                </x-table>
            </x-slot:content>
        </x-collapse>
        @endforeach
    </div>
    @endif

    <!-- Create Tag Modal -->
    <x-modal wire:model="showCreateModal" title="Create New Tag" subtitle="Define a new tag with a specific type" separator>
        <livewire:create-tag :key="'create-tag-' . now()->timestamp" />
    </x-modal>
</div>