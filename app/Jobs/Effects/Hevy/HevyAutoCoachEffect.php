<?php

namespace App\Jobs\Effects\Hevy;

use App\Jobs\Base\BaseEffectJob;

class HevyAutoCoachEffect extends BaseEffectJob
{
    public function uniqueId(): string
    {
        return 'hevy_auto_coach_' . $this->integration->id . '_' . now()->toDateString();
    }

    protected function execute(): array
    {
        // Step 1: Analyze
        $analyzeJob = new HevyAnalyzeProgressionEffect($this->integration, $this->parameters);
        $analyzeResult = $analyzeJob->handle();

        if (! $analyzeResult['success']) {
            return $analyzeResult;
        }

        // Step 2: Update
        $updateJob = new HevyUpdateRoutineEffect($this->integration, $this->parameters);
        $updateResult = $updateJob->handle();

        return [
            'success' => $updateResult['success'],
            'message' => 'Auto-coach completed: ' . $updateResult['message'],
            'data' => [
                'analysis' => $analyzeResult['data'],
                'update' => $updateResult['data'],
            ],
        ];
    }
}
