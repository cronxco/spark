<?php

namespace App\Jobs\Effects\Hevy;

use App\Jobs\Base\BaseEffectJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Services\Hevy\ProgressionAnalysisService;

class HevyAnalyzeProgressionEffect extends BaseEffectJob
{
    public function uniqueId(): string
    {
        return 'hevy_analyze_progression_' . $this->integration->id . '_' . now()->toDateString();
    }

    protected function execute(): array
    {
        $analysisService = app(ProgressionAnalysisService::class);
        $config = $this->integration->configuration ?? [];

        $result = $analysisService->analyze($this->integration, $config);

        // Store recommendations as blocks
        $this->storeRecommendationsAsBlocks($result['recommendations']);

        // Cache the last successful run timestamp (expires in 24 hours)
        $cacheKey = "hevy_coach_last_run_{$this->integration->id}";
        cache()->put($cacheKey, now(), now()->addDay());

        return [
            'success' => true,
            'message' => 'Analyzed ' . count($result['recommendations']) . ' exercise(s)',
            'data' => $result,
        ];
    }

    private function storeRecommendationsAsBlocks(array $recommendations): void
    {
        foreach ($recommendations as $rec) {
            // Get or create user object
            $userObject = EventObject::firstOrCreate([
                'user_id' => $this->integration->user_id,
                'concept' => 'user',
                'type' => 'hevy_user',
                'title' => 'Hevy Account',
            ], [
                'time' => now(),
                'content' => 'Hevy user account',
                'url' => null,
            ]);

            // Get or create routine object
            $routineObject = EventObject::firstOrCreate([
                'user_id' => $this->integration->user_id,
                'concept' => 'routine',
                'type' => 'hevy_routine',
                'title' => $rec['routine'],
            ], [
                'time' => now(),
                'content' => 'Hevy workout routine',
                'url' => null,
            ]);

            // Create recommendation event with deterministic source_id
            $date = now()->format('Ymd');
            $routineName = $rec['routine'];
            $exerciseName = $rec['exercise'];
            $sourceId = 'hevy_coach_' . $this->integration->id . '_' . $routineObject->id . '_' . md5($exerciseName) . '_' . $date;

            $event = Event::create([
                'source_id' => $sourceId,
                'time' => now(),
                'integration_id' => $this->integration->id,
                'actor_id' => $userObject->id,
                'target_id' => $routineObject->id,
                'service' => 'hevy',
                'domain' => 'health',
                'action' => 'had_coach_recommendation',
                'event_metadata' => $rec,
            ]);

            // Create recommendation block
            $event->createBlock([
                'block_type' => 'coach_recommendation',
                'time' => now(),
                'title' => $rec['routine'] . ' - ' . $rec['exercise'] . ' - ' . ucfirst($rec['action']),
                'content' => $rec['reason'],
                'metadata' => $rec,
            ]);
        }
    }
}
