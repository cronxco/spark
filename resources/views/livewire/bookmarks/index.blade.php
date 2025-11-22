<?php

use App\Models\Event;
use App\Models\Integration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;

new class extends \Livewire\Volt\Component
{
    use WithPagination;

    public int $perPage = 15;

    #[Computed]
    public function bookmarks()
    {
        return Event::query()
            ->whereIn('action', ['bookmarked', 'fetched', 'bookmarked_post', 'liked_post', 'reposted'])
            ->whereHas('integration', fn ($q) => $q->where('user_id', Auth::id()))
            ->with(['target', 'blocks', 'integration', 'actor'])
            ->orderByDesc('time')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function hasReddit(): bool
    {
        return Integration::where('user_id', Auth::id())
            ->where('service', 'reddit')
            ->exists();
    }

    #[Computed]
    public function hasKarakeep(): bool
    {
        return Integration::where('user_id', Auth::id())
            ->where('service', 'karakeep')
            ->exists();
    }

    #[Computed]
    public function hasBlueSky(): bool
    {
        return Integration::where('user_id', Auth::id())
            ->where('service', 'bluesky')
            ->exists();
    }

    public function getBookmarkSummary(Event $event): ?string
    {
        // Try to get tweet-sized summary from Fetch (uses 'content' field)
        $tweetSummary = $event->blocks->firstWhere('block_type', 'fetch_summary_tweet');
        if ($tweetSummary && ! empty($tweetSummary->metadata['content'])) {
            return $tweetSummary->metadata['content'];
        }

        // Try to get Karakeep AI summary (uses 'summary' field)
        $karakeepSummary = $event->blocks->firstWhere('block_type', 'bookmark_summary');
        if ($karakeepSummary && ! empty($karakeepSummary->metadata['summary'])) {
            return $karakeepSummary->metadata['summary'];
        }

        // Try to get BlueSky post content
        $postContent = $event->blocks->firstWhere('block_type', 'post_content');
        if ($postContent && ! empty($postContent->metadata['content'])) {
            return Str::limit($postContent->metadata['content'], 280);
        }

        // Last resort: event metadata description
        if (! empty($event->event_metadata['description'])) {
            return Str::limit($event->event_metadata['description'], 280);
        }

        return null;
    }

    public function getBookmarkUrl(Event $event): ?string
    {
        // Check target metadata for URL
        if ($event->target && ! empty($event->target->metadata['url'])) {
            return $event->target->metadata['url'];
        }

        // Check event metadata
        if (! empty($event->event_metadata['url'])) {
            return $event->event_metadata['url'];
        }

        // Check for link in blocks (BlueSky)
        $linkPreview = $event->blocks->firstWhere('type', 'link_preview');
        if ($linkPreview && ! empty($linkPreview->metadata['url'])) {
            return $linkPreview->metadata['url'];
        }

        return null;
    }

    public function getBookmarkTitle(Event $event): string
    {
        // Try target title first
        if ($event->target && ! empty($event->target->title)) {
            return $event->target->title;
        }

        // Try event metadata
        if (! empty($event->event_metadata['title'])) {
            return $event->event_metadata['title'];
        }

        // Fallback to action type
        return ucfirst(str_replace('_', ' ', $event->action));
    }

    public function getBookmarkImage(Event $event): ?string
    {
        // Check Fetch metadata block
        $metadataBlock = $event->blocks->firstWhere('type', 'fetch_metadata');
        if ($metadataBlock && ! empty($metadataBlock->metadata['image'])) {
            return $metadataBlock->metadata['image'];
        }

        // Check Karakeep bookmark metadata
        $karakeepMetadata = $event->blocks->firstWhere('type', 'bookmark_metadata');
        if ($karakeepMetadata && ! empty($karakeepMetadata->metadata['image'])) {
            return $karakeepMetadata->metadata['image'];
        }

        // Check BlueSky post media
        $postMedia = $event->blocks->firstWhere('type', 'post_media');
        if ($postMedia && ! empty($postMedia->metadata['images'][0])) {
            return $postMedia->metadata['images'][0];
        }

        // Check target metadata
        if ($event->target && ! empty($event->target->metadata['image'])) {
            return $event->target->metadata['image'];
        }

        return null;
    }
}; ?>

<div>
    <x-header title="Bookmarks" subtitle="All your saved content from across your integrations" separator />

    <!-- Bookmarks Grid -->
    @if ($this->bookmarks->count() > 0)
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6 mb-8">
            @foreach ($this->bookmarks as $event)
                <x-bookmark-card
                    :event="$event"
                    :title="$this->getBookmarkTitle($event)"
                    :summary="$this->getBookmarkSummary($event)"
                    :url="$this->getBookmarkUrl($event)"
                    :image="$this->getBookmarkImage($event)"
                />
            @endforeach
        </div>

        <!-- Pagination -->
        <div class="mb-8">
            {{ $this->bookmarks->links() }}
        </div>
    @else
        <div class="card bg-base-200 shadow mb-8">
            <div class="card-body">
                <div class="flex flex-col items-center text-center py-8">
                    <div class="w-16 h-16 rounded-full bg-base-300 flex items-center justify-center mb-4">
                        <x-icon name="fas-bookmark" class="w-8 h-8 text-base-content/50" />
                    </div>
                    <h3 class="text-xl font-semibold mb-2">No bookmarks yet</h3>
                    <p class="text-base-content/70 max-w-md">
                        Start saving content from your integrations. Use Fetch to monitor URLs, or bookmark posts from BlueSky, Reddit, and Karakeep.
                    </p>
                </div>
            </div>
        </div>
    @endif

    <!-- Available Bookmark Sources -->
    <div>
        <h2 class="text-xl font-semibold mb-4">Bookmark Sources</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6">
            <!-- Fetch Integration Card (Always shown) -->
            <a href="{{ route('bookmarks.fetch') }}" wire:navigate class="card bg-base-200 shadow hover:shadow-lg transition-shadow cursor-pointer">
                <div class="card-body">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-lg bg-info/10 flex items-center justify-center flex-shrink-0">
                            <x-icon name="fas-shield-halved" class="w-5 h-5 text-info" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-lg font-semibold">Fetch</h3>
                        </div>
                    </div>
                    <p class="text-sm text-base-content/70 mb-4">
                        Monitor any URL, extract content with cookie support, and get AI-powered summaries in multiple formats.
                    </p>
                    <div class="flex items-center gap-2 text-sm text-primary">
                        <span>Manage URLs</span>
                        <x-icon name="fas-arrow-right" class="w-4 h-4" />
                    </div>
                </div>
            </a>

            <!-- Reddit Integration Card (Conditional) -->
            @if ($this->hasReddit)
                <a href="{{ route('plugins.show', ['service' => 'reddit']) }}" wire:navigate class="card bg-base-200 shadow hover:shadow-lg transition-shadow cursor-pointer">
                    <div class="card-body">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-lg bg-error/10 flex items-center justify-center flex-shrink-0">
                                <x-icon name="fas-comments" class="w-5 h-5 text-error" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold">Reddit</h3>
                            </div>
                        </div>
                        <p class="text-sm text-base-content/70 mb-4">
                            Track your bookmarked posts and comments from Reddit with automatic updates.
                        </p>
                        <div class="flex items-center gap-2 text-sm text-primary">
                            <span>Manage</span>
                            <x-icon name="fas-arrow-right" class="w-4 h-4" />
                        </div>
                    </div>
                </a>
            @endif

            <!-- Karakeep Integration Card (Conditional) -->
            @if ($this->hasKarakeep)
                <a href="{{ route('plugins.show', ['service' => 'karakeep']) }}" wire:navigate class="card bg-base-200 shadow hover:shadow-lg transition-shadow cursor-pointer">
                    <div class="card-body">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-lg bg-warning/10 flex items-center justify-center flex-shrink-0">
                                <x-icon name="fas-bookmark" class="w-5 h-5 text-warning" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold">Karakeep</h3>
                            </div>
                        </div>
                        <p class="text-sm text-base-content/70 mb-4">
                            Sync bookmarks from Karakeep with AI-generated summaries and rich previews.
                        </p>
                        <div class="flex items-center gap-2 text-sm text-primary">
                            <span>Manage</span>
                            <x-icon name="fas-arrow-right" class="w-4 h-4" />
                        </div>
                    </div>
                </a>
            @endif

            <!-- BlueSky Integration Card (Conditional) -->
            @if ($this->hasBlueSky)
                <a href="{{ route('plugins.show', ['service' => 'bluesky']) }}" wire:navigate class="card bg-base-200 shadow hover:shadow-lg transition-shadow cursor-pointer">
                    <div class="card-body">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center flex-shrink-0">
                                <x-icon name="fas-cloud" class="w-5 h-5 text-primary" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold">BlueSky</h3>
                            </div>
                        </div>
                        <p class="text-sm text-base-content/70 mb-4">
                            Track your bookmarked, liked, and reposted content from BlueSky.
                        </p>
                        <div class="flex items-center gap-2 text-sm text-primary">
                            <span>Manage</span>
                            <x-icon name="fas-arrow-right" class="w-4 h-4" />
                        </div>
                    </div>
                </a>
            @endif

            <!-- API Access Card (Always shown) -->
            <a href="{{ route('bookmarks.fetch') }}?tab=api" wire:navigate class="card bg-base-200 shadow hover:shadow-lg transition-shadow cursor-pointer">
                <div class="card-body">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-lg bg-accent/10 flex items-center justify-center flex-shrink-0">
                            <x-icon name="fas-key" class="w-5 h-5 text-accent" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-lg font-semibold">API & Share</h3>
                        </div>
                    </div>
                    <p class="text-sm text-base-content/70 mb-4">
                        Save bookmarks via API, Apple Shortcuts, browser bookmarklets, or custom integrations.
                    </p>
                    <div class="flex items-center gap-2 text-sm text-primary">
                        <span>Setup & Docs</span>
                        <x-icon name="fas-arrow-right" class="w-4 h-4" />
                    </div>
                </div>
            </a>

            <!-- Add More Sources Card (Always shown) -->
            <a href="{{ route('integrations.index') }}" wire:navigate class="card bg-base-100 border-2 border-dashed border-base-300 shadow hover:shadow-lg transition-shadow cursor-pointer">
                <div class="card-body">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-lg bg-base-200 flex items-center justify-center flex-shrink-0">
                            <x-icon name="fas-circle-plus" class="w-5 h-5 text-base-content/50" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-lg font-semibold text-base-content/70">Add more sources</h3>
                        </div>
                    </div>
                    <p class="text-sm text-base-content/50">
                        Connect additional bookmark sources to track content from more services.
                    </p>
                    <div class="flex items-center gap-2 text-sm text-primary">
                        <span>Browse integrations</span>
                        <x-icon name="fas-arrow-right" class="w-4 h-4" />
                    </div>
                </div>
            </a>
        </div>
    </div>
</div>
