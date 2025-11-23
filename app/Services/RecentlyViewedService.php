<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Service for managing and retrieving recently viewed items.
 *
 * This service uses the existing activity_log table with event type 'viewed'
 * to track and retrieve a user's recently viewed Events, EventObjects, and Blocks.
 */
class RecentlyViewedService
{
    /**
     * The default number of recently viewed items to retrieve.
     */
    public const DEFAULT_LIMIT = 20;

    /**
     * Get recently viewed items for a user.
     *
     * Returns a collection of items (Events, EventObjects, Blocks) that the user
     * has recently viewed, ordered by most recent first.
     *
     * @param User $user The user to get recently viewed items for
     * @param int $limit Maximum number of items to return
     * @param array|null $types Filter by subject types (Event::class, EventObject::class, Block::class)
     * @return Collection Collection of recently viewed models with their view timestamps
     */
    public function getRecentlyViewed(User $user, int $limit = self::DEFAULT_LIMIT, ?array $types = null): Collection
    {
        $query = Activity::query()
            ->where('causer_type', User::class)
            ->where('causer_id', $user->id)
            ->where('event', 'viewed')
            ->whereNotNull('subject_type')
            ->whereNotNull('subject_id');

        // Filter by specific subject types if provided
        if ($types !== null && count($types) > 0) {
            $query->whereIn('subject_type', $types);
        } else {
            // Default: only look for our main model types
            $query->whereIn('subject_type', [
                Event::class,
                EventObject::class,
                Block::class,
            ]);
        }

        // Get the most recent view for each unique subject (deduped)
        // We use a subquery to get the latest view per subject
        $latestViewsSubquery = Activity::query()
            ->selectRaw('MAX(id) as id')
            ->where('causer_type', User::class)
            ->where('causer_id', $user->id)
            ->where('event', 'viewed')
            ->whereNotNull('subject_type')
            ->whereNotNull('subject_id');

        if ($types !== null && count($types) > 0) {
            $latestViewsSubquery->whereIn('subject_type', $types);
        } else {
            $latestViewsSubquery->whereIn('subject_type', [
                Event::class,
                EventObject::class,
                Block::class,
            ]);
        }

        $latestViewsSubquery->groupBy('subject_type', 'subject_id');

        // Get the activity records for these latest views
        $activities = Activity::query()
            ->whereIn('id', $latestViewsSubquery)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        // Load the actual models and return with metadata
        return $activities->map(function (Activity $activity) {
            $model = $this->loadSubject($activity);

            if (!$model) {
                return null;
            }

            return (object) [
                'model' => $model,
                'type' => $activity->subject_type,
                'type_label' => $this->getTypeLabel($activity->subject_type),
                'viewed_at' => $activity->created_at,
                'id' => $model->id,
            ];
        })->filter();
    }

    /**
     * Get recently viewed events for a user.
     *
     * @param User $user The user to get recently viewed events for
     * @param int $limit Maximum number of items to return
     * @return Collection Collection of recently viewed Event models
     */
    public function getRecentlyViewedEvents(User $user, int $limit = self::DEFAULT_LIMIT): Collection
    {
        return $this->getRecentlyViewed($user, $limit, [Event::class])
            ->pluck('model');
    }

    /**
     * Get recently viewed objects for a user.
     *
     * @param User $user The user to get recently viewed objects for
     * @param int $limit Maximum number of items to return
     * @return Collection Collection of recently viewed EventObject models
     */
    public function getRecentlyViewedObjects(User $user, int $limit = self::DEFAULT_LIMIT): Collection
    {
        return $this->getRecentlyViewed($user, $limit, [EventObject::class])
            ->pluck('model');
    }

    /**
     * Get recently viewed blocks for a user.
     *
     * @param User $user The user to get recently viewed blocks for
     * @param int $limit Maximum number of items to return
     * @return Collection Collection of recently viewed Block models
     */
    public function getRecentlyViewedBlocks(User $user, int $limit = self::DEFAULT_LIMIT): Collection
    {
        return $this->getRecentlyViewed($user, $limit, [Block::class])
            ->pluck('model');
    }

    /**
     * Get the count of recently viewed items for a user.
     *
     * @param User $user The user to count recently viewed items for
     * @param array|null $types Filter by subject types
     * @return int The count of unique recently viewed items
     */
    public function getRecentlyViewedCount(User $user, ?array $types = null): int
    {
        $subjectTypes = $types !== null && count($types) > 0
            ? $types
            : [Event::class, EventObject::class, Block::class];

        // Use a subquery to count unique subject_type/subject_id combinations
        // PostgreSQL doesn't support COUNT(DISTINCT col1, col2)
        $uniqueSubjects = Activity::query()
            ->selectRaw('1')
            ->where('causer_type', User::class)
            ->where('causer_id', $user->id)
            ->where('event', 'viewed')
            ->whereNotNull('subject_type')
            ->whereNotNull('subject_id')
            ->whereIn('subject_type', $subjectTypes)
            ->groupBy('subject_type', 'subject_id');

        return $uniqueSubjects->get()->count();
    }

    /**
     * Load the subject model from an activity record.
     *
     * @param Activity $activity The activity record
     * @return \Illuminate\Database\Eloquent\Model|null The loaded model or null
     */
    protected function loadSubject(Activity $activity): ?\Illuminate\Database\Eloquent\Model
    {
        $subjectType = $activity->subject_type;
        $subjectId = $activity->subject_id;

        if (!$subjectType || !$subjectId) {
            return null;
        }

        // Check if the model class exists
        if (!class_exists($subjectType)) {
            return null;
        }

        // Load the model with appropriate relationships based on type
        try {
            switch ($subjectType) {
                case Event::class:
                    return Event::with(['actor', 'target', 'integration', 'tags'])
                        ->find($subjectId);

                case EventObject::class:
                    return EventObject::with(['tags'])
                        ->find($subjectId);

                case Block::class:
                    return Block::with(['event', 'event.integration'])
                        ->find($subjectId);

                default:
                    return $subjectType::find($subjectId);
            }
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get a human-readable label for a subject type.
     *
     * @param string $subjectType The fully qualified class name
     * @return string The human-readable label
     */
    protected function getTypeLabel(string $subjectType): string
    {
        return match ($subjectType) {
            Event::class => 'Event',
            EventObject::class => 'Object',
            Block::class => 'Block',
            default => class_basename($subjectType),
        };
    }

    /**
     * Purge old view records for a user beyond the retention window.
     *
     * This keeps only the DEFAULT_LIMIT most recent unique views per user,
     * deleting older view records to prevent the activity log from growing indefinitely.
     *
     * @param User $user The user to purge old views for
     * @param int|null $retentionLimit Maximum views to retain (defaults to DEFAULT_LIMIT)
     * @return int Number of records deleted
     */
    public function purgeOldViewsForUser(User $user, ?int $retentionLimit = null): int
    {
        $limit = $retentionLimit ?? self::DEFAULT_LIMIT;

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
     * Purge old view records for all users.
     *
     * This is useful for scheduled cleanup tasks.
     *
     * @param int|null $retentionLimit Maximum views to retain per user
     * @return array Array with 'users_processed' and 'total_deleted' counts
     */
    public function purgeOldViewsForAllUsers(?int $retentionLimit = null): array
    {
        $subjectTypes = [
            Event::class,
            EventObject::class,
            Block::class,
        ];

        // Get all users who have view records
        $userIds = Activity::query()
            ->where('event', 'viewed')
            ->whereIn('subject_type', $subjectTypes)
            ->distinct()
            ->pluck('causer_id');

        $totalDeleted = 0;
        $usersProcessed = 0;

        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if ($user) {
                $deleted = $this->purgeOldViewsForUser($user, $retentionLimit);
                $totalDeleted += $deleted;
                $usersProcessed++;
            }
        }

        return [
            'users_processed' => $usersProcessed,
            'total_deleted' => $totalDeleted,
        ];
    }
}
