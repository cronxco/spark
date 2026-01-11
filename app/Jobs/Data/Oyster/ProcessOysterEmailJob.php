<?php

namespace App\Jobs\Data\Oyster;

use App\Integrations\Oyster\OysterCsvParser;
use App\Integrations\Oyster\OysterPdfParser;
use App\Integrations\Oyster\OysterTransportModeDetector;
use App\Integrations\Oyster\TflStationLookup;
use App\Jobs\Concerns\EnhancedIdempotency;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZBateson\MailMimeParser\MailMimeParser;

class ProcessOysterEmailJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes for email processing + TfL API calls

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        public Integration $integration,
        public ?string $s3ObjectKey = null,
        public ?string $rawEmailContent = null
    ) {}

    public function handle(): void
    {
        Log::info('Oyster: Processing Oyster journey email', [
            'integration_id' => $this->integration->id,
            's3_object_key' => $this->s3ObjectKey,
            'has_raw_content' => ! empty($this->rawEmailContent),
        ]);

        try {
            // Get email content
            $emailContent = $this->getEmailContent();

            // Parse email to extract attachments
            $parser = new MailMimeParser;
            $message = $parser->parse($emailContent, false);

            // Extract PDF and CSV content
            $pdfContent = null;
            $csvContent = null;

            foreach ($message->getAllAttachmentParts() as $attachment) {
                $contentType = $attachment->getContentType();
                $filename = $attachment->getFilename() ?? '';

                if ($contentType === 'application/pdf' || str_ends_with(strtolower($filename), '.pdf')) {
                    $pdfContent = $attachment->getContent();
                }

                if ($contentType === 'text/csv' || str_ends_with(strtolower($filename), '.csv')) {
                    $csvContent = $attachment->getContent();
                }
            }

            if (! $csvContent) {
                Log::warning('Oyster: No CSV attachment found in email', [
                    'integration_id' => $this->integration->id,
                ]);

                return;
            }

            // Parse PDF for card number
            $cardNumber = null;
            $statementPeriod = null;

            if ($pdfContent) {
                $pdfParser = new OysterPdfParser;
                $cardNumber = $pdfParser->extractCardNumber($pdfContent);
                $statementPeriod = $pdfParser->extractStatementPeriod($pdfContent);

                Log::info('Oyster: Extracted PDF data', [
                    'card_number' => $cardNumber ? substr($cardNumber, 0, 4) . '****' . substr($cardNumber, -4) : null,
                    'statement_period' => $statementPeriod,
                ]);
            }

            // Get or create Oyster card object
            $oysterCard = $this->getOrCreateOysterCard($cardNumber);

            // Parse CSV
            $csvParser = new OysterCsvParser;
            $parsed = $csvParser->parse($csvContent);

            Log::info('Oyster: Parsed CSV data', [
                'journeys_count' => count($parsed['journeys']),
                'non_journeys_count' => count($parsed['non_journeys']),
            ]);

            // Process journeys
            $this->processJourneys($parsed['journeys'], $oysterCard);

            // Process non-journeys (top-ups, season tickets)
            $this->processNonJourneys($parsed['non_journeys'], $oysterCard);

            // Dispatch job to link journey pairs
            LinkOysterJourneyEventsJob::dispatch($this->integration, $statementPeriod);

            Log::info('Oyster: Successfully processed Oyster email', [
                'integration_id' => $this->integration->id,
                'journeys_processed' => count($parsed['journeys']),
                'non_journeys_processed' => count($parsed['non_journeys']),
            ]);
        } catch (Exception $e) {
            Log::error('Oyster: Failed to process email', [
                'integration_id' => $this->integration->id,
                's3_object_key' => $this->s3ObjectKey,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function uniqueId(): string
    {
        $contentHash = $this->s3ObjectKey
            ? md5($this->s3ObjectKey)
            : md5($this->rawEmailContent ?? '');

        return 'process_oyster_email_' . $this->integration->id . '_' . $contentHash;
    }

    /**
     * Get email content from S3 or raw content
     */
    private function getEmailContent(): string
    {
        if (! empty($this->rawEmailContent)) {
            return $this->rawEmailContent;
        }

        if (! empty($this->s3ObjectKey)) {
            return $this->downloadEmailFromS3($this->s3ObjectKey);
        }

        throw new Exception('No email content or S3 key provided');
    }

    /**
     * Download email file from S3
     */
    private function downloadEmailFromS3(string $objectKey): string
    {
        $disk = Storage::disk('s3-oyster');

        if (! $disk->exists($objectKey)) {
            throw new Exception("Email file not found in S3: {$objectKey}");
        }

        $content = $disk->get($objectKey);

        Log::info('Oyster: Downloaded email from S3', [
            's3_object_key' => $objectKey,
            'size_bytes' => strlen($content),
        ]);

        return $content;
    }

    /**
     * Get or create the Oyster card EventObject
     */
    private function getOrCreateOysterCard(?string $cardNumber): EventObject
    {
        // Create a display name for the card
        $displayName = $cardNumber
            ? 'Oyster Card ****' . substr($cardNumber, -4)
            : 'Oyster Card';

        return EventObject::firstOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'card',
                'type' => 'oyster_card',
                'title' => $displayName,
            ],
            [
                'time' => now(),
                'metadata' => [
                    'card_number_hash' => $cardNumber ? hash('sha256', $cardNumber) : null,
                    'card_last_4' => $cardNumber ? substr($cardNumber, -4) : null,
                ],
            ]
        );
    }

    /**
     * Process journey entries and create events
     */
    private function processJourneys(array $journeys, EventObject $oysterCard): void
    {
        $stationLookup = new TflStationLookup;

        foreach ($journeys as $journey) {
            // Skip if no origin (shouldn't happen but be defensive)
            if (empty($journey['origin'])) {
                continue;
            }

            // Get or create origin station
            $originStation = $stationLookup->getOrCreateStationObject(
                $journey['origin'],
                $this->integration->user_id
            );

            // Create source_id for idempotency
            $touchedInSourceId = 'oyster_in_' . md5(
                $journey['date'] . '|' . $journey['start_time'] . '|' . $journey['origin']
            );

            // Convert fare from pounds to pence (value must be integer)
            $fareInPence = $journey['charge'] ? (int) round($journey['charge'] * 100) : null;

            // Create touched_in event with fare attached
            $touchedInEvent = Event::updateOrCreate(
                [
                    'integration_id' => $this->integration->id,
                    'source_id' => $touchedInSourceId,
                ],
                [
                    'time' => $journey['start_datetime'],
                    'service' => 'oyster',
                    'domain' => 'online',
                    'action' => 'touched_in_at',
                    'actor_id' => $oysterCard->id,
                    'actor_metadata' => [],
                    'target_id' => $originStation->id,
                    'target_metadata' => [],
                    'value' => $fareInPence,
                    'value_multiplier' => 100, // 100 pence = £1
                    'value_unit' => 'GBP',
                    'event_metadata' => [
                        'transport_mode' => $journey['transport_mode'],
                        'transport_mode_display' => OysterTransportModeDetector::getDisplayName($journey['transport_mode']),
                        'balance_after' => $journey['balance'],
                        'raw_action' => $journey['raw_action'],
                        'note' => $journey['note'] ?: null,
                    ],
                ]
            );

            // Inherit location from station
            if ($originStation->location && ! $touchedInEvent->location) {
                $touchedInEvent->inheritLocationFromTarget();
            }

            // Create touched_out event if there's a destination
            if (! empty($journey['destination'])) {
                $destinationStation = $stationLookup->getOrCreateStationObject(
                    $journey['destination'],
                    $this->integration->user_id
                );

                $touchedOutSourceId = 'oyster_out_' . md5(
                    $journey['date'] . '|' . ($journey['end_time'] ?? $journey['start_time']) . '|' . $journey['destination']
                );

                $touchedOutEvent = Event::updateOrCreate(
                    [
                        'integration_id' => $this->integration->id,
                        'source_id' => $touchedOutSourceId,
                    ],
                    [
                        'time' => $journey['end_datetime'] ?? $journey['start_datetime'],
                        'service' => 'oyster',
                        'domain' => 'online',
                        'action' => 'touched_out_at',
                        'actor_id' => $oysterCard->id,
                        'actor_metadata' => [],
                        'target_id' => $destinationStation->id,
                        'target_metadata' => [],
                        'value' => null, // Fare is on touched_in
                        'value_multiplier' => 100, // 100 pence = £1
                        'value_unit' => 'GBP',
                        'event_metadata' => [
                            'transport_mode' => $journey['transport_mode'],
                            'transport_mode_display' => OysterTransportModeDetector::getDisplayName($journey['transport_mode']),
                            'balance_after' => $journey['balance'],
                            'raw_action' => $journey['raw_action'],
                            'note' => $journey['note'] ?: null,
                        ],
                    ]
                );

                // Inherit location from station
                if ($destinationStation->location && ! $touchedOutEvent->location) {
                    $touchedOutEvent->inheritLocationFromTarget();
                }
            }
        }
    }

    /**
     * Process non-journey entries (top-ups, season tickets, etc.)
     */
    private function processNonJourneys(array $nonJourneys, EventObject $oysterCard): void
    {
        $stationLookup = new TflStationLookup;

        foreach ($nonJourneys as $entry) {
            $actionType = $entry['action_type'];

            // Skip unknown action types
            if (! $actionType) {
                continue;
            }

            $sourceId = 'oyster_' . $actionType . '_' . md5(
                $entry['date'] . '|' . $entry['time'] . '|' . $entry['raw_action']
            );

            // Determine value based on action type (convert from pounds to pence)
            $value = null;
            if ($actionType === 'topped_up_balance' && $entry['credit']) {
                $value = (int) round($entry['credit'] * 100); // Convert to pence
            } elseif ($actionType === 'received_refund' && $entry['credit']) {
                $value = (int) round($entry['credit'] * 100); // Refund credit
            } elseif ($actionType === 'fare_adjustment') {
                // Fare adjustments could be credits or charges
                if ($entry['credit']) {
                    $value = (int) round($entry['credit'] * 100);
                } elseif ($entry['charge']) {
                    $value = (int) round($entry['charge'] * 100);
                }
            }

            // Get or create station if present
            $targetId = null;
            if (! empty($entry['station'])) {
                $station = $stationLookup->getOrCreateStationObject(
                    $entry['station'],
                    $this->integration->user_id
                );
                $targetId = $station->id;
            }

            Event::updateOrCreate(
                [
                    'integration_id' => $this->integration->id,
                    'source_id' => $sourceId,
                ],
                [
                    'time' => $entry['datetime'],
                    'service' => 'oyster',
                    'domain' => 'online',
                    'action' => $actionType,
                    'actor_id' => $oysterCard->id,
                    'actor_metadata' => [],
                    'target_id' => $targetId,
                    'target_metadata' => [],
                    'value' => $value,
                    'value_multiplier' => 100, // 100 pence = £1
                    'value_unit' => 'GBP',
                    'event_metadata' => [
                        'station' => $entry['station'] ?? null,
                        'balance_after' => $entry['balance'],
                        'raw_action' => $entry['raw_action'],
                        'note' => $entry['note'] ?: null,
                    ],
                ]
            );
        }
    }
}
