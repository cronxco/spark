<?php

namespace App\Services\Fetch\Links;

/**
 * Everything the deterministic link pass learns about a page: its links and
 * the page-level signals that say what kind of page it is.
 */
final readonly class PageLinks
{
    /**
     * @param  list<LinkCandidate>  $candidates  In DOM order
     * @param  list<string>  $feedUrls  Advertised RSS/Atom feeds
     * @param  list<string>  $structuredItemUrls  URLs from a JSON-LD ItemList / CollectionPage, in order
     */
    public function __construct(
        public string $pageUrl,
        public string $baseUrl,
        public ?string $title,
        public ?string $h1,
        public ?string $ogType,
        public array $candidates,
        public array $feedUrls,
        public array $structuredItemUrls,
    ) {}

    public static function empty(string $pageUrl): self
    {
        return new self($pageUrl, $pageUrl, null, null, null, [], [], []);
    }

    public function totalAnchorTextLength(): int
    {
        return array_sum(array_map(fn (LinkCandidate $candidate): int => mb_strlen($candidate->anchorText), $this->candidates));
    }
}
