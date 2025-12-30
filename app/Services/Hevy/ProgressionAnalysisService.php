<?php

namespace App\Services\Hevy;

use App\Models\Event;
use App\Models\Integration;

class ProgressionAnalysisService
{
    /**
     * Analyze workouts and generate progression recommendations.
     */
    public function analyze(Integration $integration, array $config = []): array
    {
        $windowDays = $config['analysis_window_days'] ?? 7;
        $goalReps = $config['goal_reps'] ?? 12;
        $rpeThreshold = $config['progression_rpe_trigger'] ?? 9.0;
        $weightIncrementKg = $config['weight_increment_kg'] ?? 5.0;
        $deloadPercentage = $config['deload_percentage'] ?? 90.0;

        // Fetch recent workout events
        $workouts = Event::where('integration_id', $integration->id)
            ->where('action', 'completed_workout')
            ->where('time', '>=', now()->subDays($windowDays))
            ->with(['blocks' => function ($q) {
                $q->where('block_type', 'exercise');
            }, 'target'])
            ->get();

        if ($workouts->isEmpty()) {
            return ['recommendations' => [], 'message' => 'No workouts found in analysis window'];
        }

        // Group blocks by routine title AND exercise name (to handle per-routine progression)
        $exercisesByRoutineAndName = [];
        foreach ($workouts as $workout) {
            $routineTitle = $workout->target?->title ?? 'Unknown Routine';

            foreach ($workout->blocks as $block) {
                $exerciseName = $block->metadata['exercise_name'] ?? 'Unknown';
                $key = "{$routineTitle}::{$exerciseName}";

                if (! isset($exercisesByRoutineAndName[$key])) {
                    $exercisesByRoutineAndName[$key] = [
                        'routine' => $routineTitle,
                        'exercise' => $exerciseName,
                        'blocks' => [],
                    ];
                }

                $exercisesByRoutineAndName[$key]['blocks'][] = $block;
            }
        }

        // Analyze each routine-exercise combination
        $recommendations = [];
        foreach ($exercisesByRoutineAndName as $key => $data) {
            $recommendation = $this->analyzeExercise(
                $data['routine'],
                $data['exercise'],
                $data['blocks'],
                $goalReps,
                $rpeThreshold,
                $weightIncrementKg,
                $deloadPercentage
            );

            $recommendations[] = $recommendation;
        }

        return [
            'recommendations' => $recommendations,
            'analyzed_at' => now()->toIso8601String(),
            'config' => $config,
        ];
    }

    /**
     * Format notes field with narrative and new target.
     */
    public function formatNotes(array $recommendation): string
    {
        $narrative = $recommendation['narrative'] ?? '';
        $target = $recommendation['new_target'] ?? '';

        return trim("{$narrative} | {$target}");
    }

    /**
     * Parse notes field to extract target configuration.
     * Format: "15:kg@5" means target 15 reps, increment by 5kg.
     */
    public function parseNotesTarget(string $notes): ?array
    {
        if (preg_match('/(\d+):(kg|lb|reps)@([\d.]+)/', $notes, $matches)) {
            return [
                'target_reps' => (int) $matches[1],
                'increment_type' => $matches[2],
                'increment_amount' => (float) $matches[3],
            ];
        }

        return null;
    }

    /**
     * Round a value to the nearest increment.
     */
    public function roundToIncrement(float $value, float $increment): float
    {
        if ($increment <= 0) {
            return $value;
        }

        return round($value / $increment) * $increment;
    }

    /**
     * Analyze a specific exercise and generate recommendation.
     */
    private function analyzeExercise(
        string $routine,
        string $exerciseName,
        array $blocks,
        int $goalReps,
        float $rpeThreshold,
        float $weightIncrementKg,
        float $deloadPercentage
    ): array {
        // Find heaviest set (not just last set)
        $heaviestSet = null;
        $maxWeight = 0;

        foreach ($blocks as $block) {
            $weight = $block->metadata['weight'] ?? 0;
            if ($weight > $maxWeight) {
                $maxWeight = $weight;
                $heaviestSet = $block;
            }
        }

        if (! $heaviestSet) {
            return [
                'routine' => $routine,
                'exercise' => $exerciseName,
                'action' => 'maintain',
                'reason' => 'No set data found',
                'narrative' => '▶️ Maintain',
                'new_target' => null,
            ];
        }

        $reps = $heaviestSet->metadata['reps'] ?? 0;
        $weight = $heaviestSet->metadata['weight'] ?? 0;
        $unit = $heaviestSet->metadata['unit'] ?? 'kg';
        $rpe = $heaviestSet->metadata['rpe'] ?? null;

        // Parse existing notes for targets (format: "15:kg@5")
        $currentTarget = $this->parseNotesTarget($heaviestSet->metadata['notes'] ?? '');
        $targetReps = $currentTarget['target_reps'] ?? $goalReps;
        $incrementType = $currentTarget['increment_type'] ?? $unit;
        $incrementAmount = $currentTarget['increment_amount'] ?? $weightIncrementKg;

        // Decision logic based on inspiration code
        if ($reps >= $goalReps && $rpe !== null && $rpe <= $rpeThreshold) {
            // Increase weight (rounded to increment)
            $newWeight = $this->roundToIncrement($weight + $incrementAmount, $incrementAmount);
            $newTargetReps = $goalReps;

            return [
                'routine' => $routine,
                'exercise' => $exerciseName,
                'action' => 'increase_weight',
                'reason' => "Achieved {$reps} reps at RPE {$rpe}",
                'narrative' => "⬆️ Increased Weight by {$incrementAmount}{$unit}",
                'new_target' => "{$newTargetReps}:{$incrementType}@{$incrementAmount}",
                'new_weight' => $newWeight,
                'new_reps' => $newTargetReps,
                'current_weight' => $weight,
                'current_reps' => $reps,
                'current_rpe' => $rpe,
                'current_unit' => $unit,
            ];
        } elseif ($reps < $goalReps && $rpe !== null && $rpe < $rpeThreshold) {
            // Add reps (rounded to increment)
            $repIncrement = $this->roundToIncrement(2, 1); // Default 2 rep increment
            $newTargetReps = min($reps + $repIncrement, $goalReps);

            return [
                'routine' => $routine,
                'exercise' => $exerciseName,
                'action' => 'increase_reps',
                'reason' => "Only {$reps} reps but RPE {$rpe} is low",
                'narrative' => "⬆️ Increase Reps by {$repIncrement}",
                'new_target' => "{$newTargetReps}:reps@{$repIncrement}",
                'new_weight' => $weight,
                'new_reps' => $newTargetReps,
                'current_weight' => $weight,
                'current_reps' => $reps,
                'current_rpe' => $rpe,
                'current_unit' => $unit,
            ];
        } elseif ($reps < ($goalReps - 4) && $rpe !== null && $rpe >= 9.5) {
            // Deload (rounded to increment)
            $deloadWeight = $weight * ($deloadPercentage / 100);
            $newWeight = $this->roundToIncrement($deloadWeight, $incrementAmount);

            return [
                'routine' => $routine,
                'exercise' => $exerciseName,
                'action' => 'deload',
                'reason' => "Only {$reps} reps at high RPE {$rpe}",
                'narrative' => "⏪ Deloaded to {$deloadPercentage}%",
                'new_target' => "{$goalReps}:{$incrementType}@{$incrementAmount}",
                'new_weight' => $newWeight,
                'new_reps' => $goalReps,
                'current_weight' => $weight,
                'current_reps' => $reps,
                'current_rpe' => $rpe,
                'current_unit' => $unit,
            ];
        } else {
            // Maintain
            return [
                'routine' => $routine,
                'exercise' => $exerciseName,
                'action' => 'maintain',
                'reason' => 'Performance within expected range',
                'narrative' => '▶️ Maintain',
                'new_target' => "{$targetReps}:{$incrementType}@{$incrementAmount}",
                'new_weight' => $weight,
                'new_reps' => $targetReps,
                'current_weight' => $weight,
                'current_reps' => $reps,
                'current_rpe' => $rpe,
                'current_unit' => $unit,
            ];
        }
    }
}
