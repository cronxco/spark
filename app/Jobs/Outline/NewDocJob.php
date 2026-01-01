<?php

namespace App\Jobs\Outline;

use App\Integrations\Outline\OutlineApi;
use App\Models\Integration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NewDocJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 30, 60];

    public function __construct(
        public Integration $integration,
        public string $title,
        public string $collectionId,
        public ?string $parentId = null
    ) {}

    public function handle(): void
    {
        $api = new OutlineApi($this->integration);

        // Check if document already exists
        $existing = $api->searchSingleDocument([
            'collectionId' => $this->collectionId,
            'query' => $this->title,
        ]);

        if ($existing && ($existing['document']['title'] ?? '') === $this->title) {
            Log::info('NewDocJob: Document already exists, skipping', [
                'title' => $this->title,
                'collection_id' => $this->collectionId,
            ]);

            return;
        }

        $api->createDocument($this->title, $this->collectionId, $this->parentId, true);
    }
}
