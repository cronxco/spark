<?php

namespace App\Services\Fetch\Assessment;

use App\Services\Fetch\Links\LinkCandidate;
use App\Services\Fetch\Links\LinkCluster;
use App\Services\Jev\JevAssessment;

/**
 * The outcome of asking whether a page is a list of articles, and which of
 * its links are those articles.
 */
final readonly class ListAssessment
{
    public const STATUS_ASSESSED = 'assessed';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_ERROR = 'error';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_MEMO = 'memo';

    /**
     * @param  list<LinkCluster>  $clusters  Every group that was assessed
     * @param  list<string>  $selectedClusterIds
     * @param  list<LinkCandidate>  $acceptedItems  In list order
     * @param  list<string>  $rejectedItemIds  Links in selected groups judged not to be articles
     * @param  array<string, float>  $thresholds
     */
    public function __construct(
        public string $status,
        public bool $isList,
        public string $reason,
        public array $clusters = [],
        public array $selectedClusterIds = [],
        public array $acceptedItems = [],
        public array $rejectedItemIds = [],
        public ?JevAssessment $jev = null,
        public array $thresholds = [],
    ) {}

    public static function notList(string $status, string $reason): self
    {
        return new self($status, false, $reason);
    }

    /**
     * Signatures of the groups chosen as the primary list, for the audit trail.
     *
     * @return list<string>
     */
    public function selectedSignatures(): array
    {
        return array_values(array_map(
            fn (LinkCluster $cluster): string => $cluster->signature,
            array_filter($this->clusters, fn (LinkCluster $cluster): bool => in_array($cluster->id, $this->selectedClusterIds, true)),
        ));
    }

    /**
     * Audit record stored with the expansion (and on the bookmark in shadow mode).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'is_list' => $this->isList,
            'reason' => $this->reason,
            'model' => $this->jev?->model,
            'input_tokens' => $this->jev?->inputTokens,
            'page_kind' => $this->jev?->has('page_kind') ? $this->jev->probabilities('page_kind') : null,
            'has_article_list' => $this->jev?->has('has_article_list') ? $this->jev->noul('has_article_list') : null,
            'clusters' => array_map(fn (LinkCluster $cluster): array => $cluster->toArray() + [
                'selected' => in_array($cluster->id, $this->selectedClusterIds, true),
                'is_primary' => $this->jev?->has("cluster_{$cluster->id}_is_primary") ? $this->jev->noul("cluster_{$cluster->id}_is_primary") : null,
                'role' => $this->jev?->has("cluster_{$cluster->id}_role") ? $this->jev->choice("cluster_{$cluster->id}_role") : null,
            ], $this->clusters),
            'accepted_count' => count($this->acceptedItems),
            'rejected_item_ids' => $this->rejectedItemIds,
            'thresholds' => $this->thresholds,
            'assessed_at' => now()->toIso8601String(),
        ];
    }
}
