<?php

namespace App\Jobs\OAuth\Goodreads;

use App\Integrations\Goodreads\GoodreadsPlugin;
use App\Integrations\Goodreads\GoodreadsScraper;
use App\Jobs\Base\BaseFetchJob;
use App\Jobs\Data\Goodreads\GoodreadsPageData;
use App\Models\Integration;
use RuntimeException;

class GoodreadsShelfPull extends BaseFetchJob
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
        return 'shelf';
    }

    protected function fetchData(): array
    {
        $shelf = GoodreadsPlugin::getShelfFromInstanceType($this->integration->instance_type)
            ?? throw new RuntimeException('Invalid Goodreads shelf instance type.');

        return app(GoodreadsScraper::class)->shelf($this->integration, $shelf, $this->page);
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
