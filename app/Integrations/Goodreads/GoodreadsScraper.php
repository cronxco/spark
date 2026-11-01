<?php

namespace App\Integrations\Goodreads;

use App\Integrations\Fetch\PlaywrightFetchClient;
use App\Models\EventObject;
use App\Models\Integration;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

class GoodreadsScraper
{
    public function __construct(private PlaywrightFetchClient $browser, private GoodreadsPageParser $parser) {}

    public function shelf(Integration $integration, string $shelf, int $page): array
    {
        [$userId, $timezone] = $this->settings($integration);
        $url = GoodreadsPlugin::buildShelfPageUrl($userId, $shelf, $page);
        $data = $this->parser->shelf($this->html($integration, $url), $userId, $shelf, $page, $timezone);
        $data['activity'] = $this->activity($integration);

        return [...$data, 'kind' => 'shelf', 'shelf' => $shelf, 'observed_at' => now()->toIso8601String()];
    }

    public function progress(Integration $integration, int $page): array
    {
        [$userId, $timezone] = $this->settings($integration);
        $url = GoodreadsPlugin::buildStatusPageUrl($userId, $page);
        $data = $this->parser->statuses($this->html($integration, $url), $userId, $page, $timezone);
        foreach ($data['items'] as &$item) {
            if ($item['progress_percentage'] === null && $item['progress_pages'] === null) {
                $status = $this->html($integration, 'https://www.goodreads.com/user_status/show/' . $item['status_id']);
                $reviewId = $this->parser->reviewId($status);
                $review = $this->html($integration, 'https://www.goodreads.com/review/show/' . $reviewId);
                $item['progress_percentage'] = $this->parser->reviewPercentage($review, $item['status_id']);
                $item['review_id'] = $reviewId;
            }
            if ($item['progress_pages'] !== null) {
                $book = $this->book($integration, $item['book_id']);
                $pages = $book['num_pages'] ?? null;
                if (! $pages || $item['progress_pages'] > $pages) {
                    throw new RuntimeException('Cannot convert Goodreads page progress to a percentage without a valid edition page count.');
                }
                $item['progress_percentage'] = (int) floor($item['progress_pages'] / $pages * 100);
            }
        }
        unset($item);
        if ($page === 1) {
            $data['items'] = array_merge($data['items'], array_values(array_filter($this->activity($integration), fn ($item) => $item['type'] === 'start_reading')));
        }

        return [...$data, 'kind' => 'progress', 'observed_at' => now()->toIso8601String()];
    }

    public function book(Integration $integration, string $bookId): array
    {
        $this->settings($integration);
        if (! ctype_digit($bookId)) {
            throw new RuntimeException('Invalid Goodreads book ID.');
        }

        return Cache::remember('goodreads:book:' . $integration->user_id . ':' . $bookId, now()->addDay(), function () use ($integration, $bookId) {
            return [...$this->parser->book($this->html($integration, 'https://www.goodreads.com/book/show/' . $bookId), $bookId), 'details_fetched_at' => now()->toIso8601String()];
        });
    }

    public function needsBookDetails(Integration $integration, string $bookId): bool
    {
        $book = EventObject::where('user_id', $integration->user_id)->where('type', 'goodreads_book')
            ->where(fn ($query) => $query->where('metadata->book_id', $bookId)->orWhere('metadata->book_id', (int) $bookId))->first();
        $fetched = $book?->metadata['details_fetched_at'] ?? null;

        return $fetched === null || now()->subWeek()->gt($fetched);
    }

    private function activity(Integration $integration): array
    {
        [$userId, $timezone] = $this->settings($integration);

        return Cache::remember('goodreads:activity:' . $integration->user_id . ':' . $userId . ':' . $timezone, now()->addMinutes(5), function () use ($integration, $userId, $timezone) {
            return $this->parser->activity($this->html($integration, 'https://www.goodreads.com/user/show/' . $userId), $userId, $timezone);
        });
    }

    private function settings(Integration $integration): array
    {
        $metadata = $integration->group->auth_metadata ?? [];
        $userId = (string) ($metadata['user_id'] ?? '');
        $timezone = $metadata['source_timezone'] ?? 'America/Los_Angeles';
        if (! ctype_digit($userId) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('Configure a numeric Goodreads user ID and valid Goodreads display timezone in the integration group settings.');
        }

        return [$userId, $timezone];
    }

    private function html(Integration $integration, string $url): string
    {
        $group = $integration->user->integrationGroups()->where('service', 'fetch')->first() ?? $integration->group;
        $key = 'goodreads:requests:' . $integration->user_id;
        if (RateLimiter::tooManyAttempts($key, 30)) {
            throw new RuntimeException('Goodreads request limit reached; the job will retry with backoff.');
        }
        RateLimiter::hit($key, 60);
        $result = $this->browser->fetchForMcp($url, $group);
        $final = parse_url($result['url'] ?? '');
        $expectedPath = parse_url($url, PHP_URL_PATH);
        $status = $result['status'] ?? 200;
        if (! ($result['success'] ?? false) || ($result['html_truncated'] ?? false)
            || $status >= 400 || ! in_array($final['host'] ?? '', ['www.goodreads.com', 'goodreads.com'], true)
            || ! preg_match('~^' . preg_quote($expectedPath, '~') . '(?:[-.][^/]*)?$~', $final['path'] ?? '')
            || preg_match('~^/(?:user/sign_in|ap/signin|user/new)~', $final['path'] ?? '')) {
            throw new RuntimeException('Goodreads page fetch failed, was truncated, or requires a browser login. ' . ($result['error'] ?? ''));
        }

        return $result['html'];
    }
}
