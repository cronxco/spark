# Spark design language

The one set of design rules for Spark web (`cronxco/spark`) and Spark iOS (`cronxco/spark-ios`), so a sleep score, a Monzo payment or an AI summary reads as the same thing on either platform. The same text lives in both repositories: `docs/design-language.md` on iOS and `docs/UI and UX/DESIGN_LANGUAGE.md` on the web. Change both in the same pair of pull requests.

**How this relates to the other design sources.** The [Spark Design System](https://claude.ai/artifact/GbFZoNjtnLwNpKNrJ5SsuN) holds the token values (colour ramps, type scale, spacing, radii) and shows each component and pattern rendered. This document says how to use them, on both platforms. It replaces the web's `DESIGN_PATTERNS.md` and `BLOCK_CARDS.md`, which were merged into it on 29 October 2026. Where the old documents disagreed with it, Will Scott decided each point and this text records the result.

Written 28 October 2026 from a read of iOS `main` @ `539f651` and web `dev` @ `f8d3ad4b`, and merged 29 October 2026. Sources are listed at the end.

## Contents

1. [Principles](#1-principles)
2. [Platform mapping](#2-platform-mapping)
3. [Colour](#3-colour)
4. [Type](#4-type)
5. [Space, shape and surfaces](#5-space-shape-and-surfaces)
6. [Glyphs](#6-glyphs)
7. [Cards](#7-cards)
8. [Visual patterns for blocks and events](#8-visual-patterns-for-blocks-and-events)
9. [Writing data](#9-writing-data)
10. [Interaction and motion](#10-interaction-and-motion)
11. [Accessibility checks](#11-accessibility-checks)
12. [Page patterns](#12-page-patterns)
13. [What to remove](#13-what-to-remove)
14. [Known deviations](#14-known-deviations)
15. [Checklist for a new screen](#15-checklist-for-a-new-screen)
16. [Sources](#16-sources)

---

## 1. Principles

1. **Calm before dense.** Each screen has one subject and one hero. Day is the calm briefing, Explore is the dashboard, Flint is the coach. They share rules, not density.
2. **The number first, its meaning second.** Lead with the value and unit. Add one line of context (against your usual, against a target) only when it changes what the reader thinks.
3. **Colour is information.** A tint says which domain something belongs to, or that something needs attention. It is never decoration, and it is never the only carrier of meaning.
4. **One layout per type, one fallback.** Pick a card's layout from its `block_type` or event `action` through one registry per platform. Anything unknown gets the generic card. Adding a type means adding one entry, never a new branch at a call site.
5. **Glass is for controls, not content.** Liquid Glass floats navigation and controls above content. Content sits on a quiet surface (see [5.3](#53-surfaces)).
6. **Show the essentials, disclose the rest.** The main view holds what the reader came for. Technical detail (raw metadata, activity logs, IDs) is one tap away in a drawer or inspector, never in the way.
7. **Say what the data can and cannot tell you.** Show freshness, partial days and missing values honestly. A gap is shown as a gap, never as zero.
8. **Accessible by construction.** Contrast, Dynamic Type, Reduce Motion and Reduce Transparency are design inputs, not checks at the end.
9. **Quiet at rest, responsive to touch, rewarding at completion.** Interaction feedback is immediate, spatially coherent and restrained.
10. **Less is more.** When in doubt, remove a badge, border or colour rather than add one.

## 2. Platform mapping

| Concept | iOS (SparkUI) | Web (Tailwind + daisyUI + maryUI) |
|---|---|---|
| Page ground | `Color.sparkSurface` + `.sparkAppBackground()` wash | `bg-base-100` |
| Content surface | `SparkContentSurface` (new, see 5.3) | `bg-base-200` card, no shadow, no hover lift |
| Featured section | Content surface with a 2pt `info` stroke at 10% | `bg-base-200/50 border-2 border-info/10` |
| Control surface | `.sparkGlass()` | `btn`, `bg-base-100` + `border-base-300` |
| Text, secondary text | `.primary`, `.secondary` | `text-base-content`, `text-base-content/70` |
| Hero value colour | `Color.sparkHero(domain:)` (new, see 3.6) | `text-hero` (new theme var, see 3.6) |
| Domain tint | `Color.domainTint(_:)` via `EntityPresentation` | `text-domain-*`, `bg-domain-*` (new theme vars, see 3.2) |
| Display face | `SparkTypography.hero*` (Comfortaa) | `font-display` (Comfortaa) |
| UI face | SF Pro via `Font.system` | `font-sans` (Nunito) |
| Mono | `SparkTypography.mono*` (PT Mono) | `font-mono` (PT Mono) |
| Reading face | `SparkTypography.longForm*` (New York) | `font-serif` = `ui-serif, Georgia, serif` |
| Spacing | `SparkSpacing.*` (4pt grid) | Tailwind spacing (4px grid) |
| Radius | `SparkRadii.*` | `rounded-box` (8px), `rounded-field` (4px), `rounded-full` |
| Glyph | Font Awesome Solid, via `SparkIcon` and `EntityPresentation` | Font Awesome Solid, via a PHP map (see 6) |
| Glyph chip | `DomainGlyph` | Rounded-square `div` with a 12% domain wash (see 6) |
| Card registry | `BlockKind` / `EventKind` → `BlockCard` / `EventCard` | `blocks/types/{type}.blade.php` → `<x-block-card>` |
| Page header | `.navigationTitle` + `.toolbar` | `<x-header>` |
| Technical detail | Inspector sheet of `InspectorRow`s | `<x-drawer>` of `<x-collapse>` sections |
| Section heading | `SparkSectionHeader`, `SectionLabel` | `h3.text-lg.font-semibold` |
| Status line | `StatusPill` (dot, finding, count) | Text with a status glyph |
| Status chip (action needed) | A capsule in the status fill with its `-content` text | `badge badge-error` / `badge-warning` |
| Domain filter chip | `DomainChip` | `btn btn-sm rounded-full` with a domain wash |
| Tag | `TagChip` | `<x-tag-ref>` |
| Reference (integration, object, block, event, place) | `EntityRefChip` | `<x-integration-ref>`, `<x-object-ref>`, `<x-block-ref>`, `<x-event-ref>`, `<x-place-ref>` and the other `x-*-ref` components |
| Peek card (a tag's or reference's summary) | `.popover` with `.presentationCompactAdaptation(.popover)` | The `x-*-ref` popover card |
| Empty state | `EmptyState` | Centred block (see 12.8) |

## 3. Colour

### 3.1 Roles

Use semantic tokens, never raw ramp steps or system colours (`Color.red`, `text-blue-500`, `bg-purple-200`).

| Role | Use | Rule |
|---|---|---|
| `primary` (spark-5 amber) | The one action on a screen, the active tab, progress, hero values in dark mode | A **fill** in light mode. Never text on a light ground (1.6:1). Text on it is `primary-content` / `sparkOnAccent`. In dark mode it is also the hero value colour (3.6). |
| `accent` (ocean-5 light, sky-3 dark) | Links, quiet emphasis | Reads as text in both themes (8:1+). |
| `success`, `warning`, `error`, `info` | Status that needs a reaction | Fills in light mode, text only in dark. Pair with a glyph and words. |
| Domain tints | Which part of life a thing belongs to | One per domain; see 3.2. |
| `mood-2` … `mood-10` | Check-in scores | Fills only. Position and label carry the value. |

**Secondary text** is `text-base-content/70` on the web and `.secondary` on iOS. That is the faintest any text may be: `/70` passes 4.5:1 in both themes, `/50` does not. `/50` and `/40` (and `.tertiary` on iOS) are allowed only for decorative glyphs that have a text label beside them.

Plugin accent colours (`getAccentColor()`) are never used for UI. A Spotify block is a media block.

### 3.2 Domain tints

The platforms disagree today. iOS uses palette steps (money is amber, media is coral). The web maps domains onto daisyUI status colours in nine separate Blade files (money is `warning`, which is mauve; media is `info`, which is blue). Status colours mean "act on this", so borrowing them for domains makes every money card look like a warning. **Both platforms adopt the iOS mapping**, plus an ink step for text and an `online` entry iOS lacks.

| Domain | Shared Font Awesome glyph | Fill | Ink, light | Ink, dark |
|---|---|---|---|---|
| health | `fas.heart-pulse` | success `#7abaa1` | `success-content` | success-dark `#b3d8c9` |
| activity | `fas.person-running` | ember-5 `#F79129` | ember-7 `#A75706` (5.1:1) | ember-5 (7.9:1) |
| money | `fas.sterling-sign` | spark-5 `#FFBF00` | spark-8 `#664D00` (7.8:1) | spark-5 (11.1:1) |
| media | `fas.circle-play` | flame-5 `#EE6352` | flame-7 `#B02411` (6.6:1) | flame-5 (5.7:1) |
| knowledge | `fas.book` | sky-5 `#3f88c5` | sky-6 `#316ea1` (5.3:1) | sky-3 `#8db9dd` (8.8:1) |
| online | `fas.globe` | ocean-5 `#244f83` in light, sky-3 `#8db9dd` in dark (the `accent` token) | ocean-5 (8.1:1) | sky-3 (8.8:1) |
| anomaly | `fas.triangle-exclamation` | warning `#b16c89` | `base-content` (no step clears 4.5:1) | warning-dark `#d2abbb` |

Contrast figures are against `ash-1` in light and `slate-5` in dark. Online shares the `accent` hue with links, so an online glyph or chip is always paired with its `globe` glyph and a label, never shown as a bare blue word.

- **Fill** is for glyph chips, ring and bar strokes, chart marks and 10–15% tinted backgrounds. Fills do not need 4.5:1 because a number or label always sits next to them.
- **Ink** is for tinted text: a domain chip's label, or a hero value in light mode (3.6). If a domain has no ink step, the text stays `base-content` and the glyph carries the hue.
- Glyphs take their domain fill. Glyphs with no domain (actions, navigation, section headings) are neutral: `base-content`, or `base-content/70` when secondary. Status colours go on a glyph only when it shows a status (6).
- On the web, add `--color-domain-{name}` and `--color-domain-{name}-ink` to both daisyUI themes in `resources/css/app.css`, and replace the nine `$domainColors` arrays with one helper.

### 3.3 Highlights in prose

Facts that Flint or a summary picks out inside running text (in Up to Speed, digests and recaps) take one `highlight` token, whatever their domain: ocean-5 `#244f83` in light (8.1:1 on ash-1) and spark-5 `#FFBF00` in dark (11.1:1 on slate-5), at semibold weight. On iOS this is `Color.sparkHighlight`, which replaces `.sparkAccent` as the tint passed to `SparkLongFormContentView`. On the web it is `--color-highlight` in both daisyUI themes. Never use spark-5 as text on a light ground.

### 3.4 Change and comparison

A delta is good or bad according to the metric, not its direction. Lower spend, lower resting heart rate and fewer awake minutes are good. Each metric declares its polarity (`higherIsBetter`), and the arrow glyph takes `success` or `error` from that. The number itself stays `base-content`. A neutral change (inside the usual range) is `secondary` with a flat arrow.

Every comparison line has one shape: arrow, amount, direction word, baseline. `↓ 4bpm below 30d trend`, `↑ £9 above 7d trend`, `↓ 12 below 30d trend` for a score. The arrow shows direction and carries the polarity colour; the amount always carries the metric's unit, unspaced (`4bpm`, `35m`), and only unitless scores go bare; the baseline is the window (`30d`, `7d`, `12w`) followed by "trend". No "your", no metric name repeated.

### 3.5 Generated text

Anything a model wrote sits on a **generated surface** so it can be told apart from source content at a glance:

- A diagonal gradient from spark-5 at 6% to ember-5 at 16% (top-leading to bottom-trailing), laid over the normal content surface, with a 1px hairline of ember-5 at 30%. In dark mode the same hues run from 10% to 22%. This is the Flint avatar's amber-to-ember pairing, so generated text reads as Flint's voice. It replaces the web's `from-warning/5 to-warning/25` panel, because `warning` means "act on this".
- The eyebrow carries the `sparkles` glyph (`fas.wand-magic-sparkles` on the web) and the word "Summary" or "Flint". Never use the glyph alone, and never put it beside text a person wrote.
- Text on the surface stays `base-content`. The gradient is the only tint.
- One generated surface per card. Inside a list of generated items (takeaways, key points), the card carries the surface and the rows stay plain.
- No model names, word counts or character counts.
- On iOS this is `.sparkGeneratedSurface(radius:)` in SparkUI/Materials. It is opaque content, not glass, and under Increase Contrast it keeps the hairline at full strength. On the web it is a `.surface-generated` class in `app.css`, built from `--color-spark` and `--color-ember` tokens.

### 3.6 Hero values

The hero value (the big number on a card or detail page) is the one place amber is used as text, and only in dark mode, where it reads at 11:1 or better.

- **Light mode**: the card's domain ink (3.2), or `base-content` when the card has no domain or its domain has no ink step.
- **Dark mode**: spark amber (`primary` in the dark theme), whatever the domain.
- The unit and any secondary values stay `secondary` in both modes. Rings keep their domain fill for the stroke; only the number inside follows this rule.
- On the web this is the `text-hero` utility, which resolves to `var(--color-hero, var(--hero-ink, base-content))`. The card shell and detail pages set `--hero-ink` to the domain ink, and the dark theme sets `--color-hero` to `primary`. On iOS it is `Color.sparkHero(domain:)`, which resolves the same way from `Color.domainInk(for:)`.

## 4. Type

| Voice | Face | Use | Do not use for |
|---|---|---|---|
| Display | Comfortaa | One hero per screen, section headings on Day, hero numerals | Body text, buttons, data tables |
| UI | SF Pro (iOS), Nunito (web) | Everything else: rows, labels, controls, most numbers | |
| Reading | New York / `ui-serif` | Articles, digests, Flint prose | Chrome, labels |
| Mono | PT Mono | IDs, hex, code, raw technical values | Ordinary timestamps (use UI face, `secondary`) |

- **Numbers in rows and grids use tabular figures** (`.monospacedDigit()`, `tabular-nums`) so columns line up and values don't jitter on refresh.
- **Units scale with the value**: the unit is about 55% of the value's size, in the same face, `secondary`, baseline-aligned. At the card hero (44pt) that is 24pt; at compact (24pt), 13pt. `7h 55m` is the exception; it is one string at one size.
- **Sentence case everywhere.** No all-caps eyebrows. Section labels use `captionStrong` (iOS) or `text-xs font-semibold` (web).
- **Headings never skip a level**: h2 for the page hero, h3 for sections, h4 inside a section.
- iOS uses text styles so Dynamic Type works. A layout that cannot hold a value at accessibility sizes stacks vertically rather than truncating the number.

Web scale:

| Element | Classes |
|---|---|
| Detail page hero value | `font-display text-4xl sm:text-5xl lg:text-6xl font-bold text-hero`, unit `text-xl sm:text-2xl lg:text-3xl` |
| Detail page title | `text-xl sm:text-2xl lg:text-3xl font-bold`, above the value |
| Card hero value | `font-display text-5xl font-bold text-hero`, unit `text-2xl` |
| Compact card hero value | `font-display text-2xl font-bold text-hero`, unit `text-sm` |
| Section title | `text-lg font-semibold` |
| Card title, list item title | `text-base font-semibold` (or `font-medium` in dense lists) |
| Body | `text-sm` in cards, `text-base` on pages |
| Meta, eyebrow | `text-xs text-base-content/70` (`font-semibold` for an eyebrow) |

iOS adds three display styles for values, all Comfortaa bold and scaling with Dynamic Type: `heroDetail` (56pt, relative to `.largeTitle`) for the detail page, `heroCard` (44pt) for cards and `heroCompact` (24pt) for compact cards, each with a unit style at about 55%. The existing `heroXL`, `hero` and `heroSmall` stay for titles. The full view is always the loudest.

## 5. Space, shape and surfaces

### 5.1 Spacing

A 4pt grid on both platforms. Compose the steps; don't add values between them.

| Between | iOS | Web |
|---|---|---|
| Card padding | 16 (`SparkSpacing.lg`) | `p-4` (maryUI `x-card` default); `p-3` for nested items and grid tiles; `!p-2` for cards inside a drawer |
| Elements inside a card | 8–12 | `gap-2` to `gap-3`, `space-y-2` for related items |
| Glyph and its text | 8 | `gap-2` |
| Cards in a grid or row, items in a list | 12 | `gap-3`, `space-y-3` |
| Cards stacked down a page | 16, 24 on wide screens | `space-y-4 lg:space-y-6`, `gap-4 lg:gap-6` |
| Groups | 24 | `space-y-6` |
| Sections | 32 | `space-y-8` |

### 5.2 Shape

The radii differ by platform on purpose: iOS is card-led and rounder, the web is denser.

| Element | iOS | Web |
|---|---|---|
| Card | `SparkRadii.lg` (22) | `rounded-box` (8px) |
| Compact card, tile, nested item | `SparkRadii.md` (14) | `rounded-box` (8px) |
| Glyph chip, thumbnail | `SparkRadii.sm` (8) | `rounded-field` (4px) on rows, `rounded-lg` on a detail hero |
| Chip, pill button | capsule | `rounded-full` |

Nested corners are concentric: an inner radius equals the outer radius minus the padding.

### 5.3 Surfaces

Three layers, top to bottom:

1. **Controls**: tab bar, toolbars, floating buttons, the Up to Speed header. Liquid Glass on iOS. Never put glass on glass.
2. **Content**: cards and grouped rows. An opaque surface (`sparkElevated`) with no shadow on iOS. `bg-base-200` with no shadow on the web. Simple timeline rows can sit straight on the page with no container.
3. **Ground**: the page, with the time-of-day wash on iOS. The web stays flat.

This follows Apple's guidance that Liquid Glass belongs to the navigation layer above content, and finding 8 of the 6 September iOS review. It reverses part of the 3 October iOS change that moved Flint cards onto glass. iOS adds one modifier, `.sparkContentSurface(radius:tint:)`, and `GlassCard` switches to it. Reduce Transparency and Increase Contrast keep today's fallback: opaque fill and a visible edge.

Elevation is shown by surface change, not shadows. No card, filter panel or nested item has a shadow or lifts on hover, on either platform.

**Featured sections** are the one exception to "no borders". A section that deserves emphasis on a detail page (linked blocks, the actor → target flow) keeps a coloured border: `bg-base-200/50 border-2 border-info/10` on the web, and the content surface with a 2pt `info` stroke at 10% on iOS. Items nested inside a featured section use `border-2 border-info/30 bg-base-100/80`. Use at most one featured section per page, and never on a card in a grid.

**Nested items** inside an ordinary card sit on the page colour to step back down: `rounded-box bg-base-100 p-3` with `hover:bg-base-300` when they link somewhere. No border is needed, because the surface change separates them.

## 6. Glyphs

- **One concept, one Font Awesome glyph, one semantic manifest.** Both platforms resolve named Spark concepts through a single version-pinned manifest (for example `sleep: moon`, `task: circle-check`, `flint: wand-magic-sparkles`). Generate or validate the Swift enum and PHP mapping from it; neither platform guesses glyphs from labels. iOS uses `SparkIcon` through `EntityPresentation`; web uses `App\Support\BlockPresentation`. Bundle only required, licensed Font Awesome assets (prefer vector asset-catalog images on iOS) and test for missing glyphs.
- **Font Awesome Solid is the default on web and iOS.** Brand glyphs (`fab.`) are for genuine service/network marks only. Native platform-owned controls, such as back navigation, switches or standard disclosure controls, may retain their system iconography when substitution harms usability or accessibility. Do not introduce new Heroicons or SF Symbols for Spark-specific semantic content.
- **Licensing and accessibility:** Verify the version and redistribution permissions of bundled assets (Free SVGs, fonts and Pro assets have different terms). Decorative glyphs are hidden from assistive technology when adjacent text conveys their meaning. Interactive glyphs require accessible labels.
- **Colour**: a domain glyph takes its domain fill (3.2). A neutral glyph (action, navigation, section heading) inherits `base-content`, or `base-content/70` when secondary. A status glyph takes its status colour (`fas.circle-check` in `success`). Never colour a glyph for decoration, and never use a status colour for a domain.
- **Glyph chip**: the glyph in its domain fill on a 12% tint of the same fill, in a **rounded square**, the same shape and size on both platforms. A neutral glyph chip uses `base-content` on `base-300`.

| Use | iOS | Web container | Web glyph size |
|---|---|---|---|
| Inline with caption text | 12–14pt, no chip | — | `size-3` to `size-3.5` |
| Eyebrow, button, inline with body | 16–20pt, no chip | — | `size-4` (`size-5` in a section title) |
| Row chip | `DomainGlyph(size: 30)` | `size-7.5 rounded-field` | `size-4` |
| Detail hero chip | `DomainGlyph(size: 42)` | `size-10.5 rounded-lg` | `size-5` |
| Empty state | `.largeTitle`, no chip | — | `size-12`, `text-base-content/70` |

- In a row, align the chip to the first line of text (`mt-1` on the web) rather than centring it on a wrapped block.
- Decorative glyphs are hidden from VoiceOver and screen readers when a text label is next to them.

Block and event kinds (domains are in [3.2](#32-domain-tints)):

| Kind | Shared Font Awesome glyph |
|---|---|---|
| Sleep, sleep stage | `fas.moon` |
| Heart rate | `fas.heart` |
| HRV | `fas.wave-square` |
| Readiness, score | `fas.gauge-high` |
| Steps | `fas.person-walking` |
| Energy, calories | `fas.fire` |
| Intensity | `fas.bolt` |
| Duration | `fas.clock` |
| Distance | `fas.route` |
| Workout set | `fas.dumbbell` |
| Track, album | `fas.music` |
| Book | `fas.book` |
| Article, bookmark | `fas.file-lines` |
| Payment, receipt | `fas.receipt` |
| Transfer | `fas.money-bill-transfer` |
| Balance | `fas.sterling-sign` |
| Savings pot | `fas.piggy-bank` |
| Budget | `fas.wallet` |
| Income | `fas.hand-holding-dollar` |
| Invoice | `fas.file-invoice-dollar` |
| Task | `fas.circle-check` |
| Place | `fas.location-dot` |
| Person | `fas.user` |
| Calendar event | `fas.calendar` |
| Tag | `fas.tag` |
| Link | `fas.link` |
| Generated summary | `fas.wand-magic-sparkles` |
| Photo | `fas.image` |

Interface glyphs:

| Meaning | Shared Font Awesome glyph |
|---|---|---|
| Add, create | `fas.plus` |
| Edit | `fas.pen` |
| Delete | `fas.trash` |
| Search | `fas.magnifying-glass` |
| Filter | `fas.filter` |
| Settings | `fas.gear` |
| More (card and page menus) | `fas.ellipsis-vertical` |
| Back, forward | `fas.arrow-left`, `fas.chevron-right` |
| Expand, collapse | `fas.chevron-down`, `fas.chevron-up` |
| Open externally | `fas.arrow-up-right-from-square` |
| Show technical detail | `fas.sliders` |
| Success, complete | `fas.circle-check` |
| Error, failed | `fas.circle-xmark` |
| Warning, attention | `fas.triangle-exclamation` |
| Info | `fas.circle-info` |
| Pending | `fas.clock` |
| Payment card | `fas.credit-card`, or `fab.cc-visa`, `fab.cc-mastercard`, `fab.cc-amex`, `fab.cc-apple-pay`, `fab.cc-paypal` for a known network |
| Chart, list, grid view | `fas.chart-simple`, `fas.list`, `fas.grip` |

## 7. Cards

### 7.1 Anatomy

Every block and event card is built from the same four slots, top to bottom. Only the hero is required. Cards are leading-aligned at every size; only rings and stat-grid cells centre.

1. **Eyebrow**: glyph, a label (the type's `display_name`, never the raw `block_type`) and, trailing, a relative time. `captionStrong` / `text-xs font-semibold`, `secondary`.
2. **Hero**: either a value with its unit (coloured per 3.6), or a title for text-led cards.
3. **Body**: one visual pattern from [section 8](#8-visual-patterns-for-blocks-and-events), or up to three lines of text.
4. **Context**: one line, `secondary`. A comparison ("↓ 9 below 30d trend", see 3.4), a source ("Oura"), or a count ("+3 more").

Unless a card has its own controls (`linked: false`, below), the whole card is one tap target and goes to the block or event detail. There are no footer badges, type badges or "View" buttons; the eyebrow names the type. Secondary actions (open the event, open the block, open the source URL, copy) sit in one menu with the same items on both platforms:

- **Web**: a `⋮` button at the trailing end of the eyebrow, after the time, in `base-content/70`, with a 24 × 24px minimum target. The card is a link stretched over the title, or over the hero value when the card has no title, and the menu button sits above it, so the button never nests inside a link. `row` and `compact` sizes leave it out; `card` and `full` show it.
- **iOS**: a long press on the card opens a `.contextMenu` with a preview of the card. There is no visible button. Every size gets it.
- The menu never holds the card's main action. A tap still opens the detail.

### 7.2 Sizes

| Size | Where | Shows | Layout |
|---|---|---|---|
| `ref` | Inline in prose, event and object detail, relationship lists, the actor and target of an event | Glyph, title, and an optional service label | The smallest view of any integration, object, block or event. See 7.5 |
| `compact` | Up to Speed, widgets, a row of signals on Day | Eyebrow (glyph + short label), hero value (with an against-the-mean bar for durations and counts) or a 64pt ring for scores, one context line | Minimum height, 2 or 3 across. At accessibility text sizes, one column with variable height so nothing clips. No body. |
| `row` | Event detail, Knowledge item, timelines, lists inside a section | Glyph chip, label, value or title, trailing time | One line, 44pt minimum height |
| `card` | Web grids, iOS block lists that need a body | All four slots | Full width on phone, grid on the web (`grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3`) |
| `full` | Block or event detail screen | Hero at display size (4), body large, context and source below | Whole screen; see 12.2 |

One component renders every size: `BlockCard(block, size:)` and `EventCard(event, size:)` in SparkUI, `<x-block-card :block :size>` and `<x-event-card :event :size>` on the web.

### 7.3 Event cards in Up to Speed

When Flint prose mentions a metric that has an event behind it (sleep duration, sleep score, readiness, activity score), show those events as `compact` cards under the paragraph. Put up to three in a row, ordered as the prose mentions them. Highlighted phrases in the prose use the `highlight` token (3.3), and each card's glyph carries its domain tint. Scores (sleep score, readiness, activity score) show as a ring. Durations and counts (sleep, steps) show as the value over a thin against-the-mean bar (§8, Bar) with its Avg marker. Tapping a card opens the event. With more than three, the third card reads "+N more" and opens a list.

### 7.4 Adding a card type

Each platform has one registry from type to layout, and one fallback.

- **iOS**: add a case to `BlockKind` (or `EventKind`) with its glyph, domain and pattern. `BlockCard` and `EventCard` switch on the kind, never on substrings of `block_type` or `action`.
- **Web**: add `resources/views/blocks/types/{block_type}.blade.php` (for example `fetch_key_takeaways.blade.php`), and give the type a `display_name` and a `fas.` icon in the plugin's `getBlockTypes()`. The label comes from that `display_name`, and the domain from the plugin's `getDomain()` (falling back to knowledge). The glyph comes from `App\Support\BlockPresentation::KIND_ICONS` first, then the plugin's icon, then the domain glyph, so add a `KIND_ICONS` entry only when the glyph should differ from the plugin's. `<x-block-card>` includes `$block->getCustomCardLayoutPath()` (`blocks.types.{type}`) when `$block->hasCustomCardLayout()`, and otherwise falls back to the generic value card (blocks with a value) or content card (blocks without). `Block::getBlockTypesWithCustomLayouts()` lists the types that have one.

**A type's layout draws the hero and body only.** It wraps them in `<x-block-card.shell>`, which draws the surface, eyebrow (glyph, label, time, `⋮`), optional title, context line and tap target, and switches to the generated surface (3.5) for AI-written blocks. The body uses the shared pattern components (`<x-block-card.value>`, `.ring`, `.bar`, `.stats`, `.takeaways`, `.summary`) rather than its own markup, so layouts cannot drift:

```blade
{{-- resources/views/blocks/types/fetch_key_takeaways.blade.php --}}
@props(['block'])

<x-block-card.shell :block="$block">
    <x-block-card.takeaways :items="$block->metadata['content'] ?? []" />
</x-block-card.shell>
```

The shell takes a `title`, a `context` slot, `linked` and `menu`. With `linked` true (the default) the shell provides the one card-wide detail link. With `linked` false it provides none, for cards with their own controls where a card-wide link would swallow clicks; those controls stay individually reachable. Links and buttons inside the slot need `relative z-10` to sit above the card-wide link. No layout adds its own `card`, `card-body`, shadow, badge, header, footer or gradient.

Block cards appear on event detail pages (`/events/{event}`, every block linked to the event), object pages (`/objects/{object}`, blocks related through relationships) and anywhere `<x-block-card>` is used. The admin blocks table (`/admin/blocks`) stays a table.

### 7.5 References and tags

The current web `x-*-ref` and `<x-tag-ref>` components are the primary design for both platforms. iOS follows them.

**A reference** is the smallest view of an integration, object, block or event: a pill with a leading glyph, the title, and an optional service label (an object or event ref names its integration, e.g. "Had Physical Effort · Apple Health"). On the web it is `inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-sm font-medium` with its colour at 10% fill and 20% border, the glyph at `size-3` and 70% opacity, and the title truncated at about 200px. On iOS it is `EntityRefChip`. Use a reference wherever a card would be too much: in prose, in relationship and "linked" lists, for an event's actor and target, and for the integration behind an item.

**A tag** is a pill in the same shape, with the tag type's glyph (`fas.microphone` for an artist, `fas.compact-disc` for an album, `fab.spotify` for a Spotify context; `fas.tag` otherwise) and the tag name, truncated at a word boundary near 20 characters. On the web it is `<x-tag-ref>`; on iOS, `TagChip`. An item can show all its tags; they wrap.

**Both open a peek card, then link onwards.**

- **Web, pointer:** hovering for 200ms opens the peek card under the pill; it stays open while the pointer is over the card and closes 150ms after the pointer leaves. Clicking the pill goes straight to the detail page (`wire:navigate`).
- **Web, touch** (under `md`), and **iOS:** a tap opens the peek card, and the card's button goes to the detail page. A second tap or a tap outside closes it. On iOS this is a `.popover` adapted to stay a popover on iPhone. A long press keeps the context-menu preview (7.1).
- Only one peek card is open at a time, and Escape closes it.
- **The peek card** is a transient surface (10.3): `bg-base-100` with `shadow-xl` and a 30% tinted border. It shows a header with a rounded-square glyph chip, the type in the eyebrow style (e.g. "MUSIC ALBUM"), and the title. Below are a few facts (counts, last update, status) and one full-width button to the destination ("View Tagged Items", "Configure", "View Event").
- A tag or reference that has no destination still opens its peek card, which says so in a single line of text.
- **Accessibility:** the pill is a link on the web (a button on touch) whose accessible name is the title plus the service, e.g. "Had Physical Effort, Apple Health". The peek card's button is reachable by keyboard. On iOS, the chip is one element with the `.isButton` trait.

**Colour (Will, 2026-10-29): tags and references keep their current per-type colours**, an exception to the domain-tint rule in 3.2 and 6:

- **Tags** colour by tag type, as in `<x-tag-ref>`: money tags (category, type, status, scheme, currency, balance, card) `warning`; decline reason `error`; merchant country and category `info`; music artist and person `secondary`; music album `accent`; Spotify context `success`; emoji `warning`; Spark `primary`; anything else `info`.
- **Integration references** use the plugin's accent colour (`getAccentColor()`). **Object references** colour by concept (person and user `secondary`, account and money `warning`, media `info`, bookmark and document `primary`, day `accent`, otherwise `secondary`). **Block and event references** use their integration's domain mapped to the same daisyUI colours.
- iOS uses the same role names from SparkUI (`success`, `warning`, `error`, `info`, `primary`, `secondary`, `accent`), so a tag is the same colour on both platforms.
- These colours sit only on the pill and its peek card. Glyph chips, cards and heroes still use domain tints.

Tags and references are links, so they are exempt from the "chips mean action" rule in 12.9.

## 8. Visual patterns for blocks and events

Pick one pattern per card. Each has one implementation per platform, shared across every type that uses it.

| Pattern | Use for | iOS | Web | Rules |
|---|---|---|---|---|
| **Value** | Any single number | Hero `Text` + unit | `font-display text-5xl font-bold text-hero` + unit `text-2xl` | Glyph in the eyebrow, not beside the number. Format per [9](#9-writing-data). |
| **Ring** | A ratio with a known maximum: scores out of 100, energy out of 5 | A hero-sized ring view (`Gauge(.accessoryCircularCapacity)` only in widgets and complications) | `<x-block-card.ring>` | The ring is the hero: centred, about 128pt on a card (64pt compact), with the number inside at hero size. Track at 15% fill. Every score out of 100 (sleep, readiness, activity) uses Ring at `compact` and `card` size, with a short tick at the provider's "excellent" threshold (85 for Oura). Never a ring without a maximum. In a `row`, the score is plain text (`66`), since a one-line row has no room for a ring. |
| **Bar** | Progress or share: reading progress, contributors, popularity | `ProgressView` / `Gauge(.linearCapacity)` | `<x-block-card.bar>` | Label and value as text too. **Against the mean** variant for values with no fixed maximum (sleep duration, steps, spend): a vertical marker labelled "Avg" sits at the 30-day mean, the track runs to about 1.5× the mean, and the difference is stated in text ("↑ 35m above 30d trend", in the 3.4 shape). |
| **Stat grid** | 2–8 related fields of one record: set, reps, weight, RPE, rest | `StatGrid` (adaptive `Grid` or `ViewThatFits`) | `<x-block-card.stats>`: `grid grid-cols-[repeat(auto-fit,minmax(4rem,1fr))]` | Each cell centre-aligned, label above value, tabular figures, no dividers. One row when the fields fit; otherwise wrap to a second row (never more than two). Never shrink the type to fit. |
| **Flow** | Money or values moving: transfers, FX, before → after, actor → target | `HStack` with `arrow.right` | `flex flex-col sm:flex-row` with `fas.arrow-down` / `fas.arrow-right` | Amounts in UI face, masked numbers as `••1234`. On a phone-width web layout the flow stacks and the arrow points down. |
| **List** | Takeaways, receipt lines, checklists | `VStack` of rows | `ul` with `space-y-1` | Up to 5 in `card`, then "+N more". Real checkboxes only for tasks. |
| **Image-led** | Album art (1:1), book cover (2:3), photo, bookmark hero (16:9) | `AsyncImage` in the body slot | `img` with `aspect-*` and `object-cover` | Aspect ratio comes from the kind. Placeholder is the kind's glyph on a 12% tint. Never tint artwork. |
| **Text** | Summaries, notes, quotes | `body` or `longFormBodySmall`, 3–5 lines | `text-sm line-clamp-5` | Generated text sits on the generated surface in [3.5](#35-generated-text). |
| **Trend** | A value over time, on `full` cards only | Swift Charts | Chart.js | Show the usual range as a band. Label the latest point. No gridlines beyond a baseline. A line for scores and continuous readings; **bars** for daily totals (sleep duration, steps, spend): one bar per day from zero, the usual range as a band drawn over them, earlier bars at 50% fill, the latest at full fill and labelled. |

## 9. Writing data

These extend the Design System's content rules.

- **Durations** read `7h 55m`, `42m` or `1m 05s`. They are always formatted on the client from seconds or minutes, never shown as raw values.
- **Money** reads `£24.80` (symbol, two decimals) and `£1,240` in compact cards above £1,000. Outgoings have no minus sign in a context that already says "spent". Incomings show `+£`.
- **Scores** read as a bare number (`88`). "Out of 100" is in the accessible label on both platforms: the VoiceOver label on iOS, the `aria-label` on the web.
- **Counts** use grouping separators: `8,412 steps`. A count in a heading is plain text in brackets: "Linked blocks (5)".
- **Missing values** show `—` with the reason one tap away, never `0`. A partial day says so: "So far today".
- **Freshness**: when data is older than its TTL, the context line says "From 3h ago" in `secondary`, and the hero does not change colour.
- **Comparisons** name the baseline: "↓ 9 below 30d trend", not "-9". The default baseline is the 30-day trend, written "30d trend"; other windows follow the same form ("7d trend"). The arrow takes the polarity colour (3.4).
- **Labels** come from the backend `display_name`. Clients don't guess labels from `block_type` strings.
- **Metadata** is text, joined with ` · `: "Monzo · Current account · 2h ago". It is never a row of badges.
- **No implementation detail** in the interface: no "OAuth", "Webhook" or "API key" labels, no model names.

## 10. Interaction, motion, depth and haptics

**Principle: Quiet at rest, responsive to touch, rewarding at completion.** Spark feels personal through consistent feedback, spatial continuity and meaningful acknowledgement—not decorative animation.

### 10.1 Targets and hierarchy

- Targets: at least 44 × 44pt on iOS; WCAG 2.5.8's 24 × 24 CSS px minimum on web (subject to its spacing and other exceptions). Prefer 44 × 44 CSS px for touch actions; preserve a visible keyboard focus indicator.
- One *dominant* primary action per interaction context: `btn-primary` on web, `PillButton` or `.borderedProminent` on iOS. Do not artificially limit a long screen to one actionable operation.
- Feedback is immediate even when an asynchronous operation has yet to commit. Pending, successful, failed and disabled states are visually distinguishable.

### 10.2 Motion

Motion has four jobs: **response** (press/selection), **continuity** (disclosure/navigation), **confirmation** (completion), and **focus** (foreground sheets and menus).

| Motion token | Starting range | Use |
|---|---|---|
| `motion.micro` | 100–150ms | Press and immediate selection feedback |
| `motion.state` | 180–240ms | Disclosure, selection and status changes |
| `motion.spatial` | 250–350ms | Custom card expansion and meaningful layout changes |

- These are proposed testing ranges, not overrides of native system timing. Prefer iOS system navigation and spring behaviour for gesture-driven transitions; gesture motion follows the finger and remains interruptible.
- Animate only the relevant properties. Avoid `transition-all`, unrelated layout movement, repeated entrance effects, loops, pulses, confetti and decorative parallax.
- Motion never delays the next action. Under Reduce Motion / `prefers-reduced-motion`, replace spatial travel with a restrained cross-fade or immediate change as appropriate; avoid making opacity changes mandatory.

### 10.3 Depth and surfaces

Spark has three persistent visual levels and one temporary level:

1. **Ground:** page background and the iOS ambient time-of-day wash.
2. **Content:** opaque cards and grouped rows; no persistent shadows or hover lift.
3. **Floating controls:** navigation and toolbars, native Liquid Glass on supported iOS surfaces and solid elevated surfaces on web.
4. **Transient:** sheets, menus, drag previews and popovers. Restrained shadows, scrims or elevation are permitted *only* where they clarify temporary focus.

- On web, hover changes background (`hover:bg-base-300`), not elevation, scale or shadow. Pressed controls may briefly compress; keyboard activation receives equivalent visual feedback.
- Depth communicates interaction hierarchy, not a domain's importance or status. Avoid glass-on-glass. Reduce Transparency and Increase Contrast use opaque surfaces and visible edges.

### 10.4 iOS haptics

Centralise semantic feedback in `SparkHaptics`, using SwiftUI `.sensoryFeedback` and UIKit feedback generators only where appropriate.

| Event | Feedback | Timing |
|---|---|---|
| Discrete selection in a custom selector | Selection | At selection change |
| Occasional custom tactile impact | Light impact | Synchronised to the physical-feeling gesture |
| Completed task, saved check-in or reflection | Success | Only after confirmed persistence |
| Significant recoverable failure | Error / warning | With visible explanatory feedback |
| Explicit start/stop of a timed activity | Start / stop | At the actual transition |
| Navigation, scrolling, background refresh, passive reading | None | Never automatically vibrate |

- Prefer built-in system control feedback and never duplicate it. Do not add a haptic to every tap, or use vibration as the only feedback channel.
- Support a setting to disable Spark-specific haptics. Respect OS behaviour and accessibility settings.

### 10.5 Loading and completion

- Initial loading uses skeletons at the content's real dimensions (`LoadingShimmer` on iOS). Preserve existing content on ordinary background refresh rather than re-skeletonising it.
- Progress indicators are allowed when they meaningfully communicate an ongoing operation; avoid spinners in each card. Preserve layout to prevent shifts.
- Success is ordinarily expressed by the resulting state, not a toast. On optimistic failure, roll back clearly and offer retry. Never animate passive live values continuously.

### 10.6 Verification

Check touch, keyboard and VoiceOver/screen reader behaviour; light/dark themes; Dynamic Type; Increase Contrast; Reduce Transparency; Reduce Motion; rapid repeated input; failed requests; cancellation; animation interruption; and absence of duplicated haptics. Icon semantics and accessible labels must match across platforms.

## 11. Accessibility checks

Run these on every new card or layout before merging.

- Text is 4.5:1 against its surface in both themes (3:1 at 24pt bold and above). No text below `base-content/70`. Glyphs and ring strokes are 3:1, unless a number beside them carries the same information. Focus indicators are always 3:1.
- Nothing is told apart by hue alone. Domain tints are always paired with a glyph and a label, and deltas with an arrow.
- The card is one accessible element on both platforms, announced as "Sleep score, 66 out of 100, 12 below 30-day trend, 7:40 am". On iOS it is one VoiceOver element. On the web it is one link with that accessible name, reached with Tab and opened with Enter; the ⋮ menu is a separate button after it. A `linked: false` card has no card-wide link; its controls are reached one by one. Decorative glyphs are hidden.
- Every form control has a visible label.
- Works at the largest accessibility text size (iOS) and at 200% zoom (web) without clipping a number.
- Works under Reduce Transparency, Increase Contrast and Reduce Motion.
- Tested with empty, missing, very long and very large values (a £12,450.00 payment, a 90-character track title).

## 12. Page patterns

How whole screens are put together. The web examples use maryUI components; the iOS column names the SparkUI or SwiftUI equivalent.

### 12.1 Page headers

Every web page starts with `<x-header title subtitle separator>`. The subtitle is one sentence. Actions sit in its `actions` slot: at most one `btn-primary`, the rest `btn-outline` or `btn-ghost`. Below `sm`, more than one action collapses into an `<x-dropdown>` behind a `btn-ghost btn-sm` with `fas.ellipsis-vertical`.

On iOS the header is `.navigationTitle` with a `.toolbar`: at most one prominent action, the rest in a `Menu` behind `ellipsis.circle`.

### 12.2 Detail pages and progressive disclosure

A detail page (an event, object, block or integration) shows what the reader came for in the main column, and keeps technical detail one tap away.

| Main view | Drawer (web) / inspector (iOS) |
|---|---|
| The hero: title, value, glyph chip, key metadata | Tags and comments |
| The flow (actor → action → target) | Activity timeline |
| Linked and related items | Technical metadata (raw values, JSON, IDs in mono) |
| Anything the reader acts on | Actor and target properties, system information |

```blade
<div class="flex flex-col lg:flex-row gap-4 lg:gap-6">
    <div class="flex-1 space-y-4 lg:space-y-6">
        <x-header :title="$event->display_name" separator>
            <x-slot:actions>
                <x-button wire:click="toggleSidebar" class="btn-ghost btn-sm" aria-label="Show details">
                    <x-icon name="fas.sliders" class="size-4" />
                </x-button>
            </x-slot:actions>
        </x-header>

        {{-- Hero: centred on phones, leading from sm up --}}
        <x-card class="bg-base-200">
            <div class="flex flex-col sm:flex-row items-center sm:items-start gap-4 lg:gap-6">
                <div class="size-10.5 shrink-0 rounded-lg bg-domain-health/12 flex items-center justify-center">
                    <x-icon name="fas.heart" class="size-5 text-domain-health" aria-hidden="true" />
                </div>
                <div class="flex-1 min-w-0 text-center sm:text-left">
                    <h2 class="text-xl sm:text-2xl lg:text-3xl font-bold">Resting heart rate</h2>
                    <div class="font-display text-4xl sm:text-5xl lg:text-6xl font-bold text-hero tabular-nums">
                        52<span class="text-xl sm:text-2xl lg:text-3xl font-bold text-base-content/70">bpm</span>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center justify-center sm:justify-start gap-x-2 text-sm text-base-content/70">
                        <span>Oura</span><span aria-hidden="true">·</span><span>7:40 am</span>
                    </div>
                </div>
            </div>
        </x-card>

        {{-- Featured section (5.3), then ordinary sections --}}
    </div>

    <x-drawer wire:model="showSidebar" right title="Details" separator with-close-button class="w-11/12 lg:w-1/3">
        <div class="space-y-4 lg:space-y-6">
            <x-card class="!p-2">{{-- Tags --}}</x-card>
            <x-collapse wire:model="activityOpen">{{-- Activity, open by default --}}</x-collapse>
            <x-collapse wire:model="metadataOpen">{{-- Technical metadata, closed by default --}}</x-collapse>
        </div>
    </x-drawer>
</div>
```

- The **detail hero centres on phones** and is leading-aligned from `sm` up (`text-center sm:text-left`, `items-center sm:items-start`, `justify-center sm:justify-start`). On iOS the detail hero centres in a compact horizontal size class and is leading-aligned in regular. Cards elsewhere stay leading-aligned (7.1).
- The title sits above the value. The value uses the detail scale from 4 and the hero colour from 3.6.
- The drawer is closed by default (`$showSidebar = false`). In it, the activity timeline is open and technical metadata is closed.
- On iOS the same split is a scroll view of content surfaces, with an inspector sheet of `InspectorRow`s opened from `info.circle` in the toolbar.

### 12.3 Sections inside a page

- A section is a card with an h3: `text-lg font-semibold mb-4 flex items-center gap-2`, with a `size-5` glyph in `base-content/70` (or the domain fill when the whole section belongs to one domain). On iOS, `SparkSectionHeader` above a content surface.
- A list of related items inside a section uses `space-y-3`, each item a `row` card (7.2) on `bg-base-100` with `hover:bg-base-300`, a glyph chip, the title and value on one line, metadata below in `text-sm text-base-content/70`, and a trailing `fas.chevron-right` in `base-content/50`.
- Featured sections follow 5.3.

### 12.4 Filters

- **Desktop (`lg` and up)**: filters are always visible, in a flat `card bg-base-200` above the results, in one row: search (`flex-1`), then selects, then a "Clear filters" `btn-outline` aligned to the inputs with an empty label.
- **Phone and tablet**: the same controls in a column inside an `<x-collapse>` headed "Filters", closed by default.
- Use the same controls in both views. Search uses `wire:model.live.debounce.300ms`.
- **Domain filter chips** are pills: unselected, the domain's 15% wash with the label in the domain **ink** and the glyph in the domain fill; selected, the solid fill with `primary-content` text. On iOS this is `DomainChip`, on a content surface rather than glass.
- On iOS, search is `.searchable` and filters are a chip row (`RangeChipBar`, `DomainChip`) or a filter `Menu`.

### 12.5 Forms

| Control | Web | iOS |
|---|---|---|
| Text input, select, textarea | Stacked: `label` above, the control `w-full` below, helper text in `label-text-alt` under it | A `Form` row with a visible label |
| Toggle | A horizontal row: label and one-line description on the left, `toggle toggle-primary` on the right, in `p-3 bg-base-100 rounded-box` when grouped (`space-y-3`). Never a toggle inline after its label | `Toggle` in a `Form` section, with the description as the section footer |
| Small checkbox | Inline: `label.cursor-pointer.justify-start.gap-2` with `checkbox checkbox-sm` first | `Toggle` with `.toggleStyle(.checkbox)` on macOS only; a `Toggle` on iOS |
| Radio group | `radio radio-primary` with a label and a one-line description, `space-y-3` | `Picker` (inline or menu) |

Descriptions under toggles and radios use `text-xs text-base-content/70`, not `/60`.

### 12.6 Lists, tables and tabs

- **Tables** (web): `overflow-x-auto` around `table table-zebra`. The first column holds the main information in `font-medium` with metadata below in `text-sm text-base-content/70`. Actions are right-aligned `btn-ghost btn-sm` glyph buttons with an `aria-label`. No badges for categories; write them as text.
- **Lists** (iOS): `List` with `row` cards, or a `LazyVStack` of rows on a content surface.
- **View modes** (cards, table, list) of the same data use `<x-tabs>` with a label and glyph on each tab bound with `wire:model` to a property marked `#[Url]` (or `#[Session]`), so the choice survives a refresh. On iOS, a segmented `Picker`. Tabs never navigate between different pages.

### 12.7 Status lists

Pages that monitor many things (Updates, integrations) sort and collapse by need:

- **Sort by need**: items with issues first (error, then warning, then in progress), most issues first, then healthy items alphabetically.
- **Collapsed by default**, with the summary in the heading: the name, a count in text ("12 instances"), and a status chip only when something needs action ("3 need update" in `badge-error`, "2 pending" in `badge-warning`). Healthy groups show nothing extra, or a `fas.circle-check` in `success` with the words "Up to date".
- Status is text with a glyph, plus a chip when the reader must act. Never a coloured left border (13).
- On iOS: `DisclosureGroup` rows, with the summary as a `StatusPill` line and a status chip only for the states that need action.

### 12.8 Empty states

Centred: a `size-12` glyph in `base-content/70`, an h3 (`text-lg font-medium`), one sentence saying why it is empty (`text-base-content/70`), and a `btn-primary` only when there is something to create and no filter is hiding results ("Try adjusting your filters" otherwise). Use `py-12`. On iOS, `EmptyState(systemImage:title:message:actionTitle:action:)`.

### 12.9 Chips and badges

A chip means "this state needs you". Everything else is text.

- **Status chips** (`badge badge-error`, `badge-warning`, `badge-info` on the web; a capsule in the status fill on iOS) appear only for a state the reader must act on: failed, needs update, pending approval. Pair the colour with words.
- **No badges** for entity type (the eyebrow names it), category ("Current account" is metadata text), counts (write "(5)" in the heading), durations, timestamps or implementation details. A service name appears as text, or as a reference pill when it links to the integration (7.5).
- **Tags and references** are links, not labels. They follow 7.5, and an item can carry several.
- **Domain chips** are filters: `DomainChip` on iOS, `rounded-full` pills on the web.
- At most one status chip per item.

### 12.10 Responsive web layout

- Design for the phone first, then add `sm:` (640px) and `lg:` (1024px) enhancements.
- Stacks become rows at `sm`: `flex flex-col sm:flex-row`. Directional arrows follow: `fas.arrow-down` with `sm:hidden`, `fas.arrow-right` with `hidden sm:block`.
- Inline metadata separators (`·`) are `hidden sm:inline`, and a `sm:hidden w-full` spacer forces the line break on phones.
- Drawers are `w-11/12` on phones and `lg:w-1/3` wider.
- Card grids: `grid-cols-1 md:grid-cols-2 lg:grid-cols-3`.

## 13. What to remove

From the web block and event layouts (`resources/views/blocks/types/*.blade.php`, 64 files, `components/block-card.blade.php`) and pages:

| Remove | Found in | Replace with |
|---|---|---|
| `shadow` + `hover:shadow-lg transition-all` on every card | 64 of 64 layouts, most pages | Flat `bg-base-200`, `hover:bg-base-300` when the card is a link |
| `shadow` and `shadow-sm` on filter panels and nested items | Pages following the old guide | Flat surfaces (5.3) |
| Per-card footer: type badge + `⋮` menu with "View Block" / "View Event" | 64 of 64 | The whole card links to the detail page, the eyebrow names the type, and the `⋮` moves into the eyebrow after the time (7.1) |
| Layouts that draw their own card, header and footer | 64 of 64 | Hero and body only, inside the shared card (7.4) |
| Domain colour via daisyUI status colours (`warning` for money, `info` for media) | 9 `$domainColors` / `$badgeColorMap` arrays | `domain-*` tokens from 3.2. Tag and reference pills are the exception and keep their per-type colours (7.5) |
| Status colours on non-status glyphs (`text-warning` section icons, `text-success` piggy bank) | Detail pages | Neutral or domain colour (6) |
| Round `bg-primary/10` glyph circles | Detail heroes and list rows | Rounded-square glyph chips in the domain tint (6) |
| The `warning`-coloured AI gradient, model-name and word-count footers | 11 AI layouts with `from-warning/5 to-warning/25` | The generated surface in 3.5, which keeps a gradient in the Flint hues |
| `animate-pulse` heart | `heart_rate.blade.php` | Static glyph in the eyebrow |
| Raw metadata preview ("Key: value" for the first three keys) in the generic value card | `block-card.blade.php` | The context line, or nothing |
| Hero values in `text-primary` amber on a light ground | 9 layouts, the value card, detail pages | `text-hero` (3.6) |
| Entity-type, category, count and metadata badges (`badge-outline`, `badge-xs`, `badge-info` counts) | Layouts and pages | Eyebrow, text and "(N)" (12.9) |
| Text at `base-content/60`, `/50` or `/40` | Toggle descriptions, metadata | `base-content/70` (3.1) |
| Heroicons (`o-*`) | 38 of 64 layouts, 158 uses app-wide | Font Awesome solid per 6 |
| Left-border status cards (`border-l-4`) | 2 uses | Status in text, with a chip when action is needed |
| `hover:bg-base-50` | Old nested-item examples | `hover:bg-base-300` (`base-50` is not a daisyUI colour) |

From iOS:

| Remove | Found in | Replace with |
|---|---|---|
| Glass on content cards | `GlassCard`, `MetricCard` and 95 `.sparkGlass` call sites | `.sparkContentSurface` for content; keep glass on controls |
| System colours (`.red`, `.green`, `.indigo`, `.yellow`, `.teal`, `.purple`, `.orange`) | about 23 call sites, including `DomainChip.Domain.tint` | Semantic or domain tokens |
| Substring-matched layouts and glyphs (`"takeaway"`, `"summary"`, `action.contains("sleep")`) | `EventDetailView.BlockGroups`, `EntityPresentation.icon`, three `flint_*` switches | `BlockKind` / `EventKind` registries |

## 14. Known deviations

Things in the current code that break these rules, for the convergence threads to fix. The list is not exhaustive; anything else that breaks a rule above is a deviation too.

1. **Amber text on light grounds.** Flint prose highlights (`SparkLongFormContentView(tint: .sparkAccent)` in `RecapScreen` and `FlintDigestScreen`) set spark-5 text on the light wash at about 1.6:1. The Up to Speed briefing is the visible case. Use the `highlight` token from 3.3: ocean-5 in light, spark-5 in dark.
2. **`MetricCard.Trend` colours by direction**: up is always green and down is always red, so lower spend shows red. Add polarity (3.4).
3. **Media glyph differs within iOS**: `EntityPresentation.icon` currently returns `iphone` for media and `integrationIcon` returns `play.circle.fill`. Both should use the shared `fas.circle-play` glyph through `EntityPresentation` (3.2, 6).
4. **No `online` tint on iOS**: it falls through to `sparkAccent`, so calendar and email events look like money. Add ocean (3.2).
5. **`DomainChip`** uses system colours for its tints, sets the unselected label in the tint (1.6:1 for money), and sits on glass. Use the domain fill, the domain ink for the label, and a content surface (12.4).
6. **`Block.mediaUrl` is decoded and stored but never drawn.** The image-led pattern needs it.
7. **Raw durations**: the compact API sends `formatted_value` unchanged for `sleep_stage` and `duration`, so iOS can show seconds. Format on the client (9).
8. **Web custom layouts draw the whole card** on `dev`. spark#1284 moves all 64 onto `<x-block-card.shell>` (7.4); until it merges, the old chrome is still live. That PR also sets `online` to neutral, which changes to ocean (3.2).
9. **iOS references and tags have no peek card.** `EntityRefChip` and `TagChip` either navigate on tap or show only a context menu, and `EntityRefChip` sits on glass. Add the tap-to-peek popover and use the reference shape from 7.5.

## 15. Checklist for a new screen

- [ ] One subject, one hero, one primary action.
- [ ] Web: `<x-header>` with a one-sentence subtitle. iOS: navigation title and toolbar.
- [ ] Detail pages keep technical detail in a drawer or inspector (12.2), and the hero centres on phones.
- [ ] Hero value uses the detail or card scale (4) and `text-hero` / `sparkHero` (3.6). Units at about 55%.
- [ ] Cards are flat content surfaces: no shadows, no lift, no borders except one featured section (5.3).
- [ ] Every card comes from the registry and a shared card component (7.4). No per-card footers or type badges.
- [ ] Glyphs come from the map (6), in rounded-square chips, in their domain tint or neutral.
- [ ] Colour means a domain or a status, never decoration. No raw palette or system colours.
- [ ] No text fainter than `base-content/70` / `.secondary`.
- [ ] Chips only for states that need action (12.9).
- [ ] Spacing from the 5.1 table, headings in order (h2 → h3).
- [ ] Filters collapse on phones and stay open on desktop (12.4). Form controls have visible labels (12.5).
- [ ] Empty, loading, missing and very large values all designed (9, 10, 12.8).
- [ ] The accessibility checks in 11 pass in both themes.

## 16. Sources

- Apple, [Materials](https://developer.apple.com/design/human-interface-guidelines/materials) and [Adopting Liquid Glass](https://developer.apple.com/documentation/technologyoverviews/adopting-liquid-glass): Liquid Glass is for controls and navigation, not the content layer; don't stack glass on glass.
- Apple, [Meet Liquid Glass (WWDC25)](https://developer.apple.com/videos/play/wwdc2025/219/).
- Apple, [Typography](https://developer.apple.com/design/human-interface-guidelines/typography), [Accessibility](https://developer.apple.com/design/human-interface-guidelines/accessibility), [Charting data](https://developer.apple.com/design/human-interface-guidelines/charting-data) and [SF Symbols](https://developer.apple.com/design/human-interface-guidelines/sf-symbols).
- W3C, [WCAG 2.2](https://www.w3.org/TR/WCAG22/): 1.4.3 text contrast, 1.4.11 non-text contrast, 1.4.1 use of colour, 2.5.8 target size, 2.3.3 animation from interactions.
- Cloudscape, [Generative AI output label](https://cloudscape.design/patterns/genai/output-label/); PatternFly, [AI iconography](https://www.patternfly.org/ai/guidelines/iconography); Red Hat, [AI transparency notices](https://ux.redhat.com/ai-guidelines/transparency-notices/): mark generated output with a glyph and text, never the glyph alone, and not beside human-written text.
- Stephen Few, *Information Dashboard Design*, and Edward Tufte, *The Visual Display of Quantitative Information*: lead with the measure and its context, and remove non-data ink (borders, shadows, gridlines) that doesn't carry information.
- Spark iOS, `docs/reviews/2026-09-06-ui-ux-review.md` (findings 4, 8 and 12, and the product roles); the 27 October 2026 block design review; the Spark Design System README and `tokens.json`.
- Spark web, the former `DESIGN_PATTERNS.md` and `BLOCK_CARDS.md` (merged here), and the events show page (`livewire/events/show.blade.php`) they used as a reference.
