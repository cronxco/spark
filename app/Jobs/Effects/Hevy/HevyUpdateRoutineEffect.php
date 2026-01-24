<?php

namespace App\Jobs\Effects\Hevy;

use App\Integrations\Hevy\HevyPlugin;
use App\Jobs\Base\BaseEffectJob;
use App\Models\Block;
use App\Services\Hevy\ProgressionAnalysisService;
use Throwable;

class HevyUpdateRoutineEffect extends BaseEffectJob
{
    public function uniqueId(): string
    {
        return 'hevy_update_routine_'.$this->integration->id.'_'.now()->toDateString();
    }

    protected function execute(): array
    {
        // Get latest recommendations (from last 24 hours)
        $recommendations = $this->getLatestRecommendations();

        if (empty($recommendations)) {
            return [
                'success' => false,
                'message' => 'No recommendations found. Run analysis first.',
                'data' => [],
            ];
        }

        // Fetch current routines from Hevy
        $plugin = new HevyPlugin;
        $routinesData = $plugin->pullRoutineData($this->integration);
        $routines = $routinesData['routines'] ?? [];

        if (empty($routines)) {
            return [
                'success' => false,
                'message' => 'No routines found in Hevy',
                'data' => [],
            ];
        }

        // Update each routine with recommendations
        $updated = 0;
        $errors = [];

        foreach ($routines as $routine) {
            try {
                $updates = $this->buildRoutineUpdates($routine, $recommendations);
                if (! empty($updates['exercises'])) {
                    $plugin->updateRoutine($this->integration, $routine['id'], $updates);
                    $updated++;
                }
            } catch (Throwable $e) {
                $errors[] = ($routine['title'] ?? 'Unknown').': '.$e->getMessage();
            }
        }

        return [
            'success' => $updated > 0,
            'message' => "Updated {$updated} routine(s)".(! empty($errors) ? ' with '.count($errors).' error(s)' : ''),
            'data' => [
                'updated_count' => $updated,
                'errors' => $errors,
            ],
        ];
    }

    private function getLatestRecommendations(): array
    {
        // Get most recent coach_recommendation blocks
        $blocks = Block::whereHas('event', function ($q) {
            $q->where('integration_id', $this->integration->id)
                ->where('action', 'had_coach_recommendation');
        })
            ->where('block_type', 'coach_recommendation')
            ->where('created_at', '>=', now()->subHours(24))
            ->get();

        return $blocks->pluck('metadata')->toArray();
    }

    private function buildRoutineUpdates(array $routine, array $recommendations): array
    {
        $routineTitle = $routine['title'] ?? '';
        $exercises = $routine['exercises'] ?? [];
        $updatedExercises = [];
        $analysisService = app(ProgressionAnalysisService::class);

        foreach ($exercises as $exercise) {
            $exerciseName = $exercise['title'] ?? $exercise['name'] ?? '';

            // Find matching recommendation (by routine title AND exercise name)
            $rec = collect($recommendations)->first(function ($r) use ($routineTitle, $exerciseName) {
                return ($r['routine'] ?? '') === $routineTitle && ($r['exercise'] ?? '') === $exerciseName;
            });

            if ($rec && $rec['action'] !== 'maintain') {
                $sets = $exercise['sets'] ?? [];

                // Update each set with new targets
                foreach ($sets as &$set) {
                    if (isset($rec['new_weight'])) {
                        $unit = $rec['current_unit'] ?? 'kg';
                        if ($unit === 'kg') {
                            $set['weight_kg'] = $rec['new_weight'];
                        } else {
                            $set['weight_lb'] = $rec['new_weight'];
                        }
                    }
                    if (isset($rec['new_reps'])) {
                        $set['reps'] = $rec['new_reps'];
                    }
                }
                unset($set); // Remove lingering reference

                // Update notes field with narrative + new target
                $exercise['notes'] = $analysisService->formatNotes($rec);
                $exercise['sets'] = $sets;

                $updatedExercises[] = $exercise;
            } else {
                // No change, keep as is
                $updatedExercises[] = $exercise;
            }
        }

        return [
            'title' => $routineTitle,
            'exercises' => $updatedExercises,
        ];
    }
}
