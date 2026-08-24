<?php

namespace App\Integrations\ManualLog;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parses a Vivino wine-search results page into the single best (first)
 * match for a manually-logged wine name.
 *
 * IMPORTANT: this could not be built or verified against Vivino's real,
 * live markup in the environment this was written in (no outbound browsing
 * access to vivino.com). The selectors below are a best-effort scaffold
 * based on common patterns for this kind of search-result card (a
 * repeating item carrying a wine name, winery, vintage, and rating), not a
 * confirmed match for Vivino's actual DOM. This needs a pass against real
 * fetched HTML (e.g. via VivinoEnrichmentPull's Playwright fetch, or a
 * saved sample search page) to correct the selectors before relying on it
 * in production. The parsing logic itself (extraction, normalization) is
 * unit-tested against a constructed fixture so it's correct for whatever
 * shape of matching HTML it's given.
 */
class VivinoSearchParser
{
    /**
     * @return array{title: string, winery: ?string, vintage: ?string, rating: ?float, region: ?string, url: ?string, image: ?string}|null
     */
    public function parseFirstResult(string $html): ?array
    {
        if (trim($html) === '') {
            return null;
        }

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($document);

        $cards = $xpath->query('//*[@data-testid="wineCard" or contains(concat(" ", normalize-space(@class), " "), " wine-card ")]');

        if ($cards === false || $cards->length === 0) {
            return null;
        }

        $card = $cards->item(0);

        if (! $card instanceof DOMElement) {
            return null;
        }

        $title = $this->firstText($xpath, $card, './/*[@data-testid="wineName" or contains(@class, "wine-name")]');

        if ($title === null) {
            return null;
        }

        $winery = $this->firstText($xpath, $card, './/*[@data-testid="wineryName" or contains(@class, "winery-name")]');
        $vintage = $this->firstText($xpath, $card, './/*[@data-testid="vintageYear" or contains(@class, "vintage-year")]');
        $region = $this->firstText($xpath, $card, './/*[@data-testid="regionName" or contains(@class, "region-name")]');
        $url = $this->firstAttribute($xpath, $card, './/a[@data-testid="wineNameLink" or contains(@class, "wine-name")]', 'href');
        $image = $this->firstAttribute($xpath, $card, './/img[@data-testid="wineLabel" or contains(@class, "wine-label")]', 'src');

        $ratingText = $this->firstAttribute($xpath, $card, './/*[@data-testid="ratingStars" or contains(@class, "rating-stars")]', 'aria-label')
            ?? $this->firstText($xpath, $card, './/*[@data-testid="ratingValue" or contains(@class, "rating-value")]');

        return [
            'title' => $title,
            'winery' => $winery,
            'vintage' => $vintage,
            'rating' => $this->parseRating($ratingText),
            'region' => $region,
            'url' => $url,
            'image' => $image,
        ];
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
