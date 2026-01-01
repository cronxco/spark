<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Event;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Log;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

class AgentOrchestrationService
{
    public function __construct(
        protected AgentWorkingMemoryService $workingMemory,
        protected AgentMemoryService $memory,
        protected AssistantPromptingService $prompting,
        protected DomainAgentService $domainAgent,
        protected FlintBlockCreationService $blockCreation,
        protected AssistantContextService $contextService,
        protected FutureAgentService $futureAgent,
        protected InsightDeduplicationService $deduplication
    ) {}

    /**
     * Orchestrate pre-digest refresh for a user
     */
    public function runPreDigestRefresh(User $user): array
    {
        Log::info('[Flint] [ORCHESTRATION] Starting pre-digest refresh', [
            'user_id' => $user->id,
        ]);

        $span = SentrySdk::getCurrentHub()->getSpan();
        $childSpan = $span ? $span->startChild(new SpanContext) : null;
        if ($childSpan) {
            $childSpan->setOp('flint.pre_digest_refresh');
            $childSpan->setDescription("Pre-digest refresh for user {$user->id}");
        }

        try {
            $results = [];
            $enabledDomains = $this->getEnabledDomains($user);

            Log::info('[Flint] [ORCHESTRATION] Enabled domains retrieved', [
                'user_id' => $user->id,
                'enabled_domains' => $enabledDomains,
                'domain_count' => count($enabledDomains),
            ]);

            // Run future agent first (calendar + weather)
            Log::info('[Flint] [ORCHESTRATION] Running future agent', [
                'user_id' => $user->id,
            ]);

            $futureStartTime = microtime(true);
            $results['future'] = $this->futureAgent->generateFutureInsights($user, hoursAhead: 48);
            $futureDuration = microtime(true) - $futureStartTime;

            Log::info('[Flint] [ORCHESTRATION] Future agent completed', [
                'user_id' => $user->id,
                'duration_seconds' => round($futureDuration, 2),
                'insights_count' => count($results['future']['insights'] ?? []),
                'suggestions_count' => count($results['future']['suggestions'] ?? []),
            ]);

            // Run each domain agent with fresh data
            $previousDomain = 'future';
            foreach ($enabledDomains as $index => $domain) {
                Log::info('[Flint] [ORCHESTRATION] Processing domain agent', [
                    'user_id' => $user->id,
                    'domain' => $domain,
                    'domain_index' => $index + 1,
                    'total_domains' => count($enabledDomains),
                ]);

                $domainStartTime = microtime(true);

                // Track handoff between domain agents
                if ($previousDomain !== null) {
                    $handoffSpan = start_ai_handoff_span(
                        "{$previousDomain}_domain_agent",
                        "{$domain}_domain_agent",
                        ['mode' => 'pre_digest']
                    );
                    finish_ai_handoff_span($handoffSpan);
                }

                $results[$domain] = $this->runDomainAgent($user, $domain, 'pre_digest');
                $domainDuration = microtime(true) - $domainStartTime;

                Log::info('[Flint] [ORCHESTRATION] Domain agent completed', [
                    'user_id' => $user->id,
                    'domain' => $domain,
                    'duration_seconds' => round($domainDuration, 2),
                    'result_type' => $results[$domain] === null ? 'null' : (is_array($results[$domain]) ? 'array(' . count($results[$domain]) . ' items)' : gettype($results[$domain])),
                ]);

                $previousDomain = $domain;
            }

            // Always run cross-domain synthesizer before digest
            Log::info('[Flint] [ORCHESTRATION] Starting cross-domain synthesizer', [
                'user_id' => $user->id,
                'previous_domain' => $previousDomain,
            ]);

            $synthesizerStartTime = microtime(true);

            if ($previousDomain !== null) {
                $handoffSpan = start_ai_handoff_span(
                    "{$previousDomain}_domain_agent",
                    'cross_domain_synthesizer',
                    ['insights_from_domains' => array_keys($results)]
                );
                finish_ai_handoff_span($handoffSpan);
            }

            $results['cross_domain'] = $this->runCrossDomainSynthesizer($user);
            $synthesizerDuration = microtime(true) - $synthesizerStartTime;

            Log::info('[Flint] [ORCHESTRATION] Cross-domain synthesizer completed', [
                'user_id' => $user->id,
                'duration_seconds' => round($synthesizerDuration, 2),
                'result_type' => $results['cross_domain'] === null ? 'null' : (is_array($results['cross_domain']) ? 'array(' . count($results['cross_domain']) . ' items)' : gettype($results['cross_domain'])),
            ]);

            // Handoff from synthesizer to action prioritization
            Log::info('[Flint] [ORCHESTRATION] Starting action prioritization', [
                'user_id' => $user->id,
            ]);

            $actionStartTime = microtime(true);

            $handoffSpan = start_ai_handoff_span(
                'cross_domain_synthesizer',
                'action_prioritization_agent',
                ['has_cross_domain_observations' => true]
            );
            finish_ai_handoff_span($handoffSpan);

            // Always run action prioritization before digest
            $results['actions'] = $this->runActionPrioritization($user);
            $actionDuration = microtime(true) - $actionStartTime;

            Log::info('[Flint] [ORCHESTRATION] Action prioritization completed', [
                'user_id' => $user->id,
                'duration_seconds' => round($actionDuration, 2),
                'result_type' => $results['actions'] === null ? 'null' : (is_array($results['actions']) ? 'array(' . count($results['actions']) . ' items)' : gettype($results['actions'])),
            ]);

            // Update last execution time
            Log::info('[Flint] [ORCHESTRATION] Updating last execution time', [
                'user_id' => $user->id,
            ]);

            $this->workingMemory->setLastExecutionTime($user->id, 'pre_digest_refresh');

            if ($childSpan) {
                $childSpan->finish();
            }

            Log::info('[Flint] [ORCHESTRATION] Pre-digest refresh completed successfully', [
                'user_id' => $user->id,
                'results_summary' => array_map(function ($r) {
                    if ($r === null) {
                        return 'null';
                    }
                    if (is_array($r)) {
                        return 'array(' . count($r) . ' items)';
                    }

                    return gettype($r);
                }, $results),
            ]);

            return $results;
        } catch (Exception $e) {
            Log::error('[Flint] [ORCHESTRATION] Pre-digest refresh exception', [
                'user_id' => $user->id,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_code' => $e->getCode(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
            ]);

            if ($childSpan) {
                $childSpan->setStatus(SpanStatus::internalError());
                $childSpan->finish();
            }

            Log::error('[Flint] Pre-digest refresh failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Orchestrate digest generation for a user
     */
    public function runDigestGeneration(User $user, string $period = 'morning'): ?string
    {
        $span = SentrySdk::getCurrentHub()->getSpan();
        $childSpan = $span ? $span->startChild(new SpanContext) : null;
        if ($childSpan) {
            $childSpan->setOp('flint.digest_generation');
            $childSpan->setDescription("Digest generation for user {$user->id} ({$period})");
        }

        try {

            // Generate digest using AssistantPromptingService
            $digestBlockId = $this->generateDigest($user);

            // Update last execution time
            $this->workingMemory->setLastExecutionTime($user->id, 'digest_generation');

            if ($childSpan) {
                $childSpan->setData([
                    'digest_block_id' => $digestBlockId,
                    'period' => $period,
                ]);
                $childSpan->finish();
            }

            // Notification is now sent separately by SendDigestNotificationJob
            // at the scheduled time (not immediately after generation)

            return $digestBlockId;
        } catch (Exception $e) {
            if ($childSpan) {
                $childSpan->setStatus(SpanStatus::internalError());
                $childSpan->finish();
            }

            Log::error('Digest generation failed', [
                'user_id' => $user->id,
                'period' => $period,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Orchestrate pattern detection for a user (runs weekly)
     */
    public function runPatternDetection(User $user): array
    {
        $span = SentrySdk::getCurrentHub()->getSpan();
        $childSpan = $span ? $span->startChild(new SpanContext) : null;
        if ($childSpan) {
            $childSpan->setOp('flint.pattern_detection');
            $childSpan->setDescription("Pattern detection for user {$user->id}");
        }

        try {
            $patterns = [];
            $enabledDomains = $this->getEnabledDomains($user);

            // Get 90 days of historical data
            $historicalData = $this->getHistoricalData($user, 90);

            if (empty($historicalData)) {
                if ($childSpan) {
                    $childSpan->setData(['skip_reason' => 'no_historical_data']);
                    $childSpan->finish();
                }

                return [];
            }

            // Get existing patterns for context
            $existingPatterns = $this->memory->getPatterns($user->id);

            // Build prompt for pattern detection
            $prompt = $this->buildPatternDetectionPrompt($user, $historicalData, $existingPatterns);

            // Call LLM using AssistantPromptingService
            $response = $this->prompting->generateResponse($prompt, [
                'model' => 'gpt-5.1',
                'user_id' => $user->id,
                'context' => ['agent_type' => 'pattern_detection'],
            ]);

            // Parse and store patterns
            $detectedPatterns = $this->parsePatternDetectionResponse($response);

            // Store patterns in long-term memory and create blocks
            $createdBlocks = [];
            if (! empty($detectedPatterns)) {
                $flintEvent = $this->blockCreation->getOrCreateFlintEvent($user);

                foreach ($detectedPatterns as $pattern) {
                    // Store in long-term memory
                    $this->memory->storePattern($user->id, $pattern);

                    // Create pattern block
                    $block = $this->blockCreation->createPatternBlock(
                        $user,
                        $pattern,
                        $flintEvent
                    );

                    $createdBlocks[] = $block;
                    $patterns[] = $pattern;
                }
            }

            // Update last execution time
            $this->workingMemory->setLastExecutionTime($user->id, 'pattern_detection');

            if ($childSpan) {
                $childSpan->setData([
                    'patterns_detected' => count($patterns),
                    'blocks_created' => count($createdBlocks),
                    'days_analyzed' => 90,
                ]);
                $childSpan->finish();
            }

            return $patterns;
        } catch (Exception $e) {
            if ($childSpan) {
                $childSpan->setStatus(SpanStatus::internalError());
                $childSpan->finish();
            }

            Log::error('Pattern detection failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Run a domain-specific agent
     */
    protected function runDomainAgent(User $user, string $domain, string $mode): ?array
    {
        // Start AI agent invocation span
        $agentSpan = start_ai_agent_span("{$domain}_domain_agent", [
            'domain' => $domain,
            'mode' => $mode,
            'user_id' => $user->id,
        ]);

        try {
            // Get Flint integration for context generation
            $flintIntegration = $user->integrations()->where('service', 'flint')->first();
            if (! $flintIntegration) {
                if ($agentSpan) {
                    $agentSpan->setData(['skip_reason' => 'no_flint_integration']);
                    $agentSpan->finish();
                }

                return null;
            }

            // Generate full context schema for this domain
            $context = $this->contextService->generateContext(
                $user,
                now(),
                $flintIntegration,
                domains: [$domain]
            );

            // Check if we have any events
            $totalEvents = $context['yesterday']['event_count'] + $context['today']['event_count'];
            if ($totalEvents === 0) {
                if ($agentSpan) {
                    $agentSpan->setData(['skip_reason' => 'no_events']);
                    $agentSpan->finish();
                }

                return null;
            }

            // Get agent learning data
            $learning = $this->memory->getAgentLearning($user->id, $domain);

            // Get user feedback statistics
            $feedbackStats = $this->workingMemory->getFeedbackStatistics($user->id);

            // Get unanswered queries for this domain
            $queries = $this->workingMemory->getUnansweredQueriesForDomain($user->id, $domain);

            // Build prompt for domain agent (now receives full JSON context)
            $prompt = $this->buildDomainAgentPrompt($user, $domain, $context, $learning, $feedbackStats, $queries);

            // Call LLM using AssistantPromptingService
            $response = $this->prompting->generateResponse($prompt, [
                'model' => 'gpt-5-mini',
                'user_id' => $user->id,
                'context' => [
                    'domain' => $domain,
                    'mode' => $mode,
                    'prompt_type' => 'domain_agent',
                ],
            ]);

            // Parse and store insights
            $insights = $this->parseDomainAgentResponse($response);

            // Log parse success/failure
            $logData = [
                'prompt_type' => 'domain_agent',
                'domain' => $domain,
                'mode' => $mode,
                'insights_count' => count($insights['insights'] ?? []),
                'suggestions_count' => count($insights['suggestions'] ?? []),
                'metrics_count' => count($insights['metrics'] ?? []),
                'urgent_flags_count' => count($insights['urgent_flags'] ?? []),
                'parse_success' => ! empty($insights['insights']) || ! empty($insights['suggestions']),
            ];

            // Log if insights were filtered for quality
            if (isset($insights['quality_filtered_count']) && $insights['quality_filtered_count'] > 0) {
                $logData['quality_filtered_count'] = $insights['quality_filtered_count'];
                $logData['quality_filter_applied'] = true;
            }

            // Log if agent explicitly said no insights
            if (! empty($insights['no_insights_reason'])) {
                $logData['no_insights_reason'] = $insights['no_insights_reason'];
                Log::info('Agent returned no insights', $logData);
            } elseif (empty($insights['insights']) && empty($insights['suggestions'])) {
                $logData['no_insights_reason'] = 'Empty response after parsing';
                Log::info('Agent produced no actionable insights', $logData);
            } else {
                Log::info('Agent Response Parsed', $logData);
            }

            $this->workingMemory->storeDomainInsight($user->id, $domain, $insights);

            // Create insight blocks if we're in pre-digest mode (not continuous)
            $createdBlocks = [];
            if ($mode === 'pre_digest' && ! empty($insights['insights'])) {
                // Deduplicate insights before creating blocks
                $originalCount = count($insights['insights']);
                $deduplicatedInsights = $this->deduplicateInsights($user->id, $domain, $insights['insights']);
                $duplicateCount = $originalCount - count($deduplicatedInsights);

                if ($duplicateCount > 0) {
                    Log::info('[Flint] [DEDUP] Filtered duplicate insights', [
                        'user_id' => $user->id,
                        'domain' => $domain,
                        'original_count' => $originalCount,
                        'deduplicated_count' => count($deduplicatedInsights),
                        'duplicates_removed' => $duplicateCount,
                    ]);
                }

                // Update insights array with deduplicated version
                $insights['insights'] = $deduplicatedInsights;

                // Get or create FlintEvent if we have insights or urgent flags
                $flintEvent = null;
                if (! empty($deduplicatedInsights) || ! empty($insights['urgent_flags'])) {
                    $flintEvent = $this->blockCreation->getOrCreateFlintEvent($user);
                }

                // Only create blocks if we have non-duplicate insights
                if (! empty($deduplicatedInsights)) {
                    $createdBlocks = $this->blockCreation->createDomainInsightBlocks(
                        $user,
                        $domain,
                        $insights,
                        $flintEvent
                    );

                    // Mark these insights as seen
                    foreach ($deduplicatedInsights as $insight) {
                        $this->deduplication->markAsSeen($insight, $domain, $user->id);
                    }
                } else {
                    Log::info('[Flint] [DEDUP] All insights were duplicates - no blocks created', [
                        'user_id' => $user->id,
                        'domain' => $domain,
                    ]);
                }

                // Handle urgent flags if any
                if (! empty($insights['urgent_flags'])) {
                    $urgentBlocks = $this->blockCreation->createUrgentAlertBlocks(
                        $user,
                        $insights['urgent_flags'],
                        $flintEvent
                    );
                    $createdBlocks = array_merge($createdBlocks, $urgentBlocks);

                    // Also store in working memory for immediate attention
                    foreach ($insights['urgent_flags'] as $flag) {
                        $this->workingMemory->raiseUrgentFlag(
                            $user->id,
                            $domain,
                            $flag['reason'] ?? 'Urgent attention needed',
                            $flag['context'] ?? []
                        );
                    }
                }

                // Store cross-domain observations if any
                if (! empty($insights['cross_domain_observations'])) {
                    foreach ($insights['cross_domain_observations'] as $observation) {
                        $this->workingMemory->addCrossDomainObservation($user->id, $observation);
                    }
                }
            }

            // Answer any queries from other agents
            foreach ($queries as $index => $query) {
                if (isset($insights['query_responses'][$query['question']])) {
                    $this->workingMemory->answerAgentQuery(
                        $user->id,
                        $index,
                        $insights['query_responses'][$query['question']]
                    );
                }
            }

            // Finish AI agent span with output
            finish_ai_agent_span($agentSpan, [
                'insights_count' => count($insights['insights'] ?? []),
                'suggestions_count' => count($insights['suggestions'] ?? []),
                'confidence' => $insights['confidence'] ?? 0,
                'blocks_created' => count($createdBlocks),
            ]);

            return $insights;
        } catch (Exception $e) {
            if ($agentSpan) {
                $agentSpan->setStatus(SpanStatus::internalError());
                $agentSpan->finish();
            }

            Log::error("Domain agent failed: {$domain}", [
                'user_id' => $user->id,
                'mode' => $mode,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Run cross-domain synthesizer
     */
    protected function runCrossDomainSynthesizer(User $user): ?array
    {
        // Start AI agent invocation span
        $agentSpan = start_ai_agent_span('cross_domain_synthesizer', [
            'user_id' => $user->id,
        ]);

        try {
            // Get all domain insights
            $domainInsights = $this->workingMemory->getAllDomainInsights($user->id);

            // Filter out empty insights
            $domainInsights = array_filter($domainInsights, fn ($insight) => ! empty($insight));

            if (count($domainInsights) < 2) {
                if ($agentSpan) {
                    $agentSpan->setData(['skip_reason' => 'insufficient_insights']);
                    $agentSpan->finish();
                }

                return null;
            }

            // Build prompt for synthesizer
            $prompt = $this->buildCrossDomainSynthesizerPrompt($user, $domainInsights);

            // Call LLM using AssistantPromptingService
            $response = $this->prompting->generateResponse($prompt, [
                'model' => 'gpt-5.1',
                'user_id' => $user->id,
                'context' => ['agent_type' => 'cross_domain_synthesizer'],
            ]);

            // Parse and store observations
            $observations = $this->parseCrossDomainResponse($response);

            // Store in working memory
            foreach ($observations as $observation) {
                $this->workingMemory->addCrossDomainObservation($user->id, $observation);
            }

            // Create cross-domain blocks
            $createdBlocks = [];
            if (! empty($observations)) {
                $flintEvent = $this->blockCreation->getOrCreateFlintEvent($user);
                $createdBlocks = $this->blockCreation->createCrossDomainBlocks(
                    $user,
                    $observations,
                    $flintEvent
                );
            }

            // Finish AI agent span with output
            finish_ai_agent_span($agentSpan, [
                'observations_count' => count($observations),
                'blocks_created' => count($createdBlocks),
            ]);

            return $observations;
        } catch (Exception $e) {
            if ($agentSpan) {
                $agentSpan->setStatus(SpanStatus::internalError());
                $agentSpan->finish();
            }

            Log::error('Cross-domain synthesizer failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Run action prioritization
     */
    protected function runActionPrioritization(User $user): ?array
    {
        // Start AI agent invocation span
        $agentSpan = start_ai_agent_span('action_prioritization_agent', [
            'user_id' => $user->id,
        ]);

        try {
            // Get all domain insights
            $domainInsights = $this->workingMemory->getAllDomainInsights($user->id);

            // Get cross-domain observations
            $observations = $this->workingMemory->getCrossDomainObservations($user->id, 20);

            // Get urgent flags
            $urgentFlags = $this->workingMemory->getUnresolvedUrgentFlags($user->id);

            // Build prompt for action prioritization
            $prompt = $this->buildActionPrioritizationPrompt($user, $domainInsights, $observations, $urgentFlags);

            // Call LLM using AssistantPromptingService
            $response = $this->prompting->generateResponse($prompt, [
                'model' => 'gpt-5.1',
                'user_id' => $user->id,
                'context' => ['agent_type' => 'action_prioritization'],
            ]);

            // Parse and store prioritized actions
            $actions = $this->parseActionPrioritizationResponse($response);
            $this->workingMemory->storePrioritizedActions($user->id, $actions);

            // Create action blocks
            $createdBlocks = [];
            if (! empty($actions)) {
                $flintEvent = $this->blockCreation->getOrCreateFlintEvent($user);
                $createdBlocks = $this->blockCreation->createActionBlocks(
                    $user,
                    $actions,
                    $flintEvent
                );
            }

            // Finish AI agent span with output
            finish_ai_agent_span($agentSpan, [
                'actions_count' => count($actions),
                'blocks_created' => count($createdBlocks),
            ]);

            return $actions;
        } catch (Exception $e) {
            if ($agentSpan) {
                $agentSpan->setStatus(SpanStatus::internalError());
                $agentSpan->finish();
            }

            Log::error('Action prioritization failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate digest
     */
    protected function generateDigest(User $user): string
    {
        // Get all insights and actions
        $domainInsights = $this->workingMemory->getAllDomainInsights($user->id);
        $observations = $this->workingMemory->getCrossDomainObservations($user->id, 10);
        $actions = $this->workingMemory->getPrioritizedActions($user->id);

        // Build prompt for digest generation
        $prompt = $this->buildDigestPrompt($user, $domainInsights, $observations, $actions);

        // Call LLM using AssistantPromptingService
        $response = $this->prompting->generateResponse($prompt, [
            'model' => 'gpt-5.1',
            'user_id' => $user->id,
            'context' => ['agent_type' => 'digest_generation'],
        ]);

        // Parse digest response
        $digestData = $this->parseDigestResponse($response);

        // Prepare digest data for block creation (new scannable structure)
        $digestBlockData = [
            'headline' => $digestData['headline'] ?? 'Daily Digest',
            'top_insights' => $digestData['top_insights'] ?? [],
            'wins' => $digestData['wins'] ?? [],
            'watch_points' => $digestData['watch_points'] ?? [],
            'tomorrow_focus' => $digestData['tomorrow_focus'] ?? [],
            'domain_insights' => $domainInsights,
            'cross_domain_insights' => $observations,
            'prioritized_actions' => $actions,
            'metrics' => [
                'total_insights' => array_sum(array_map(fn ($d) => count($d['insights'] ?? []), $domainInsights)),
                'cross_domain_connections' => count($observations),
                'recommended_actions' => count($actions),
            ],
        ];

        // Create digest block
        $flintEvent = $this->blockCreation->getOrCreateFlintEvent($user);
        $digestBlock = $this->blockCreation->createDigestBlock($user, $digestBlockData, $flintEvent);

        return $digestBlock->id;
    }

    /**
     * Parse digest response
     */
    protected function parseDigestResponse(string $response): array
    {
        // Extract JSON from response
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Try to extract from markdown code block
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // Try to find any JSON object in the response
        if (preg_match('/(\{.*\})/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // Fallback: return basic scannable structure
        return [
            'headline' => 'Daily Digest',
            'top_insights' => [],
            'wins' => [],
            'watch_points' => [],
            'tomorrow_focus' => [],
        ];
    }

    /**
     * Get enabled domains for a user
     */
    protected function getEnabledDomains(User $user): array
    {
        $settings = $user->settings ?? [];
        $flintSettings = $settings['flint'] ?? [];

        $enabledDomains = $flintSettings['enabled_domains'] ?? ['health', 'money', 'media', 'knowledge', 'online'];

        return $enabledDomains;
    }

    /**
     * Get recent domain events
     */
    protected function getRecentDomainEvents(User $user, string $domain, string $mode): array
    {
        // For continuous mode, look at last 4 hours
        // For pre-digest mode, look at last 24 hours
        $hours = $mode === 'continuous' ? 4 : 24;

        $events = Event::forUser($user->id)
            ->where('domain', $domain)
            ->where('time', '>=', now()->subHours($hours))
            ->orderBy('time', 'desc')
            ->limit(100)
            ->get();

        return $events->toArray();
    }

    /**
     * Build domain agent prompt
     */
    protected function buildDomainAgentPrompt(User $user, string $domain, array $context, ?array $learning, array $feedbackStats, array $queries): string
    {
        return $this->domainAgent->buildDomainPrompt($user, $domain, $context, $learning, $feedbackStats, $queries);
    }

    /**
     * Parse domain agent response
     */
    protected function parseDomainAgentResponse(string $response): array
    {
        return $this->domainAgent->parseAgentResponse($response);
    }

    /**
     * Build cross-domain synthesizer prompt
     */
    protected function buildCrossDomainSynthesizerPrompt(User $user, array $domainInsights): string
    {
        $insightsText = '';
        foreach ($domainInsights as $domain => $data) {
            $insightsText .= "## {$domain} Domain\n\n";

            if (! empty($data['insights'])) {
                $insightsText .= "**Insights:**\n";
                foreach ($data['insights'] as $insight) {
                    $insightsText .= "- {$insight['title']}: {$insight['description']}\n";
                }
                $insightsText .= "\n";
            }

            if (! empty($data['metrics'])) {
                $insightsText .= "**Key Metrics:**\n";
                foreach ($data['metrics'] as $key => $metric) {
                    $insightsText .= "- {$key}: {$metric['value']}";
                    if (isset($metric['change'])) {
                        $insightsText .= " ({$metric['change']})";
                    }
                    $insightsText .= "\n";
                }
                $insightsText .= "\n";
            }
        }

        return <<<PROMPT
You are the Cross-Domain Synthesizer for Flint, with a highly conservative approach to finding connections.

**Your Role:**
- Identify ONLY obvious, strong cross-domain connections
- Focus on clear causation or timing correlations with strong evidence
- Be extremely skeptical - most apparent connections are coincidental
- Silence is better than weak speculation

**CRITICAL - High Bar for Connections:**
- **DO**: Report clear patterns with temporal correlation AND logical mechanism
- **DON'T**: Speculate about correlations without strong supporting evidence
- **DON'T**: Make obvious connections ("poor sleep affects energy" - too generic)
- **DON'T**: Report single-instance patterns (need 3+ occurrences)

**Strong Connection Examples:**
- ✅ "High spending weeks consistently precede poor sleep (4/5 occurrences, likely financial stress)"
- ✅ "Intense training days followed by 20% higher knowledge work next day (6 instances, pattern holds)"
- ❌ "You slept poorly and spent more yesterday" (single instance, no pattern)
- ❌ "Poor sleep might affect productivity" (generic, obvious)

**Domain Insights Available:**

{$insightsText}

## Your Task

Analyze the insights above for STRONG cross-domain connections only. Return your response as JSON:

```json
[
  {
    "domains": ["domain1", "domain2"],
    "observation": "Specific pattern with evidence",
    "confidence": 0.0-1.0,
    "supporting_evidence": ["specific data point 1", "specific data point 2"],
    "frequency": "How often this pattern appears (e.g., '4 out of 5 weeks')"
  }
]
```

**Guidelines:**
- ONLY report connections with confidence >= 0.85 (very high bar)
- Need 3+ instances to establish pattern
- Be specific about temporal relationship (leads to, follows, coincides with)
- Include frequency/consistency data
- **Return empty array if no strong connections found** (most of the time)
- Provide concrete evidence from the domain insights
- Avoid generic observations - be data-specific

Focus on actionable cross-domain insights the user can understand and act on.
PROMPT;
    }

    /**
     * Parse cross-domain response
     */
    protected function parseCrossDomainResponse(string $response): array
    {
        // Try to extract JSON from the response (same logic as domain agent)
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Try to extract from markdown code block
        if (preg_match('/```(?:json)?\s*(\[.*?\])\s*```/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // Try to find any JSON array in the response
        if (preg_match('/(\[.*\])/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // Fallback: return empty array
        return [];
    }

    /**
     * Build action prioritization prompt
     */
    protected function buildActionPrioritizationPrompt(User $user, array $domainInsights, array $observations, array $urgentFlags): string
    {
        // Format domain suggestions
        $suggestionsText = '';
        foreach ($domainInsights as $domain => $data) {
            if (! empty($data['suggestions'])) {
                $suggestionsText .= "## {$domain} Domain Suggestions\n\n";
                foreach ($data['suggestions'] as $suggestion) {
                    $suggestionsText .= "- **{$suggestion['title']}**\n";
                    $suggestionsText .= "  {$suggestion['description']}\n";
                    $suggestionsText .= "  Priority: {$suggestion['priority']}\n\n";
                }
            }
        }

        // Format cross-domain observations
        $observationsText = '';
        if (! empty($observations)) {
            $observationsText = "## Cross-Domain Observations\n\n";
            foreach ($observations as $obs) {
                $observationsText .= "- **{$obs['observation']}**\n";
                $observationsText .= '  Affects: ' . implode(', ', $obs['domains']) . "\n";
                $observationsText .= '  Confidence: ' . round($obs['confidence'] * 100) . "%\n\n";
            }
        }

        // Format urgent flags
        $urgentText = '';
        if (! empty($urgentFlags)) {
            $urgentText = "## URGENT FLAGS\n\n";
            foreach ($urgentFlags as $flag) {
                $urgentText .= "- **{$flag['reason']}** (Domain: {$flag['domain']})\n";
                if (! empty($flag['context'])) {
                    $urgentText .= '  Context: ' . json_encode($flag['context']) . "\n";
                }
                $urgentText .= "\n";
            }
        }

        return <<<PROMPT
You are the Action Prioritization Agent for Flint, responsible for converting insights into actionable recommendations.

**Your Role:**
- Review all suggestions from domain agents and cross-domain observations
- Prioritize actions based on urgency, impact, and feasibility
- Flag urgent items that need immediate attention
- Provide clear, specific, actionable recommendations

**Tone:**
- Action-oriented and practical
- Clear about priorities (high/medium/low)
- Specific about what to do and why
- Encouraging but realistic

{$urgentText}

{$suggestionsText}

{$observationsText}

## Your Task

Analyze all suggestions and observations above. Return your response as JSON:

```json
[
  {
    "title": "Clear, actionable title (e.g., 'Schedule 30min break after lunch')",
    "description": "Why this matters and what the user should do",
    "priority": "high|medium|low",
    "actionable": true|false,
    "source_domains": ["domain1", "domain2"],
    "suggested_due_date": "YYYY-MM-DD or null"
  }
]
```

**Prioritization Guidelines:**
- **HIGH**: Urgent flags, health risks, significant financial issues, time-sensitive opportunities
- **MEDIUM**: Important improvements, optimization opportunities, beneficial habits
- **LOW**: Nice-to-have optimizations, long-term considerations

**Action Guidelines:**
- Be specific (not "improve sleep" but "Go to bed by 10:30 PM")
- Include "why" in the description (connect to insights)
- Only suggest actionable items (user can actually do something)
- Limit to top 1-2 actions (avoid overwhelming the user)
- Consider dependencies (some actions enable others)

Focus on actions that will have the biggest positive impact on the user's life.
PROMPT;
    }

    /**
     * Parse action prioritization response
     */
    protected function parseActionPrioritizationResponse(string $response): array
    {
        // Use the same JSON extraction logic as parseCrossDomainResponse
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Try to extract from markdown code block
        if (preg_match('/```(?:json)?\s*(\[.*?\])\s*```/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // Try to find any JSON array in the response
        if (preg_match('/(\[.*\])/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // Fallback: return empty array
        return [];
    }

    /**
     * Build digest prompt
     */
    protected function buildDigestPrompt(User $user, array $domainInsights, array $observations, array $actions): string
    {
        // Format domain summaries
        $domainsText = '';
        foreach ($domainInsights as $domain => $data) {
            $insightCount = count($data['insights'] ?? []);
            if ($insightCount > 0) {
                $domainsText .= "## {$domain}\n";
                $domainsText .= "- {$insightCount} insights generated\n";

                // Include top 2 insights
                $topInsights = array_slice($data['insights'] ?? [], 0, 2);
                foreach ($topInsights as $insight) {
                    $domainsText .= "- {$insight['title']}\n";
                }
                $domainsText .= "\n";
            }
        }

        // Format cross-domain connections
        $connectionsText = '';
        if (! empty($observations) && is_array($observations)) {
            $connectionsText = "## Cross-Domain Connections\n\n";
            foreach (array_slice($observations, 0, 3) as $obs) {
                if (is_array($obs) && isset($obs['observation'])) {
                    $connectionsText .= "- {$obs['observation']}\n";
                }
            }
            $connectionsText .= "\n";
        }

        // Format top actions
        $actionsText = '';
        if (! empty($actions) && is_array($actions)) {
            $actionsText = "## Recommended Actions\n\n";
            foreach (array_slice($actions, 0, 5) as $action) {
                if (is_array($action) && isset($action['priority']) && isset($action['title'])) {
                    $actionsText .= "- **[{$action['priority']}]** {$action['title']}\n";
                }
            }
            $actionsText .= "\n";
        }

        return <<<PROMPT
You are the Digest Generator for Flint, responsible for creating a scannable, action-oriented daily summary.

**Your Role:**
- Create a *scannable* digest that can be read in 30 seconds
- Focus on what matters most: key insights, wins, and watch points
- Be concise and direct - no fluff
- Include weather context if relevant to scheduled activities

**Tone:**
- Direct and scannable (no long paragraphs)
- Celebrate wins prominently
- Flag concerns clearly but not alarmist
- Forward-looking

**Today's Analysis:**

{$domainsText}

{$connectionsText}

{$actionsText}

## Your Task

Create a scannable daily digest. Return your response as JSON:

```json
{
  "headline": "15-word max headline capturing the day's theme (e.g., 'Strong recovery and focused work, watch tomorrow's rainy outdoor plans')",
  "top_insights": [
    {
      "title": "Insight title (6-8 words max)",
      "description": "Max 2 sentences. Be specific with numbers.",
      "domain": "health|money|media|knowledge|online|future",
      "type": "win|concern|neutral"
    }
  ],
  "wins": [
    "Brief celebration (1 sentence max)"
  ],
  "watch_points": [
    "Brief concern or upcoming challenge (1 sentence max)"
  ],
  "tomorrow_focus": [
    "Top 1-2 priorities for tomorrow (brief, actionable)"
  ]
}
```

**Critical Guidelines:**
- **Headline**: 15 words maximum, captures day's theme
- **Top Insights**: Maximum 3 insights, prioritize by importance
- **Each insight description**: 2 sentences maximum, include specific numbers
- **Wins**: Celebrate prominently - users need positive reinforcement
- **Watch Points**: Only real concerns, not hypotheticals
- **Tomorrow Focus**: 1-2 actions maximum, highly specific

**Scannability Test:**
- User should grasp the key points in 30 seconds
- No long paragraphs or narrative summaries
- Every word must earn its place

Focus on creating a digest that respects the user's time and highlights what truly matters.
PROMPT;
    }

    /**
     * Get historical data for pattern detection
     */
    protected function getHistoricalData(User $user, int $days): array
    {
        $events = Event::forUser($user->id)
            ->where('time', '>=', now()->subDays($days))
            ->orderBy('time', 'desc')
            ->get();

        // Group events by domain and week
        $grouped = [];
        foreach ($events as $event) {
            $domain = $event->domain;
            $weekStart = $event->time->startOfWeek()->format('Y-m-d');

            if (! isset($grouped[$domain])) {
                $grouped[$domain] = [];
            }

            if (! isset($grouped[$domain][$weekStart])) {
                $grouped[$domain][$weekStart] = [
                    'week_start' => $weekStart,
                    'event_count' => 0,
                    'actions' => [],
                    'services' => [],
                ];
            }

            $grouped[$domain][$weekStart]['event_count']++;

            $action = $event->action;
            if (! isset($grouped[$domain][$weekStart]['actions'][$action])) {
                $grouped[$domain][$weekStart]['actions'][$action] = 0;
            }
            $grouped[$domain][$weekStart]['actions'][$action]++;

            $service = $event->service;
            if (! in_array($service, $grouped[$domain][$weekStart]['services'])) {
                $grouped[$domain][$weekStart]['services'][] = $service;
            }
        }

        return $grouped;
    }

    /**
     * Build pattern detection prompt
     */
    protected function buildPatternDetectionPrompt(User $user, array $historicalData, array $existingPatterns): string
    {
        // Format historical data by domain
        $dataText = '';
        foreach ($historicalData as $domain => $weeks) {
            $dataText .= "## {$domain} Domain\n\n";
            $dataText .= "Weekly activity over the past 90 days:\n\n";

            foreach ($weeks as $weekData) {
                $dataText .= "**Week of {$weekData['week_start']}:**\n";
                $dataText .= "- Total events: {$weekData['event_count']}\n";

                if (! empty($weekData['actions'])) {
                    $dataText .= '- Top actions: ';
                    arsort($weekData['actions']);
                    $topActions = array_slice($weekData['actions'], 0, 5, true);
                    $actionStrings = array_map(
                        fn ($action, $count) => "{$action} ({$count})",
                        array_keys($topActions),
                        $topActions
                    );
                    $dataText .= implode(', ', $actionStrings) . "\n";
                }

                if (! empty($weekData['services'])) {
                    $dataText .= '- Services: ' . implode(', ', $weekData['services']) . "\n";
                }

                $dataText .= "\n";
            }

            $dataText .= "\n";
        }

        // Format existing patterns
        $patternsText = '';
        if (! empty($existingPatterns)) {
            $patternsText = "## Previously Detected Patterns\n\n";
            $patternsText .= "These patterns were detected in the past. Validate if they still hold true or if they've evolved:\n\n";

            foreach ($existingPatterns as $pattern) {
                $patternsText .= "- **{$pattern['title']}** ({$pattern['pattern_type']})\n";
                $patternsText .= "  Confidence: {$pattern['confidence']}\n";
                if (! empty($pattern['description'])) {
                    $patternsText .= "  Description: {$pattern['description']}\n";
                }
                $patternsText .= "\n";
            }
        }

        return <<<PROMPT
You are the Pattern Detection Agent for Flint, specializing in identifying long-term patterns and correlations across 90 days of user data.

**Your Role:**
- Analyze 90 days of historical data to identify recurring patterns
- Detect correlations between different domains (e.g., poor sleep preceding low productivity)
- Identify seasonal or cyclical patterns (e.g., weekday vs weekend behavior)
- Validate or update previously detected patterns
- Find causal relationships and trends

**Pattern Types to Look For:**
1. **Correlation patterns**: Two or more metrics that move together (e.g., "Exercise days correlate with better sleep scores")
2. **Temporal patterns**: Time-based patterns (e.g., "Productivity peaks on Tuesday-Thursday")
3. **Seasonal patterns**: Week-level or month-level cycles (e.g., "Spending increases at month-end")
4. **Trend patterns**: Long-term directional changes (e.g., "Sleep duration declining over 90 days")
5. **Anomaly patterns**: Consistent unusual behavior (e.g., "No exercise on Mondays")
6. **Cross-domain patterns**: Connections spanning multiple life areas

**Historical Data:**

{$dataText}

{$patternsText}

## Your Task

Analyze the historical data above and identify meaningful patterns. Return your response as JSON:

```json
[
  {
    "title": "Clear, concise pattern title",
    "pattern_type": "correlation|temporal|seasonal|trend|anomaly|cross_domain",
    "description": "2-3 sentence explanation of the pattern and why it matters",
    "confidence": 0.0-1.0,
    "domains": ["domain1", "domain2"],
    "supporting_evidence": ["specific data point 1", "specific data point 2", "specific data point 3"],
    "occurrences": [
      {
        "week": "2024-01-01",
        "observation": "What you observed this week that supports the pattern"
      }
    ],
    "actionable_insight": "What the user should do with this information"
  }
]
```

**Guidelines:**
- Only report patterns with confidence >= 0.6
- Focus on actionable patterns (not just interesting observations)
- Look for 3-7 strong patterns (quality over quantity)
- Provide specific supporting evidence with numbers
- If a previous pattern no longer holds, note it in your analysis
- Consider both positive patterns (to reinforce) and negative patterns (to address)

Be thorough and data-driven in your analysis.
PROMPT;
    }

    /**
     * Parse pattern detection response
     */
    protected function parsePatternDetectionResponse(string $response): array
    {
        // Try to extract JSON from the response
        $json = $this->extractJson($response);

        if ($json === null) {
            return [];
        }

        // Ensure it's an array of patterns
        if (! is_array($json)) {
            return [];
        }

        // Filter patterns by confidence threshold
        return array_filter($json, function ($pattern) {
            return isset($pattern['confidence']) && $pattern['confidence'] >= 0.6;
        });
    }

    /**
     * Extract JSON from LLM response
     */
    protected function extractJson(string $response): ?array
    {
        // Try direct JSON decode
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Try to extract from markdown code block
        if (preg_match('/```(?:json)?\\s*(\\{.*?\\}|\\[.*?\\])\\s*```/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Try to find any JSON object or array in the response
        if (preg_match('/(\\{.*\\}|\\[.*\\])/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Deduplicate insights using the deduplication service
     *
     * @return array Deduplicated insights
     */
    protected function deduplicateInsights(int $userId, string $domain, array $insights): array
    {
        $deduplicated = [];

        foreach ($insights as $insight) {
            $duplicateCheck = $this->deduplication->isDuplicate($insight, $domain, $userId);

            if ($duplicateCheck['is_duplicate']) {
                Log::debug('[Flint] [DEDUP] Skipping duplicate insight', [
                    'user_id' => $userId,
                    'domain' => $domain,
                    'title' => $insight['title'] ?? 'N/A',
                    'similarity_score' => $duplicateCheck['similarity_score'],
                    'seen_at' => $duplicateCheck['seen_at'] ?? null,
                ]);

                continue;
            }

            $deduplicated[] = $insight;
        }

        return $deduplicated;
    }
}
