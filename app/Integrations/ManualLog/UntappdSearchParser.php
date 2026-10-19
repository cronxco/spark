<?php

namespace App\Integrations\ManualLog;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parses an Untappd beer-search results page (untappd.com/search?q=...).
 *
 * Verified against the live page (19 Oct 2026). Results are drawn
 * client-side by Algolia into #algolia-hits, so the page must be rendered
 * (Playwright waiting for network idle) before parsing. A saved real page
 * lives in tests/Fixtures/ManualLog/untappd-search-results.html.
 */
class UntappdSearchParser
{
    private const BASE_URL = 'https://untappd.com';

    /**
     * @return array<int, array{beer_id: string, name: string, title: string, brewery: ?string, brewery_id: ?string, style: ?string, abv: ?float, ibu: ?int, rating: ?float, url: string, image: ?string}>
     */
    public function parseResults(string $html, int $limit = 10): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);
        $items = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " beer-item ")]');

        $results = [];
        foreach ($items ?: [] as $item) {
            if (! $item instanceof DOMElement) {
                continue;
            }

            $result = $this->parseItem($xpath, $item);
            if ($result !== null) {
                $results[] = $result;
            }
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * The result that best matches the query (brewery and beer name), or null
     * when none covers enough of it.
     */
    public function parseBestMatch(string $html, string $query): ?array
    {
        return NameMatcher::best($this->parseResults($html), $query, fn (array $result) => $result['title']);
    }

    /**
     * @return array<int, array>
     */
    public function parseCandidates(string $html, string $query, int $limit = 5): array
    {
        return NameMatcher::candidates($this->parseResults($html), $query, fn (array $result) => $result['title'], $limit);
    }

    private function parseItem(DOMXPath $xpath, DOMElement $item): ?array
    {
        $beerId = $item->getAttribute('data-bid');
        $name = $this->text($xpath, $item, './/p[contains(@class, "name")]');

        if ($beerId === '' || $name === null) {
            return null;
        }

        $brewery = $this->text($xpath, $item, './/p[contains(@class, "brewery")]');
        $breweryHref = $xpath->query('.//p[contains(@class, "brewery")]//a', $item)->item(0);
        $nameHref = $xpath->query('.//p[contains(@class, "name")]//a', $item)->item(0);
        $rating = $xpath->query('.//*[@data-rating]', $item)->item(0);
        $image = $xpath->query('.//a[contains(@class, "label")]//img', $item)->item(0);

        $href = $nameHref instanceof DOMElement ? $nameHref->getAttribute('href') : '/beer/'.$beerId;

        return [
            'beer_id' => $beerId,
            'name' => $name,
            'title' => trim(($brewery ? $brewery.' ' : '').$name),
            'brewery' => $brewery,
            'brewery_id' => $breweryHref instanceof DOMElement && preg_match('#/brewery/(\d+)#', $breweryHref->getAttribute('href'), $m) === 1 ? $m[1] : null,
            'style' => $this->text($xpath, $item, './/p[contains(@class, "style")]'),
            'abv' => $this->number($this->text($xpath, $item, './/p[contains(@class, "abv")]')),
            'ibu' => ($ibu = $this->number($this->text($xpath, $item, './/p[contains(@class, "ibu")]'))) !== null ? (int) $ibu : null,
            'rating' => $rating instanceof DOMElement && is_numeric($rating->getAttribute('data-rating')) ? (float) $rating->getAttribute('data-rating') : null,
            'url' => self::BASE_URL.$href,
            'image' => $image instanceof DOMElement && $image->getAttribute('src') !== '' ? $image->getAttribute('src') : null,
        ];
    }

    private function text(DOMXPath $xpath, DOMElement $context, string $query): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $xpath->query($query, $context)->item(0)?->textContent ?? '') ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * "8.4% ABV" => 8.4, "N/A IBU" => null.
     */
    private function number(?string $text): ?float
    {
        if ($text === null || preg_match('/(\d+(?:\.\d+)?)/', $text, $matches) !== 1) {
            return null;
        }

        return (float) $matches[1];
    }
}
