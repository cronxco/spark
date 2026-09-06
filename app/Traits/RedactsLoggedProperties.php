<?php

namespace App\Traits;

use Spatie\Activitylog\Contracts\Activity;

/**
 * Keeps credentials out of the activity log without discarding the diff.
 *
 * Spatie's logFillable() reads attributes through their casts, so anything a
 * model stores inside a fillable array attribute lands in the changelog in
 * plaintext — Integration::$configuration carries api_key for several plugins,
 * and IntegrationGroup::$auth_metadata carries tokens and cookies.
 *
 * logExcept() would drop the whole attribute and with it every legitimate
 * setting change, so instead each activity is tapped on its way to the database
 * and only the secret leaves are replaced. sanitizeData() already recurses by
 * key name against sensitive_log_keys(), so there is one redaction list rather
 * than two that drift apart.
 *
 * Spatie calls this from ActivityLogger::log() whenever the subject defines it.
 */
trait RedactsLoggedProperties
{
    public function tapActivity(Activity $activity, string $eventName): void
    {
        $properties = $activity->properties;

        if ($properties === null) {
            return;
        }

        $activity->properties = collect(sanitizeData($properties->toArray()));
    }
}
