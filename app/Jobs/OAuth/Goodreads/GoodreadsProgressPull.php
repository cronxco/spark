<?php

namespace App\Jobs\OAuth\Goodreads;

use App\Integrations\Goodreads\GoodreadsScraper;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Goodreads\GoodreadsPageData;
use App\Models\Integration;

class GoodreadsProgressPull extends BaseFetchJob
{
    public int $page = 1;

    public function __construct(Integration $integration, int $page = 1)
    {
        parent::__construct($integration);
        $this->page = $page;
    }

    public function uniqueId(): string
    {
        return parent::uniqueId() . '_page_' . $this->page;
    }

    protected function getServiceName(): string
    {
        return 'goodreads';
    }

    protected function getJobType(): string
    {
        return 'progress';
    }

    protected function fetchData(): array
    {
        return app(GoodreadsScraper::class)->progress($this->integration, $this->page);
    }

    protected function dispatchProcessingJobs(array $rawData): void
    {
        GoodreadsPageData::dispatch($this->integration, $rawData)->onQueue('pull');
        GoodreadsBookPull::dispatchMissing($this->integration, $rawData['items']);
        if ($rawData['next_page'] !== null) {
            self::dispatch($this->integration, $rawData['next_page'])->onQueue('pull')->delay(now()->addSeconds(5));
        }
    }
}
