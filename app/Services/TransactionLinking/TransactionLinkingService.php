<?php

namespace App\Services\TransactionLinking;

use App\Models\Event;
use App\Models\PendingTransactionLink;
use App\Models\Relationship;
use App\Services\TransactionLinking\Contracts\LinkingStrategy;
use App\Services\TransactionLinking\Strategies\BacsRecordStrategy;
use App\Services\TransactionLinking\Strategies\CrossProviderStrategy;
use App\Services\TransactionLinking\Strategies\ExplicitReferenceStrategy;
use Illuminate\Support\Collection;
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
            } catch (\Exception $e) {
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

        // Check if relationship already exists
        $existingRelationship = Relationship::where('user_id', $userId)
            ->where('from_type', Event::class)
            ->where('from_id', $sourceEvent->id)
            ->where('to_type', Event::class)
            ->where('to_id', $targetEvent->id)
            ->where('type', $link['relationship_type'])
            ->exists();

        if ($existingRelationship) {
            return 'skipped';
        }

        // Check if pending link already exists
        $existingPending = PendingTransactionLink::where('user_id', $userId)
            ->where('source_event_id', $sourceEvent->id)
            ->where('target_event_id', $targetEvent->id)
            ->where('relationship_type', $link['relationship_type'])
            ->exists();

        if ($existingPending) {
            return 'skipped';
        }

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
                'metadata' => [
                    'auto_linked' => true,
                    'detection_strategy' => $strategy->getIdentifier(),
                    'confidence' => $link['confidence'],
                    'matching_criteria' => $link['matching_criteria'],
                ],
            ]);

            return 'created';
        }

        // Create pending link for manual review
        PendingTransactionLink::create([
            'user_id' => $userId,
            'source_event_id' => $sourceEvent->id,
            'target_event_id' => $targetEvent->id,
            'relationship_type' => $link['relationship_type'],
            'confidence' => $link['confidence'],
            'detection_strategy' => $strategy->getIdentifier(),
            'matching_criteria' => $link['matching_criteria'],
            'value' => $link['value'],
            'value_multiplier' => $link['value_multiplier'],
            'value_unit' => $link['value_unit'],
        ]);

        return 'pending';
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
        return [
            'total' => PendingTransactionLink::where('user_id', $userId)->pending()->count(),
            'by_strategy' => PendingTransactionLink::where('user_id', $userId)
                ->pending()
                ->selectRaw('detection_strategy, COUNT(*) as count')
                ->groupBy('detection_strategy')
                ->pluck('count', 'detection_strategy')
                ->toArray(),
            'by_confidence' => [
                'high' => PendingTransactionLink::where('user_id', $userId)
                    ->pending()
                    ->where('confidence', '>=', 80)
                    ->count(),
                'medium' => PendingTransactionLink::where('user_id', $userId)
                    ->pending()
                    ->whereBetween('confidence', [50, 80])
                    ->count(),
                'low' => PendingTransactionLink::where('user_id', $userId)
                    ->pending()
                    ->where('confidence', '<', 50)
                    ->count(),
            ],
        ];
    }
}
