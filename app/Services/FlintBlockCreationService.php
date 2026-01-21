<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Support\Str;

class FlintBlockCreationService
{
    /**
     * Create insight blocks from domain agent response
     */
    public function createDomainInsightBlocks(User $user, string $domain, array $insights, Event $flintEvent): array
    {
        $toolSpan = start_ai_tool_span('create_domain_insight_blocks', [
            'domain' => $domain,
            'insight_count' => count($insights['insights'] ?? []),
        ]);

        $blocks = [];

        foreach ($insights['insights'] ?? [] as $insight) {
            $block = $this->createInsightBlock(
                $user,
                $flintEvent,
                "flint_{$domain}_insight",
                $insight
            );

            if ($block) {
                $blocks[] = $block;
            }
        }

        finish_ai_tool_span($toolSpan, ['blocks_created' => count($blocks)]);

        return $blocks;
    }

    /**
     * Create cross-domain insight blocks
     */
    public function createCrossDomainBlocks(User $user, array $observations, Event $flintEvent): array
    {
        $toolSpan = start_ai_tool_span('create_cross_domain_blocks', [
            'observation_count' => count($observations),
        ]);

        $blocks = [];

        foreach ($observations as $observation) {
            $block = Block::create([
                'id' => Str::uuid(),
                'event_id' => $flintEvent->id,
                'block_type' => 'flint_cross_domain_insight',
                'time' => now(),
                'title' => 'Cross-Domain Insight',
                'value' => (int) (($observation['confidence'] ?? 0.7) * 100),
                'value_multiplier' => 100,
                'value_unit' => 'confidence',
                'metadata' => [
                    'domains' => $observation['domains'] ?? [],
                    'observation' => $observation['observation'] ?? '',
                    'confidence' => $observation['confidence'] ?? 0.7,
                    'generated_at' => now()->toIso8601String(),
                ],
            ]);

            $blocks[] = $block;
        }

        finish_ai_tool_span($toolSpan, ['blocks_created' => count($blocks)]);

        return $blocks;
    }

    /**
     * Create pattern detection blocks
     */
    public function createPatternBlock(User $user, array $patternData, Event $flintEvent): Block
    {
        return Block::create([
            'id' => Str::uuid(),
            'event_id' => $flintEvent->id,
            'block_type' => 'flint_pattern_detected',
            'time' => now(),
            'title' => $patternData['title'] ?? 'Detected Pattern',
            'value' => (int) (($patternData['confidence'] ?? 0.7) * 100),
            'value_multiplier' => 100,
            'value_unit' => 'confidence',
            'metadata' => [
                'pattern_type' => $patternData['pattern_type'] ?? 'correlation',
                'description' => $patternData['description'] ?? '',
                'domains' => $patternData['domains'] ?? [],
                'occurrences' => $patternData['occurrences'] ?? [],
                'supporting_evidence' => $patternData['supporting_evidence'] ?? [],
                'confidence' => $patternData['confidence'] ?? 0.7,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Create prioritized action blocks
     */
    public function createActionBlocks(User $user, array $actions, Event $flintEvent): array
    {
        $toolSpan = start_ai_tool_span('create_action_blocks', [
            'action_count' => count($actions),
        ]);

        $blocks = [];

        foreach ($actions as $index => $action) {
            $priority = match ($action['priority'] ?? 'medium') {
                'high' => 3,
                'medium' => 2,
                'low' => 1,
                default => 2,
            };

            $block = Block::create([
                'id' => Str::uuid(),
                'event_id' => $flintEvent->id,
                'block_type' => 'flint_prioritized_action',
                'time' => now(),
                'title' => $action['title'] ?? 'Action Required',
                'value' => $priority,
                'value_unit' => 'priority',
                'metadata' => [
                    'description' => $action['description'] ?? '',
                    'priority' => $action['priority'] ?? 'medium',
                    'actionable' => $action['actionable'] ?? false,
                    'suggested_due_date' => $action['suggested_due_date'] ?? null,
                    'source_domains' => $action['source_domains'] ?? [],
                    'generated_at' => now()->toIso8601String(),
                    'completed' => false,
                ],
            ]);

            $blocks[] = $block;
        }

        finish_ai_tool_span($toolSpan, ['blocks_created' => count($blocks)]);

        return $blocks;
    }

    /**
     * Create urgent alert blocks
     */
    public function createUrgentAlertBlocks(User $user, array $urgentFlags, Event $flintEvent): array
    {
        $blocks = [];

        foreach ($urgentFlags as $flag) {
            $block = Block::create([
                'id' => Str::uuid(),
                'event_id' => $flintEvent->id,
                'block_type' => 'flint_urgent_alert',
                'time' => now(),
                'title' => 'Urgent Alert',
                'metadata' => [
                    'reason' => $flag['reason'] ?? 'Requires attention',
                    'domain' => $flag['domain'] ?? 'unknown',
                    'context' => $flag['context'] ?? [],
                    'generated_at' => now()->toIso8601String(),
                    'resolved' => false,
                ],
            ]);

            $blocks[] = $block;
        }

        return $blocks;
    }

    /**
     * Create digest block
     */
    public function createDigestBlock(User $user, array $digestData, Event $flintEvent): Block
    {
        $insightCount = count($digestData['domain_insights'] ?? []) +
            count($digestData['cross_domain_insights'] ?? []) +
            count($digestData['patterns'] ?? []);

        // Preserve all digest data in metadata
        $metadata = array_merge($digestData, [
            'generated_at' => now()->toIso8601String(),
        ]);

        return Block::create([
            'id' => Str::uuid(),
            'event_id' => $flintEvent->id,
            'block_type' => 'flint_digest',
            'time' => now(),
            'title' => 'Daily Digest',
            'value' => $insightCount,
            'value_unit' => 'insights',
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get or create Flint event for a user
     * Creates one event per analysis run (multiple per day), all linked to the day object
     */
    public function getOrCreateFlintEvent(User $user): Event
    {
        // Get or create the Flint integration group
        $integrationGroup = IntegrationGroup::firstOrCreate(
            [
                'user_id' => $user->id,
                'service' => 'flint',
            ],
            [
                'id' => Str::uuid(),
                'credentials' => [],
                'state' => [],
            ]
        );

        // Get or create the Flint integration
        $integration = Integration::firstOrCreate(
            [
                'user_id' => $user->id,
                'service' => 'flint',
                'integration_group_id' => $integrationGroup->id,
            ],
            [
                'id' => Str::uuid(),
                'instance_type' => 'assistant',
                'configuration' => [
                    'continuous_analysis_enabled' => true,
                ],
            ]
        );

        // Get or create the Flint EventObject (represents the AI assistant)
        $flintObject = EventObject::firstOrCreate(
            [
                'user_id' => $user->id,
                'concept' => 'flint',
                'type' => 'assistant',
                'title' => 'Flint AI Assistant',
            ],
            [
                'time' => now(),
                'metadata' => [
                    'description' => 'AI assistant for analyzing daily events',
                ],
            ]
        );

        // Get or create the day object (shared across all events today)
        $today = now()->startOfDay();
        $dateString = $today->format('Y-m-d');

        $dayObject = EventObject::firstOrCreate(
            [
                'user_id' => $user->id,
                'concept' => 'day',
                'type' => 'day',
                'title' => $dateString,
            ],
            [
                'time' => $today,
                'content' => null,
                'metadata' => [],
            ]
        );

        // Determine period: morning (<12), afternoon (12-18:59), evening (>=19)
        $hour = (int) now()->format('H');
        if ($hour < 12) {
            $period = 'morning';
        } elseif ($hour < 19) {
            $period = 'afternoon';
        } else {
            $period = 'evening';
        }

        // Create synthetic source_id that's the same for all agents in this period
        // This allows multiple agent calls (domain, cross-domain, actions, digest) to update the same event
        $syntheticSourceId = "flint_analysis_{$dateString}_{$period}";

        // Use updateOrCreate so all agents in this analysis run share the same event
        $event = Event::updateOrCreate(
            [
                'integration_id' => $integration->id,
                'source_id' => $syntheticSourceId,  // Unique per period
            ],
            [
                'actor_id' => $flintObject->id,      // The AI assistant
                'target_id' => $dayObject->id,       // The day being analyzed
                'time' => now(),
                'service' => 'flint',
                'domain' => 'online',
                'action' => 'had_analysis',
                'event_metadata' => [
                    'analysis_type' => 'multi_agent',
                    'period' => $period,
                    'timestamp' => now()->toIso8601String(),
                ],
            ]
        );

        return $event;
    }

    /**
     * Create news briefing block from synthesized recurring fetch sources
     */
    public function createNewsBriefingBlock(User $user, array $briefingData, Event $flintEvent): Block
    {
        return Block::create([
            'id' => Str::uuid(),
            'event_id' => $flintEvent->id,
            'block_type' => 'flint_news_briefing',
            'time' => now(),
            'title' => $briefingData['title'] ?? 'News Briefing',
            'value' => count($briefingData['sources'] ?? []),
            'value_unit' => 'sources',
            'metadata' => [
                'summary' => $briefingData['summary'] ?? '',
                'sources' => $briefingData['sources'] ?? [],
                'key_stories' => $briefingData['key_stories'] ?? [],
                'themes' => $briefingData['themes'] ?? [],
                'period' => $briefingData['period'] ?? [
                    'start' => now()->subDay()->toIso8601String(),
                    'end' => now()->toIso8601String(),
                ],
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Create articles waiting block for unread one-time bookmarks
     */
    public function createArticlesWaitingBlock(User $user, array $articlesData, Event $flintEvent): Block
    {
        return Block::create([
            'id' => Str::uuid(),
            'event_id' => $flintEvent->id,
            'block_type' => 'flint_articles_waiting',
            'time' => now(),
            'title' => $articlesData['title'] ?? 'Articles Waiting',
            'value' => count($articlesData['articles'] ?? []),
            'value_unit' => 'articles',
            'metadata' => [
                'articles' => $articlesData['articles'] ?? [],
                'total_unread' => $articlesData['total_unread'] ?? 0,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Create coaching check-in block for health anomaly Q&A
     */
    public function createCoachingCheckInBlock(User $user, array $checkInData, Event $flintEvent): Block
    {
        return Block::create([
            'id' => Str::uuid(),
            'event_id' => $flintEvent->id,
            'block_type' => 'flint_coaching_check_in',
            'time' => now(),
            'title' => $checkInData['title'] ?? 'Health Check-In',
            'metadata' => [
                'coaching_session_id' => $checkInData['coaching_session_id'] ?? null,
                'anomaly_context' => $checkInData['anomaly_context'] ?? [],
                'questions' => $checkInData['questions'] ?? [],
                'pattern_suggestions' => $checkInData['pattern_suggestions'] ?? [],
                'status' => 'pending',
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Create coaching insight block from learned pattern
     */
    public function createCoachingInsightBlock(User $user, array $insightData, Event $flintEvent): Block
    {
        $confidence = $insightData['confidence'] ?? 0.5;

        return Block::create([
            'id' => Str::uuid(),
            'event_id' => $flintEvent->id,
            'block_type' => 'flint_coaching_insight',
            'time' => now(),
            'title' => $insightData['title'] ?? 'Coaching Insight',
            'value' => (int) ($confidence * 100),
            'value_multiplier' => 100,
            'value_unit' => 'confidence',
            'metadata' => [
                'learned_pattern_id' => $insightData['learned_pattern_id'] ?? null,
                'insight' => $insightData['insight'] ?? '',
                'trigger_conditions' => $insightData['trigger_conditions'] ?? [],
                'consequences' => $insightData['consequences'] ?? [],
                'confirmation_count' => $insightData['confirmation_count'] ?? 1,
                'confidence' => $confidence,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Create a single insight block
     */
    protected function createInsightBlock(User $user, Event $event, string $blockType, array $insightData): ?Block
    {
        $confidence = $insightData['confidence'] ?? 0.7;

        $block = Block::create([
            'id' => Str::uuid(),
            'event_id' => $event->id,
            'block_type' => $blockType,
            'time' => now(),
            'title' => $insightData['title'] ?? 'Insight',
            'value' => (int) ($confidence * 100), // Store confidence as 0-100
            'value_multiplier' => 100,
            'value_unit' => 'confidence',
            'metadata' => [
                'type' => $insightData['type'] ?? 'observation',
                'description' => $insightData['description'] ?? '',
                'supporting_data' => $insightData['supporting_data'] ?? [],
                'referenced_event_ids' => $insightData['referenced_event_ids'] ?? [],
                'confidence' => $confidence,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);

        return $block;
    }
}
