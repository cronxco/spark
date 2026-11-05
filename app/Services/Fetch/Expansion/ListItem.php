<?php

namespace App\Services\Fetch\Expansion;

use App\Services\Fetch\Links\LinkCandidate;

/**
 * One article found in a list, ready to be expanded. Serialisable so it can
 * travel in a queued job instead of the page HTML.
 */
final readonly class ListItem
{
    public function __construct(
        public string $url,
        public ?string $title = null,
    ) {}

    public static function fromCandidate(LinkCandidate $candidate): self
    {
        return new self($candidate->url, $candidate->anchorText === '' ? null : $candidate->anchorText);
    }

    /**
     * @param  array{url: string, title?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['url'], $data['title'] ?? null);
    }

    /**
     * @return array{url: string, title: ?string}
     */
    public function toArray(): array
    {
        return ['url' => $this->url, 'title' => $this->title];
    }
}
