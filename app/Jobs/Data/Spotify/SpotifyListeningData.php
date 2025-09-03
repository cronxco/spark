<?php

namespace App\Jobs\Data\Spotify;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use Exception;
use Illuminate\Support\Facades\Log;

class SpotifyListeningData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'spotify';
    }

    protected function getJobType(): string
    {
        return 'listening';
    }

    protected function process(): void
    {
        $listeningData = $this->rawData;

        // Process currently playing track
        if (! empty($listeningData['currently_playing'])) {
            try {
                $this->processTrackPlay($listeningData['currently_playing'], 'currently_playing');
            } catch (Exception $e) {
                Log::error('Spotify: Failed to process currently playing track', [
                    'integration_id' => $this->integration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Process recently played tracks
        if (! empty($listeningData['recently_played'])) {
            foreach ($listeningData['recently_played'] as $playedItem) {
                try {
                    $this->processTrackPlay($playedItem, 'recently_played');
                } catch (Exception $e) {
                    Log::error('Spotify: Failed to process recently played track', [
                        'integration_id' => $this->integration->id,
                        'track_id' => $playedItem['track']['id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function processTrackPlay(array $trackData, string $source): void
    {
        // Extract track information based on source
        if ($source === 'currently_playing') {
            $track = $trackData['item'];
            $playedAt = now(); // Currently playing doesn't have a played_at timestamp
            $trackId = $track['id'];
        } elseif ($source === 'recently_played') {
            $track = $trackData['track'];
            $playedAt = $trackData['played_at'];
            $trackId = $track['id'];
        } else {
            throw new Exception("Unknown track source: {$source}");
        }

        // Generate consistent source ID to prevent duplicates
        $sourceId = $this->generateTrackPlaySourceId($trackId, $playedAt);

        // Check if this track play already exists
        $existingEvent = Event::where('integration_id', $this->integration->id)
            ->where('source_id', $sourceId)
            ->first();

        if ($existingEvent) {
            Log::debug('Spotify: Track play already exists, skipping', [
                'integration_id' => $this->integration->id,
                'source_id' => $sourceId,
                'track_name' => $track['name'] ?? 'Unknown',
            ]);

            return;
        }

        Log::info('Spotify: Processing track play', [
            'integration_id' => $this->integration->id,
            'track_name' => $track['name'] ?? 'Unknown',
            'artist_name' => $track['artists'][0]['name'] ?? 'Unknown',
            'source' => $source,
        ]);

        // Create or update track object
        $trackObject = $this->upsertTrackObject($track);

        // Create or update artist objects
        $artistObjects = $this->upsertArtistObjects($track['artists'] ?? []);

        // Create or update album object
        $albumObject = null;
        if (! empty($track['album'])) {
            $albumObject = $this->upsertAlbumObject($track['album']);
        }

        // Create the track play event
        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $playedAt,
            'integration_id' => $this->integration->id,
            'actor_id' => $this->getUserObject()->id,
            'service' => 'spotify',
            'domain' => 'media',
            'action' => 'played',
            'value' => 1,
            'value_multiplier' => 1,
            'value_unit' => 'play',
            'event_metadata' => [
                'source' => $source,
                'track_id' => $trackId,
                'track_name' => $track['name'] ?? null,
                'artist_names' => array_column($track['artists'] ?? [], 'name'),
                'album_name' => $track['album']['name'] ?? null,
                'duration_ms' => $track['duration_ms'] ?? null,
                'popularity' => $track['popularity'] ?? null,
                'external_urls' => $track['external_urls'] ?? null,
            ],
            'target_id' => $trackObject->id,
        ]);

        // Store additional track information in metadata (original simple design)
        $metadata = $event->event_metadata ?? [];
        $metadata['artists'] = $artistObjects->pluck('id')->toArray();
        if ($albumObject) {
            $metadata['album'] = $albumObject->id;
        }
        $event->event_metadata = $metadata;
        $event->save();

        // Add tags based on configuration and track data
        $this->addTrackTags($event, $track, $this->integration->configuration ?? []);

        // Add blocks for additional information
        $this->addTrackBlocks($event, $track, $source);
    }

    private function generateTrackPlaySourceId(string $trackId, $playedAt): string
    {
        $timestamp = is_string($playedAt) ? $playedAt : $playedAt->toISOString();

        return 'spotify_play_' . $trackId . '_' . md5($timestamp);
    }

    private function upsertTrackObject(array $track): EventObject
    {
        return EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'track',
                'type' => 'spotify_track',
                'title' => $track['name'],
            ],
            [
                'user_id' => $this->integration->user_id,
                'content' => $track['name'],
                'metadata' => [
                    'spotify_id' => $track['id'],
                    'duration_ms' => $track['duration_ms'] ?? null,
                    'popularity' => $track['popularity'] ?? null,
                    'explicit' => $track['explicit'] ?? null,
                    'preview_url' => $track['preview_url'] ?? null,
                    'external_urls' => $track['external_urls'] ?? null,
                    'raw_track_data' => $track,
                ],
                'url' => $track['external_urls']['spotify'] ?? null,
            ]
        );
    }

    private function upsertArtistObjects(array $artists): array
    {
        $artistObjects = [];

        foreach ($artists as $artist) {
            $artistObject = EventObject::updateOrCreate(
                [
                    'user_id' => $this->integration->user_id,
                    'concept' => 'artist',
                    'type' => 'spotify_artist',
                    'title' => $artist['name'],
                ],
                [
                    'user_id' => $this->integration->user_id,
                    'content' => $artist['name'],
                    'metadata' => [
                        'spotify_id' => $artist['id'],
                        'external_urls' => $artist['external_urls'] ?? null,
                        'raw_artist_data' => $artist,
                    ],
                    'url' => $artist['external_urls']['spotify'] ?? null,
                ]
            );

            $artistObjects[] = $artistObject;
        }

        return $artistObjects;
    }

    private function upsertAlbumObject(array $album): EventObject
    {
        return EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'album',
                'type' => 'spotify_album',
                'title' => $album['name'],
            ],
            [
                'user_id' => $this->integration->user_id,
                'content' => $album['name'],
                'metadata' => [
                    'spotify_id' => $album['id'],
                    'album_type' => $album['album_type'] ?? null,
                    'total_tracks' => $album['total_tracks'] ?? null,
                    'release_date' => $album['release_date'] ?? null,
                    'external_urls' => $album['external_urls'] ?? null,
                    'images' => $album['images'] ?? null,
                    'raw_album_data' => $album,
                ],
                'url' => $album['external_urls']['spotify'] ?? null,
                'image_url' => $album['images'][0]['url'] ?? null,
            ]
        );
    }

    private function getUserObject(): EventObject
    {
        return EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'user',
                'type' => 'spotify_user',
                'title' => 'Spotify User',
            ],
            [
                'content' => 'Spotify User',
                'metadata' => [
                    'account_id' => $this->integration->group?->account_id ?? $this->integration->account_id,
                ],
            ]
        );
    }

    private function addTrackTags(Event $event, array $track, array $config): void
    {
        $tags = [
            'spotify',
            'media',
            'music',
            'played',
        ];

        // Add genre tags if enabled in configuration
        if (! empty($config['auto_tag_genres'])) {
            // Note: Spotify API doesn't provide genre info in track responses
            // This would require additional API calls to get artist genres
        }

        // Add artist name tags if enabled in configuration
        if (! empty($config['auto_tag_artists'])) {
            foreach ($track['artists'] ?? [] as $artist) {
                $tags[] = 'artist_' . str_replace(' ', '_', strtolower($artist['name']));
            }
        }

        // Add album name tag
        if (! empty($track['album']['name'])) {
            $tags[] = 'album_' . str_replace(' ', '_', strtolower($track['album']['name']));
        }

        $event->syncTags($tags);
    }

    private function addTrackBlocks(Event $event, array $track, string $source): void
    {
        $config = $this->integration->configuration ?? [];

        // Add album artwork block if enabled and available
        if (! empty($config['include_album_art']) && ! empty($track['album']['images'])) {
            $images = $track['album']['images'];
            usort($images, fn ($a, $b) => $b['height'] - $a['height']); // Sort by height descending

            $event->blocks()->create([
                'time' => $event->time,
                'block_type' => 'album_art',
                'title' => 'Album Artwork',
                'metadata' => [
                    'album_name' => $track['album']['name'] ?? null,
                    'images' => $images,
                ],
                'url' => $images[0]['url'] ?? null,
                'media_url' => $images[0]['url'] ?? null,
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
            ]);
        }

        // Add track information block
        $event->blocks()->create([
            'time' => $event->time,
            'block_type' => 'track_info',
            'title' => 'Track Information',
            'metadata' => [
                'track_name' => $track['name'] ?? null,
                'artist_names' => array_column($track['artists'] ?? [], 'name'),
                'album_name' => $track['album']['name'] ?? null,
                'duration_ms' => $track['duration_ms'] ?? null,
                'popularity' => $track['popularity'] ?? null,
                'source' => $source,
            ],
            'value' => null,
            'value_multiplier' => 1,
            'value_unit' => null,
        ]);
    }
}
