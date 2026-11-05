<?php

namespace App\Jobs\Data\Untappd;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\EventObject;
use App\Models\Integration;
use App\Services\Media\MediaDownloadHelper;
use DOMDocument;
use DOMXPath;
use Exception;

class UntappdBreweryDetailData extends BaseProcessingJob
{
    public function __construct(
        Integration $integration,
        array $rawData
    ) {
        parent::__construct($integration, $rawData);
    }

    protected function getServiceName(): string
    {
        return 'untappd';
    }

    protected function getJobType(): string
    {
        return 'brewery_detail';
    }

    protected function process(): void
    {
        $breweryId = $this->rawData['brewery_id'] ?? null;
        $html = $this->rawData['html'] ?? null;

        if (! $breweryId || ! $html) {
            logger()->warning('Missing required data for brewery detail processing', [
                'has_brewery_id' => ! empty($breweryId),
                'has_html' => ! empty($html),
            ]);

            return;
        }

        logger()->info('Starting brewery detail processing', [
            'brewery_id' => $breweryId,
            'html_length' => strlen($html),
        ]);

        // Load the brewery EventObject
        $brewery = EventObject::find($breweryId);

        if (! $brewery) {
            logger()->warning('Brewery EventObject not found for detail processing', [
                'brewery_id' => $breweryId,
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        logger()->info('Brewery loaded successfully', [
            'brewery_id' => $brewery->id,
            'brewery_title' => $brewery->title,
            'current_metadata' => $brewery->metadata,
        ]);

        // Parse HTML
        $details = $this->parseBreweryDetails($html);

        logger()->info('Brewery details parsed', [
            'brewery_id' => $brewery->id,
            'details' => $details,
        ]);

        // Merge metadata (don't overwrite existing fields)
        $metadata = $brewery->metadata ?? [];

        if (! empty($details['description'])) {
            $metadata['description'] = $details['description'];
        }
        if (! empty($details['address'])) {
            $metadata['address'] = $details['address'];
        }
        if (! empty($details['street_address'])) {
            $metadata['street_address'] = $details['street_address'];
        }
        if (! empty($details['locality'])) {
            $metadata['locality'] = $details['locality'];
        }
        if (! empty($details['region'])) {
            $metadata['region'] = $details['region'];
        }
        if ($details['aggregate_rating'] !== null) {
            $metadata['aggregate_rating'] = $details['aggregate_rating'];
        }
        if ($details['review_count'] !== null) {
            $metadata['review_count'] = $details['review_count'];
        }
        if (! empty($details['brewery_url'])) {
            $metadata['brewery_url'] = $details['brewery_url'];
        }

        $metadata['last_enriched_at'] = now()->toIso8601String();

        logger()->info('Updating brewery metadata', [
            'brewery_id' => $brewery->id,
            'metadata' => $metadata,
        ]);

        $brewery->update(['metadata' => $metadata]);

        logger()->info('Brewery metadata updated successfully', [
            'brewery_id' => $brewery->id,
        ]);

        // Download brewery logo if available
        if (! empty($details['logo_url'])) {
            try {
                $helper = app(MediaDownloadHelper::class);
                $helper->downloadAndAttachMedia(
                    $details['logo_url'],
                    $brewery,
                    'downloaded_images'
                );
            } catch (Exception $e) {
                logger()->warning('Failed to download brewery logo', [
                    'brewery_id' => $brewery->id,
                    'logo_url' => $details['logo_url'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Dispatch job to create brewery info blocks on related checkin events
        UntappdCreateBreweryInfoBlocks::dispatch(
            $this->integration,
            ['brewery_id' => $brewery->id]
        );
    }

    /**
     * Parse brewery HTML for detailed information
     */
    private function parseBreweryDetails(string $html): array
    {
        // Try JSON-LD first (most reliable)
        $jsonLdData = $this->extractJsonLd($html);

        if ($jsonLdData) {
            // Extract image URL (could be string or array)
            $imageUrl = null;
            if (isset($jsonLdData['image'])) {
                if (is_string($jsonLdData['image'])) {
                    $imageUrl = $jsonLdData['image'];
                } elseif (is_array($jsonLdData['image'])) {
                    // If array, check for contentUrl or url
                    $imageUrl = $jsonLdData['image']['contentUrl']
                        ?? $jsonLdData['image']['url']
                        ?? ($jsonLdData['image'][0] ?? null);
                }
            }

            // Extract address components
            $address = $jsonLdData['address'] ?? [];
            $fullAddress = null;
            if (! empty($address)) {
                $parts = array_filter([
                    $address['streetAddress'] ?? null,
                    $address['addressLocality'] ?? null,
                    $address['addressRegion'] ?? null,
                ]);
                $fullAddress = ! empty($parts) ? implode(', ', $parts) : null;
            }

            return [
                'description' => $jsonLdData['description'] ?? null,
                'address' => $fullAddress,
                'street_address' => $address['streetAddress'] ?? null,
                'locality' => $address['addressLocality'] ?? null,
                'region' => $address['addressRegion'] ?? null,
                'aggregate_rating' => $jsonLdData['aggregateRating']['ratingValue'] ?? null,
                'review_count' => $jsonLdData['aggregateRating']['reviewCount'] ?? null,
                'logo_url' => $imageUrl,
                'brewery_url' => $jsonLdData['url'] ?? null,
            ];
        }

        // Fallback to full HTML parsing
        return $this->parseHtmlFallback($html);
    }

    /**
     * Extract JSON-LD Brewery schema from HTML
     */
    private function extractJsonLd(string $html): ?array
    {
        if (preg_match('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $match)) {
            $data = json_decode($match[1], true);

            if ($data && isset($data['@type']) && $data['@type'] === 'Brewery') {
                return $data;
            }
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
            'address' => $this->extractAddress($xpath),
            'street_address' => null,
            'locality' => null,
            'region' => null,
            'aggregate_rating' => $this->extractAggregateRating($xpath),
            'review_count' => null,
            'logo_url' => $this->extractLogoUrl($xpath),
            'brewery_url' => null,
        ];
    }

    /**
     * Extract description from HTML
     */
    private function extractDescription(DOMXPath $xpath): ?string
    {
        // Look for description in the brewery page
        $nodes = $xpath->query("//div[contains(@class, 'brewery')]//div[contains(@class, 'bottom')]");

        if ($nodes->length > 0) {
            $text = trim($nodes->item(0)->textContent);

            // Clean up extra whitespace
            $text = preg_replace('/\s+/', ' ', $text);

            // Remove "Show Less" link text if present
            $text = preg_replace('/Show Less$/i', '', $text);

            return trim($text) ?: null;
        }

        return null;
    }

    /**
     * Extract address from HTML
     */
    private function extractAddress(DOMXPath $xpath): ?string
    {
        // Try to find address in various places
        // This is a fallback and may not be as reliable as JSON-LD
        return null;
    }

    /**
     * Extract aggregate rating from HTML
     */
    private function extractAggregateRating(DOMXPath $xpath): ?float
    {
        // Look for: <div class="caps" data-rating="3.418">
        $nodes = $xpath->query("//div[@class='caps']/@data-rating");

        if ($nodes->length > 0) {
            return floatval($nodes->item(0)->nodeValue);
        }

        return null;
    }

    /**
     * Extract logo URL from HTML
     */
    private function extractLogoUrl(DOMXPath $xpath): ?string
    {
        // Look for og:image meta tag
        $nodes = $xpath->query("//meta[@property='og:image']/@content");

        if ($nodes->length > 0) {
            return trim($nodes->item(0)->nodeValue);
        }

        return null;
    }
}
