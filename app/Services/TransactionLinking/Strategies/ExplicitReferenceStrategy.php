<?php

namespace App\Services\TransactionLinking\Strategies;

use App\Models\Event;
use App\Services\TransactionLinking\Contracts\LinkingStrategy;
use Illuminate\Support\Collection;

/**
 * Strategy for finding links via explicit transaction ID references in metadata.
 *
 * Handles: transaction_id, triggered_by
 */
class ExplicitReferenceStrategy implements LinkingStrategy
{
    /**
     * Metadata paths to check for transaction ID references.
     *
     * Note: We intentionally DO NOT include `coin_jar_transaction` here.
     * That field appears on the TRIGGERING transaction (e.g., card payment)
     * and points to the TRIGGERED transaction (e.g., pot transfer).
     * The triggered transaction has `triggered_by` pointing back, which
     * gives us the correct direction: triggered → triggering (triggered_by).
     * Including both would create duplicate/circular relationships.
     */
    private const REFERENCE_PATHS = [
        'transaction_id' => [
            'relationship_type' => 'triggered_by',
            'paths' => [
                'raw.metadata.transaction_id',
            ],
        ],
        'triggered_by' => [
            'relationship_type' => 'triggered_by',
            'paths' => [
                'raw.metadata.triggered_by',
            ],
        ],
    ];

    public function getIdentifier(): string
    {
        return 'explicit_reference';
    }

    public function getName(): string
    {
        return 'Explicit Reference';
    }

    public function canProcess(Event $event): bool
    {
        // Can process any Monzo transaction with metadata
        return $event->service === 'monzo' && ! empty($event->event_metadata);
    }

    public function findLinks(Event $event): Collection
    {
        $links = collect();
        $metadata = $event->event_metadata ?? [];

        foreach (self::REFERENCE_PATHS as $refType => $config) {
            foreach ($config['paths'] as $path) {
                $referencedId = data_get($metadata, $path);

                if (! $referencedId || ! is_string($referencedId)) {
                    continue;
                }

                // Skip if it's not a transaction ID format (e.g., "direct-debit" is not a tx ID)
                if (! str_starts_with($referencedId, 'tx_')) {
                    continue;
                }

                // Find the referenced event
                $targetEvent = Event::where('source_id', $referencedId)
                    ->whereHas('integration', function ($q) use ($event) {
                        $q->where('user_id', $event->integration->user_id);
                    })
                    ->first();

                if ($targetEvent && $targetEvent->id !== $event->id) {
                    $links->push([
                        'target_event' => $targetEvent,
                        'relationship_type' => $config['relationship_type'],
                        'confidence' => 100.0,
                        'matching_criteria' => [
                            'type' => $refType,
                            'path' => $path,
                            'referenced_id' => $referencedId,
                        ],
                        'value' => null,
                        'value_multiplier' => null,
                        'value_unit' => null,
                    ]);
                }
            }
        }

        return $links->unique(fn ($link) => $link['target_event']->id);
    }
}
