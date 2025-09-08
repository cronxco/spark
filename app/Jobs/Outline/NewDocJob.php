<?php

namespace App\Jobs\Outline;

use App\Integrations\Outline\OutlineApi;
use App\Models\Integration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NewDocJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Integration $integration,
        public string $title,
        public string $collectionId,
        public ?string $parentId = null
    ) {}

    public function handle(): void
    {
        $api = new OutlineApi($this->integration);
        $api->createDocument($this->title, $this->collectionId, $this->parentId, true);
    }
}
