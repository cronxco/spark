<?php

namespace App\Jobs\Data\Untappd;

use App\Jobs\Base\BaseProcessingJob;
use App\Jobs\OAuth\Untappd\UntappdBeerDetailPull;
use App\Models\Event;
use App\Models\EventObject;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;

class UntappdCheckinDetailData extends BaseProcessingJob
{
    public function __construct(
        public $integration,
        public int $eventId,
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
        return 'checkin_detail';
    }

    protected function process(): void
    {
        // Load the event
        $event = Event::find($this->eventId);

        if (! $event) {
            logger()->warning('Event not found for checkin detail processing', [
                'event_id' => $this->eventId,
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Parse HTML
        $details = $this->parseCheckinDetails($this->html);

        // Update event with rating
        if ($details['rating'] !== null) {
            $event->update([
                'value' => $details['rating'],
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
            $this->createBlock([
                'event_id' => $event->id,
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

        // Conditionally dispatch beer detail job
        if ($details['beer_url'] && $this->shouldFetchBeerDetails($event->target)) {
            $fullBeerUrl = 'https://untappd.com' . $details['beer_url'];

            UntappdBeerDetailPull::dispatch(
                $this->integration,
                $event->target->id,
                $fullBeerUrl
            )->onQueue('pull')->delay(now()->addMinutes(5));
        }
    }

    /**
     * Parse check-in HTML for rating, badges, serving style, and beer URL
     */
    private function parseCheckinDetails(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);

        return [
            'rating' => $this->extractRating($xpath),
            'badges' => $this->extractBadges($xpath),
            'serving_style' => $this->extractServingStyle($xpath),
            'beer_url' => $this->extractBeerUrl($xpath),
        ];
    }

    /**
     * Extract rating from check-in page
     */
    private function extractRating(DOMXPath $xpath): ?float
    {
        // Look for: <div class="caps" data-rating="4.5">
        $nodes = $xpath->query("//div[contains(@class, 'caps')]/@data-rating");

        if ($nodes->length > 0) {
            $rating = floatval($nodes->item(0)->nodeValue);

            return ($rating >= 0 && $rating <= 5) ? $rating : null;
        }

        return null;
    }

    /**
     * Extract badges from check-in page
     */
    private function extractBadges(DOMXPath $xpath): array
    {
        $badges = [];

        // Look for: <div class="badges-unlocked"><span class="badge">
        $badgeNodes = $xpath->query("//div[contains(@class, 'badges-unlocked')]//span[@class='badge']");

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
}
