<?php

namespace App\Jobs\Fetch;

use App\Models\EventObject;
use App\Models\Integration;
use App\Services\Fetch\Expansion\LinkListExpander;
use App\Services\Fetch\Expansion\ListItem;
use App\Services\Fetch\FetchMetadata;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bookmarks the new articles found on a list page.
 *
 * Runs separately from the page fetch so it can retry on its own: a one-time
 * list bookmark is already marked fetched (and disabled) by then.
 */
class ExpandLinkListJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public $uniqueFor = 3600;

    /**
     * @param  list<array{url: string, title: ?string}>  $items
     * @param  array<string, mixed>  $assessment
     */
    public function __construct(
        public Integration $integration,
        public string $listBookmarkId,
        public array $items,
        public array $assessment,
    ) {}

    public function uniqueId(): string
    {
        return $this->listBookmarkId . ':' . sha1((string) json_encode($this->items));
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('list-expand:' . $this->listBookmarkId))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(LinkListExpander $expander): void
    {
        $list = EventObject::find($this->listBookmarkId);

        if (! $list) {
            Log::warning('Fetch: List bookmark vanished before expansion', ['webpage_id' => $this->listBookmarkId]);

            return;
        }

        $result = $expander->expandBookmark(
            $this->integration,
            $list,
            array_map(fn (array $item): ListItem => ListItem::fromArray($item), $this->items),
            $this->assessment,
        );

        FetchMetadata::mutate($list, function (array $metadata) use ($result): array {
            $metadata['list_detection'] = array_merge($metadata['list_detection'] ?? [], array_filter([
                'expansion_status' => 'complete',
                'shadow' => false,
                'expanded_at' => now()->toIso8601String(),
                'last_new_count' => $result->newCount(),
                'latest_expansion_event_id' => $result->event?->id ? (string) $result->event->id : null,
            ], fn (mixed $value): bool => $value !== null));

            return $metadata;
        });

        Log::info('Fetch: List expanded', [
            'webpage_id' => $list->id,
            'items' => count($this->items),
            'new' => $result->newCount(),
            'cold_start' => $result->coldStart,
            'event_id' => $result->event?->id,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $list = EventObject::find($this->listBookmarkId);

        if (! $list) {
            return;
        }

        FetchMetadata::mutate($list, function (array $metadata) use ($exception): array {
            $metadata['list_detection'] = array_merge($metadata['list_detection'] ?? [], [
                'expansion_status' => 'failed',
                'expansion_error' => $exception->getMessage(),
            ]);

            return $metadata;
        });
    }
}
