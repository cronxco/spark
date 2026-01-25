<?php

namespace App\Services\TransactionLinking\Strategies;

use App\Models\Event;
use App\Services\TransactionLinking\Contracts\LinkingStrategy;
use Illuminate\Support\Collection;

/**
 * Strategy for finding links between direct debits and pot withdrawals via BACS record IDs.
 *
 * Handles: bacs_record_id embedded in external_id for pot withdrawals
 */
class BacsRecordStrategy implements LinkingStrategy
{
    public function getIdentifier(): string
    {
        return 'bacs_record';
    }

    public function getName(): string
    {
        return 'BACS Record Matching';
    }

    public function canProcess(Event $event): bool
    {
        // Can process Monzo transactions with BACS-related metadata
        if ($event->service !== 'monzo') {
            return false;
        }

        $metadata = $event->event_metadata ?? [];
        $rawMetadata = data_get($metadata, 'raw.metadata', []);

        // Check if this is a direct debit with bacs_record_id
        $hasBacsRecordId = ! empty($rawMetadata['bacs_record_id']);

        // Or a pot transaction with external_id containing BACS reference
        $externalId = $rawMetadata['external_id'] ?? '';
        $hasBacsInExternalId = str_contains($externalId, 'bacsrcd_');

        return $hasBacsRecordId || $hasBacsInExternalId;
    }

    public function findLinks(Event $event): Collection
    {
        $links = collect();
        $metadata = $event->event_metadata ?? [];
        $rawMetadata = data_get($metadata, 'raw.metadata', []);

        // Case 1: This is a direct debit with bacs_record_id
        $bacsRecordId = $rawMetadata['bacs_record_id'] ?? null;
        if ($bacsRecordId) {
            $potWithdrawals = $this->findPotWithdrawalsForBacsRecord($event, $bacsRecordId);
            $links = $links->merge($potWithdrawals);
        }

        // Case 2: This is a pot transaction referencing a BACS record
        $externalId = $rawMetadata['external_id'] ?? '';
        if (str_contains($externalId, 'bacsrcd_')) {
            $directDebits = $this->findDirectDebitsForExternalId($event, $externalId);
            $links = $links->merge($directDebits);
        }

        return $links->unique(fn ($link) => $link['target_event']->id);
    }

    /**
     * Find pot withdrawals that reference the given BACS record ID.
     */
    private function findPotWithdrawalsForBacsRecord(Event $event, string $bacsRecordId): Collection
    {
        $links = collect();

        // Search for pot transactions with external_id containing this bacs_record_id
        $potWithdrawals = Event::whereHas('integration', function ($q) use ($event) {
            $q->where('user_id', $event->integration->user_id);
        })
            ->where('id', '!=', $event->id)
            ->where('service', 'monzo')
            ->whereIn('action', ['pot_withdrawal_to', 'pot_transfer_from'])
            ->whereRaw("event_metadata->'raw'->'metadata'->>'external_id' LIKE ?", ['%' . $bacsRecordId . '%'])
            ->get();

        foreach ($potWithdrawals as $potWithdrawal) {
            $links->push([
                'target_event' => $potWithdrawal,
                'relationship_type' => 'funded_by',
                'confidence' => 100.0,
                'matching_criteria' => [
                    'type' => 'bacs_record_in_external_id',
                    'bacs_record_id' => $bacsRecordId,
                    'external_id' => data_get($potWithdrawal->event_metadata, 'raw.metadata.external_id'),
                ],
                'value' => $potWithdrawal->value,
                'value_multiplier' => $potWithdrawal->value_multiplier,
                'value_unit' => $potWithdrawal->value_unit,
            ]);
        }

        return $links;
    }

    /**
     * Find direct debits that match the BACS record ID in the external_id.
     */
    private function findDirectDebitsForExternalId(Event $event, string $externalId): Collection
    {
        $links = collect();

        // Extract the bacs_record_id from external_id
        // Format is like: "dd-withdrawal:bacsrcd_XXXX#pot_XXXX#bacspaymentevent_XXXX"
        if (preg_match('/bacsrcd_[A-Za-z0-9]+/', $externalId, $matches)) {
            $bacsRecordId = $matches[0];

            $directDebits = Event::whereHas('integration', function ($q) use ($event) {
                $q->where('user_id', $event->integration->user_id);
            })
                ->where('id', '!=', $event->id)
                ->where('service', 'monzo')
                ->where('action', 'direct_debit_to')
                ->whereRaw("event_metadata->'raw'->'metadata'->>'bacs_record_id' = ?", [$bacsRecordId])
                ->get();

            foreach ($directDebits as $directDebit) {
                $links->push([
                    'target_event' => $directDebit,
                    'relationship_type' => 'funded_by',
                    'confidence' => 100.0,
                    'matching_criteria' => [
                        'type' => 'bacs_record_match',
                        'bacs_record_id' => $bacsRecordId,
                        'external_id' => $externalId,
                    ],
                    'value' => $event->value,
                    'value_multiplier' => $event->value_multiplier,
                    'value_unit' => $event->value_unit,
                ]);
            }
        }

        return $links;
    }
}
