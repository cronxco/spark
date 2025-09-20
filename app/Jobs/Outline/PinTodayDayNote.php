<?php

namespace App\Jobs\Outline;

use App\Integrations\Outline\OutlineApi;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PinTodayDayNote implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Integration|string $integration) {}

    public function handle(): void
    {
        $integration = $this->integration instanceof Integration
            ? $this->integration
            : Integration::findOrFail($this->integration);

        $api = new OutlineApi($integration);
        $collectionId = (string) (($integration->configuration['daynotes_collection_id'] ?? null)
            ?: config('services.outline.daynotes_collection_id'));

        // Compute today's title (UTC)
        $today = CarbonImmutable::now('UTC');
        $title = $today->format('Y-m-d: l');

        Log::info('PinTodayDayNote: start', [
            'integration_id' => (string) $integration->id,
            'collection_id' => $collectionId,
            'title' => $title,
        ]);

        // Find today's document in the collection
        $documents = $api->listDocuments([
            'collectionId' => $collectionId,
        ]);

        Log::info('PinTodayDayNote: fetched collection documents', [
            'fetched_count' => is_countable($documents) ? count($documents) : 0,
        ]);

        $todayDoc = collect($documents)->firstWhere('title', $title);
        if (! $todayDoc) {
            Log::info('PinTodayDayNote: no document found for today title; exiting');

            return; // Nothing to pin
        }

        Log::info('PinTodayDayNote: found today document', [
            'doc_id' => $todayDoc['id'] ?? null,
            'url' => $todayDoc['url'] ?? null,
        ]);

        // Pins
        $pinsData = $api->listPins(100);
        $pins = (array) ($pinsData['pins'] ?? ($pinsData['data'] ?? []));

        $pinMap = [];
        foreach ($pins as $pin) {
            $docId = $pin['documentId'] ?? null;
            $pinId = $pin['id'] ?? null;
            if ($docId && $pinId) {
                $pinMap[$docId] = $pinId;
            }
        }

        Log::info('PinTodayDayNote: loaded pins', [
            'pin_count' => is_countable($pins) ? count($pins) : 0,
            'mapped' => count($pinMap),
        ]);

        // Unpin all pinned docs that belong to the day notes collection (except today's)
        foreach ($pinMap as $docId => $pinId) {
            if (($todayDoc['id'] ?? null) === $docId) {
                continue;
            }

            try {
                // Get minimal document info to check collection without triggering full processing
                $docInfo = $api->getDocument($docId);
                $doc = $docInfo['data'] ?? $docInfo;
                $docCollectionId = $doc['collectionId'] ?? null;

                if ($docCollectionId === $collectionId) {
                    Log::debug('PinTodayDayNote: unpinning previous day note', [
                        'doc_id' => $docId,
                        'pin_id' => $pinId,
                    ]);

                    $deleteResult = $api->deletePin($pinId);

                    // Verify the pin was actually deleted
                    if ($deleteResult && ! isset($deleteResult['error'])) {
                        Log::debug('PinTodayDayNote: successfully unpinned', [
                            'doc_id' => $docId,
                            'pin_id' => $pinId,
                        ]);
                    } else {
                        Log::warning('PinTodayDayNote: failed to unpin', [
                            'doc_id' => $docId,
                            'pin_id' => $pinId,
                            'result' => $deleteResult,
                        ]);
                    }
                } else {
                    Log::debug('PinTodayDayNote: keeping pin (different collection)', [
                        'doc_id' => $docId,
                        'pin_id' => $pinId,
                        'doc_collection_id' => $docCollectionId,
                    ]);
                }
            } catch (Exception $e) {
                Log::error('PinTodayDayNote: error processing pin', [
                    'doc_id' => $docId,
                    'pin_id' => $pinId,
                    'error' => $e->getMessage(),
                ]);
                // Continue with other pins even if one fails
            }
        }

        // Pin today's note
        try {
            $pinResult = $api->createPin($todayDoc['id']);

            if ($pinResult && ! isset($pinResult['error'])) {
                Log::info('PinTodayDayNote: successfully pinned today note', [
                    'doc_id' => $todayDoc['id'] ?? null,
                    'pin_id' => $pinResult['id'] ?? null,
                ]);
            } else {
                Log::error('PinTodayDayNote: failed to pin today note', [
                    'doc_id' => $todayDoc['id'] ?? null,
                    'result' => $pinResult,
                ]);
            }
        } catch (Exception $e) {
            Log::error('PinTodayDayNote: error pinning today note', [
                'doc_id' => $todayDoc['id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
