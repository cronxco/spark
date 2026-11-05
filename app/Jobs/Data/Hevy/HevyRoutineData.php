<?php

namespace App\Jobs\Data\Hevy;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\EventObject;
use App\Models\Relationship;

class HevyRoutineData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'hevy';
    }

    protected function getJobType(): string
    {
        return 'routine';
    }

    protected function process(): void
    {
        $routines = $this->rawData['routines'] ?? [];

        foreach ($routines as $routine) {
            $this->createRoutineObject($routine);
        }
    }

    private function createRoutineObject(array $routine): EventObject
    {
        $routineId = $routine['id'] ?? null;
        $title = $routine['title'] ?? $routine['name'] ?? 'Routine';

        $routineObject = EventObject::firstOrCreate([
            'user_id' => $this->integration->user_id,
            'concept' => 'routine',
            'type' => 'hevy_routine',
            'title' => $title,
        ], [
            'time' => now(),
            'content' => $routine['description'] ?? 'Hevy workout routine',
            'url' => null,
        ]);

        // Store full routine structure in metadata
        $routineObject->update([
            'metadata' => array_merge($routine, [
                'hevy_routine_id' => $routineId,
                'last_synced_at' => now()->toIso8601String(),
            ]),
        ]);

        // Create exercise template objects for each exercise in routine
        $exercises = $routine['exercises'] ?? [];
        foreach ($exercises as $exercise) {
            $this->createExerciseTemplate($exercise, $routineObject);
        }

        return $routineObject;
    }

    private function createExerciseTemplate(array $exercise, EventObject $routine): EventObject
    {
        $exerciseName = $exercise['title'] ?? $exercise['name'] ?? 'Exercise';

        // Create unique title combining routine and exercise name
        // to allow same exercise in different routines
        $uniqueTitle = "{$routine->title} - {$exerciseName}";

        $template = EventObject::firstOrCreate([
            'user_id' => $this->integration->user_id,
            'concept' => 'exercise_template',
            'type' => 'hevy_exercise_template',
            'title' => $uniqueTitle,
        ], [
            'time' => now(),
            'content' => 'Exercise template from Hevy routine',
            'url' => null,
        ]);

        // Store exercise configuration (sets, reps, weight, notes)
        $template->update(['metadata' => array_merge($exercise, [
            'routine_id' => $routine->id,
            'routine_title' => $routine->title,
        ])]);

        // Link template to routine using relationship (check for existing relationship first)
        $existingRelationship = Relationship::where('user_id', $this->integration->user_id)
            ->where('from_type', EventObject::class)
            ->where('from_id', $template->id)
            ->where('to_type', EventObject::class)
            ->where('to_id', $routine->id)
            ->where('type', 'part_of')
            ->first();

        if (! $existingRelationship) {
            Relationship::createRelationship([
                'user_id' => $this->integration->user_id,
                'from_type' => EventObject::class,
                'from_id' => $template->id,
                'to_type' => EventObject::class,
                'to_id' => $routine->id,
                'type' => 'part_of',
            ]);
        }

        return $template;
    }
}
