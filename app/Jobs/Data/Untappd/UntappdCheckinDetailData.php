<?php

namespace App\Jobs\Data\Untappd;

use App\Jobs\Base\BaseProcessingJob;
use App\Jobs\OAuth\Untappd\UntappdBeerDetailPull;
use App\Jobs\OAuth\Untappd\UntappdBreweryDetailPull;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;

class UntappdCheckinDetailData extends BaseProcessingJob
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
        return 'checkin_detail';
    }

    protected function process(): void
    {
        $eventId = $this->rawData['event_id'] ?? null;
        $html = $this->rawData['html'] ?? null;

        if (! $eventId || ! $html) {
            logger()->warning('Missing required data for checkin detail processing', [
                'has_event_id' => ! empty($eventId),
                'has_html' => ! empty($html),
            ]);

            return;
        }

        // Load the event
        $event = Event::find($eventId);

        if (! $event) {
            logger()->warning('Event not found for checkin detail processing', [
                'event_id' => $eventId,
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Parse HTML
        $details = $this->parseCheckinDetails($html);

        // Update event with rating (normalize to integer with multiplier)
        if ($details['rating'] !== null) {
            $normalizedValue = (int) ($details['rating'] * 100);

            logger()->info('Updating event with rating', [
                'event_id' => $event->id,
                'raw_rating' => $details['rating'],
                'normalized_value' => $normalizedValue,
            ]);

            $event->update([
                'value' => $normalizedValue,
                'value_multiplier' => 100,
                'value_unit' => '/5',
            ]);
        }

        // Update event metadata
        $metadata = $event->event_metadata ?? [];
        $metadata['enriched_at'] = now()->toIso8601String();
        $metadata['needs_enrichment'] = false;
        $metadata['serving_style'] = $details['serving_style'];
        $metadata['badges'] = $details['badges'];

        $event->update(['event_metadata' => $metadata]);

        // Create badge blocks
        foreach ($details['badges'] as $badge) {
            $event->createBlock([
                'block_type' => 'badge_earned',
                'title' => $badge['name'],
                'content' => $badge['description'],
                'metadata' => [
                    'image_url' => $badge['image_url'],
                    'badge_name' => $this->extractBadgeNameWithoutLevel($badge['name']),
                    'badge_level' => $this->extractBadgeLevel($badge['name']),
                ],
                'url' => null,
                'media_url' => null,
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
                'time' => $event->time,
            ]);
        }

        // Conditionally dispatch beer detail job OR create beer block immediately
        if ($details['beer_url'] && $event->target) {
            if ($this->shouldFetchBeerDetails($event->target)) {
                $fullBeerUrl = 'https://untappd.com' . $details['beer_url'];

                logger()->info('Dispatching beer detail job', [
                    'beer_id' => $event->target->id,
                    'beer_url' => $fullBeerUrl,
                    'beer_title' => $event->target->title,
                ]);

                UntappdBeerDetailPull::dispatch(
                    $this->integration,
                    (string) $event->target->id,
                    $fullBeerUrl
                )->onQueue('pull')->delay(now()->addMinutes(5));
            } else {
                // Beer details are already complete, create block immediately
                logger()->info('Creating beer info block immediately', [
                    'beer_id' => $event->target->id,
                    'beer_title' => $event->target->title,
                ]);

                UntappdCreateBeerInfoBlocks::dispatch(
                    $this->integration,
                    ['beer_id' => $event->target->id]
                );
            }
        } else {
            logger()->info('Skipping beer detail processing', [
                'has_beer_url' => ! empty($details['beer_url']),
                'has_target' => ! empty($event->target),
            ]);
        }

        // Conditionally dispatch brewery detail job OR create brewery block immediately
        if ($details['brewery_url'] && $event->target) {
            $breweryId = $this->getOrCreateBreweryObject($details['brewery_name'], $details['brewery_url']);

            if ($breweryId && $this->shouldFetchBreweryDetails($breweryId)) {
                $fullBreweryUrl = 'https://untappd.com' . $details['brewery_url'];

                logger()->info('Dispatching brewery detail job', [
                    'brewery_id' => $breweryId,
                    'brewery_url' => $fullBreweryUrl,
                ]);

                UntappdBreweryDetailPull::dispatch(
                    $this->integration,
                    (string) $breweryId,
                    $fullBreweryUrl
                )->onQueue('pull')->delay(now()->addMinutes(5));
            } elseif ($breweryId) {
                // Brewery details are already complete, create block immediately
                logger()->info('Creating brewery info block immediately', [
                    'brewery_id' => $breweryId,
                ]);

                UntappdCreateBreweryInfoBlocks::dispatch(
                    $this->integration,
                    ['brewery_id' => $breweryId]
                );
            }
        }
    }

    /**
     * Parse check-in HTML for rating, badges, serving style, beer URL, and brewery info
     */
    private function parseCheckinDetails(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);

        logger()->info('Parsing check-in details', [
            'html_length' => strlen($html),
            'html_preview' => substr($html, 0, 500),
        ]);

        return [
            'rating' => $this->extractRating($xpath),
            'badges' => $this->extractBadges($xpath),
            'serving_style' => $this->extractServingStyle($xpath),
            'beer_url' => $this->extractBeerUrl($xpath),
            'brewery_name' => $this->extractBreweryName($xpath),
            'brewery_url' => $this->extractBreweryUrl($xpath),
        ];
    }

    /**
     * Extract rating from check-in page
     */
    private function extractRating(DOMXPath $xpath): ?float
    {
        // Look for: <div class="caps" data-rating="4.5">
        $nodes = $xpath->query("//div[contains(@class, 'caps')]/@data-rating");

        logger()->info('Rating extraction attempt', [
            'nodes_found' => $nodes->length,
            'first_node_value' => $nodes->length > 0 ? $nodes->item(0)->nodeValue : null,
        ]);

        if ($nodes->length > 0) {
            $rating = floatval($nodes->item(0)->nodeValue);

            logger()->info('Rating extracted', [
                'raw_value' => $nodes->item(0)->nodeValue,
                'parsed_rating' => $rating,
            ]);

            return ($rating >= 0 && $rating <= 5) ? $rating : null;
        }

        // Try alternative selector: div.caps with data-rating
        $altNodes = $xpath->query("//div[@class='caps']/@data-rating");
        if ($altNodes->length > 0) {
            $rating = floatval($altNodes->item(0)->nodeValue);
            logger()->info('Rating extracted via alternative selector', [
                'rating' => $rating,
            ]);

            return ($rating >= 0 && $rating <= 5) ? $rating : null;
        }

        logger()->warning('No rating found in HTML');

        return null;
    }

    /**
     * Extract badges from check-in page
     */
    private function extractBadges(DOMXPath $xpath): array
    {
        $badges = [];

        // Look for: <div class="badges unlocked"><span class="badge">
        $badgeNodes = $xpath->query("//div[contains(@class, 'badges')]//span[@class='badge']");

        foreach ($badgeNodes as $badgeNode) {
            $imgNode = $xpath->query('.//img', $badgeNode)->item(0);
            $textNode = $xpath->query('.//span', $badgeNode)->item(0);

            if ($imgNode && $textNode) {
                $badges[] = [
                    'name' => trim($imgNode->getAttribute('alt')),
                    'description' => trim($textNode->textContent),
                    'image_url' => trim($imgNode->getAttribute('src')),
                ];
            }
        }

        return $badges;
    }

    /**
     * Extract serving style from check-in page
     */
    private function extractServingStyle(DOMXPath $xpath): ?string
    {
        // Look for: <p class="serving"><span>Can</span></p>
        $nodes = $xpath->query("//p[contains(@class, 'serving')]//span");

        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }

        return null;
    }

    /**
     * Extract beer URL from check-in page
     */
    private function extractBeerUrl(DOMXPath $xpath): ?string
    {
        // Look for beer link in: <div class="checkin-info"><div class="beer"><p><a href="/b/...">
        $nodes = $xpath->query("//div[contains(@class, 'checkin-info')]//div[contains(@class, 'beer')]//a[starts-with(@href, '/b/')]/@href");

        if ($nodes->length > 0) {
            return trim($nodes->item(0)->nodeValue);
        }

        // Fallback: any link starting with /b/
        $nodes = $xpath->query("//a[starts-with(@href, '/b/')]/@href");

        if ($nodes->length > 0) {
            return trim($nodes->item(0)->nodeValue);
        }

        return null;
    }

    /**
     * Extract badge name without level
     */
    private function extractBadgeNameWithoutLevel(string $fullName): string
    {
        // Remove "(Level X)" from name
        return preg_replace('/\s*\(Level\s+\d+\)\s*$/i', '', $fullName);
    }

    /**
     * Extract badge level number
     */
    private function extractBadgeLevel(string $fullName): ?string
    {
        if (preg_match('/\(Level\s+(\d+)\)/i', $fullName, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Determine if beer details should be fetched
     */
    private function shouldFetchBeerDetails(EventObject $beer): bool
    {
        $metadata = $beer->metadata ?? [];

        // Check completeness (needs description, style, and ABV)
        $isComplete = ! empty($metadata['description'])
            && ! empty($metadata['style'])
            && ! empty($metadata['abv']);

        if ($isComplete) {
            return false; // Already have everything
        }

        // Check recent attempts (30-day grace period)
        $lastEnrichedAt = isset($metadata['last_enriched_at'])
            ? Carbon::parse($metadata['last_enriched_at'])
            : null;

        if ($lastEnrichedAt && $lastEnrichedAt->diffInDays() < 30) {
            return false; // Tried recently
        }

        return true; // Needs enrichment
    }

    /**
     * Extract brewery name from check-in page
     */
    private function extractBreweryName(DOMXPath $xpath): ?string
    {
        // Look for: <div class="checkin-info"><div class="beer"><span><a href="/...">Brewery Name</a>
        $nodes = $xpath->query("//div[contains(@class, 'checkin-info')]//div[contains(@class, 'beer')]//span/a");

        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }

        return null;
    }

    /**
     * Extract brewery URL from check-in page
     */
    private function extractBreweryUrl(DOMXPath $xpath): ?string
    {
        // Look for: <div class="checkin-info"><div class="beer"><span><a href="/BrewerySlug">
        $nodes = $xpath->query("//div[contains(@class, 'checkin-info')]//div[contains(@class, 'beer')]//span/a/@href");

        if ($nodes->length > 0) {
            $url = trim($nodes->item(0)->nodeValue);
            // Make sure it's not a beer URL (starts with /b/)
            if (! str_starts_with($url, '/b/')) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Get or create brewery EventObject
     */
    private function getOrCreateBreweryObject(string $breweryName, string $breweryUrl): ?int
    {
        if (empty($breweryName) || empty($breweryUrl)) {
            return null;
        }

        $brewery = EventObject::firstOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'brewery',
                'type' => 'untappd_brewery',
                'title' => $breweryName,
            ],
            [
                'time' => now(),
                'url' => 'https://untappd.com' . $breweryUrl,
                'metadata' => [
                    'created_from_checkin' => true,
                ],
            ]
        );

        return $brewery->id;
    }

    /**
     * Determine if brewery details should be fetched
     */
    private function shouldFetchBreweryDetails(int $breweryId): bool
    {
        $brewery = EventObject::find($breweryId);

        if (! $brewery) {
            return false;
        }

        $metadata = $brewery->metadata ?? [];

        // Check completeness (needs description and address)
        $isComplete = ! empty($metadata['description'])
            && ! empty($metadata['address']);

        if ($isComplete) {
            return false; // Already have everything
        }

        // Check recent attempts (30-day grace period)
        $lastEnrichedAt = isset($metadata['last_enriched_at'])
            ? Carbon::parse($metadata['last_enriched_at'])
            : null;

        if ($lastEnrichedAt && $lastEnrichedAt->diffInDays() < 30) {
            return false; // Tried recently
        }

        return true; // Needs enrichment
    }
}
