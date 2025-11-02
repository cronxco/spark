<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div>
    <x-header title="Bookmarks" subtitle="All your saved content from across your integrations" separator />

    <!-- Hero Section -->
    <div class="card bg-base-200 shadow mb-6">
        <div class="card-body">
            <div class="flex flex-col items-center text-center py-8">
                <div class="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center mb-4">
                    <x-icon name="o-bookmark" class="w-8 h-8 text-primary" />
                </div>
                <h2 class="text-2xl font-bold mb-2">Your Bookmarks</h2>
                <p class="text-base-content/70 max-w-2xl mb-6">
                    Save and track content from across the web. Fetch automatically monitors your subscribed URLs,
                    extracts content, and generates AI-powered summaries.
                </p>
            </div>
        </div>
    </div>

    <!-- Available Bookmark Sources -->
    <div>
        <h2 class="text-xl font-semibold mb-4">Bookmark Sources</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6">
            <!-- Fetch Integration Card -->
            <a href="{{ route('bookmarks.fetch') }}" wire:navigate class="card bg-base-200 shadow hover:shadow-lg transition-shadow cursor-pointer">
                <div class="card-body">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-lg bg-info/10 flex items-center justify-center flex-shrink-0">
                            <x-icon name="o-shield-check" class="w-5 h-5 text-info" />
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
                        <x-icon name="o-arrow-right" class="w-4 h-4" />
                    </div>
                </div>
            </a>

            <!-- Placeholder for future integrations -->
            <div class="card bg-base-100 border-2 border-dashed border-base-300 shadow">
                <div class="card-body">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-lg bg-base-200 flex items-center justify-center flex-shrink-0">
                            <x-icon name="o-ellipsis-horizontal" class="w-5 h-5 text-base-content/50" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-lg font-semibold text-base-content/50">More sources coming soon</h3>
                        </div>
                    </div>
                    <p class="text-sm text-base-content/50">
                        Additional bookmark sources will be available here in the future.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
