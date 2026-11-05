<?php

namespace App\Traits;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;

/**
 * Trait for tracking views of models in the activity log.
 *
 * This uses the existing activity_log table with event type 'viewed'
 * to track when users view specific items.
 */
trait TracksViews
{
    /**
     * Maximum number of recently viewed items to retain per user.
     */
    public const MAX_RECENT_VIEWS = 20;

    /**
     * Purge old view records for a user beyond the retention window.
     *
     * This keeps only the MAX_RECENT_VIEWS most recent unique views per user,
     * deleting older view records to prevent the activity log from growing indefinitely.
     *
     * @param  User  $user  The user to purge old views for
     * @param  int|null  $retentionLimit  Maximum views to retain (defaults to MAX_RECENT_VIEWS)
     * @return int Number of records deleted
     */
    public static function purgeOldViews(User $user, ?int $retentionLimit = null): int
    {
        $limit = $retentionLimit ?? self::MAX_RECENT_VIEWS;

        // Get the IDs of the most recent view for each unique subject
        // We want to keep only the latest view per subject, up to the limit
        $subjectTypes = [
            Event::class,
            EventObject::class,
            Block::class,
        ];

        // Get the most recent activity ID for each unique subject
        $latestViewIds = Activity::query()
            ->selectRaw('MAX(id) as id')
            ->where('causer_type', User::class)
            ->where('causer_id', $user->id)
            ->where('event', 'viewed')
            ->whereIn('subject_type', $subjectTypes)
            ->whereNotNull('subject_type')
            ->whereNotNull('subject_id')
            ->groupBy('subject_type', 'subject_id')
            ->pluck('id');

        // Get the top N most recent unique views to keep
        $idsToKeep = Activity::query()
            ->whereIn('id', $latestViewIds)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->pluck('id');

        // Delete all view records for this user that are not in the keep list
        $deleted = Activity::query()
            ->where('causer_type', User::class)
            ->where('causer_id', $user->id)
            ->where('event', 'viewed')
            ->whereIn('subject_type', $subjectTypes)
            ->whereNotIn('id', $idsToKeep)
            ->delete();

        return $deleted;
    }

    /**
     * Log a view activity for this model.
     */
    public function logView(): void
    {
        $user = Auth::guard('web')->user();

        if (! $user) {
            return;
        }

        activity('changelog')
            ->performedOn($this)
            ->causedBy($user)
            ->event('viewed')
            ->log('viewed');

        // Purge old views to maintain the retention window
        static::purgeOldViews($user);
    }

    /**
     * Check if this model was recently viewed by the current user.
     * This can be used to prevent logging duplicate views in quick succession.
     *
     * @param  int  $withinMinutes  Only check for views within this many minutes
     */
    public function wasRecentlyViewed(int $withinMinutes = 5): bool
    {
        $user = Auth::guard('web')->user();

        if (! $user) {
            return false;
        }

        return Activity::query()
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
     * @param  int  $withinMinutes  Only log if not viewed within this many minutes
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
