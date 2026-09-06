<?php

namespace App\Traits;

use App\Casts\EncryptedJsonSecrets;
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
 * and only the credential leaves governed by EncryptedJsonSecrets are replaced.
 * The narrower list deliberately preserves ordinary configuration fields such
 * as `key`, `auth`, and `server_url` while recursively redacting cookies.
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

        $activity->properties = collect(EncryptedJsonSecrets::redact($properties->toArray()));
    }
}
