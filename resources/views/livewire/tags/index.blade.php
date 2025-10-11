<?php

use Livewire\Volt\Component;
use Spatie\Tags\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Emoji\EmojiTransliterator;

new class extends Component {
    public array $tagsByType = [];
    public string $searchQuery = '';
    public array $collapsedSections = [];

    public function mount(): void
    {
        $this->loadTags();
    }

    public function updatedSearchQuery(): void
    {
        $this->loadTags();
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

        // Sort each group by total_count descending
        $this->tagsByType = $grouped->map(function ($typeTags) {
            return $typeTags->sortByDesc('total_count')->values();
        })->sortKeys()->all();
    }

    public function getTagTypeLabel(string $type): string
    {
        return match($type) {
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
        return match($type) {
            'transaction_category', 'transaction_type', 'transaction_status',
            'transaction_scheme', 'transaction_currency' => 'o-currency-pound',
            'balance_type' => 'o-scale',
            'merchant_emoji', 'merchant_country', 'merchant_category' => 'o-building-storefront',
            'person' => 'o-user',
            'card_pan' => 'o-credit-card',
            'decline_reason' => 'o-x-circle',
            'music_artist', 'music_album', 'spotify_context' => 'o-musical-note',
            'emoji' => 'o-face-smile',
            'spark' => 'o-sparkles',
            'untyped' => 'o-tag',
            default => 'o-tag',
        };
    }

    public function getTagTypeColor(string $type): string
    {
        return match($type) {
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
    <x-header title="Tags" separator>
        <x-slot:subtitle>
            Browse and manage all tags across events and objects
        </x-slot:subtitle>
    </x-header>

    <!-- Search -->
    <x-card class="mb-6">
        <x-input
            wire:model.live.debounce.300ms="searchQuery"
            placeholder="Search tags by name or type..."
            icon="o-magnifying-glass"
        />
    </x-card>

    @if (empty($tagsByType))
        <x-card>
            <div class="text-center py-8">
                <x-icon name="o-tag" class="w-12 h-12 text-base-content/40 mx-auto mb-4" />
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
                        <div class="overflow-x-auto">
                            <table class="table table-zebra">
                                <thead>
                                    <tr>
                                        <th>Tag</th>
                                        <th class="text-center">Events</th>
                                        <th class="text-center">Objects</th>
                                        <th class="text-center">Total</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($tags as $tag)
                                        <tr class="hover">
                                            <td>
                                                <div class="flex items-center gap-2">
                                                    <x-spark-tag :tag="$tag" />
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <x-badge :value="$tag->events_count" class="badge-sm badge-ghost" />
                                            </td>
                                            <td class="text-center">
                                                <x-badge :value="$tag->objects_count" class="badge-sm badge-ghost" />
                                            </td>
                                            <td class="text-center">
                                                <x-badge :value="$tag->total_count" class="badge-sm badge-primary" />
                                            </td>
                                            <td class="text-right">
                                                @if ($tag->slug)
                                                    <a href="{{ route('tags.show', [$tag->type ?? 'untyped', $tag->slug, $tag->id]) }}"
                                                       class="btn btn-sm btn-ghost">
                                                        View
                                                        <x-icon name="o-arrow-right" class="w-4 h-4" />
                                                    </a>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-slot:content>
                </x-collapse>
            @endforeach
        </div>
    @endif
</div>
