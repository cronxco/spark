<?php

namespace App\Integrations\ManualLog;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parses a Vivino wine-search results page.
 *
 * Verified against the live page (19 Oct 2026): the search URL redirects to
 * /en/explore?search_term=..., a server-rendered list of
 * [data-testid="wineCard"] cards. Only that test id and
 * [data-testid="vintagePageLink"] are stable; the inner elements carry
 * hashed CSS-module classes (e.g. "wineInfoVintage__truncate--JwXCa"), so
 * they are matched on the class prefix. A saved real page lives in
 * tests/Fixtures/ManualLog/vivino-search-results.html.
 *
 * Vivino's search is fuzzy and its first card is often unrelated to the
 * query, so callers should use parseBestMatch(), which only returns a card
 * whose name covers the query, rather than parseFirstResult().
 */
class VivinoSearchParser
{
    private const BASE_URL = 'https://www.vivino.com';

    /**
     * @return array<int, array{title: string, wine: string, winery: ?string, vintage: ?string, rating: ?float, rating_count: ?int, region: ?string, url: ?string, image: ?string}>
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
        $cards = $xpath->query('//*[@data-testid="wineCard"]');

        $results = [];
        foreach ($cards ?: [] as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }

            $result = $this->parseCard($xpath, $card);
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
     * The first card on the page. Only trustworthy when the query was exact;
     * prefer parseBestMatch().
     */
    public function parseFirstResult(string $html): ?array
    {
        return $this->parseResults($html, 1)[0] ?? null;
    }

    /**
     * The card that best matches the query, or null when none covers enough
     * of it.
     */
    public function parseBestMatch(string $html, string $query): ?array
    {
        return NameMatcher::best($this->parseResults($html), $query, fn (array $result) => $result['title']);
    }

    /**
     * Cards that partly match the query, best first, for asking the user which one they meant.
     *
     * @return array<int, array>
     */
    public function parseCandidates(string $html, string $query, int $limit = 5): array
    {
        return NameMatcher::candidates($this->parseResults($html), $query, fn (array $result) => $result['title'], $limit);
    }

    public function matchScore(string $query, string $title): float
    {
        return NameMatcher::score($query, $title);
    }

    private function parseCard(DOMXPath $xpath, DOMElement $card): ?array
    {
        $info = $xpath->query('.//*[contains(@class, "wineInfoVintage__wineInfoVintage")]', $card)->item(0);
        if (! $info instanceof DOMElement) {
            return null;
        }

        $rows = $xpath->query('./div', $info);
        $winery = $this->clean($rows->item(0)?->textContent);
        $wineAndYear = $this->clean($rows->item(1)?->textContent);

        if ($wineAndYear === null) {
            return null;
        }

        $vintage = null;
        $wine = $wineAndYear;
        if (preg_match('/^(.*?)\s+((?:19|20)\d{2})$/', $wineAndYear, $matches) === 1) {
            $wine = $matches[1];
            $vintage = $matches[2];
        }

        $href = $this->attribute($xpath, $card, './/a[@data-testid="vintagePageLink"]', 'href');
        $ratingLabel = $this->attribute($xpath, $card, './/*[@role="img" and starts-with(@aria-label, "Rated ")]', 'aria-label');
        $ratingText = $this->clean($xpath->query('.//*[contains(@class, "vivinoRating__averageValue")]', $card)->item(0)?->textContent)
            ?? $ratingLabel;
        $caption = $this->clean($xpath->query('.//*[contains(@class, "vivinoRating__caption")]', $card)->item(0)?->textContent);

        return [
            'title' => trim(($winery ? $winery.' ' : '').$wine),
            'wine' => $wine,
            'winery' => $winery,
            'vintage' => $vintage,
            'rating' => $this->parseRating($ratingText),
            'rating_count' => $caption !== null && preg_match('/([\d,]+)\s+ratings?/', $caption, $m) === 1
                ? (int) str_replace(',', '', $m[1])
                : null,
            'region' => $this->clean($xpath->query('.//*[contains(@class, "wineInfoLocation__regionAndCountry")]', $card)->item(0)?->textContent),
            'url' => $this->absoluteUrl($href),
            'image' => $this->image($xpath, $card),
        ];
    }

    private function image(DOMXPath $xpath, DOMElement $card): ?string
    {
        $src = $this->attribute($xpath, $card, './/img[contains(@src, "images.vivino.com")]', 'src');
        if ($src === null) {
            return null;
        }

        return str_starts_with($src, '//') ? 'https:'.$src : $src;
    }

    /**
     * Wine page URL without the price/tracking query, keeping the vintage year.
     */
    private function absoluteUrl(?string $href): ?string
    {
        if ($href === null) {
            return null;
        }

        $parts = parse_url(html_entity_decode($href));
        if (! is_array($parts) || ! isset($parts['path'])) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        $url = self::BASE_URL.$parts['path'];

        return isset($query['year']) ? $url.'?year='.$query['year'] : $url;
    }

    private function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return $text === '' ? null : $text;
    }

    private function attribute(DOMXPath $xpath, DOMElement $context, string $query, string $attribute): ?string
    {
        $node = $xpath->query($query, $context)->item(0);

        if (! $node instanceof DOMElement || ! $node->hasAttribute($attribute)) {
            return null;
        }

        return $this->clean($node->getAttribute($attribute));
    }

    /**
     * Extract a 0-5 rating from a plain number ("4.2") or a label ("Rated 4.2 stars").
     */
    private function parseRating(?string $raw): ?float
    {
        if ($raw === null || preg_match('/(\d+(?:\.\d+)?)/', $raw, $matches) !== 1) {
            return null;
        }

        $rating = (float) $matches[1];

        return $rating >= 0 && $rating <= 5 ? $rating : null;
    }
}
