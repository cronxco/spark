<?php

namespace App\Services\TransactionLinking;

use App\Models\Event;
use App\Models\Relationship;
use App\Services\TransactionLinking\Contracts\LinkingStrategy;
use App\Services\TransactionLinking\Strategies\BacsRecordStrategy;
use App\Services\TransactionLinking\Strategies\CrossProviderStrategy;
use App\Services\TransactionLinking\Strategies\ExplicitReferenceStrategy;
use Exception;
use Illuminate\Support\Facades\Log;

class TransactionLinkingService
{
    /**
     * Default confidence threshold for auto-approval.
     */
    public const DEFAULT_AUTO_APPROVE_THRESHOLD = 85.0;

    /**
     * Registered linking strategies.
     *
     * @var array<LinkingStrategy>
     */
    private array $strategies = [];

    public function __construct()
    {
        // Register default strategies
        $this->registerStrategy(new ExplicitReferenceStrategy);
        $this->registerStrategy(new BacsRecordStrategy);
        $this->registerStrategy(new CrossProviderStrategy);
    }

    /**
     * Register a linking strategy.
     */
    public function registerStrategy(LinkingStrategy $strategy): self
    {
        $this->strategies[$strategy->getIdentifier()] = $strategy;

        return $this;
    }

    /**
     * Get all registered strategies.
     *
     * @return array<string, LinkingStrategy>
     */
    public function getStrategies(): array
    {
        return $this->strategies;
    }

    /**
     * Find and process potential links for an event.
     *
     * @return array{created: int, pending: int, skipped: int}
     */
    public function processEvent(Event $event, float $autoApproveThreshold = self::DEFAULT_AUTO_APPROVE_THRESHOLD): array
    {
        $stats = ['created' => 0, 'pending' => 0, 'skipped' => 0];

        // Ensure the event has integration loaded
        $event->loadMissing('integration');

        if (! $event->integration) {
            return $stats;
        }

        $userId = $event->integration->user_id;

        foreach ($this->strategies as $strategy) {
            if (! $strategy->canProcess($event)) {
                continue;
            }

            try {
                $links = $strategy->findLinks($event);

                foreach ($links as $link) {
                    $result = $this->processLink($event, $link, $userId, $strategy, $autoApproveThreshold);
                    $stats[$result]++;
                }
            } catch (Exception $e) {
                Log::error('Transaction linking strategy failed', [
                    'strategy' => $strategy->getIdentifier(),
                    'event_id' => $event->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Process all events for a user (batch operation).
     *
     * @return array{created: int, pending: int, skipped: int, processed: int}
     */
    public function processAllEventsForUser(
        string $userId,
        ?int $limit = null,
        float $autoApproveThreshold = self::DEFAULT_AUTO_APPROVE_THRESHOLD
    ): array {
        $stats = ['created' => 0, 'pending' => 0, 'skipped' => 0, 'processed' => 0];

        $query = Event::whereHas('integration', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })
            ->where('domain', 'money')
            ->orderBy('time', 'desc');

        if ($limit) {
            $query->limit($limit);
        }

        $query->chunk(100, function ($events) use (&$stats, $autoApproveThreshold) {
            foreach ($events as $event) {
                $result = $this->processEvent($event, $autoApproveThreshold);
                $stats['created'] += $result['created'];
                $stats['pending'] += $result['pending'];
                $stats['skipped'] += $result['skipped'];
                $stats['processed']++;
            }
        });

        return $stats;
    }

    /**
     * Get statistics about pending links for a user.
     */
    public function getPendingStats(string $userId): array
    {
        $baseQuery = Relationship::where('user_id', $userId)
            ->where('from_type', Event::class)
            ->where('to_type', Event::class)
            ->pending();

        return [
            'total' => (clone $baseQuery)->count(),
            'by_strategy' => (clone $baseQuery)
                ->selectRaw("metadata->>'detection_strategy' as strategy, COUNT(*) as count")
                ->groupByRaw("metadata->>'detection_strategy'")
                ->pluck('count', 'strategy')
                ->toArray(),
            'by_confidence' => [
                'high' => (clone $baseQuery)->aboveConfidence(80)->count(),
                'medium' => (clone $baseQuery)
                    ->whereRaw("(metadata->>'confidence')::numeric >= 50")
                    ->whereRaw("(metadata->>'confidence')::numeric < 80")
                    ->count(),
                'low' => (clone $baseQuery)
                    ->whereRaw("(metadata->>'confidence')::numeric < 50")
                    ->count(),
            ],
        ];
    }

    /**
     * Process a single potential link.
     */
    private function processLink(
        Event $sourceEvent,
        array $link,
        string $userId,
        LinkingStrategy $strategy,
        float $autoApproveThreshold
    ): string {
        $targetEvent = $link['target_event'];

        // Check if relationship already exists (either direction, confirmed or pending)
        $existingRelationship = Relationship::where('user_id', $userId)
            ->where('type', $link['relationship_type'])
            ->betweenEvents($sourceEvent->id, $targetEvent->id)
            ->exists();

        if ($existingRelationship) {
            return 'skipped';
        }

        // Build metadata for the relationship
        $metadata = [
            'auto_linked' => true,
            'detection_strategy' => $strategy->getIdentifier(),
            'confidence' => $link['confidence'],
            'matching_criteria' => $link['matching_criteria'],
        ];

        // Auto-approve high confidence links
        if ($link['confidence'] >= $autoApproveThreshold) {
            Relationship::createRelationship([
                'user_id' => $userId,
                'from_type' => Event::class,
                'from_id' => $sourceEvent->id,
                'to_type' => Event::class,
                'to_id' => $targetEvent->id,
                'type' => $link['relationship_type'],
                'value' => $link['value'],
                'value_multiplier' => $link['value_multiplier'],
                'value_unit' => $link['value_unit'],
                'metadata' => $metadata,
            ]);

            return 'created';
        }

        // Create pending relationship for manual review
        $metadata['pending'] = true;

        Relationship::createRelationship([
            'user_id' => $userId,
            'from_type' => Event::class,
            'from_id' => $sourceEvent->id,
            'to_type' => Event::class,
            'to_id' => $targetEvent->id,
            'type' => $link['relationship_type'],
            'value' => $link['value'],
            'value_multiplier' => $link['value_multiplier'],
            'value_unit' => $link['value_unit'],
            'metadata' => $metadata,
        ]);

        return 'pending';
    }
}
