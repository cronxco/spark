<?php

namespace App\Services\Fetch\Links;

/**
 * A group of links that repeat the same structure and URL shape — a candidate
 * for being the page's primary list of articles.
 *
 * All counting and measuring happens here in code; the Jev state only ever
 * sees the word buckets from toState().
 */
final readonly class LinkCluster
{
    /**
     * @param  list<LinkCandidate>  $items  In DOM order, one per distinct URL
     * @param  list<string>  $landmarks  Soft landmarks shared by most items
     */
    public function __construct(
        public string $id,
        public string $signature,
        public string $urlTemplate,
        public array $items,
        public array $landmarks,
        public ?string $precedingHeading,
        public float $meanAnchorLength,
        public float $headingRatio,
        public float $timeRatio,
        public float $sameHostRatio,
        public string $positionBucket,
        public float $score,
    ) {}

    public function count(): int
    {
        return count($this->items);
    }

    public function countBucket(): string
    {
        return match (true) {
            $this->count() <= 5 => 'a handful (3-5)',
            $this->count() <= 15 => 'several (6-15)',
            default => 'many (16 or more)',
        };
    }

    /**
     * Compact, number-free description of the cluster for a Jev state.
     *
     * @return array<string, mixed>
     */
    public function toState(): array
    {
        return [
            'size' => $this->countBucket(),
            'position_on_page' => $this->positionBucket,
            'heading_above_group' => $this->precedingHeading ?? 'none',
            'page_regions' => $this->landmarks === [] ? 'main content' : implode(', ', $this->landmarks),
            'links_are_headlines' => $this->ratioWords($this->headingRatio),
            'items_show_a_date' => $this->ratioWords($this->timeRatio),
            'links_point_to_this_site' => $this->ratioWords($this->sameHostRatio),
            'url_pattern' => $this->urlTemplate,
            'example_link_texts' => array_map(
                fn (LinkCandidate $item): string => $item->anchorText,
                array_slice($this->items, 0, 5),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'signature' => $this->signature,
            'url_template' => $this->urlTemplate,
            'count' => $this->count(),
            'landmarks' => $this->landmarks,
            'preceding_heading' => $this->precedingHeading,
            'mean_anchor_length' => round($this->meanAnchorLength, 1),
            'heading_ratio' => round($this->headingRatio, 2),
            'time_ratio' => round($this->timeRatio, 2),
            'same_host_ratio' => round($this->sameHostRatio, 2),
            'position_bucket' => $this->positionBucket,
            'score' => round($this->score, 2),
        ];
    }

    private function ratioWords(float $ratio): string
    {
        return match (true) {
            $ratio >= 0.8 => 'almost all',
            $ratio >= 0.5 => 'most',
            $ratio > 0.1 => 'some',
            default => 'none',
        };
    }
}
