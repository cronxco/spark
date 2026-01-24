<?php

namespace App\Spotlight\Queries\Search;

use Spatie\Tags\Tag;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class TagSearchQuery
{
    /**
     * Create Spotlight query for searching tags (mode-specific).
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forMode('tags', function (string $query) {
            // Use a subquery to count taggables in a single query (avoid N+1)
            $tagsQuery = Tag::query()
                ->selectRaw('tags.*, (SELECT COUNT(*) FROM taggables WHERE taggables.tag_id = tags.id) as taggables_count');

            if (! blank($query)) {
                $tagsQuery->where(function ($q) use ($query) {
                    $q->where('name->en', 'ilike', "%{$query}%")
                        ->orWhere('slug->en', 'ilike', "%{$query}%")
                        ->orWhere('type', 'ilike', "%{$query}%");
                });
            }

            return $tagsQuery
                ->limit(5)
                ->get()
                ->map(function (Tag $tag) {
                    $taggablesCount = $tag->taggables_count ?? 0;

                    $subtitle = ucfirst($tag->type ?? 'general');
                    if ($taggablesCount > 0) {
                        $subtitle .= ' • Used '.$taggablesCount.' '.str('time')->plural($taggablesCount);
                    }

                    return SpotlightResult::make()
                        ->setTitle($tag->name)
                        ->setSubtitle($subtitle)
                        ->setTypeahead('Tag: '.$tag->name)
                        ->setIcon('tag')
                        ->setGroup('tags')
                        ->setPriority(1)
                        ->setAction('jump_to', ['path' => route('tags.show', [
                            'type' => $tag->type ?? 'general',
                            'slug' => $tag->slug,
                            'id' => $tag->id,
                        ])]);
                });
        });
    }
}
