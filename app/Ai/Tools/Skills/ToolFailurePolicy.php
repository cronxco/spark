<?php

namespace App\Ai\Tools\Skills;

/**
 * What a failed call to one of a skill's tools does to the run.
 */
enum ToolFailurePolicy
{
    /** An optional source: the model leaves out what depended on it. */
    case CarryOn;

    /**
     * A tool the skill must succeed with: the model sees why the call was
     * rejected and may correct it and call again, a few times at most. The
     * run still fails if the tool never succeeds.
     */
    case Retry;

    /** Any other write: the run ends, since a half-done write is not safe to repeat. */
    case EndRun;
}
