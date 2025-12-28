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
                    <x-icon name="fas.bookmark" class="w-8 h-8 text-base-content/50" />
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
        <a href="{{ route('bookmarks') }}?tab=urls" wire:navigate class="card bg-base-200 shadow hover:shadow-lg transition-shadow cursor-pointer">
            <div class="card-body">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-lg bg-info/10 flex items-center justify-center flex-shrink-0">
                        <x-icon name="fas.shield-halved" class="w-5 h-5 text-info" />
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
                    <x-icon name="fas.arrow-right" class="w-4 h-4" />
                </div>
            </div>
        </a>

        <!-- Reddit Integration Card (Conditional) -->
        @if ($this->hasReddit)
            <a href="{{ route('plugins.show', ['service' => 'reddit']) }}" wire:navigate class="card bg-base-200 shadow hover:shadow-lg transition-shadow cursor-pointer">
                <div class="card-body">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-lg bg-error/10 flex items-center justify-center flex-shrink-0">
                            <x-icon name="fas.comments" class="w-5 h-5 text-error" />
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
                        <x-icon name="fas.arrow-right" class="w-4 h-4" />
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
                            <x-icon name="fas.bookmark" class="w-5 h-5 text-warning" />
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
                        <x-icon name="fas.arrow-right" class="w-4 h-4" />
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
                            <x-icon name="fas.cloud" class="w-5 h-5 text-primary" />
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
                        <x-icon name="fas.arrow-right" class="w-4 h-4" />
                    </div>
                </div>
            </a>
        @endif

        <!-- API Access Card (Always shown) -->
        <a href="{{ route('bookmarks') }}?tab=api" wire:navigate class="card bg-base-200 shadow hover:shadow-lg transition-shadow cursor-pointer">
            <div class="card-body">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-lg bg-accent/10 flex items-center justify-center flex-shrink-0">
                        <x-icon name="fas.key" class="w-5 h-5 text-accent" />
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
                    <x-icon name="fas.arrow-right" class="w-4 h-4" />
                </div>
            </div>
        </a>

        <!-- Add More Sources Card (Always shown) -->
        <a href="{{ route('integrations.index') }}" wire:navigate class="card bg-base-100 border-2 border-dashed border-base-300 shadow hover:shadow-lg transition-shadow cursor-pointer">
            <div class="card-body">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-lg bg-base-200 flex items-center justify-center flex-shrink-0">
                        <x-icon name="fas.circle-plus" class="w-5 h-5 text-base-content/50" />
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
                    <x-icon name="fas.arrow-right" class="w-4 h-4" />
                </div>
            </div>
        </a>
    </div>
</div>
