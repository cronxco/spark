<?php

namespace App\Integrations\Vivino;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parses a Vivino profile activity page into a normalized list of wine
 * ratings.
 *
 * IMPORTANT: this could not be built or verified against Vivino's real,
 * live markup in the environment this was written in (no outbound browsing
 * access to vivino.com). The selectors below are a best-effort scaffold
 * based on common patterns for this kind of activity feed (a repeating
 * card element carrying a rating and a wine name), not a confirmed match
 * for Vivino's actual DOM. Scrapers are inherently fragile to markup
 * changes anyway - but this one specifically needs a pass against real
 * fetched HTML (e.g. via VivinoActivityPull's Playwright fetch, or a saved
 * sample page) to correct the selectors before relying on it in production.
 * The parsing logic itself (extraction, normalization, dedup by title) is
 * unit-tested against a constructed fixture so it's correct for whatever
 * shape of matching HTML it's given.
 */
class VivinoActivityParser
{
    /**
     * @return array<int, array{
     *     title: string,
     *     winery: ?string,
     *     vintage: ?string,
     *     rating: ?float,
     *     region: ?string,
     *     note: ?string,
     *     url: ?string,
     * }>
     */
    public function parse(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        // Best-effort: activity cards commonly carry a data-testid hook.
        // Falls back to a class-based match if no such node is found.
        $cards = $xpath->query('//*[@data-testid="activityFeedItem" or contains(concat(" ", normalize-space(@class), " "), " activity-feed-item ")]');

        $entries = [];
        $seenTitles = [];

        foreach ($cards as $card) {
            if (! $card instanceof DOMElement) {
                continue;
            }

            $title = $this->firstText($xpath, $card, './/*[@data-testid="wineName" or contains(@class, "wine-name")]');

            if ($title === null) {
                continue;
            }

            // Skip duplicate entries for the same wine within one page fetch.
            if (isset($seenTitles[$title])) {
                continue;
            }
            $seenTitles[$title] = true;

            $winery = $this->firstText($xpath, $card, './/*[@data-testid="wineryName" or contains(@class, "winery-name")]');
            $vintage = $this->firstText($xpath, $card, './/*[@data-testid="vintageYear" or contains(@class, "vintage-year")]');
            $region = $this->firstText($xpath, $card, './/*[@data-testid="regionName" or contains(@class, "region-name")]');
            $note = $this->firstText($xpath, $card, './/*[@data-testid="reviewNote" or contains(@class, "review-note")]');
            $url = $this->firstAttribute($xpath, $card, './/a[@data-testid="wineNameLink" or contains(@class, "wine-name")]', 'href');

            $ratingText = $this->firstAttribute($xpath, $card, './/*[@data-testid="ratingStars" or contains(@class, "rating-stars")]', 'aria-label')
                ?? $this->firstText($xpath, $card, './/*[@data-testid="ratingValue" or contains(@class, "rating-value")]');

            $entries[] = [
                'title' => $title,
                'winery' => $winery,
                'vintage' => $vintage,
                'rating' => $this->parseRating($ratingText),
                'region' => $region,
                'note' => $note,
                'url' => $url,
            ];
        }

        return $entries;
    }

    private function firstText(DOMXPath $xpath, DOMElement $context, string $query): ?string
    {
        $nodes = $xpath->query($query, $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $text = trim($nodes->item(0)->textContent);

        return $text === '' ? null : $text;
    }

    private function firstAttribute(DOMXPath $xpath, DOMElement $context, string $query, string $attribute): ?string
    {
        $nodes = $xpath->query($query, $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        if (! $node instanceof DOMElement || ! $node->hasAttribute($attribute)) {
            return null;
        }

        $value = trim($node->getAttribute($attribute));

        return $value === '' ? null : $value;
    }

    /**
     * Extract a 1-5 rating from either a plain numeric string ("4.5") or an
     * accessibility label like "Rated 4.5 out of 5".
     */
    private function parseRating(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        if (preg_match('/(\d+(?:\.\d+)?)/', $raw, $matches) !== 1) {
            return null;
        }

        $rating = (float) $matches[1];

        if ($rating < 0 || $rating > 5) {
            return null;
        }

        return $rating;
    }
}
