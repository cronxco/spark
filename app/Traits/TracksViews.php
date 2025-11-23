<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;

/**
 * Trait for tracking views of models in the activity log.
 *
 * This uses the existing activity_log table with event type 'viewed'
 * to track when users view specific items.
 */
trait TracksViews
{
    /**
     * Log a view activity for this model.
     *
     * @return void
     */
    public function logView(): void
    {
        $user = Auth::guard('web')->user();

        if (!$user) {
            return;
        }

        activity('changelog')
            ->performedOn($this)
            ->causedBy($user)
            ->event('viewed')
            ->log('viewed');
    }

    /**
     * Check if this model was recently viewed by the current user.
     * This can be used to prevent logging duplicate views in quick succession.
     *
     * @param int $withinMinutes Only check for views within this many minutes
     * @return bool
     */
    public function wasRecentlyViewed(int $withinMinutes = 5): bool
    {
        $user = Auth::guard('web')->user();

        if (!$user) {
            return false;
        }

        return \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', get_class($this))
            ->where('subject_id', $this->id)
            ->where('causer_type', get_class($user))
            ->where('causer_id', $user->id)
            ->where('event', 'viewed')
            ->where('created_at', '>=', now()->subMinutes($withinMinutes))
            ->exists();
    }

    /**
     * Log a view if not recently viewed.
     * This prevents flooding the activity log with duplicate views.
     *
     * @param int $withinMinutes Only log if not viewed within this many minutes
     * @return bool Whether a new view was logged
     */
    public function logViewIfNotRecent(int $withinMinutes = 5): bool
    {
        if ($this->wasRecentlyViewed($withinMinutes)) {
            return false;
        }

        $this->logView();
        return true;
    }
}
