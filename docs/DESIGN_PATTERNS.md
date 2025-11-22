# Design Patterns & UI Consistency Guide

This document defines the visual design patterns and UI consistency standards for the Spark application.

## Reference Implementation

The **[events show page](resources/views/livewire/events/show.blade.php)** is a good example of our visual hierarchy and patterns. When building new pages or updating existing ones, refer to this page as the gold standard for:

- **Visual hierarchy**: Hero content → secondary sections → technical details
- **Progressive disclosure**: Main content visible, technical details in drawer
- **Responsive design**: Mobile-first with thoughtful breakpoints
- **Typography scale**: Responsive sizing from mobile to desktop
- **Card patterns**: Hero card, featured cards, nested items
- **Spacing consistency**: `space-y-4 lg:space-y-6` throughout
- **Icon usage**: Semantic, consistent sizing, proper alignment

## Core Principles

1. **Hierarchy First**: Every page should have a clear visual hierarchy (primary → secondary → tertiary)
2. **Minimal Color**: Use color sparingly and semantically (success/error/warning only)
3. **Consistent Icons**: Same concepts always use the same icons across the app
4. **Reduced Noise**: Minimize badges, borders, and visual elements that don't serve the user
5. **Progressive Disclosure**: Show essential information first, technical details in drawers/collapses
6. **Mobile-First**: Design for mobile, enhance for larger screens

## Visual Hierarchy

### Level 1: Hero/Primary Content (Most Important)

The "hero" section is the page's focal point - what the user came to see.

- **Hero value/title**: `text-2xl sm:text-3xl lg:text-4xl font-bold` with semantic color (e.g., `text-primary`)
- **Hero icon**: Large circular background `w-12 h-12 sm:w-16 sm:h-16` with icon inside
- **Action headline**: `text-xl sm:text-2xl lg:text-3xl font-bold text-base-content`
- **Primary card**: Full-width card with hero content, prominent spacing

**Example from events show page:**

```blade
<!-- Primary Event Information Card -->
<x-card>
    <div class="flex flex-col sm:flex-row items-start gap-4 lg:gap-6">
        <!-- Large event icon -->
        <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-primary/10 flex items-center justify-center">
            <x-icon name="icon-name" class="w-6 h-6 sm:w-8 sm:h-8 text-primary" />
        </div>

        <!-- Event title and value -->
        <div class="flex-1">
            <div class="flex items-center justify-between gap-2 mb-2">
                <h2 class="text-xl sm:text-2xl lg:text-3xl font-bold text-base-content">
                    Primary Action Title
                </h2>
                <div class="text-2xl sm:text-3xl lg:text-4xl font-bold text-primary">
                    £123.45
                </div>
            </div>
        </div>
    </div>
</x-card>
```

### Level 2: Secondary Content (Supporting Information)

- **Section card titles**: `text-lg font-semibold mb-4` with icon `w-5 h-5`
- **List item titles**: `text-base font-semibold` or `font-medium`
- **Body text**: `text-base` (default)
- **Secondary values**: `text-lg font-bold text-primary` (smaller than hero)
- **Metadata groups**: `text-sm text-base-content/70` with inline icons

**Example from events show page:**

```blade
<!-- Related Events Section -->
<x-card class="bg-base-200 shadow">
    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
        <x-icon name="o-arrow-path" class="w-5 h-5 text-warning" />
        Related Events
    </h3>
    <div class="space-y-3">
        <!-- Related items with clear hierarchy -->
    </div>
</x-card>
```

### Level 3: Tertiary/Technical Details (Background Information)

Technical details should be hidden by default, accessible via drawer or collapse.

- **Drawer sections**: `text-lg font-semibold mb-4` with icon
- **Metadata labels**: `text-base-content/70` followed by values
- **Timestamps**: `text-sm text-base-content/70`
- **Badges**: `badge-xs badge-outline` for categories
- **Tertiary actions**: `btn-xs btn-ghost`

**Example from events show page:**

```blade
<!-- Technical Details in Drawer -->
<x-drawer wire:model="showSidebar" right title="Event Details">
    <x-collapse wire:model="activityOpen">
        <x-slot:heading>
            <div class="text-lg font-semibold text-base-content flex items-center gap-2">
                <x-icon name="o-clock" class="w-5 h-5 text-primary" />
                Activity
            </div>
        </x-slot:heading>
        <x-slot:content>
            <!-- Technical timeline details -->
        </x-slot:content>
    </x-collapse>
</x-drawer>
```

### Responsive Typography Scale

Use responsive sizing for important content:

| Element         | Mobile      | Tablet        | Desktop       |
| --------------- | ----------- | ------------- | ------------- |
| Hero value      | `text-2xl`  | `sm:text-3xl` | `lg:text-4xl` |
| Hero title      | `text-xl`   | `sm:text-2xl` | `lg:text-3xl` |
| Section title   | `text-lg`   | (same)        | (same)        |
| Body/list items | `text-base` | (same)        | (same)        |
| Metadata        | `text-sm`   | (same)        | (same)        |

## Spacing Standards

### Page Layout Pattern (Events Show Example)

Follow this consistent layout pattern for detail pages:

```blade
<div>
    <!-- Two-column layout: main content + optional drawer -->
    <div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
        <!-- Main Content Area -->
        <div class="flex-1 space-y-4 lg:space-y-6">
            <!-- Header -->
            <x-header title="Page Title" separator>
                <x-slot:actions>
                    <!-- Toggle drawer button -->
                    <x-button wire:click="toggleSidebar" class="btn-ghost btn-sm">
                        <x-icon name="{{ $showSidebar ? 'o-x-mark' : 'o-adjustments-horizontal' }}" />
                    </x-button>
                </x-slot:actions>
            </x-header>

            <!-- Primary Hero Card -->
            <x-card>
                <!-- Hero content with large icon, title, value -->
            </x-card>

            <!-- Secondary Sections -->
            <x-card class="bg-base-200/50 border-2 border-info/10">
                <!-- Featured content like linked blocks -->
            </x-card>

            <x-card class="bg-base-200 shadow">
                <!-- Related items -->
            </x-card>
        </div>

        <!-- Drawer for Technical Details -->
        <x-drawer wire:model="showSidebar" right title="Details" separator with-close-button class="w-11/12 lg:w-1/3">
            <div class="space-y-4 lg:space-y-6">
                <!-- Collapsible sections for technical info -->
            </div>
        </x-drawer>
    </div>
</div>
```

### Between Sections

- **Page sections**: `space-y-4 lg:space-y-6` (smaller on mobile, larger on desktop)
- **Flex layouts**: `gap-4 lg:gap-6` (flex-based layouts)
- **Major content blocks**: `gap-4` or `space-y-4`
- **List items**: `space-y-3` (compact lists)

### Within Components

- **Primary card hero**: `gap-4 lg:gap-6` between icon and content
- **Card sections**: `space-y-4` for major elements, `space-y-2` for related items
- **Metadata groups**: `gap-2` or `space-x-2` for inline items
- **Icon + text**: `gap-2` standard
- **Actor/Target flow boxes**: `p-3 lg:p-4` responsive padding

### Component Padding

- **Cards**: Use default `x-card` padding (Mary UI handles this)
- **Nested sections**: `p-3 lg:p-4` responsive padding
- **Drawer cards**: `!p-2` for compact drawer content
- **Grid items**: `p-3` for items in grids

## Color Usage (Minimal & Semantic)

### Semantic Colors Only

- **Success**: Green for completed, active, positive states
- **Error**: Red for errors, deletions, negative states
- **Warning**: Yellow/Orange for needs attention, pending states
- **Info**: Blue for informational (use sparingly)

### Default Colors

- **Text**: `text-base-content` (default, no class needed)
- **Muted text**: `text-base-content/70` or `text-base-content/50`
- **Backgrounds**:
    - Navbar & Sidebar: `bg-base-200` (darker structural elements)
    - Page background: `bg-base-100` (lighter content area)
    - Primary cards: `bg-base-200 shadow` (darker than page, with medium shadow for clear separation and depth)
    - Featured cards: `bg-base-200/50 border-2 border-info/10` (semi-transparent with colored border for emphasis)
    - Nested items in cards: `border border-base-200 bg-base-300` or `border-2 border-info/30 bg-base-100/80` (with borders for item separation)
    - Drawer cards: Use Mary UI default with padding adjustments (e.g., `class="!p-2"`)
- **Borders**:
    - Standard borders: `border-base-300` or `border-base-200`
    - Featured/highlighted borders: `border-2 border-info/10` to `border-info/30`
- **Shadows**: Use `shadow` (medium) for primary cards, `shadow-sm` for nested items, NOT `shadow-xs` (too subtle)

### What NOT to Do

- ❌ Don't use arbitrary colors like `text-blue-500`, `bg-purple-200`
- ❌ Don't use accent colors from plugins for UI elements
- ❌ Don't use color for decoration - only for meaning

## Standard Patterns

### Pattern 1: Page Headers

All pages should use the `x-header` component with consistent structure.

```blade
<x-header title="Page Title" subtitle="Brief description of the page" separator>
    <x-slot:actions>
        <!-- Desktop: Full buttons -->
        <div class="hidden sm:flex gap-2">
            <x-button class="btn-primary">
                <x-icon name="o-plus" class="w-4 h-4" />
                Primary Action
            </x-button>
            <x-button class="btn-outline">
                <x-icon name="o-cog-6-tooth" class="w-4 h-4" />
                Secondary Action
            </x-button>
        </div>

        <!-- Mobile: Dropdown for multiple actions -->
        <div class="sm:hidden">
            <x-dropdown>
                <x-slot:trigger>
                    <x-button class="btn-ghost btn-sm">
                        <x-icon name="o-ellipsis-vertical" class="w-5 h-5" />
                    </x-button>
                </x-slot:trigger>
                <x-menu-item title="Primary Action" icon="o-plus" />
                <x-menu-item title="Secondary Action" icon="o-cog-6-tooth" />
            </x-dropdown>
        </div>
    </x-slot:actions>
</x-header>
```

**Guidelines:**

- One primary action maximum (btn-primary)
- All other actions should be outline or ghost style
- Mobile: collapse multiple actions into dropdown
- Keep subtitle concise (one sentence)

### Pattern 2: Filter Sections

Filters should be collapsible on mobile, expanded on desktop. **Important**: Always use `bg-base-200 shadow` for desktop filters to pop them off the page background.

```blade
<!-- Desktop: Expanded filters in card with visual separation -->
<div class="hidden lg:block card bg-base-200 shadow mb-6">
    <div class="card-body">
        <div class="flex flex-row gap-4">
            <!-- Search (flex-1 to fill space) -->
            <div class="form-control flex-1">
                <label class="label">
                    <span class="label-text">Search</span>
                </label>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Search..."
                    class="input input-bordered w-full"
                />
            </div>

            <!-- Filter controls -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text">Filter Type</span>
                </label>
                <select wire:model.live="filter" class="select select-bordered">
                    <option value="">All</option>
                    <option value="type1">Type 1</option>
                </select>
            </div>

            <!-- Clear filters button (aligned with inputs) -->
            <div class="form-control">
                <label class="label">
                    <span class="label-text">&nbsp;</span>
                </label>
                <button wire:click="clearFilters" class="btn btn-outline">
                    Clear Filters
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Mobile: Collapsed by default -->
<div class="lg:hidden">
    <x-collapse separator class="bg-base-200 mb-4">
        <x-slot:heading>Filters</x-slot:heading>
        <x-slot:content>
            <div class="flex flex-col gap-4">
                <!-- Same controls but in column layout -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Search</span>
                    </label>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search..."
                        class="input input-bordered w-full"
                    />
                </div>
                <!-- ... other controls ... -->
            </div>
        </x-slot:content>
    </x-collapse>
</div>
```

**Guidelines:**

- Desktop filters: `bg-base-200 shadow` (darker than page background, pops off with shadow)
- Mobile filters: Use collapse component with default background (collapse has built-in borders)
- Always collapsed on mobile (`<lg`)
- Always visible on desktop (`lg:`)
- Use same controls in both views (DRY code)
- Search input gets `flex-1` to fill available space
- Clear filters button aligns with other inputs (use label spacer)

### Pattern 3: Form Controls

All form controls should have consistent label/input structure. **Important**: Toggles have a different pattern than text inputs - they use a horizontal layout.

#### Text Inputs, Selects, Textareas (Stacked/Vertical Layout)

```blade
<!-- ✅ CORRECT: Standard input -->
<div class="form-control">
    <label class="label">
        <span class="label-text">Field Name</span>
    </label>
    <input type="text" class="input input-bordered w-full" />
    <label class="label">
        <span class="label-text-alt">Optional helper text below the input</span>
    </label>
</div>

<!-- ✅ CORRECT: Select -->
<div class="form-control">
    <label class="label">
        <span class="label-text">Select Option</span>
    </label>
    <select class="select select-bordered w-full">
        <option>Option 1</option>
    </select>
</div>

<!-- ✅ CORRECT: Textarea -->
<div class="form-control">
    <label class="label">
        <span class="label-text">Description</span>
    </label>
    <textarea class="textarea textarea-bordered w-full" rows="3"></textarea>
</div>
```

#### Toggles (Horizontal Layout with Background Box)

Toggles are settings controls, not form inputs. They should be in a horizontal layout with the label/description on the left and toggle on the right.

```blade
<!-- ✅ CORRECT: Toggle in horizontal box (recommended pattern) -->
<div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
    <div>
        <div class="font-medium text-sm">Enable Feature</div>
        <div class="text-xs text-base-content/60">Description of what this toggle does</div>
    </div>
    <input type="checkbox" wire:model="feature" class="toggle toggle-primary" />
</div>

<!-- ✅ CORRECT: Multiple toggles in a group -->
<div class="space-y-3">
    <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
        <div>
            <div class="font-medium text-sm">Email Notifications</div>
            <div class="text-xs text-base-content/60">Receive notifications via email</div>
        </div>
        <input type="checkbox" wire:model="emailEnabled" class="toggle toggle-primary" />
    </div>

    <div class="flex items-center justify-between p-3 bg-base-100 rounded-lg">
        <div>
            <div class="font-medium text-sm">SMS Notifications</div>
            <div class="text-xs text-base-content/60">Receive notifications via SMS</div>
        </div>
        <input type="checkbox" wire:model="smsEnabled" class="toggle toggle-primary" />
    </div>
</div>

<!-- ✅ ACCEPTABLE: Simple toggle without background (when used alone) -->
<div class="flex items-center justify-between">
    <div>
        <div class="font-medium">Enable Feature</div>
        <div class="text-sm text-base-content/70">Description text</div>
    </div>
    <input type="checkbox" wire:model="feature" class="toggle toggle-primary" />
</div>
```

#### Small Checkboxes (Inline Layout)

```blade
<!-- ✅ CORRECT: Small checkbox with inline label -->
<div class="form-control">
    <label class="label cursor-pointer justify-start gap-2">
        <input type="checkbox" class="checkbox checkbox-sm" />
        <span class="label-text">Agree to terms and conditions</span>
    </label>
</div>

<!-- ✅ CORRECT: Multiple checkboxes (like a filter list) -->
<div class="space-y-2">
    <label class="label cursor-pointer justify-start gap-2">
        <input type="checkbox" class="checkbox checkbox-sm" wire:model="filters.active" />
        <span class="label-text">Active</span>
    </label>
    <label class="label cursor-pointer justify-start gap-2">
        <input type="checkbox" class="checkbox checkbox-sm" wire:model="filters.pending" />
        <span class="label-text">Pending</span>
    </label>
</div>
```

#### Radio Buttons

```blade
<!-- ✅ CORRECT: Radio buttons with labels -->
<div class="space-y-3">
    <div class="form-control">
        <label class="label cursor-pointer justify-start gap-3">
            <input type="radio" name="mode" value="immediate" class="radio radio-primary" wire:model="mode" />
            <div>
                <span class="label-text font-medium">Immediate</span>
                <p class="text-xs text-base-content/60">Send notifications immediately</p>
            </div>
        </label>
    </div>

    <div class="form-control">
        <label class="label cursor-pointer justify-start gap-3">
            <input type="radio" name="mode" value="delayed" class="radio radio-primary" wire:model="mode" />
            <div>
                <span class="label-text font-medium">Delayed</span>
                <p class="text-xs text-base-content/60">Wait until work hours</p>
            </div>
        </label>
    </div>
</div>
```

**Guidelines:**

- **Text inputs, selects, textareas**: Stacked layout - label above, input below, helper text (optional) at bottom
- **Toggles**: Horizontal layout - label/description on left, toggle on right, in a `bg-base-100` box when grouped
- **Small checkboxes**: Inline with label (checkbox → label)
- **Radio buttons**: Similar to small checkboxes but with descriptions
- Always use `w-full` on text inputs and selects
- Helper text uses `label-text-alt` class for inputs, or `text-xs text-base-content/60` for toggle descriptions
- Toggle descriptions should be concise (one line)

### Pattern 4: Card Hierarchy & Types

Cards should have clear visual hierarchy. There are several card types based on context.

#### Hero/Primary Card (Top of Page)

The first card on a detail page - features large icon, prominent title, and main value.

```blade
<!-- Hero card with no background styling - clean and prominent -->
<x-card>
    <div class="flex flex-col sm:flex-row items-start gap-4 lg:gap-6">
        <!-- Large icon with circular background -->
        <div class="flex-shrink-0 self-center sm:self-start">
            <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-primary/10 flex items-center justify-center">
                <x-icon name="o-bolt" class="w-6 h-6 sm:w-8 sm:h-8 text-primary" />
            </div>
        </div>

        <!-- Main content -->
        <div class="flex-1">
            <div class="mb-4 text-center sm:text-left">
                <div class="flex flex-col sm:flex-row items-center sm:items-start justify-between gap-2 mb-2">
                    <h2 class="text-xl sm:text-2xl lg:text-3xl font-bold text-base-content">
                        Primary Title
                    </h2>
                    <!-- Large value display -->
                    <div class="text-2xl sm:text-3xl lg:text-4xl font-bold text-primary flex-shrink-0">
                        £123.45
                    </div>
                </div>
            </div>

            <!-- Key metadata badges/icons -->
            <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2 text-sm">
                <div class="flex items-center gap-2">
                    <x-icon name="o-clock" class="w-4 h-4 text-base-content/60" />
                    <span class="text-base-content/70">2 hours ago</span>
                </div>
                <span class="hidden sm:inline">·</span>
                <x-badge value="Category" class="badge-xs badge-outline" />
            </div>

            <!-- Actor/Target flow (if applicable) -->
            <div class="mt-4 lg:mt-6 p-3 lg:p-4 rounded-lg bg-base-300/50 border-2 border-info/20">
                <div class="flex flex-col sm:flex-row items-center justify-center gap-3 lg:gap-4">
                    <!-- Actor → Action → Target flow -->
                </div>
            </div>
        </div>
    </div>
</x-card>
```

#### Featured/Highlighted Cards (Special Emphasis)

```blade
<!-- Featured card with colored border (e.g., for linked blocks, special sections) -->
<x-card class="bg-base-200/50 border-2 border-info/10">
    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
        <x-icon name="o-squares-2x2" class="w-5 h-5 text-info" />
        Linked Blocks (5)
    </h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <!-- Nested items inside featured card -->
        <div class="border-2 border-info/30 bg-base-100/80 rounded-lg p-3 hover:bg-base-50 transition-colors shadow-sm">
            <div class="flex items-start justify-between gap-3 mb-2">
                <a href="#" class="font-semibold text-base-content hover:text-primary transition-colors text-base flex-1">
                    Block Title
                </a>
                <span class="text-lg font-bold text-primary flex-shrink-0">42</span>
            </div>
            <div class="text-sm text-base-content/70">Metadata</div>
        </div>
    </div>
</x-card>
```

#### Cards with Nested Items (Lists)

```blade
<!-- Card containing a list of related items -->
<x-card class="bg-base-200 shadow">
    <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
        <x-icon name="o-arrow-path" class="w-5 h-5 text-warning" />
        Related Items
    </h3>
    <div class="space-y-3">
        <!-- Each nested item has border for separation -->
        <div class="border border-base-200 bg-base-100 rounded-lg p-3 hover:bg-base-50 transition-colors">
            <a href="#" class="block hover:text-primary transition-colors">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0 mt-1">
                        <x-icon name="o-bolt" class="w-4 h-4 text-primary" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2 mb-1">
                            <span class="font-medium">Item Title</span>
                            <span class="text-sm text-primary font-semibold flex-shrink-0">£42</span>
                        </div>
                        <div class="text-sm text-base-content/70 flex flex-wrap items-center gap-1">
                            <span>Metadata</span>
                            <span>·</span>
                            <x-badge value="tag" class="badge-xs badge-outline" />
                        </div>
                    </div>
                    <x-icon name="o-chevron-right" class="w-4 h-4 text-base-content/40 flex-shrink-0 mt-1" />
                </div>
            </a>
        </div>
    </div>
</x-card>
```

#### Drawer Cards (Nested Context)

```blade
<!-- Cards inside drawers use compact padding -->
<x-drawer wire:model="showSidebar" right title="Details" separator with-close-button class="w-11/12 lg:w-1/3">
    <div class="space-y-4 lg:space-y-6">
        <!-- Compact cards in drawer -->
        <x-card class="!p-2">
            <h3 class="text-lg font-semibold text-base-content mb-4 flex items-center gap-2">
                <x-icon name="o-tag" class="w-5 h-5 text-primary" />
                Tags
            </h3>
            <!-- Card content -->
        </x-card>

        <!-- Collapsible sections for technical details -->
        <x-collapse wire:model="activityOpen">
            <x-slot:heading>
                <div class="text-lg font-semibold text-base-content flex items-center gap-2">
                    <x-icon name="o-clock" class="w-5 h-5 text-primary" />
                    Activity Timeline
                </div>
            </x-slot:heading>
            <x-slot:content>
                <!-- Timeline content -->
            </x-slot:content>
        </x-collapse>
    </div>
</x-drawer>
```

**Guidelines:**

- **Hero card**: No background class (clean white), large responsive icon, prominent title+value
- **Featured cards**: `bg-base-200/50 border-2 border-{color}/10` (semi-transparent with colored border)
- **Standard cards**: `bg-base-200 shadow` (darker than page, clear separation)
- **Nested items in featured cards**: `border-2 border-{color}/30 bg-base-100/80` (lighter with border)
- **Nested items in standard cards**: `border border-base-200 bg-base-100` (subtle border, clean background)
- **Drawer cards**: Use default Mary UI styling with `!p-2` for compact padding
- Use `space-y-3` for lists of items
- Maintain heading hierarchy: h2 → h3 (never skip levels)
- Add hover states for clickable items: `hover:bg-base-50 transition-colors`
- Icons in nested items should be `mt-1` to align with text baseline

### Pattern 5: Empty States

Empty states should be clear and helpful.

```blade
<div class="text-center py-12">
    <x-icon name="o-inbox" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
    <h3 class="text-lg font-medium text-base-content mb-2">No items found</h3>
    <p class="text-base-content/70 mb-6">
        @if ($hasFilters)
            Try adjusting your filters or search terms.
        @else
            Get started by creating your first item.
        @endif
    </p>
    @if (!$hasFilters)
        <x-button class="btn-primary" wire:click="create">
            <x-icon name="o-plus" class="w-4 h-4" />
            Create First Item
        </x-button>
    @endif
</div>
```

**Guidelines:**

- Large muted icon (`w-16 h-16 text-base-content/70`)
- Clear heading
- Helpful message explaining why it's empty
- CTA button only if relevant (not when filters active)

## Icon Standards

Spark supports both **FontAwesome Free** (preferred) and **Heroicons**. FontAwesome is preferred for new development due to its larger icon set (2,000+ icons), brand icons, and domain-specific financial icons.

### Icon Libraries

| Library | Prefix | Example | Use Case |
| ------- | ------ | ------- | -------- |
| FontAwesome Solid | `fas-` | `fas-heart` | Default, filled icons |
| FontAwesome Regular | `far-` | `far-heart` | Outline icons |
| FontAwesome Brands | `fab-` | `fab-github` | Brand/logo icons |
| Heroicons Outline | `o-` | `o-heart` | Legacy support |
| Heroicons Solid | `s-` | `s-heart` | Legacy support |

### Size Guidelines

| Context      | Size Class                 | Usage                        |
| ------------ | -------------------------- | ---------------------------- |
| Empty states | `w-16 h-16` or `w-12 h-12` | Large icons for empty states |
| Card headers | `w-10 h-10`                | Icons in card title areas    |
| Button icons | `w-5 h-5` or `w-4 h-4`     | Icons inside buttons         |
| Inline icons | `w-4 h-4`                  | Icons inline with text       |
| Badge icons  | `w-3 h-3`                  | Icons inside badges          |

### Semantic Icon Mapping

Use these icons consistently across the entire app:

#### Actions

- **Add/Create**: `fas-plus` or `fas-circle-plus`
- **Edit**: `fas-pen` or `fas-pen-to-square`
- **Delete**: `fas-trash`
- **View**: `fas-eye`
- **Search**: `fas-magnifying-glass`
- **Filter**: `fas-filter`
- **Settings**: `fas-gear`
- **More/Menu**: `fas-ellipsis-vertical` or `fas-ellipsis`

#### Navigation

- **Back**: `fas-arrow-left`
- **Next**: `fas-arrow-right` or `fas-chevron-right`
- **Previous**: `fas-chevron-left`
- **Up**: `fas-chevron-up`
- **Down**: `fas-chevron-down`
- **External**: `fas-arrow-up-right-from-square`

#### Status

- **Success/Complete**: `fas-circle-check`
- **Error/Failed**: `fas-circle-xmark`
- **Warning/Attention**: `fas-triangle-exclamation`
- **Info**: `fas-circle-info`
- **Pending**: `fas-clock`

#### Financial (FontAwesome Exclusive)

- **Balance**: `fas-sterling-sign`
- **Transaction**: `fas-money-bills`
- **Savings/Pot**: `fas-piggy-bank`
- **Budget**: `fas-wallet`
- **Transfer**: `fas-money-bill-transfer`
- **Income**: `fas-hand-holding-dollar`
- **Receipt**: `fas-receipt`
- **Invoice**: `fas-file-invoice-dollar`

#### Payment Cards (Brand Icons)

- **Mastercard**: `fab-cc-mastercard`
- **Visa**: `fab-cc-visa`
- **Amex**: `fab-cc-amex`
- **Apple Pay**: `fab-cc-apple-pay`
- **PayPal**: `fab-cc-paypal`
- **Generic Card**: `fas-credit-card`

#### Content Types

- **Calendar/Date**: `fas-calendar`
- **Time**: `fas-clock`
- **Document**: `fas-file-lines`
- **User/Person**: `fas-user`
- **Tag**: `fas-tag`
- **Link**: `fas-link`

#### Data

- **Chart/Analytics**: `fas-chart-simple`
- **List**: `fas-list`
- **Grid**: `fas-grip`
- **Pie Chart**: `fas-chart-pie`

### Icon Color Usage

```blade
<!-- ✅ CORRECT: Default color (neutral) -->
<x-icon name="fas-gear" class="w-5 h-5" />

<!-- ✅ CORRECT: Muted for secondary icons -->
<x-icon name="fas-clock" class="w-4 h-4 text-base-content/70" />

<!-- ✅ CORRECT: Semantic color for status -->
<x-icon name="fas-circle-check" class="w-5 h-5 text-success" />
<x-icon name="fas-triangle-exclamation" class="w-5 h-5 text-warning" />

<!-- ✅ CORRECT: Brand icon for payment card -->
<x-icon name="fab-cc-mastercard" class="w-8 h-6" />

<!-- ✅ CORRECT: Financial icon -->
<x-icon name="fas-piggy-bank" class="w-5 h-5 text-success" />

<!-- ❌ INCORRECT: Arbitrary color -->
<x-icon name="fas-user" class="w-5 h-5 text-blue-500" />
```

**Guidelines:**

- Default: no color class (inherits text color)
- Muted: `text-base-content/70` or `/50` for less important icons
- Semantic only: `text-success`, `text-error`, `text-warning` for status
- Never use arbitrary colors like `text-blue-500`
- Use brand icons (`fab-`) for payment methods and integrations

### Migration from Heroicons

To migrate existing code from Heroicons to FontAwesome:

```bash
# Preview changes (dry run)
sail artisan icons:migrate-to-fontawesome --dry-run

# Apply changes
sail artisan icons:migrate-to-fontawesome

# Migrate specific directory
sail artisan icons:migrate-to-fontawesome --path=app/Integrations
```

See `config/icons.php` for the complete mapping between Heroicons and FontAwesome equivalents.

## Badge Standards

### When to Use Badges

Use badges sparingly for:

- **Status indicators**: Active, Pending, Failed
- **Categories**: Current Account, Savings Account
- **Counts**: `(5)` items
- **Metadata**: Rarely, only when necessary

### Badge Styles

```blade
<!-- Status badges (filled, semantic color) -->
<x-badge value="Active" class="badge-success" />
<x-badge value="Needs Update" class="badge-warning" />
<x-badge value="Failed" class="badge-error" />

<!-- Category badges (outline, neutral) -->
<x-badge value="Current Account" class="badge-outline" />
<x-badge value="OAuth" class="badge-outline" />

<!-- Metadata badges (extra small, outline) -->
<x-badge value="15 min" class="badge-xs badge-outline" />
<x-badge value="Updated 2h ago" class="badge-xs badge-outline" />

<!-- With icon -->
<x-badge class="badge-sm badge-outline">
    <x-slot:value>
        <x-icon name="o-clock" class="w-3 h-3 text-base-content/40" />
        Every 15 min
    </x-slot:value>
</x-badge>
```

**Guidelines:**

- Most badges should be outline style
- Filled badges only for important status (success/warning/error)
- Use `badge-xs` for metadata
- Use `badge-sm` for categories (default)
- Avoid stacking too many badges (causes visual noise)

### What NOT to Badge

❌ Don't use badges for:

- Technical implementation details (OAuth, Webhook, API Key)
- Information already obvious from context
- Decoration purposes
- Service names when already shown as text

## Component-Specific Patterns

### Collapsible Priority Lists

The **[updates page](resources/views/livewire/updates/index.blade.php)** demonstrates an excellent pattern for displaying grouped items with priority-based sorting and critical information visible in collapsed state.

#### Priority Sorting Pattern

Items are sorted by need for attention - issues first, then healthy items:

```php
// Sort: sections with issues first, then clean sections
uasort($sortedIntegrations, function ($a, $b) {
    // First, sort by whether they have issues (issues first)
    if ($a['has_issues'] && !$b['has_issues']) {
        return -1;
    }
    if (!$a['has_issues'] && $b['has_issues']) {
        return 1;
    }
    // Within same issue status, sort by issue count (desc)
    if ($a['has_issues'] && $b['has_issues']) {
        return $b['issue_count'] <=> $a['issue_count'];
    }
    return strcmp($a['plugin_name'], $b['plugin_name']);
});
```

#### Collapsed State Information Display

Show critical status information in the collapsed heading:

```blade
<x-collapse wire:model="collapse.{{ $pluginName }}" separator class="bg-base-100">
    <x-slot:heading>
        <div class="flex items-center gap-3 w-full" wire:click="toggle('{{ $pluginName }}')">
            <!-- Icon -->
            <x-icon :name="$pluginIcon" class="w-5 h-5" />

            <!-- Name -->
            <span class="flex-1 text-left">{{ $pluginName }}</span>

            <!-- Status Summary (Critical Info) -->
            <div class="flex items-center gap-2 text-xs sm:text-sm text-base-content/70">
                <span>{{ $totalInstances }} instances</span>

                <!-- Show issues with semantic badges -->
                @if ($hasIssues)
                    @if ($needsUpdateCount > 0)
                        <x-badge :value="$needsUpdateCount . ' need update'" class="badge-error" />
                    @endif
                    @if ($pendingUpdateCount > 0)
                        <x-badge :value="$pendingUpdateCount . ' pending'" class="badge-warning" />
                    @endif
                    @if ($processingCount > 0)
                        <x-badge :value="$processingCount . ' processing'" class="badge-info" />
                    @endif
                @else
                    <!-- Success checkmark for healthy sections -->
                    <x-badge value="✓" class="badge-success" />
                @endif
            </div>
        </div>
    </x-slot:heading>

    <x-slot:content>
        <!-- Detailed content here -->
    </x-slot:content>
</x-collapse>
```

#### Visual Status Indicators

Use left border colors to show status at a glance:

```blade
@php
    // Determine card border color based on status
    $cardBorderClass = 'border-l-4 ';
    if ($integration['is_processing']) {
        $cardBorderClass .= 'border-info';
    } elseif ($integration['is_paused']) {
        $cardBorderClass .= 'border-neutral';
    } elseif ($integration['status'] === 'needs_update') {
        $cardBorderClass .= 'border-error';
    } elseif ($integration['status'] === 'pending_update') {
        $cardBorderClass .= 'border-warning';
    } elseif ($integration['status'] === 'up_to_date') {
        $cardBorderClass .= 'border-success';
    } else {
        $cardBorderClass .= 'border-base-300';
    }
@endphp

<div class="card bg-base-200 shadow-sm {{ $cardBorderClass }}">
    <!-- Card content -->
</div>
```

#### Default Collapse State

Collapsed by default, users expand sections that need attention:

```php
// Initialize collapse state for new items (default to collapsed)
foreach (array_keys($groupedIntegrations) as $pluginName) {
    if (!isset($this->collapse[$pluginName])) {
        $this->collapse[$pluginName] = false; // false = collapsed
    }
}
```

**Guidelines:**

- **Sort by priority**: Issues first (error → warning → info), then healthy items
- **Critical info in heading**: Show counts, status badges at a glance
- **Semantic status badges**: `badge-error`, `badge-warning`, `badge-info`, `badge-success`
- **Left border color coding**: Quick visual status indication (`border-l-4 border-{color}`)
- **Default collapsed**: User expands only what needs attention
- **Progressive disclosure**: Summary in heading, details in expanded content

This pattern works well for:

- Status monitoring pages
- Dashboard sections
- Grouped lists with varying states
- Priority-based content organization
- Pages where users need to quickly identify problems

### Integration Cards

```blade
<div class="card bg-base-200 shadow-sm">
    <div class="card-body">
        <!-- Header with icon and title -->
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-lg bg-base-300 flex items-center justify-center">
                <x-icon name="o-link" class="w-5 h-5 text-base-content" />
            </div>
            <div class="flex-1">
                <h3 class="text-lg font-semibold">Service Name</h3>
                <p class="text-sm text-base-content/70">Brief description</p>
            </div>
        </div>

        <!-- Status/metadata (minimal) -->
        <div class="text-sm text-base-content/70 space-y-1">
            <div>Last update: 2 hours ago</div>
            <div>Next update: in 13 minutes</div>
        </div>

        <!-- Actions -->
        <div class="flex gap-2 mt-4 pt-4 border-t border-base-300">
            <x-button class="btn-outline btn-sm flex-1">Configure</x-button>
            <x-button class="btn-ghost btn-sm" icon="o-trash" />
        </div>
    </div>
</div>
```

**Guidelines:**

- No technical type badges (OAuth/Webhook)
- Icon represents connection/service type
- Metadata as text, not badges
- Actions in footer with border separator
- Keep it simple and scannable

### Tables

```blade
<div class="overflow-x-auto">
    <table class="table table-zebra">
        <thead>
            <tr>
                <th>Primary Column</th>
                <th>Secondary</th>
                <th class="text-right">Actions</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <div class="font-medium">Main information</div>
                    <div class="text-sm text-base-content/70">Metadata</div>
                </td>
                <td>
                    <x-badge value="Category" class="badge-outline badge-sm" />
                </td>
                <td class="text-right">
                    <x-button class="btn-ghost btn-sm" icon="o-eye" />
                </td>
            </tr>
        </tbody>
    </table>
</div>
```

**Guidelines:**

- Use `table-zebra` for better scannability
- Primary info bold, metadata muted below
- Actions right-aligned
- Use ghost buttons for inline actions
- Keep badges minimal

## Anti-Patterns (What NOT to Do)

### ❌ Too Many Badges

```blade
<!-- BAD: Visual noise, redundant information -->
<div class="flex gap-2">
    <x-badge value="OAuth" class="badge-primary" />
    <x-badge value="Connected" class="badge-success" />
    <x-badge value="Monzo" class="badge-secondary" />
    <x-badge value="Active" class="badge-info" />
</div>
```

### ❌ Inconsistent Hierarchy

```blade
<!-- BAD: Skipping heading levels, inconsistent sizing -->
<h2 class="text-2xl">Section</h2>
<h4 class="text-base">Subsection</h4> <!-- Skipped h3 -->
<h3 class="text-xl">Another section</h3> <!-- Wrong order -->
```

### ❌ Inline Toggle with Label

```blade
<!-- BAD: Breaks consistency with other form controls -->
<label class="label cursor-pointer">
    <span class="label-text">Show Archived</span>
    <input type="checkbox" class="toggle" />
</label>
```

### ❌ Arbitrary Colors

```blade
<!-- BAD: Non-semantic colors -->
<div class="text-blue-500">Info text</div>
<x-badge value="Type" class="bg-purple-300" />
```

### ❌ Missing Visual Hierarchy

```blade
<!-- BAD: Everything same size/weight -->
<div>
    <span>Important Title</span>
    <span>Metadata</span>
    <span>Body text</span>
</div>
```

## Progressive Disclosure Pattern

One of the key patterns demonstrated in the events show page is **progressive disclosure** - showing the most important information first, with technical details accessible via a drawer.

### When to Use Progressive Disclosure

- **Detail pages** (show pages for events, objects, blocks, etc.)
- **Pages with complex metadata** (technical info, activity logs, raw JSON)
- **Pages with multiple related sections** (actor/target details, related items)

### Pattern Structure

```blade
<div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
    <!-- Main Content (Always Visible) -->
    <div class="flex-1 space-y-4 lg:space-y-6">
        <x-header title="Title" separator>
            <x-slot:actions>
                <!-- Drawer toggle button -->
                <x-button wire:click="toggleSidebar" class="btn-ghost btn-sm">
                    <x-icon name="{{ $showSidebar ? 'o-x-mark' : 'o-adjustments-horizontal' }}" />
                </x-button>
            </x-slot:actions>
        </x-header>

        <!-- Primary hero content -->
        <x-card>
            <!-- Large icon, title, value -->
        </x-card>

        <!-- Secondary sections -->
        <!-- Linked items, related content, etc. -->
    </div>

    <!-- Drawer (Toggle for Details) -->
    <x-drawer wire:model="showSidebar" right title="Details" separator with-close-button class="w-11/12 lg:w-1/3">
        <div class="space-y-4 lg:space-y-6">
            <!-- Tags manager -->
            <x-card class="!p-2">...</x-card>

            <!-- Comments -->
            <x-card class="!p-2">...</x-card>

            <!-- Collapsible technical sections -->
            <x-collapse wire:model="activityOpen">
                <x-slot:heading>Activity Timeline</x-slot:heading>
                <x-slot:content><!-- Activity log --></x-slot:content>
            </x-collapse>

            <x-collapse wire:model="metadataOpen">
                <x-slot:heading>Technical Metadata</x-slot:heading>
                <x-slot:content><!-- Raw metadata --></x-slot:content>
            </x-collapse>
        </div>
    </x-drawer>
</div>
```

### What Goes Where

**Main Content Area:**

- Hero information (primary value, title, main action)
- Visual flow (actor → action → target)
- Linked/related items
- Critical user-facing information

**Drawer/Sidebar:**

- Tags and comments
- Activity timeline
- Technical metadata (JSON, raw values)
- Actor/target detailed properties
- System information

### Component State

```php
public bool $showSidebar = false;      // Drawer open/closed
public bool $activityOpen = true;      // Expand timeline by default
public bool $metadataOpen = false;     // Collapse technical details by default

public function toggleSidebar(): void
{
    $this->showSidebar = !$this->showSidebar;
}
```

## Responsive Patterns

### Mobile-First Approach

The events show page demonstrates excellent responsive patterns:

#### Icon Sizes

```blade
<!-- Responsive icon container -->
<div class="w-12 h-12 sm:w-16 sm:h-16 rounded-full bg-primary/10">
    <x-icon name="o-bolt" class="w-6 h-6 sm:w-8 sm:h-8" />
</div>

<!-- Nested list icons -->
<div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-primary/10">
    <x-icon name="o-user" class="w-4 h-4 sm:w-5 sm:h-5" />
</div>
```

#### Text Alignment

```blade
<!-- Center on mobile, left-align on desktop -->
<div class="mb-4 text-center sm:text-left">
    <h2 class="text-xl sm:text-2xl lg:text-3xl font-bold">Title</h2>
</div>

<!-- Metadata: centered mobile, left-aligned desktop -->
<div class="flex flex-wrap items-center justify-center sm:justify-start gap-2">
    <!-- Badges and metadata -->
</div>
```

#### Layout Switching

```blade
<!-- Column on mobile, row on tablet+ -->
<div class="flex flex-col sm:flex-row items-center gap-3">
    <div>Actor</div>
    <!-- Arrow changes direction based on screen size -->
    <x-icon name="o-arrow-down" class="w-4 h-4 sm:hidden" />
    <x-icon name="o-arrow-right" class="w-4 h-4 hidden sm:block" />
    <div>Target</div>
</div>
```

#### Separator Bullets

```blade
<!-- Hidden on mobile, shown on desktop for inline metadata -->
<span>Item 1</span>
<span class="hidden sm:inline">·</span>
<span class="sm:hidden w-full"></span> <!-- Force line break on mobile -->
<span>Item 2</span>
```

## Checklist for New Pages

When creating a new page, ensure:

### Structure

- [ ] Uses `x-header` component with title and optional subtitle
- [ ] Has ONE primary action maximum (btn-primary)
- [ ] Follows responsive patterns (mobile → tablet → desktop)
- [ ] Detail pages use progressive disclosure (main content + drawer for technical details)

### Visual Hierarchy

- [ ] Hero card has no background (clean prominent display)
- [ ] Large responsive hero icon: `w-12 h-12 sm:w-16 sm:h-16`
- [ ] Hero title: `text-xl sm:text-2xl lg:text-3xl font-bold`
- [ ] Hero value: `text-2xl sm:text-3xl lg:text-4xl font-bold text-primary`
- [ ] Section titles: `text-lg font-semibold mb-4` with icon `w-5 h-5`
- [ ] Clear heading hierarchy (h2 → h3, never skipping)

### Spacing & Layout

- [ ] Page sections: `space-y-4 lg:space-y-6`
- [ ] Hero card gap: `gap-4 lg:gap-6`
- [ ] List items: `space-y-3`
- [ ] Drawer width: `w-11/12 lg:w-1/3`

### Cards & Items

- [ ] Standard cards: `bg-base-200 shadow`
- [ ] Featured cards: `bg-base-200/50 border-2 border-info/10`
- [ ] Nested items: `border border-base-200 bg-base-100 rounded-lg p-3`
- [ ] Hover states: `hover:bg-base-50 transition-colors`
- [ ] Icons in lists: `mt-1` for text baseline alignment

### Responsive Behavior

- [ ] Text centered on mobile: `text-center sm:text-left`
- [ ] Metadata centered on mobile: `justify-center sm:justify-start`
- [ ] Directional arrows adapt: vertical mobile, horizontal desktop
- [ ] Drawer: `w-11/12` on mobile, `lg:w-1/3` on desktop

### Colors & Icons

- [ ] Icons use semantic mapping (check the icon table)
- [ ] No arbitrary colors (only semantic: success/error/warning)
- [ ] Badges minimal: `badge-xs badge-outline` for metadata
- [ ] Metadata muted: `text-base-content/70`

### Content Organization

- [ ] Essential info in main content area
- [ ] Technical details in drawer/collapsible sections
- [ ] Empty states are clear and helpful
- [ ] Form controls have labels above inputs (except small checkboxes)
- [ ] Filters collapsible on mobile, visible on desktop

## Migration Notes

When updating existing pages:

1. Start with the header (convert to `x-header`)
2. Fix form controls (toggles with labels above)
3. Audit badges (remove technical/redundant ones)
4. Check icon consistency (use semantic mapping)
5. Review hierarchy (text sizing and spacing)
6. Remove non-semantic colors
7. Test mobile responsiveness

Remember: **Less is more**. When in doubt, remove visual elements rather than adding them.
