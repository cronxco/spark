<?php

namespace App\Jobs\Data\Untappd;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\EventObject;
use App\Services\Media\MediaDownloadHelper;
use DOMDocument;
use DOMXPath;
use Exception;

class UntappdBeerDetailData extends BaseProcessingJob
{
    public function __construct(
        public $integration,
        public int $beerId,
        public string $html
    ) {
        parent::__construct($integration, []);
    }

    protected function getServiceName(): string
    {
        return 'untappd';
    }

    protected function getJobType(): string
    {
        return 'beer_detail';
    }

    protected function process(): void
    {
        // Load the beer EventObject
        $beer = EventObject::find($this->beerId);

        if (! $beer) {
            logger()->warning('Beer EventObject not found for detail processing', [
                'beer_id' => $this->beerId,
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Parse HTML
        $details = $this->parseBeerDetails($this->html);

        // Merge metadata (don't overwrite existing fields)
        $metadata = $beer->metadata ?? [];

        if (! empty($details['description'])) {
            $metadata['description'] = $details['description'];
        }
        if (! empty($details['style'])) {
            $metadata['style'] = $details['style'];
        }
        if ($details['abv'] !== null) {
            $metadata['abv'] = $details['abv'];
        }
        if ($details['ibu'] !== null) {
            $metadata['ibu'] = $details['ibu'];
        }
        if ($details['aggregate_rating'] !== null) {
            $metadata['aggregate_rating'] = $details['aggregate_rating'];
        }
        if ($details['review_count'] !== null) {
            $metadata['review_count'] = $details['review_count'];
        }
        if (! empty($details['beer_url'])) {
            $metadata['beer_url'] = $details['beer_url'];
        }

        $metadata['last_enriched_at'] = now()->toIso8601String();

        $beer->update(['metadata' => $metadata]);

        // Download beer label image if available
        if (! empty($details['label_url'])) {
            try {
                $helper = app(MediaDownloadHelper::class);
                $helper->downloadAndAttachMedia(
                    $details['label_url'],
                    $beer,
                    'downloaded_images'
                );
            } catch (Exception $e) {
                logger()->warning('Failed to download beer label', [
                    'beer_id' => $beer->id,
                    'label_url' => $details['label_url'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Parse beer HTML for detailed information
     */
    private function parseBeerDetails(string $html): array
    {
        // Try JSON-LD first (most reliable)
        $jsonLdData = $this->extractJsonLd($html);

        if ($jsonLdData) {
            return [
                'description' => $jsonLdData['description'] ?? null,
                'abv' => $this->extractABVFromJsonLd($jsonLdData),
                'ibu' => $this->extractIBUFromJsonLd($jsonLdData),
                'aggregate_rating' => $jsonLdData['aggregateRating']['ratingValue'] ?? null,
                'review_count' => $jsonLdData['aggregateRating']['reviewCount'] ?? null,
                'label_url' => $jsonLdData['image'] ?? null,
                'beer_url' => $jsonLdData['url'] ?? null,
                // Style not in JSON-LD, need HTML fallback
                'style' => $this->extractStyleFromHtml($html),
            ];
        }

        // Fallback to full HTML parsing
        return $this->parseHtmlFallback($html);
    }

    /**
     * Extract JSON-LD Product schema from HTML
     */
    private function extractJsonLd(string $html): ?array
    {
        if (preg_match('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $match)) {
            $data = json_decode($match[1], true);

            if ($data && isset($data['@type']) && $data['@type'] === 'Product') {
                return $data;
            }
        }

        return null;
    }

    /**
     * Extract ABV from JSON-LD data
     */
    private function extractABVFromJsonLd(array $jsonLd): ?float
    {
        // ABV might not be directly in JSON-LD, may need HTML parsing
        // For now, return null and rely on HTML fallback
        return null;
    }

    /**
     * Extract IBU from JSON-LD data
     */
    private function extractIBUFromJsonLd(array $jsonLd): ?int
    {
        // IBU might not be in JSON-LD, may need HTML parsing
        return null;
    }

    /**
     * Extract style from HTML
     */
    private function extractStyleFromHtml(string $html): ?string
    {
        $dom = new DOMDocument;
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);

        // Look for: <p class="style">Stout - Imperial / Double Pastry</p>
        $nodes = $xpath->query("//p[@class='style']");

        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }

        return null;
    }

    /**
     * Fallback: parse all details from HTML
     */
    private function parseHtmlFallback(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);

        return [
            'description' => $this->extractDescription($xpath),
            'style' => $this->extractStyle($xpath),
            'abv' => $this->extractABV($xpath),
            'ibu' => $this->extractIBU($xpath),
            'aggregate_rating' => $this->extractAggregateRating($xpath),
            'review_count' => $this->extractReviewCount($xpath),
            'label_url' => $this->extractLabelUrl($xpath),
            'beer_url' => null,
        ];
    }

    /**
     * Extract description from HTML
     */
    private function extractDescription(DOMXPath $xpath): ?string
    {
        // Look for: <div class="beer-descrption-read-less"> (note the typo)
        $nodes = $xpath->query("//div[contains(@class, 'beer-descrption-read-less')]");

        if ($nodes->length > 0) {
            $text = trim($nodes->item(0)->textContent);

            // Clean up extra whitespace
            $text = preg_replace('/\s+/', ' ', $text);

            return $text ?: null;
        }

        return null;
    }

    /**
     * Extract style from HTML
     */
    private function extractStyle(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query("//p[@class='style']");

        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }

        return null;
    }

    /**
     * Extract ABV from HTML
     */
    private function extractABV(DOMXPath $xpath): ?float
    {
        // Look for: <p class="abv">11% ABV</p>
        $nodes = $xpath->query("//p[@class='abv']");

        if ($nodes->length > 0) {
            $text = trim($nodes->item(0)->textContent);

            if (preg_match('/(\d+(?:\.\d+)?)\s*%/', $text, $matches)) {
                return floatval($matches[1]);
            }
        }

        return null;
    }

    /**
     * Extract IBU from HTML
     */
    private function extractIBU(DOMXPath $xpath): ?int
    {
        // Look for: <p class="ibu">65 IBU</p>
        $nodes = $xpath->query("//p[@class='ibu']");

        if ($nodes->length > 0) {
            $text = trim($nodes->item(0)->textContent);

            if (preg_match('/(\d+)\s*IBU/', $text, $matches)) {
                return intval($matches[1]);
            }

            // Check for "N/A IBU"
            if (stripos($text, 'N/A') !== false) {
                return null;
            }
        }

        return null;
    }

    /**
     * Extract aggregate rating from HTML
     */
    private function extractAggregateRating(DOMXPath $xpath): ?float
    {
        // Look for: <div class="caps" data-rating="4.23924">
        $nodes = $xpath->query("//div[@class='caps']/@data-rating");

        if ($nodes->length > 0) {
            return floatval($nodes->item(0)->nodeValue);
        }

        return null;
    }

    /**
     * Extract review count from HTML
     */
    private function extractReviewCount(DOMXPath $xpath): ?int
    {
        // This might be in various places - check for patterns like "2,161 Ratings"
        // For now, return null as it's not critical
        return null;
    }

    /**
     * Extract label URL from HTML
     */
    private function extractLabelUrl(DOMXPath $xpath): ?string
    {
        // Look for og:image meta tag
        $nodes = $xpath->query("//meta[@property='og:image']/@content");

        if ($nodes->length > 0) {
            return trim($nodes->item(0)->nodeValue);
        }

        return null;
    }
}
