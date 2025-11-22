<?php

namespace App\Services\TransactionLinking\Strategies;

use App\Models\Event;
use App\Services\TransactionLinking\Contracts\LinkingStrategy;
use Illuminate\Support\Collection;

/**
 * Strategy for finding links via explicit transaction ID references in metadata.
 *
 * Handles: transaction_id, triggered_by, coin_jar_transaction
 */
class ExplicitReferenceStrategy implements LinkingStrategy
{
    /**
     * Metadata paths to check for transaction ID references.
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
        'coin_jar_transaction' => [
            'relationship_type' => 'triggered_by',
            'paths' => [
                'raw.metadata.coin_jar_transaction',
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

        // Also check reverse: does this event's source_id appear in other events' metadata?
        $reverseLinks = $this->findReverseLinks($event);
        $links = $links->merge($reverseLinks);

        return $links->unique(fn ($link) => $link['target_event']->id);
    }

    /**
     * Find events that reference this event's source_id in their metadata.
     */
    private function findReverseLinks(Event $event): Collection
    {
        $links = collect();
        $sourceId = $event->source_id;

        if (! $sourceId) {
            return $links;
        }

        // Find events that might reference this one via coin_jar_transaction
        $coinJarEvents = Event::whereHas('integration', function ($q) use ($event) {
            $q->where('user_id', $event->integration->user_id);
        })
            ->where('id', '!=', $event->id)
            ->whereRaw("event_metadata->'raw'->'metadata'->>'coin_jar_transaction' = ?", [$sourceId])
            ->get();

        foreach ($coinJarEvents as $coinJarEvent) {
            $links->push([
                'target_event' => $coinJarEvent,
                'relationship_type' => 'triggered_by',
                'confidence' => 100.0,
                'matching_criteria' => [
                    'type' => 'coin_jar_reverse',
                    'path' => 'raw.metadata.coin_jar_transaction',
                    'referenced_id' => $sourceId,
                ],
                'value' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ]);
        }

        return $links;
    }
}
