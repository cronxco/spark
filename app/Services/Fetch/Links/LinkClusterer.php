<?php

namespace App\Services\Fetch\Links;

/**
 * Groups a page's links into candidate lists.
 *
 * Two independent signals must agree: the links share a DOM path (the same
 * item markup repeated) and a URL template (e.g. `/2026/{n}/{slug}`). URL
 * consistency is what separates an article list from navigation or tag
 * links, and the pair gives a signature that stays stable across fetches.
 */
class LinkClusterer
{
    /**
     * Landmarks that can never contain the primary list.
     */
    private const HARD_EXCLUDED_LANDMARKS = ['nav', 'footer'];

    /**
     * Landmarks that make a group less likely to be the primary list.
     */
    private const SOFT_PENALTY_LANDMARKS = ['aside', 'header', 'related', 'share', 'comments', 'tags', 'pagination', 'sponsored', 'signup', 'consent', 'form'];

    /**
     * Readable text length above which a page is treated as a real article
     * unless a group outside sidebars/related rails suggests otherwise.
     */
    private const ARTICLE_TEXT_LENGTH = 1500;

    public function __construct(
        private int $minItems = 5,
        private int $maxClusters = 6,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            minItems: max(2, (int) config('fetch.list_detection.min_cluster_items', 5)),
            maxClusters: max(1, (int) config('fetch.list_detection.max_clusters', 6)),
        );
    }

    /**
     * Reduce a URL to its shape: host plus path with variable segments
     * replaced, e.g. https://example.com/2026/09/my-post → example.com/{n}/{n}/{slug}.
     */
    public static function urlTemplate(string $url): string
    {
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH)), fn (string $segment): bool => $segment !== ''));
        $last = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            $segments[$index] = match (true) {
                (bool) preg_match('/^\d+$/', $segment) => '{n}',
                $index === $last => '{slug}',
                str_contains($segment, '-') && mb_strlen($segment) > 12, mb_strlen($segment) >= 20 => '{slug}',
                default => strtolower($segment),
            };
        }

        return $host . '/' . implode('/', $segments);
    }

    /**
     * @return list<LinkCluster> Best first
     */
    public function cluster(PageLinks $page): array
    {
        $pageHost = strtolower((string) parse_url($page->pageUrl, PHP_URL_HOST));
        $total = max(1, count($page->candidates));
        $groups = [];

        foreach ($page->candidates as $candidate) {
            if ($candidate->hasLandmark(...self::HARD_EXCLUDED_LANDMARKS)) {
                continue;
            }

            $template = self::urlTemplate($candidate->url);
            $groups[$candidate->domSignature . '|' . $template][] = $candidate;
        }

        $clusters = [];

        foreach ($groups as $key => $candidates) {
            $items = $this->distinctByUrl($candidates);

            if (count($items) < $this->minItems) {
                continue;
            }

            $clusters[] = $this->build($key, $items, $pageHost, $total);
        }

        $clusters = $this->dropDuplicateGroups($clusters);

        usort($clusters, fn (LinkCluster $a, LinkCluster $b): int => $b->score <=> $a->score);

        return array_values(array_map(
            fn (LinkCluster $cluster, int $index): LinkCluster => $this->withId($cluster, 'c' . $index),
            array_slice($clusters, 0, $this->maxClusters),
            array_keys(array_slice($clusters, 0, $this->maxClusters)),
        ));
    }

    /**
     * A group built from the page's JSON-LD ItemList, when at least half of
     * its URLs are also linked on the page (their anchors supply the titles).
     * Publishers' own structured data is the most reliable list signal.
     */
    public function structuredCluster(PageLinks $page): ?LinkCluster
    {
        if ($page->structuredItemUrls === []) {
            return null;
        }

        $byIdentity = [];
        foreach ($page->candidates as $candidate) {
            $identity = UrlCanonicalizer::canonicalize($candidate->url);
            $existing = $byIdentity[$identity] ?? null;

            if ($existing === null || mb_strlen($candidate->anchorText) > mb_strlen($existing->anchorText)) {
                $byIdentity[$identity] = $candidate;
            }
        }

        $items = [];
        foreach ($page->structuredItemUrls as $url) {
            $candidate = $byIdentity[UrlCanonicalizer::canonicalize($url)] ?? null;

            if ($candidate !== null) {
                $items[UrlCanonicalizer::canonicalize($url)] = $candidate;
            }
        }

        $items = array_values($items);

        if (count($items) < 2 || count($items) / count($page->structuredItemUrls) < 0.5) {
            return null;
        }

        $pageHost = strtolower((string) parse_url($page->pageUrl, PHP_URL_HOST));
        $cluster = $this->build('structured-data|' . self::urlTemplate($items[0]->url), $items, $pageHost, max(1, count($page->candidates)));

        return $this->withId($cluster, 's0');
    }

    /**
     * Cheap gate before asking Jev anything: is there any structure that could
     * be a list of articles? Deliberately loose.
     *
     * @param  list<LinkCluster>  $clusters
     */
    public function looksListLike(PageLinks $page, array $clusters, ?int $readableTextLength): bool
    {
        if ($page->structuredItemUrls !== []) {
            return true;
        }

        $substantialArticle = $readableTextLength !== null && $readableTextLength >= self::ARTICLE_TEXT_LENGTH;

        foreach ($clusters as $cluster) {
            if ($cluster->meanAnchorLength < 15) {
                continue;
            }

            // A long article's "related"/sidebar rail is not a reason to ask.
            if ($substantialArticle && array_intersect($cluster->landmarks, self::SOFT_PENALTY_LANDMARKS) !== []) {
                continue;
            }

            return true;
        }

        return $clusters !== []
            && $readableTextLength !== null
            && $page->totalAnchorTextLength() > $readableTextLength;
    }

    /**
     * @param  list<LinkCandidate>  $candidates
     * @return list<LinkCandidate>
     */
    private function distinctByUrl(array $candidates): array
    {
        $byUrl = [];

        foreach ($candidates as $candidate) {
            $identity = UrlCanonicalizer::canonicalize($candidate->url);
            $existing = $byUrl[$identity] ?? null;

            if ($existing === null || mb_strlen($candidate->anchorText) > mb_strlen($existing->anchorText)) {
                $byUrl[$identity] = $existing === null ? $candidate : $this->keepPosition($candidate, $existing);
            }
        }

        $items = array_values($byUrl);
        usort($items, fn (LinkCandidate $a, LinkCandidate $b): int => $a->position <=> $b->position);

        return $items;
    }

    /**
     * Keep the richer anchor text but the earlier DOM position.
     */
    private function keepPosition(LinkCandidate $richer, LinkCandidate $earlier): LinkCandidate
    {
        return new LinkCandidate(
            id: $earlier->id,
            url: $earlier->url,
            anchorText: $richer->anchorText,
            inHeading: $richer->inHeading || $earlier->inHeading,
            nearTime: $richer->nearTime || $earlier->nearTime,
            imageOnly: $richer->imageOnly && $earlier->imageOnly,
            landmarks: $earlier->landmarks,
            precedingHeading: $earlier->precedingHeading,
            position: $earlier->position,
            context: $richer->context,
            domSignature: $earlier->domSignature,
        );
    }

    /**
     * @param  list<LinkCandidate>  $items
     */
    private function build(string $key, array $items, string $pageHost, int $totalCandidates): LinkCluster
    {
        $count = count($items);
        $meanAnchor = array_sum(array_map(fn (LinkCandidate $item): int => mb_strlen($item->anchorText), $items)) / $count;
        $headingRatio = count(array_filter($items, fn (LinkCandidate $item): bool => $item->inHeading)) / $count;
        $timeRatio = count(array_filter($items, fn (LinkCandidate $item): bool => $item->nearTime)) / $count;
        $sameHostRatio = count(array_filter(
            $items,
            fn (LinkCandidate $item): bool => preg_replace('/^www\./', '', $item->host()) === preg_replace('/^www\./', '', $pageHost),
        )) / $count;

        $landmarkCounts = [];
        foreach ($items as $item) {
            foreach ($item->landmarks as $landmark) {
                $landmarkCounts[$landmark] = ($landmarkCounts[$landmark] ?? 0) + 1;
            }
        }
        $landmarks = array_keys(array_filter($landmarkCounts, fn (int $n): bool => $n / $count >= 0.5));
        sort($landmarks);

        $headings = array_count_values(array_filter(array_map(fn (LinkCandidate $item): ?string => $item->precedingHeading, $items)));
        arsort($headings);

        $meanPosition = array_sum(array_map(fn (LinkCandidate $item): int => $item->position, $items)) / $count / $totalCandidates;
        $positionBucket = match (true) {
            $meanPosition < 1 / 3 => 'top third',
            $meanPosition < 2 / 3 => 'middle third',
            default => 'bottom third',
        };

        $penalty = count(array_intersect($landmarks, self::SOFT_PENALTY_LANDMARKS));
        $score = log($count + 1) * 2
            + min($meanAnchor, 80) / 20
            + $headingRatio
            + $timeRatio * 0.5
            - $penalty * 1.5;

        [$domSignature, $template] = explode('|', $key, 2);

        return new LinkCluster(
            id: '',
            signature: substr(sha1($domSignature . '|' . $template), 0, 16),
            urlTemplate: $template,
            items: $items,
            landmarks: $landmarks,
            precedingHeading: array_key_first($headings),
            meanAnchorLength: $meanAnchor,
            headingRatio: $headingRatio,
            timeRatio: $timeRatio,
            sameHostRatio: $sameHostRatio,
            positionBucket: $positionBucket,
            score: $score,
        );
    }

    /**
     * An item often has both an image link and a headline link to the same
     * URL; those form two groups over the same URLs. Keep the one with the
     * more descriptive anchors.
     *
     * @param  list<LinkCluster>  $clusters
     * @return list<LinkCluster>
     */
    private function dropDuplicateGroups(array $clusters): array
    {
        usort($clusters, fn (LinkCluster $a, LinkCluster $b): int => $b->meanAnchorLength <=> $a->meanAnchorLength);
        $kept = [];

        foreach ($clusters as $cluster) {
            $urls = array_map(fn (LinkCandidate $item): string => UrlCanonicalizer::canonicalize($item->url), $cluster->items);

            foreach ($kept as $existing) {
                $existingUrls = array_map(fn (LinkCandidate $item): string => UrlCanonicalizer::canonicalize($item->url), $existing->items);
                $overlap = count(array_intersect($urls, $existingUrls)) / max(1, min(count($urls), count($existingUrls)));

                if ($overlap >= 0.8) {
                    continue 2;
                }
            }

            $kept[] = $cluster;
        }

        return $kept;
    }

    private function withId(LinkCluster $cluster, string $id): LinkCluster
    {
        return new LinkCluster(
            id: $id,
            signature: $cluster->signature,
            urlTemplate: $cluster->urlTemplate,
            items: $cluster->items,
            landmarks: $cluster->landmarks,
            precedingHeading: $cluster->precedingHeading,
            meanAnchorLength: $cluster->meanAnchorLength,
            headingRatio: $cluster->headingRatio,
            timeRatio: $cluster->timeRatio,
            sameHostRatio: $cluster->sameHostRatio,
            positionBucket: $cluster->positionBucket,
            score: $cluster->score,
        );
    }
}
