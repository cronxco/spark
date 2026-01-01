<?php

namespace App\Jobs\Outline;

use App\Integrations\Outline\OutlineApi;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PinTodayDayNote implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $uniqueFor = 3600; // 1 hour

    public function __construct(public Integration|string $integration) {}

    public function uniqueId(): string
    {
        $integration = $this->integration instanceof Integration
            ? $this->integration
            : Integration::find($this->integration);

        return 'pin-today-' . $integration->id . '-' . now('UTC')->format('Y-m-d');
    }

    public function handle(): void
    {
        $integration = $this->integration instanceof Integration
            ? $this->integration
            : Integration::findOrFail($this->integration);

        $api = new OutlineApi($integration);
        $collectionId = $api->daynotesCollectionId();

        // Compute today's title (UTC)
        $today = CarbonImmutable::now('UTC');
        $title = $today->format('Y-m-d: l');

        Log::info('PinTodayDayNote: start', [
            'integration_id' => (string) $integration->id,
            'collection_id' => $collectionId,
            'title' => $title,
        ]);

        // Find today's document using efficient search
        $searchResult = $api->searchSingleDocument([
            'collectionId' => $collectionId,
            'query' => $today->format('Y-m-d'), // Search by date for efficiency
        ]);

        if (! $searchResult) {
            Log::info('PinTodayDayNote: no document found for today; exiting');

            return; // Nothing to pin
        }

        // Extract document from search result structure
        $todayDoc = $searchResult['document'] ?? $searchResult;

        // Verify it's the correct document by checking the full title
        if (($todayDoc['title'] ?? '') !== $title) {
            Log::info('PinTodayDayNote: found document but title mismatch; exiting', [
                'found_title' => $todayDoc['title'] ?? 'No title',
                'expected_title' => $title,
            ]);

            return; // Wrong document
        }

        Log::info('PinTodayDayNote: found today document', [
            'doc_id' => $todayDoc['id'] ?? null,
            'url' => $todayDoc['url'] ?? null,
        ]);

        // Get all pins ONCE
        $pinsData = $api->listPins(100);
        $pins = (array) ($pinsData['pins'] ?? ($pinsData['data'] ?? []));

        Log::info('PinTodayDayNote: loaded pins', [
            'total_pins' => count($pins),
        ]);

        // Build map using embedded document data (NO extra getDocument calls!)
        $todayIsAlreadyPinned = false;
        $daynotePinsToUnpin = [];

        foreach ($pins as $pin) {
            $docId = $pin['documentId'] ?? null;
            $pinId = $pin['id'] ?? null;
            $doc = $pin['document'] ?? null; // Use embedded data!

            if (! $docId || ! $pinId || ! $doc) {
                continue;
            }

            // Pattern match daynote titles: "YYYY-MM-DD: DayName"
            $docTitle = $doc['title'] ?? '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}: \w+$/', $docTitle)) {
                if ($docId === ($todayDoc['id'] ?? null)) {
                    $todayIsAlreadyPinned = true;
                    Log::info('PinTodayDayNote: today note already pinned', [
                        'doc_id' => $docId,
                        'title' => $docTitle,
                    ]);
                } else {
                    $daynotePinsToUnpin[$docId] = [
                        'pin_id' => $pinId,
                        'title' => $docTitle,
                    ];
                }
            }
        }

        Log::info('PinTodayDayNote: reconciliation complete', [
            'today_already_pinned' => $todayIsAlreadyPinned,
            'daynotes_to_unpin' => count($daynotePinsToUnpin),
        ]);

        // Check pin limit before attempting to pin
        $maxPinsAllowed = 10;
        if (count($pins) >= $maxPinsAllowed && ! $todayIsAlreadyPinned) {
            Log::error('PinTodayDayNote: at Outline pin limit, cannot pin today', [
                'current_pins' => count($pins),
                'max_allowed' => $maxPinsAllowed,
            ]);

            return;
        }

        // Unpin old daynotes
        $unpinnedCount = 0;
        foreach ($daynotePinsToUnpin as $docId => $data) {
            try {
                $api->deletePin($data['pin_id']);
                $unpinnedCount++;
                Log::debug('PinTodayDayNote: unpinned daynote', [
                    'doc_id' => $docId,
                    'pin_id' => $data['pin_id'],
                    'title' => $data['title'],
                ]);
            } catch (Exception $e) {
                Log::warning('PinTodayDayNote: failed to unpin daynote', [
                    'doc_id' => $docId,
                    'pin_id' => $data['pin_id'],
                    'title' => $data['title'],
                    'error' => $e->getMessage(),
                ]);
                // Continue with other pins even if one fails
            }
        }

        Log::info('PinTodayDayNote: unpinning complete', [
            'unpinned_count' => $unpinnedCount,
            'attempted_count' => count($daynotePinsToUnpin),
        ]);

        // Only pin if not already pinned
        if (! $todayIsAlreadyPinned) {
            try {
                $pinResult = $api->createPin($todayDoc['id']);

                if ($pinResult && ! isset($pinResult['error'])) {
                    Log::info('PinTodayDayNote: successfully pinned today note', [
                        'doc_id' => $todayDoc['id'] ?? null,
                        'pin_id' => $pinResult['id'] ?? null,
                    ]);

                    // Track last pin metadata
                    $integration->update([
                        'configuration' => array_merge($integration->configuration ?? [], [
                            'last_pin_attempt_at' => now()->toISOString(),
                            'last_pin_success_at' => now()->toISOString(),
                            'last_pinned_doc_id' => $todayDoc['id'],
                            'last_pinned_doc_title' => $title,
                        ]),
                    ]);
                } else {
                    Log::error('PinTodayDayNote: failed to pin today note', [
                        'doc_id' => $todayDoc['id'] ?? null,
                        'result' => $pinResult,
                    ]);

                    // Track failed attempt
                    $integration->update([
                        'configuration' => array_merge($integration->configuration ?? [], [
                            'last_pin_attempt_at' => now()->toISOString(),
                        ]),
                    ]);
                }
            } catch (Exception $e) {
                Log::error('PinTodayDayNote: error pinning today note', [
                    'doc_id' => $todayDoc['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);

                // Track failed attempt
                $integration->update([
                    'configuration' => array_merge($integration->configuration ?? [], [
                        'last_pin_attempt_at' => now()->toISOString(),
                    ]),
                ]);
            }
        } else {
            Log::info('PinTodayDayNote: skipping pin (already pinned)');
        }
    }
}
