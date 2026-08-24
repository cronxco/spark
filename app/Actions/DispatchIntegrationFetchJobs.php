<?php

namespace App\Actions;

use App\Jobs\Fetch\FetchScheduledUrls;
use App\Jobs\OAuth\GitHub\GitHubActivityPull;
use App\Jobs\OAuth\GoCardless\GoCardlessAccountPull;
use App\Jobs\OAuth\GoCardless\GoCardlessBalancePull;
use App\Jobs\OAuth\GoCardless\GoCardlessTransactionPull;
use App\Jobs\OAuth\Goodreads\GoodreadsProgressPull;
use App\Jobs\OAuth\Goodreads\GoodreadsShelfPull;
use App\Jobs\OAuth\GoogleCalendar\GoogleCalendarEventsPull;
use App\Jobs\OAuth\Hevy\HevyWorkoutPull;
use App\Jobs\OAuth\Immich\PeoplePull;
use App\Jobs\OAuth\Immich\PhotosPull;
use App\Jobs\OAuth\Karakeep\KarakeepBookmarksPull;
use App\Jobs\OAuth\Monzo\MonzoAccountPull;
use App\Jobs\OAuth\Monzo\MonzoBalancePull;
use App\Jobs\OAuth\Monzo\MonzoPotPull;
use App\Jobs\OAuth\Monzo\MonzoTransactionPull;
use App\Jobs\OAuth\Oura\OuraActivityPull;
use App\Jobs\OAuth\Oura\OuraCardiovascularAgePull;
use App\Jobs\OAuth\Oura\OuraEnhancedTagPull;
use App\Jobs\OAuth\Oura\OuraHeartratePull;
use App\Jobs\OAuth\Oura\OuraReadinessPull;
use App\Jobs\OAuth\Oura\OuraResiliencePull;
use App\Jobs\OAuth\Oura\OuraRestModePeriodPull;
use App\Jobs\OAuth\Oura\OuraSessionsPull;
use App\Jobs\OAuth\Oura\OuraSleepPull;
use App\Jobs\OAuth\Oura\OuraSleepRecordsPull;
use App\Jobs\OAuth\Oura\OuraSleepTimePull;
use App\Jobs\OAuth\Oura\OuraSpo2Pull;
use App\Jobs\OAuth\Oura\OuraStressPull;
use App\Jobs\OAuth\Oura\OuraTagsPull;
use App\Jobs\OAuth\Oura\OuraVO2MaxPull;
use App\Jobs\OAuth\Oura\OuraWorkoutsPull;
use App\Jobs\OAuth\Reddit\RedditSavedPull;
use App\Jobs\OAuth\Spotify\SpotifyListeningPull;
use App\Jobs\OAuth\Untappd\UntappdRssPull;
use App\Jobs\Outline\OutlinePull;
use App\Jobs\Outline\OutlinePullRecentDayNotes;
use App\Jobs\Outline\OutlinePullRecentDocuments;
use App\Jobs\RunIntegrationTask;
use App\Models\Integration;

class DispatchIntegrationFetchJobs
{
    /**
     * Dispatch the appropriate fetch jobs for the given integration.
     *
     * Returns the number of jobs dispatched.
     */
    public function dispatch(Integration $integration): int
    {
        if ($integration->isTaskInstance()) {
            RunIntegrationTask::dispatch($integration)
                ->onQueue($integration->configuration['task_queue'] ?? 'pull');

            return 1;
        }

        $fetchJobs = $this->getFetchJobsForIntegration($integration);

        foreach ($fetchJobs as $jobClass) {
            $jobClass::dispatch($integration);
        }

        return count($fetchJobs);
    }

    private function getFetchJobsForIntegration(Integration $integration): array
    {
        return match ($integration->service) {
            'monzo' => $this->getMonzoFetchJobs($integration),
            'gocardless' => $this->getGoCardlessFetchJobs($integration),
            'github' => $this->getGitHubFetchJobs($integration),
            'spotify' => $this->getSpotifyFetchJobs($integration),
            'reddit' => $this->getRedditFetchJobs($integration),
            'oura' => $this->getOuraFetchJobs($integration),
            'hevy' => $this->getHevyFetchJobs($integration),
            'outline' => $this->getOutlineFetchJobs($integration),
            'karakeep' => $this->getKarakeepFetchJobs($integration),
            'google-calendar' => $this->getGoogleCalendarFetchJobs($integration),
            'fetch' => $this->getFetchFetchJobs($integration),
            'goodreads' => $this->getGoodreadsFetchJobs($integration),
            'untappd' => $this->getUntappdFetchJobs($integration),
            'immich' => $this->getImmichFetchJobs($integration),
            default => [],
        };
    }

    private function getMonzoFetchJobs(Integration $integration): array
    {
        return match ($integration->instance_type ?: 'transactions') {
            'accounts' => [MonzoAccountPull::class],
            'transactions' => [MonzoTransactionPull::class],
            'pots' => [MonzoPotPull::class],
            'balances' => [MonzoBalancePull::class],
            default => [],
        };
    }

    private function getGoCardlessFetchJobs(Integration $integration): array
    {
        return match ($integration->instance_type ?: 'transactions') {
            'accounts' => [GoCardlessAccountPull::class],
            'transactions' => [GoCardlessTransactionPull::class],
            'balances' => [GoCardlessBalancePull::class],
            default => [],
        };
    }

    private function getGitHubFetchJobs(Integration $integration): array
    {
        return match ($integration->instance_type ?: 'activity') {
            'activity' => [GitHubActivityPull::class],
            default => [],
        };
    }

    private function getSpotifyFetchJobs(Integration $integration): array
    {
        return match ($integration->instance_type ?: 'listening') {
            'listening' => [SpotifyListeningPull::class],
            default => [],
        };
    }

    private function getRedditFetchJobs(Integration $integration): array
    {
        return match ($integration->instance_type ?: 'saved') {
            'saved' => [RedditSavedPull::class],
            default => [],
        };
    }

    private function getOuraFetchJobs(Integration $integration): array
    {
        return match ($integration->instance_type ?: 'activity') {
            'activity' => [OuraActivityPull::class],
            'sleep' => [OuraSleepPull::class],
            'sleep_records' => [OuraSleepRecordsPull::class],
            'sleep_time' => [OuraSleepTimePull::class],
            'readiness' => [OuraReadinessPull::class],
            'resilience' => [OuraResiliencePull::class],
            'rest_mode_period' => [OuraRestModePeriodPull::class],
            'stress' => [OuraStressPull::class],
            'workouts' => [OuraWorkoutsPull::class],
            'sessions' => [OuraSessionsPull::class],
            'tags' => [OuraTagsPull::class],
            'enhanced_tag' => [OuraEnhancedTagPull::class],
            'heartrate' => [OuraHeartratePull::class],
            'spo2' => [OuraSpo2Pull::class],
            'cardiovascular_age' => [OuraCardiovascularAgePull::class],
            'vo2_max' => [OuraVO2MaxPull::class],
            default => [],
        };
    }

    private function getHevyFetchJobs(Integration $integration): array
    {
        return match ($integration->instance_type ?: 'workouts') {
            'workouts' => [HevyWorkoutPull::class],
            default => [],
        };
    }

    private function getOutlineFetchJobs(Integration $integration): array
    {
        return match ($integration->instance_type ?: 'recent_daynotes') {
            'recent_daynotes' => [OutlinePullRecentDayNotes::class],
            'recent_documents' => [OutlinePullRecentDocuments::class],
            'pull' => [OutlinePull::class],
            default => [OutlinePullRecentDayNotes::class],
        };
    }

    private function getKarakeepFetchJobs(Integration $integration): array
    {
        return match ($integration->instance_type ?: 'bookmarks') {
            'bookmarks' => [KarakeepBookmarksPull::class],
            default => [],
        };
    }

    private function getGoogleCalendarFetchJobs(Integration $integration): array
    {
        return match ($integration->instance_type ?: 'events') {
            'events' => [GoogleCalendarEventsPull::class],
            default => [],
        };
    }

    private function getFetchFetchJobs(Integration $integration): array
    {
        return match ($integration->instance_type ?: 'fetcher') {
            'fetcher' => [FetchScheduledUrls::class],
            default => [],
        };
    }

    private function getGoodreadsFetchJobs(Integration $integration): array
    {
        return match ($integration->instance_type ?: 'shelf_currently_reading') {
            'shelf_currently_reading' => [GoodreadsShelfPull::class],
            'shelf_read' => [GoodreadsShelfPull::class],
            'shelf_to_read' => [GoodreadsShelfPull::class],
            'updates_progress' => [GoodreadsProgressPull::class],
            default => [],
        };
    }

    private function getUntappdFetchJobs(Integration $integration): array
    {
        return match ($integration->instance_type ?: 'rss_feed') {
            'rss_feed' => [UntappdRssPull::class],
            default => [],
        };
    }

    private function getImmichFetchJobs(Integration $integration): array
    {
        $instanceType = $integration->instance_type ?: 'photos';
        $syncPeople = $integration->configuration['sync_people'] ?? true;

        $jobs = match ($instanceType) {
            'photos' => [PhotosPull::class],
            default => [],
        };

        if ($instanceType === 'photos' && $syncPeople) {
            $jobs[] = PeoplePull::class;
        }

        return $jobs;
    }
}
