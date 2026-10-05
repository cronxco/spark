<?php

namespace App\Spotlight\Queries\Search;

use App\Support\OwnedTagQuery;
use Illuminate\Support\Facades\Auth;
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
            $user = Auth::user();

            if (! $user) {
                return collect();
            }

            // Tags carry no user_id; ownership and usage counts are derived from
            // the signed-in user's own tagged records. The previous subquery
            // counted taggables across every tenant.
            $tagsQuery = OwnedTagQuery::for($user, $query);

            return $tagsQuery
                ->limit(5)
                ->get()
                ->map(function (Tag $tag) {
                    $taggablesCount = (int) ($tag->events_count ?? 0) + (int) ($tag->objects_count ?? 0);

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
