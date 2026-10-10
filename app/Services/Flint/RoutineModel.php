<?php

namespace App\Services\Flint;

use App\Services\Ai\AiModel;
use RuntimeException;

/**
 * The model a routine driver runs on, so each output can say what wrote it.
 *
 * Only the openai driver's model is knowable here: it runs in-process on the
 * reasoning model. The webhook driver hands the run to a Claude Code Routine
 * whose model is chosen in the Routine itself, so it records as unknown.
 */
class RoutineModel
{
    public static function for(?string $driver): ?string
    {
        if ($driver !== 'openai') {
            return null;
        }

        try {
            return AiModel::Reasoning->model();
        } catch (RuntimeException) {
            return null;
        }
    }
}
