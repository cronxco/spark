<?php

namespace App\Jobs\OAuth\Goodreads;

use App\Integrations\Goodreads\GoodreadsScraper;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Goodreads\GoodreadsPageData;
use App\Models\Integration;

class GoodreadsBookPull extends BaseFetchJob
{
    public function __construct(Integration $integration, public string $bookId)
    {
        parent::__construct($integration);
    }

    protected function getServiceName(): string
    {
        return 'goodreads';
    }

    protected function getJobType(): string
    {
        return 'book';
    }

    public function uniqueId(): string
    {
        return parent::uniqueId().'_'.$this->bookId;
    }

    protected function fetchData(): array
    {
        return ['kind' => 'book', 'items' => [app(GoodreadsScraper::class)->book($this->integration, $this->bookId)]];
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        GoodreadsPageData::dispatch($this->integration, $rawData)->onQueue('pull');
    }

    public static function dispatchMissing(Integration $integration, array $items): void
    {
        $scraper = app(GoodreadsScraper::class);
        $ids = array_values(array_unique(array_column($items, 'book_id')));
        foreach ($ids as $index => $bookId) {
            if ($scraper->needsBookDetails($integration, (string) $bookId)) {
                self::dispatch($integration, (string) $bookId)->onQueue('pull')->delay(now()->addSeconds(($index + 1) * 5));
            }
        }
    }
}
