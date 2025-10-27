<?php

namespace App\Spotlight\Queries\Search;

use Illuminate\Support\Facades\DB;
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
            $tagsQuery = Tag::query();

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
                    // Get count of tagged items using the taggables table
                    $taggablesCount = DB::table('taggables')
                        ->where('tag_id', $tag->id)
                        ->count();

                    $subtitle = ucfirst($tag->type ?? 'general');
                    if ($taggablesCount > 0) {
                        $subtitle .= ' • Used ' . $taggablesCount . ' ' . str('time')->plural($taggablesCount);
                    }

                    return SpotlightResult::make()
                        ->setTitle($tag->name)
                        ->setSubtitle($subtitle)
                        ->setTypeahead('Tag: ' . $tag->name)
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
