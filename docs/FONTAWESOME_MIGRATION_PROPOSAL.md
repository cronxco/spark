# FontAwesome Migration Proposal

This document outlines the architecture changes and icon mappings required to migrate from Heroicons to FontAwesome Free as the primary icon set for Spark.

## Executive Summary

The current Spark application uses **Heroicons** as the primary icon set (via `blade-ui-kit/blade-heroicons`). This proposal recommends migrating to **FontAwesome Free** (via `owenvoke/blade-fontawesome`, already installed) to gain access to:

- **2,000+ icons** vs Heroicons' ~300 icons
- **Brand icons** (Mastercard, Visa, PayPal, GitHub, Spotify, etc.)
- **Domain-specific icons** (piggy-bank, money-bill-transfer, receipt, etc.)
- **Better financial/banking iconography** (perfect for a finance-focused app)

---

## Part A: Architecture Changes Required

### 1. Update the Icon Component

**File:** `resources/views/components/icon.blade.php`

The current component has hardcoded SVG paths for only 8 Heroicons. This needs to be replaced with a dynamic approach that:

1. Detects the icon library from the prefix
2. Renders using the appropriate Blade package
3. Provides consistent size and styling

**Current Implementation Issues:**
- Only 8 hardcoded icons supported
- Falls back to generic "info" icon for all others
- Doesn't leverage the installed `blade-fontawesome` package

**Proposed New Implementation:**

```blade
@props([
    'name' => null,
    'size' => 'w-5 h-5',
])

@if ($name)
    @php
        $iconClass = $attributes->merge(['class' => $size])->get('class');

        // Determine icon library and normalize name
        if (str_starts_with($name, 'fa-') || str_starts_with($name, 'fas-') || str_starts_with($name, 'far-') || str_starts_with($name, 'fab-')) {
            // FontAwesome format: fa-icon-name, fas-icon-name, far-icon-name, fab-icon-name
            $library = 'fontawesome';
            $faName = $name;
        } elseif (str_contains($name, '.')) {
            // Legacy FontAwesome format: fas.icon-name
            $library = 'fontawesome';
            $parts = explode('.', $name, 2);
            $faName = $parts[0] . '-' . $parts[1];
        } elseif (str_starts_with($name, 'o-') || str_starts_with($name, 's-')) {
            // Heroicons format (legacy support)
            $library = 'heroicons';
            $heroName = $name;
        } else {
            // Default to FontAwesome solid
            $library = 'fontawesome';
            $faName = 'fas-' . $name;
        }
    @endphp

    @if ($library === 'fontawesome')
        @svg($faName, $iconClass)
    @else
        {{-- Legacy Heroicons support --}}
        @svg($heroName, $iconClass)
    @endif
@endif
```

### 2. Update Icon Naming Convention

**Current Convention (Heroicons):**
- `o-` prefix = outline style
- `s-` prefix = solid style
- Example: `o-heart`, `s-check`

**Proposed Convention (FontAwesome):**
- `fas-` prefix = solid style (default)
- `far-` prefix = regular/outline style
- `fab-` prefix = brand icons
- Example: `fas-heart`, `far-heart`, `fab-github`

**Migration Helper Function:**

Add to `app/Support/helpers.php`:

```php
/**
 * Convert Heroicon name to FontAwesome equivalent
 */
function heroicon_to_fontawesome(string $heroiconName): string
{
    static $mappings = null;

    if ($mappings === null) {
        $mappings = config('icons.heroicon_to_fontawesome_map', []);
    }

    return $mappings[$heroiconName] ?? heroicon_to_fontawesome_auto($heroiconName);
}

/**
 * Auto-convert Heroicon name when no explicit mapping exists
 */
function heroicon_to_fontawesome_auto(string $heroiconName): string
{
    // Remove o- or s- prefix
    $baseName = preg_replace('/^[os]-/', '', $heroiconName);

    // Default to solid style (fas-)
    return 'fas-' . $baseName;
}
```

### 3. Create Icon Mapping Configuration

**New File:** `config/icons.php`

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Icon Library
    |--------------------------------------------------------------------------
    */
    'default_library' => 'fontawesome',

    /*
    |--------------------------------------------------------------------------
    | Heroicon to FontAwesome Mapping
    |--------------------------------------------------------------------------
    | Maps legacy Heroicon names to their FontAwesome equivalents.
    | Format: 'heroicon-name' => 'fontawesome-name'
    */
    'heroicon_to_fontawesome_map' => [
        // See Part B for complete mapping
    ],
];
```

### 4. Update normalize_icon_for_spotlight() Helper

**File:** `app/Support/helpers.php`

Update to handle FontAwesome naming:

```php
function normalize_icon_for_spotlight(?string $icon): ?string
{
    if (! $icon) {
        return null;
    }

    // FontAwesome: fas-icon-name -> icon-name
    if (preg_match('/^fa[srb]-(.+)$/', $icon, $matches)) {
        return $matches[1];
    }

    // Legacy FontAwesome: fas.icon-name -> icon-name
    if (str_contains($icon, '.')) {
        return explode('.', $icon, 2)[1];
    }

    // Heroicons: o-icon-name or s-icon-name -> icon-name
    return preg_replace('/^[os]-/', '', $icon);
}
```

### 5. Update ListIcons Command

**File:** `app/Console/Commands/ListIcons.php`

Add FontAwesome pattern recognition and update the regex patterns to detect `fas-`, `far-`, `fab-` prefixes.

### 6. Update Design Patterns Documentation

**File:** `docs/DESIGN_PATTERNS.md`

Update the Icon Standards section with new FontAwesome conventions and size guidelines.

### 7. Migration Artisan Command

Create a new command to assist with bulk icon migration:

**New File:** `app/Console/Commands/MigrateIconsToFontAwesome.php`

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MigrateIconsToFontAwesome extends Command
{
    protected $signature = 'icons:migrate-to-fontawesome
                            {--dry-run : Preview changes without modifying files}
                            {--path= : Specific path to scan}';

    protected $description = 'Migrate Heroicon references to FontAwesome';

    public function handle(): void
    {
        // Scan files and replace icon references
        // Use the mapping from config/icons.php
    }
}
```

---

## Part B: Complete Icon Mapping

### Legend

| Category | FontAwesome Advantage |
|----------|----------------------|
| Direct equivalent | Same visual meaning |
| Better alternative | More specific/appropriate icon |
| Brand icon | Specific brand (Mastercard, Visa, etc.) |
| Domain-specific | Financial/banking specific icon |

### Current Heroicons Used (80 unique icons)

Below is a comprehensive mapping of every Heroicon currently used in Spark to its FontAwesome equivalent, with recommendations for improved alternatives where applicable.

---

#### Navigation & UI Actions

| Heroicon | Current Use | FontAwesome Equivalent | Recommended Alternative | Notes |
|----------|-------------|----------------------|------------------------|-------|
| `o-arrow-left` | Back navigation | `fas-arrow-left` | - | Direct equivalent |
| `o-arrow-right` | Forward/Next | `fas-arrow-right` | `fas-chevron-right` | Chevron often better for "next" |
| `o-arrow-up` | Up direction | `fas-arrow-up` | - | Direct equivalent |
| `o-arrow-down` | Down direction | `fas-arrow-down` | - | Direct equivalent |
| `o-arrow-up-right` | External link | `fas-arrow-up-right-from-square` | `fas-external-link` | Better semantic meaning |
| `o-arrow-down-left` | Import | `fas-arrow-down` | `fas-download` | More intuitive |
| `o-arrow-path` | Refresh/Sync | `fas-rotate` | `fas-arrows-rotate` | Better for sync actions |
| `o-arrow-path-rounded-square` | Recurring | `fas-arrows-rotate` | `fas-repeat` | Better for subscriptions |
| `o-arrows-right-left` | Transfer/Exchange | `fas-arrows-left-right` | `fas-exchange-alt` | Classic transfer icon |
| `o-arrow-down-tray` | Download | `fas-download` | - | Direct equivalent |
| `o-arrow-right-circle` | Proceed/Continue | `fas-circle-arrow-right` | `fas-play` | For actions |
| `o-arrow-up-circle` | Upload | `fas-circle-arrow-up` | `fas-upload` | Better semantic |

---

#### Money & Financial

| Heroicon | Current Use | FontAwesome Equivalent | Recommended Alternative | Notes |
|----------|-------------|----------------------|------------------------|-------|
| `o-banknotes` | Salary/Cash | `fas-money-bills` | `fas-money-bill-wave` | More dynamic |
| `o-currency-pound` | GBP/Balance | `fas-sterling-sign` | - | Direct equivalent |
| `o-credit-card` | Card transactions | `fas-credit-card` | `fab-cc-mastercard` / `fab-cc-visa` | **Brand-specific cards!** |
| `o-building-library` | Bank | `fas-building-columns` | `fas-landmark` | Classic bank icon |
| `o-building-storefront` | Merchant | `fas-store` | `fas-shop` | Better for shops |
| `o-scale` | Balance/Comparison | `fas-scale-balanced` | - | Direct equivalent |
| - | Pot/Savings | - | `fas-piggy-bank` | **NEW: Perfect for pots!** |
| - | Budget | - | `fas-wallet` | **NEW: Budget tracking** |
| - | Bill/Invoice | - | `fas-file-invoice-dollar` | **NEW: Bills** |
| - | Receipt | - | `fas-receipt` | **NEW: Receipts** |
| - | Expense | - | `fas-money-bill-transfer` | **NEW: Expense tracking** |
| - | Income | - | `fas-hand-holding-dollar` | **NEW: Income/receiving money** |

---

#### Status & Feedback

| Heroicon | Current Use | FontAwesome Equivalent | Recommended Alternative | Notes |
|----------|-------------|----------------------|------------------------|-------|
| `o-check-circle` | Success/Complete | `fas-circle-check` | - | Direct equivalent |
| `o-x-circle` | Error/Cancel | `fas-circle-xmark` | - | Direct equivalent |
| `o-exclamation-triangle` | Warning | `fas-triangle-exclamation` | - | Direct equivalent |
| `o-information-circle` | Info | `fas-circle-info` | - | Direct equivalent |
| `o-plus-circle` | Add (emphasized) | `fas-circle-plus` | - | Direct equivalent |
| `o-minus-circle` | Remove (emphasized) | `fas-circle-minus` | - | Direct equivalent |
| `o-plus` | Add | `fas-plus` | - | Direct equivalent |
| `o-minus` | Remove | `fas-minus` | - | Direct equivalent |

---

#### Trends & Analytics

| Heroicon | Current Use | FontAwesome Equivalent | Recommended Alternative | Notes |
|----------|-------------|----------------------|------------------------|-------|
| `o-arrow-trending-up` | Positive trend | `fas-arrow-trend-up` | `fas-chart-line` | For charts |
| `o-arrow-trending-down` | Negative trend | `fas-arrow-trend-down` | - | Direct equivalent |
| `o-chart-bar` | Analytics | `fas-chart-simple` | `fas-chart-bar` | Multiple options |
| `o-sparkles` | AI/Magic | `fas-wand-magic-sparkles` | `fas-stars` | AI features |
| `o-light-bulb` | Ideas/Tips | `fas-lightbulb` | - | Direct equivalent |
| `o-beaker` | Experimental | `fas-flask` | `fas-vial` | Lab/experimental |

---

#### Users & People

| Heroicon | Current Use | FontAwesome Equivalent | Recommended Alternative | Notes |
|----------|-------------|----------------------|------------------------|-------|
| `o-user` | User/Person | `fas-user` | - | Direct equivalent |
| `o-user-plus` | Add user | `fas-user-plus` | - | Direct equivalent |
| `o-user-minus` | Remove user | `fas-user-minus` | - | Direct equivalent |
| `o-user-group` | Group/Team | `fas-users` | `fas-people-group` | More people |
| `o-user-circle` | Profile | `fas-circle-user` | - | Direct equivalent |
| `o-face-smile` | Mood/Emoji | `fas-face-smile` | `fas-face-grin` | Many options |

---

#### Content & Documents

| Heroicon | Current Use | FontAwesome Equivalent | Recommended Alternative | Notes |
|----------|-------------|----------------------|------------------------|-------|
| `o-document` | Document | `fas-file` | - | Direct equivalent |
| `o-document-text` | Text document | `fas-file-lines` | - | Direct equivalent |
| `o-document-duplicate` | Copy/Duplicate | `fas-copy` | `fas-clone` | Better for copy action |
| `o-bookmark` | Saved/Bookmark | `fas-bookmark` | - | Direct equivalent |
| `o-tag` | Tag/Label | `fas-tag` | `fas-tags` | Multiple tags |
| `o-hashtag` | Hashtag | `fas-hashtag` | - | Direct equivalent |
| `o-link` | Link | `fas-link` | - | Direct equivalent |
| `o-photo` | Image | `fas-image` | - | Direct equivalent |
| `o-list-bullet` | List | `fas-list` | `fas-list-ul` | Bulleted list |

---

#### Communication

| Heroicon | Current Use | FontAwesome Equivalent | Recommended Alternative | Notes |
|----------|-------------|----------------------|------------------------|-------|
| `o-chat-bubble-left` | Message | `fas-comment` | `fas-message` | Direct equivalent |
| `o-chat-bubble-left-ellipsis` | Typing | `fas-comment-dots` | - | Direct equivalent |
| `o-chat-bubble-left-right` | Conversation | `fas-comments` | - | Direct equivalent |
| `o-microphone` | Audio/Voice | `fas-microphone` | - | Direct equivalent |
| `o-speaker-wave` | Sound on | `fas-volume-high` | - | Direct equivalent |

---

#### Time & Calendar

| Heroicon | Current Use | FontAwesome Equivalent | Recommended Alternative | Notes |
|----------|-------------|----------------------|------------------------|-------|
| `o-clock` | Time/Pending | `fas-clock` | `far-clock` | Outline available |
| `o-calendar` | Calendar | `fas-calendar` | `fas-calendar-days` | With days |
| `o-calendar-days` | Calendar (detailed) | `fas-calendar-days` | - | Direct equivalent |

---

#### Media & Entertainment

| Heroicon | Current Use | FontAwesome Equivalent | Recommended Alternative | Notes |
|----------|-------------|----------------------|------------------------|-------|
| `o-play` | Play | `fas-play` | `fas-circle-play` | Circle version |
| `o-pause` | Pause | `fas-pause` | `fas-circle-pause` | Circle version |
| `o-musical-note` | Music | `fas-music` | `fas-headphones` | For listening |
| `o-fire` | Hot/Trending | `fas-fire` | `fas-fire-flame-curved` | More dynamic |
| `o-heart` | Like/Favorite | `fas-heart` | `far-heart` | Outline for unliked |
| `o-bolt` | Power/Energy | `fas-bolt` | `fas-bolt-lightning` | More dramatic |
| `o-sun` | Light mode | `fas-sun` | - | Direct equivalent |
| `o-moon` | Dark mode | `fas-moon` | - | Direct equivalent |

---

#### Technology & System

| Heroicon | Current Use | FontAwesome Equivalent | Recommended Alternative | Notes |
|----------|-------------|----------------------|------------------------|-------|
| `o-cog` | Settings | `fas-gear` | `fas-gears` | Multiple gears |
| `o-code-bracket` | Code | `fas-code` | - | Direct equivalent |
| `o-cloud` | Cloud | `fas-cloud` | - | Direct equivalent |
| `o-puzzle-piece` | Integration/Plugin | `fas-puzzle-piece` | - | Direct equivalent |
| `o-shield-check` | Security | `fas-shield-halved` | `fas-shield` | Security icon |
| `o-globe-alt` | Web/Internet | `fas-globe` | `fas-earth-americas` | Globe options |
| `o-globe-americas` | Americas | `fas-earth-americas` | - | Direct equivalent |
| `o-globe-europe-africa` | Europe/Africa | `fas-earth-europe` | - | Direct equivalent |

---

#### Location & Maps

| Heroicon | Current Use | FontAwesome Equivalent | Recommended Alternative | Notes |
|----------|-------------|----------------------|------------------------|-------|
| `o-map` | Map | `fas-map` | `fas-map-location-dot` | With marker |
| `o-map-pin` | Location | `fas-location-dot` | `fas-map-marker-alt` | Location marker |

---

#### Misc UI Elements

| Heroicon | Current Use | FontAwesome Equivalent | Recommended Alternative | Notes |
|----------|-------------|----------------------|------------------------|-------|
| `o-archive-box` | Archive | `fas-box-archive` | - | Direct equivalent |
| `o-battery-100` | Full battery | `fas-battery-full` | - | Direct equivalent |
| `o-cursor-arrow-rays` | Click/Interact | `fas-arrow-pointer` | `fas-hand-pointer` | Pointer options |
| `o-ellipsis-horizontal` | More options | `fas-ellipsis` | - | Direct equivalent |
| `o-pencil` | Edit | `fas-pen` | `fas-pen-to-square` | Edit action |
| `o-rectangle-stack` | Layers/Stack | `fas-layer-group` | - | Direct equivalent |
| `o-squares-plus` | Grid add | `fas-grip` | `fas-plus-square` | Add to grid |

---

### New FontAwesome Icons for Financial Features

These icons are **not available in Heroicons** but would significantly improve the Spark experience:

| Icon | FontAwesome Name | Use Case |
|------|-----------------|----------|
| Piggy Bank | `fas-piggy-bank` | Savings pots, goals |
| Wallet | `fas-wallet` | Budgets, spending limits |
| Money Bill Transfer | `fas-money-bill-transfer` | Transfers between accounts |
| Hand Holding Dollar | `fas-hand-holding-dollar` | Income, receiving money |
| Receipt | `fas-receipt` | Transaction receipts |
| File Invoice Dollar | `fas-file-invoice-dollar` | Bills, invoices |
| Coins | `fas-coins` | Small amounts, change |
| Vault | `fas-vault` | Secure savings |
| Calculator | `fas-calculator` | Calculations, totals |
| Sack Dollar | `fas-sack-dollar` | Large sums, wealth |
| Cash Register | `fas-cash-register` | Point of sale |
| Chart Pie | `fas-chart-pie` | Budget breakdown |
| Percent | `fas-percent` | Interest rates, discounts |

### Brand Icons for Payment Methods

| Brand | FontAwesome Name | Use Case |
|-------|-----------------|----------|
| Mastercard | `fab-cc-mastercard` | Mastercard transactions |
| Visa | `fab-cc-visa` | Visa transactions |
| American Express | `fab-cc-amex` | Amex transactions |
| Apple Pay | `fab-cc-apple-pay` | Apple Pay |
| Google Pay | `fab-google-pay` | Google Pay |
| PayPal | `fab-cc-paypal` | PayPal transactions |
| Stripe | `fab-cc-stripe` | Stripe payments |
| Amazon Pay | `fab-amazon-pay` | Amazon payments |

### Brand Icons for Integrations

| Brand | FontAwesome Name | Use Case |
|-------|-----------------|----------|
| GitHub | `fab-github` | GitHub integration |
| Spotify | `fab-spotify` | Spotify integration |
| Slack | `fab-slack` | Slack integration |
| Reddit | `fab-reddit` | Reddit integration |
| Google | `fab-google` | Google Calendar |
| Apple | `fab-apple` | Apple Health |
| Discord | `fab-discord` | Discord (future) |
| Trello | `fab-trello` | Trello (future) |
| Notion | - | Not in FA Free |

---

## Part C: Implementation Plan

### Phase 1: Foundation (Non-breaking)

1. **Create config/icons.php** with the complete mapping
2. **Update icon component** to support both libraries
3. **Add helper functions** for icon conversion
4. **Update tests** to validate icon references

### Phase 2: Gradual Migration

1. **New features use FontAwesome** by default
2. **Update plugin getIcon() methods** one integration at a time
3. **Update action/block types** with better icons where available
4. **Update Blade templates** in priority order

### Phase 3: Legacy Cleanup

1. **Remove Heroicons package** (optional, can keep for fallback)
2. **Update all remaining references**
3. **Update documentation**
4. **Run migration command** to catch stragglers

---

## Appendix: Quick Reference Card

### Size Classes (Same as Heroicons)

| Context | Size Class |
|---------|-----------|
| Empty states | `w-16 h-16` or `w-12 h-12` |
| Card headers | `w-10 h-10` |
| Button icons | `w-5 h-5` or `w-4 h-4` |
| Inline icons | `w-4 h-4` |
| Badge icons | `w-3 h-3` |

### Style Prefixes

| Prefix | Style | Example |
|--------|-------|---------|
| `fas-` | Solid (filled) | `fas-heart` |
| `far-` | Regular (outlined) | `far-heart` |
| `fab-` | Brand | `fab-github` |

### Common Patterns

```blade
{{-- Solid icon (default) --}}
<x-icon name="fas-heart" class="w-5 h-5" />

{{-- Outlined icon --}}
<x-icon name="far-heart" class="w-5 h-5" />

{{-- Brand icon --}}
<x-icon name="fab-github" class="w-5 h-5" />

{{-- Payment card with brand --}}
<x-icon name="fab-cc-mastercard" class="w-8 h-6" />

{{-- Financial icons --}}
<x-icon name="fas-piggy-bank" class="w-5 h-5 text-success" />
<x-icon name="fas-money-bill-transfer" class="w-5 h-5 text-primary" />
```

---

## Conclusion

Migrating to FontAwesome Free provides significant advantages for Spark:

1. **3x more icons** available for use
2. **Brand icons** for payment methods and integrations
3. **Domain-specific icons** perfect for financial apps
4. **Better visual consistency** across the application
5. **Future-proofed** with regular updates from FontAwesome

The `owenvoke/blade-fontawesome` package is already installed, making this migration straightforward. The proposed architecture maintains backward compatibility while enabling a gradual transition.
