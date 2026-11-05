<?php

namespace App\Services\Fetch\Links;

/**
 * One `<a href>` found in a page or email, with the structural context used
 * to decide whether it belongs to the page's primary list of articles.
 */
final readonly class LinkCandidate
{
    /**
     * @param  list<string>  $landmarks  Structural regions the link sits in (nav, footer, aside, related, share, …)
     */
    public function __construct(
        public string $id,
        public string $url,
        public string $anchorText,
        public bool $inHeading,
        public bool $nearTime,
        public bool $imageOnly,
        public array $landmarks,
        public ?string $precedingHeading,
        public int $position,
        public string $context,
        public string $domSignature,
    ) {}

    public function host(): string
    {
        return strtolower((string) parse_url($this->url, PHP_URL_HOST));
    }

    public function hasLandmark(string ...$landmarks): bool
    {
        return array_intersect($landmarks, $this->landmarks) !== [];
    }

    /**
     * @return array{id: string, url: string, anchor_text: string, in_heading: bool, near_time: bool, image_only: bool, landmarks: list<string>, preceding_heading: ?string, position: int, context: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'anchor_text' => $this->anchorText,
            'in_heading' => $this->inHeading,
            'near_time' => $this->nearTime,
            'image_only' => $this->imageOnly,
            'landmarks' => $this->landmarks,
            'preceding_heading' => $this->precedingHeading,
            'position' => $this->position,
            'context' => $this->context,
        ];
    }
}
