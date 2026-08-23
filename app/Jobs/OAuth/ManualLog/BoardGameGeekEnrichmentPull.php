<?php

namespace App\Jobs\OAuth\ManualLog;

use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\ManualLog\BoardGameGeekEnrichmentData;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

/**
 * Looks up richer detail (image, player count, playing time, BGG rating)
 * for a manually-logged board game, via BoardGameGeek's free, public,
 * no-auth XML API - the same "ingest thin, enrich after" pattern used for
 * Home Assistant watches, dispatched once per manual "played_board_game"
 * entry by ManualLogPlugin.
 */
class BoardGameGeekEnrichmentPull extends BaseFetchJob
{
    public function __construct(
        Integration $integration,
        public string $eventId,
        public string $gameName,
    ) {
        parent::__construct($integration);
    }

    protected function getServiceName(): string
    {
        return 'manual_log';
    }

    protected function getJobType(): string
    {
        return 'board_game_enrichment';
    }

    protected function fetchData(): array
    {
        $baseUrl = config('services.boardgamegeek.base_url');

        $searchResponse = Http::get("{$baseUrl}/search", [
            'query' => $this->gameName,
            'type' => 'boardgame',
        ]);

        if (! $searchResponse->successful()) {
            Log::warning('Board game enrichment: BGG search failed', [
                'event_id' => $this->eventId,
                'status' => $searchResponse->status(),
            ]);

            return ['event_id' => $this->eventId, 'game' => null];
        }

        $bggId = $this->firstSearchResultId($searchResponse->body());

        if ($bggId === null) {
            return ['event_id' => $this->eventId, 'game' => null];
        }

        $detailResponse = Http::get("{$baseUrl}/thing", ['id' => $bggId]);

        if (! $detailResponse->successful()) {
            return ['event_id' => $this->eventId, 'game' => null];
        }

        $game = $this->parseThingResponse($detailResponse->body(), $bggId);

        return ['event_id' => $this->eventId, 'game' => $game];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        if (empty($rawData['game'])) {
            return;
        }

        BoardGameGeekEnrichmentData::dispatch($this->integration, $rawData);
    }

    private function firstSearchResultId(string $xmlBody): ?string
    {
        $xml = @simplexml_load_string($xmlBody);

        if ($xml === false || ! isset($xml->item)) {
            return null;
        }

        foreach ($xml->item as $item) {
            return (string) $item['id'];
        }

        return null;
    }

    /**
     * @return array{bgg_id: string, name: ?string, description: ?string, year_published: ?string, min_players: ?string, max_players: ?string, playing_time: ?string, image: ?string, rating: ?string}|null
     */
    private function parseThingResponse(string $xmlBody, string $bggId): ?array
    {
        $xml = @simplexml_load_string($xmlBody);

        if ($xml === false || ! isset($xml->item)) {
            return null;
        }

        $item = $xml->item;

        return [
            'bgg_id' => $bggId,
            'name' => $this->primaryName($item),
            'description' => isset($item->description)
                ? html_entity_decode(strip_tags((string) $item->description), ENT_QUOTES)
                : null,
            'year_published' => $this->attributeValue($item, 'yearpublished'),
            'min_players' => $this->attributeValue($item, 'minplayers'),
            'max_players' => $this->attributeValue($item, 'maxplayers'),
            'playing_time' => $this->attributeValue($item, 'playingtime'),
            'image' => isset($item->image) ? trim((string) $item->image) : null,
            'rating' => isset($item->statistics->ratings->average)
                ? (string) $item->statistics->ratings->average['value']
                : null,
        ];
    }

    private function primaryName(SimpleXMLElement $item): ?string
    {
        foreach ($item->name as $name) {
            if ((string) $name['type'] === 'primary') {
                return (string) $name['value'];
            }
        }

        return null;
    }

    private function attributeValue(SimpleXMLElement $item, string $tag): ?string
    {
        if (! isset($item->{$tag})) {
            return null;
        }

        $value = (string) $item->{$tag}['value'];

        return $value === '' ? null : $value;
    }
}
