## Block Card Display System

Spark uses a flexible card-based system for displaying blocks throughout the application. Blocks are automatically rendered using smart defaults with support for custom layouts.

### Overview

The block card system provides:

- Two default card variants: **value cards** (for numeric blocks) and **content cards** (for text/summary blocks)
- Custom layout support per block type
- Automatic fallback to default layouts
- Consistent styling across the application

### Using Block Cards

Display blocks using the `<x-block-card>` component:

```blade
{{-- Single block --}}
<x-block-card :block="$block" />

{{-- Grid of blocks --}}
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
    @foreach ($blocks as $block)
        <x-block-card :block="$block" />
    @endforeach
</div>
```

The component automatically:

1. Checks if a custom layout exists for the block type
2. Falls back to appropriate default variant (value or content)
3. Displays all relevant metadata, timestamps, and actions

### Default Card Variants

**Value Card** (for blocks with numeric values):

- Prominent stat-style value display at top
- Block type badge and timestamp
- Title centered below value
- Compact metadata preview
- Footer with integration badge and actions

**Content Card** (for blocks without values):

- Block type badge and timestamp
- Optional image (h-48)
- Title with line-clamp-2
- Content preview with line-clamp-5
- Footer with integration badge and actions

### Creating Custom Layouts

Create custom blade files in `resources/views/blocks/types/` named after the block type:

**File naming:** `{block_type}.blade.php` (e.g., `fetch_summary_tweet.blade.php`)

**Available props:**

- `$block` - The Block model instance with all relationships loaded

**Example custom layout:**

```blade
{{-- resources/views/blocks/types/fetch_summary_tweet.blade.php --}}
@props(['block'])

@php

use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$summary = $block->metadata['content'] ?? '';
$charCount = mb_strlen($summary);
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Custom header --}}
        <div class="flex items-center justify-between gap-2">
            <div class="badge badge-info badge-outline badge-sm gap-1">
                <x-icon name="o-chat-bubble-left-right" class="w-3 h-3" />
                Tweet Summary
            </div>
            <div class="badge badge-ghost badge-xs">{{ $charCount }}/280</div>
        </div>

        {{-- Custom content --}}
        <div class="bg-base-100 rounded-lg p-3 border border-base-300">
            <p class="text-sm">{{ $summary }}</p>
        </div>

        {{-- Footer (keep consistent) --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            {{-- ... standard footer elements ... --}}
        </div>
    </div>
</div>
```

### Block Model Helper Methods

The Block model provides methods for working with custom layouts:

```php
// Check if custom layout exists
$block->hasCustomCardLayout(); // returns bool

// Get custom layout path
$block->getCustomCardLayoutPath(); // returns "blocks.types.{type}" or null

// Get all block types with custom layouts

Block::getBlockTypesWithCustomLayouts(); // returns array
```

### Where Blocks Display

Blocks using the card system appear in:

- Event detail pages (`/events/{event}`) - Shows all blocks linked to the event
- EventObject pages (`/objects/{object}`) - Shows blocks related via relationships
- Any custom views using the `<x-block-card>` component

The admin blocks table (`/admin/blocks`) maintains its table format for management purposes.

### Custom Layout Examples

The codebase includes example custom layouts:

- `fetch_summary_tweet` - Twitter-style card with character count
- `fetch_key_takeaways` - Bullet list with checkmarks
- `fetch_tags` - Tag cloud with emoji support
- `bookmark_summary` - AI-focused card with gradient styling
- `bookmark_metadata` - Preview card with larger image

Use these as references when creating new custom layouts.
