# Spark design language

The rules Spark web (`cronxco/spark`) and Spark iOS (`cronxco/spark-ios`) both follow, so a sleep score, a Monzo payment or an AI summary reads as the same thing on either platform. This file exists in both repositories with the same text: `docs/design-language.md` on iOS and `docs/UI and UX/DESIGN_LANGUAGE.md` on the web. Change both in the same pair of pull requests.

**How this relates to the other design documents.** The [Spark Design System](https://claude.ai/artifact/GbFZoNjtnLwNpKNrJ5SsuN) holds the token values: colour ramps, type scale, spacing, radii. This document says how to use them, and adds the card, block and event rules both platforms share. The web's `DESIGN_PATTERNS.md` keeps the web implementation detail (forms, tables, filters). Where they disagree, this document wins and the other is updated.

Written 28 October 2026 from a read of iOS `main` @ `539f651` and web `dev` @ `f8d3ad4b`. Sources are listed at the end.

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
12. [What to remove](#12-what-to-remove)
13. [Known deviations](#13-known-deviations)
14. [Sources](#14-sources)

---

## 1. Principles

1. **Calm before dense.** Each screen has one subject and one hero. Day is the calm briefing, Explore is the dashboard, Flint is the coach. They share rules, not density.
2. **The number first, its meaning second.** Lead with the value and unit. Add one line of context (against your usual, against a target) only when it changes what the reader thinks.
3. **Colour is information.** A tint says which domain something belongs to, or that something needs attention. It is never decoration, and it is never the only carrier of meaning.
4. **One layout per type, one fallback.** Pick a card's layout from its `block_type` or event `action` through one registry per platform. Anything unknown gets the generic card. Adding a type means adding one entry, never a new branch at a call site.
5. **Glass is for controls, not content.** Liquid Glass floats navigation and controls above content. Content sits on a quiet surface (see [5.3](#53-surfaces)).
6. **Say what the data can and cannot tell you.** Show freshness, partial days and missing values honestly. A gap is shown as a gap, never as zero.
7. **Accessible by construction.** Contrast, Dynamic Type, Reduce Motion and Reduce Transparency are design inputs, not checks at the end.

## 2. Platform mapping

| Concept | iOS (SparkUI) | Web (Tailwind + daisyUI + maryUI) |
|---|---|---|
| Page ground | `Color.sparkSurface` + `.sparkAppBackground()` wash | `bg-base-100` |
| Content surface | `SparkContentSurface` (new, see 5.3) | `bg-base-200` card, no hover lift |
| Control surface | `.sparkGlass()` | `btn`, `bg-base-100` + `border-base-300` |
| Text, secondary text | `.primary`, `.secondary` | `text-base-content`, `text-base-content/70` |
| Domain tint | `Color.domainTint(_:)` via `EntityPresentation` | `text-domain-*`, `bg-domain-*` (new theme vars, see 3.2) |
| Display face | `SparkTypography.hero*` (Comfortaa) | `font-display` (Comfortaa) |
| UI face | SF Pro via `Font.system` | `font-sans` (Nunito) |
| Mono | `SparkTypography.mono*` (PT Mono) | `font-mono` (PT Mono) |
| Reading face | `SparkTypography.longForm*` (New York) | `font-serif` = `ui-serif, Georgia, serif` |
| Spacing | `SparkSpacing.*` (4pt grid) | Tailwind spacing (4px grid) |
| Radius | `SparkRadii.*` | `rounded-box` (8px), `rounded-field` (4px), `rounded-full` |
| Glyph | SF Symbols, via `EntityPresentation` | Font Awesome solid, via a PHP map (see 6) |
| Card registry | `BlockKind` / `EventKind` → `BlockCard` / `EventCard` | `blocks/types/{type}.blade.php` → `<x-block-card>` |

## 3. Colour

### 3.1 Roles

Use semantic tokens, never raw ramp steps or system colours (`Color.red`, `text-blue-500`).

| Role | Use | Rule |
|---|---|---|
| `primary` (spark-5 amber) | The one action on a screen, the active tab, progress | A **fill**. Never text on a light ground (1.6:1). Text on it is `primary-content` / `sparkOnAccent`. |
| `accent` (ocean-5 light, sky-3 dark) | Links, quiet emphasis | Reads as text in both themes (8:1+). |
| `success`, `warning`, `error`, `info` | Status that needs a reaction | Fills in light mode, text only in dark. Pair with a glyph and words. |
| Domain tints | Which part of life a thing belongs to | One per domain; see 3.2. |
| `mood-2` … `mood-10` | Check-in scores | Fills only. Position and label carry the value. |

### 3.2 Domain tints

The platforms disagree today. iOS uses palette steps (money is amber, media is coral). The web maps domains onto daisyUI status colours in nine separate Blade files (money is `warning`, which is mauve; media is `info`, which is blue). Status colours mean "act on this", so borrowing them for domains makes every money card look like a warning. **Both platforms adopt the iOS mapping**, plus an ink step for text and an `online` entry iOS lacks.

| Domain | Glyph (iOS / web) | Fill, both themes | Ink, light | Ink, dark |
|---|---|---|---|---|
| health | `heart.text.square.fill` / `fas.heart-pulse` | success `#7abaa1` | `success-content` | success-dark `#b3d8c9` |
| activity | `figure.run` / `fas.person-running` | ember-5 `#F79129` | ember-7 `#A75706` (5.1:1) | ember-5 (7.9:1) |
| money | `sterlingsign.circle.fill` / `fas.sterling-sign` | spark-5 `#FFBF00` | spark-8 `#664D00` (7.8:1) | spark-5 (11.1:1) |
| media | `play.circle.fill` / `fas.circle-play` | flame-5 `#EE6352` | flame-7 `#B02411` (6.6:1) | flame-5 (5.7:1) |
| knowledge | `book.fill` / `fas.book` | sky-5 `#3f88c5` | sky-6 `#316ea1` (5.3:1) | sky-3 `#8db9dd` (8.8:1) |
| online | `globe` / `fas.globe` | neutral, `base-content` at 60% | `base-content` | `base-content` |
| anomaly | `exclamationmark.triangle.fill` / `fas.triangle-exclamation` | warning `#b16c89` | `base-content` (no step clears 4.5:1) | warning-dark `#d2abbb` |

Contrast figures are against `ash-1` in light and `slate-5` in dark.

- **Fill** is for glyph chips, ring and bar strokes, chart marks and 10–15% tinted backgrounds. Fills do not need 4.5:1 because a number or label always sits next to them.
- **Ink** is for tinted text, such as a hero value that should carry its domain. If a domain has no ink step, the text stays `base-content` and the glyph carries the hue.
- **Plugin accent colours** (`getAccentColor()`) are not used for UI. A Spotify block is a media block.
- On the web, add `--color-domain-{name}` and `--color-domain-{name}-ink` to both daisyUI themes in `resources/css/app.css`, and replace the nine `$domainColors` arrays with one helper.

### 3.3 Highlights in prose

Facts that Flint or a summary picks out inside running text (in Up to Speed, digests and recaps) take one `highlight` token, whatever their domain: ocean-5 `#244f83` in light (8.1:1 on ash-1) and spark-5 `#FFBF00` in dark (11.1:1 on slate-5), at semibold weight. On iOS this is `Color.sparkHighlight`, which replaces `.sparkAccent` as the tint passed to `SparkLongFormContentView`. On the web it is `--color-highlight` in both daisyUI themes. Never use spark-5 as text on a light ground.

### 3.4 Change and comparison

A delta is good or bad according to the metric, not its direction. Lower spend, lower resting heart rate and fewer awake minutes are good. Each metric declares its polarity (`higherIsBetter`), and the arrow glyph takes `success` or `error` from that. The number itself stays `base-content`. A neutral change (inside the usual range) is `secondary` with a flat arrow.

### 3.5 Generated text

Anything a model wrote sits on a **generated surface** so it can be told apart from source content at a glance:

- A diagonal gradient from spark-5 at 6% to ember-5 at 16% (top-leading to bottom-trailing), laid over the normal content surface, with a 1px hairline of ember-5 at 30%. In dark mode the same hues run from 10% to 22%. This is the Flint avatar's amber-to-ember pairing, so generated text reads as Flint's voice. It replaces the web's `from-warning/5 to-warning/25` panel, because `warning` means "act on this".
- The eyebrow carries the `sparkles` glyph (`fas.wand-magic-sparkles` on the web) and the word "Summary" or "Flint". Never use the glyph alone, and never put it beside text a person wrote.
- Text on the surface stays `base-content`. The gradient is the only tint.
- One generated surface per card. Inside a list of generated items (takeaways, key points), the card carries the surface and the rows stay plain.
- No model names, word counts or character counts.
- On iOS this is `.sparkGeneratedSurface(radius:)` in SparkUI/Materials. It is opaque content, not glass, and under Increase Contrast it keeps the hairline at full strength. On the web it is a `.surface-generated` class in `app.css`, built from `--color-spark` and `--color-ember` tokens.

## 4. Type

| Voice | Face | Use | Do not use for |
|---|---|---|---|
| Display | Comfortaa | One hero per screen, section headings on Day, hero numerals | Body text, buttons, data tables |
| UI | SF Pro (iOS), Nunito (web) | Everything else: rows, labels, controls, most numbers | |
| Reading | New York / `ui-serif` | Articles, digests, Flint prose | Chrome, labels |
| Mono | PT Mono | IDs, hex, code, raw technical values | Ordinary timestamps (use UI face, `secondary`) |

- **Numbers in rows and grids use tabular figures** (`.monospacedDigit()`, `tabular-nums`) so columns line up and values don't jitter on refresh.
- **Units are smaller and secondary**: the value at the hero size, the unit at body size, baseline-aligned. `7h 55m` is the exception; it is one string at one size.
- **Sentence case everywhere.** No all-caps eyebrows. Section labels use `captionStrong` (iOS) or `text-xs font-semibold` (web).
- iOS uses text styles so Dynamic Type works. A layout that cannot hold a value at accessibility sizes stacks vertically rather than truncating the number.
- Web scale for cards: hero value `text-3xl font-bold`, card title `text-base font-semibold`, body `text-sm`, meta `text-xs text-base-content/70`.

## 5. Space, shape and surfaces

### 5.1 Spacing

A 4pt grid on both platforms. Card padding is 16 (`SparkSpacing.lg`, `p-4`). Gap inside a card is 8–12. Gap between cards is 12 in a grid or row, 24 between groups. Between sections is 32. Compose the steps; don't add values between them.

### 5.2 Shape

The radii differ by platform on purpose: iOS is card-led and rounder, the web is denser.

| Element | iOS | Web |
|---|---|---|
| Card | `SparkRadii.lg` (22) | `rounded-box` (8px) |
| Compact card, tile | `SparkRadii.md` (14) | `rounded-box` (8px) |
| Glyph chip, thumbnail | `SparkRadii.sm` (8) | `rounded-field` (4px) or `rounded-lg` |
| Chip, pill button | capsule | `rounded-full` |

Nested corners are concentric: an inner radius equals the outer radius minus the padding.

### 5.3 Surfaces

Three layers, top to bottom:

1. **Controls**: tab bar, toolbars, floating buttons, the Up to Speed header. Liquid Glass on iOS. Never put glass on glass.
2. **Content**: cards and grouped rows. An opaque surface (`sparkElevated`, or a standard material at most) with no shadow on iOS. `bg-base-200` with no shadow on the web. Simple timeline rows can sit straight on the page with no container.
3. **Ground**: the page, with the time-of-day wash on iOS. The web stays flat.

This follows Apple's guidance that Liquid Glass belongs to the navigation layer above content, and finding 8 of the 6 September iOS review. It reverses part of the 3 October iOS change that moved Flint cards onto glass. iOS adds one modifier, `.sparkContentSurface(radius:tint:)`, and `GlassCard` switches to it. Reduce Transparency and Increase Contrast keep today's fallback: opaque fill and a visible edge.

Elevation is shown by surface change, not shadows. The web's `shadow` + `hover:shadow-lg` on every card goes (see [12](#12-what-to-remove)).

## 6. Glyphs

- **One concept, one glyph, one map.** iOS resolves glyphs only in `EntityPresentation`. The web adds the same lookup in PHP (`App\Support\EntityPresentation::icon()`), and Blade files stop hard-coding icons. The tables below are the contract.
- Solid glyphs on both platforms. Font Awesome solid (`fas.`) on the web; brands (`fab.`) only for service logos. Heroicons (`o-`, 158 uses) are not used in new work.
- **Sizes**: 12–14 inline with caption text, 16–20 in a row eyebrow, a 28–30pt glyph chip on a row, a 42pt chip on a detail hero.
- A glyph chip is the glyph in its domain fill on a 12% tint of the same fill.
- Decorative glyphs are hidden from VoiceOver and screen readers when a text label is next to them.

Common block and event kinds (domains are in [3.2](#32-domain-tints)):

| Kind | iOS | Web |
|---|---|---|
| Sleep, sleep stage | `moon.zzz.fill` | `fas.moon` |
| Heart rate | `heart.fill` | `fas.heart` |
| HRV | `waveform.path.ecg` | `fas.wave-square` |
| Readiness, score | `gauge.with.needle` | `fas.gauge-high` |
| Steps | `figure.walk` | `fas.person-walking` |
| Energy, calories | `flame.fill` | `fas.fire` |
| Intensity | `bolt.fill` | `fas.bolt` |
| Duration | `clock` | `fas.clock` |
| Distance | `point.topleft.down.to.point.bottomright.curvepath` | `fas.route` |
| Workout set | `dumbbell.fill` | `fas.dumbbell` |
| Track, album | `music.note` | `fas.music` |
| Book | `book.closed.fill` | `fas.book` |
| Article, bookmark | `doc.text` | `fas.file-lines` |
| Payment, receipt | `receipt` | `fas.receipt` |
| Transfer | `arrow.left.arrow.right` | `fas.money-bill-transfer` |
| Task | `checkmark.circle` | `fas.circle-check` |
| Place | `mappin.circle.fill` | `fas.location-dot` |
| Person | `person.fill` | `fas.user` |
| Generated summary | `sparkles` | `fas.wand-magic-sparkles` |
| Photo | `photo` | `fas.image` |

## 7. Cards

### 7.1 Anatomy

Every block and event card is built from the same four slots, top to bottom. Only the hero is required.

1. **Eyebrow**: glyph, a label (the type's `display_name`, never the raw `block_type`) and, trailing, a relative time. `captionStrong` / `text-xs font-semibold`, `secondary`.
2. **Hero**: either a value with its unit, or a title for text-led cards.
3. **Body**: one visual pattern from [section 8](#8-visual-patterns-for-blocks-and-events), or up to three lines of text.
4. **Context**: one line, `secondary`. A comparison ("9 below your usual"), a source ("Oura"), or a count ("+3 more").

The whole card is one tap target and goes to the block or event detail. There are no footer badges or "View" buttons. Secondary actions (open the event, open the block, open the source URL, copy) sit in one menu with the same items on both platforms:

- **Web**: a `⋮` button at the trailing end of the eyebrow, after the time, in `base-content/70`, with a 24 × 24px minimum target. The card is a link through a stretched title link, and the menu button sits above it, so the button never nests inside a link. `row` and `compact` sizes leave it out; `card` and `full` show it.
- **iOS**: a long press on the card opens a `.contextMenu` with a preview of the card. There is no visible button. Every size gets it.
- The menu never holds the card's main action. A tap still opens the detail.

### 7.2 Sizes

| Size | Where | Shows | Layout |
|---|---|---|---|
| `compact` | Up to Speed, widgets, a row of signals on Day | Eyebrow (glyph + short label), hero value, one context line | Fixed height, 2 or 3 across. No body. |
| `row` | Event detail, Knowledge item, timelines | Glyph chip, label, value or title, trailing time | One line, 44pt minimum height |
| `card` | Web grids, iOS block lists that need a body | All four slots | Full width on phone, grid on the web |
| `full` | Block or event detail screen | Hero at display size, body large, context and source below | Whole screen |

One component renders every size: `BlockCard(block, size:)` and `EventCard(event, size:)` in SparkUI, `<x-block-card :block :size>` and `<x-event-card :event :size>` on the web. A type's custom layout supplies only the hero and body. The shared component draws the eyebrow, context, surface and tap target, so custom layouts can't drift.

### 7.3 Event cards in Up to Speed

When Flint prose mentions a metric that has an event behind it (sleep duration, sleep score, readiness, activity score), show those events as `compact` cards under the paragraph. Put up to three in a row, ordered as the prose mentions them. Highlighted phrases in the prose use the `highlight` token (3.3), and each card's glyph carries its domain tint. Tapping a card opens the event. With more than three, the third card reads "+N more" and opens a list.

## 8. Visual patterns for blocks and events

Pick one pattern per card. Each has one implementation per platform, shared across every type that uses it.

| Pattern | Use for | iOS | Web | Rules |
|---|---|---|---|---|
| **Value** | Any single number | Hero `Text` + unit | `text-3xl font-bold` + unit | Glyph in the eyebrow, not beside the number. Format per [9](#9-writing-data). |
| **Ring** | A ratio with a known maximum: scores out of 100, energy out of 5 | `Gauge(.accessoryCircularCapacity)` | SVG ring component (`<x-ring>`) | The number sits inside or beside. Track at 15% fill. Never a ring without a maximum. |
| **Bar** | Progress or share: reading progress, contributors, popularity | `ProgressView` / `Gauge(.linearCapacity)` | `<progress class="progress">` | Label and value as text too. |
| **Stat grid** | 2–4 related fields of one record: set, reps, weight, RPE | `StatGrid` (`Grid`) | `grid grid-cols-2 sm:grid-cols-4` | Each cell centre-aligned, label above value, tabular figures, no dividers. |
| **Flow** | Money or values moving: transfers, FX, before → after | `HStack` with `arrow.right` | `flex` with `fas.arrow-right` | Amounts in UI face, masked numbers as `••1234`. |
| **List** | Takeaways, receipt lines, checklists | `VStack` of rows | `ul` with `space-y-1` | Up to 5 in `card`, then "+N more". Real checkboxes only for tasks. |
| **Image-led** | Album art (1:1), book cover (2:3), photo, bookmark hero (16:9) | `AsyncImage` in the body slot | `img` with `aspect-*` and `object-cover` | Aspect ratio comes from the kind. Placeholder is the kind's glyph on a 12% tint. Never tint artwork. |
| **Text** | Summaries, notes, quotes | `body` or `longFormBodySmall`, 3–5 lines | `text-sm line-clamp-5` | Generated text sits on the generated surface in [3.5](#35-generated-text). |
| **Trend** | A value over time, on `full` cards only | Swift Charts | Chart.js | Show the usual range as a band. Label the latest point. No gridlines beyond a baseline. |

## 9. Writing data

These extend the Design System's content rules.

- **Durations** read `7h 55m`, `42m` or `1m 05s`. They are always formatted on the client from seconds or minutes, never shown as raw values.
- **Money** reads `£24.80` (symbol, two decimals) and `£1,240` in compact cards above £1,000. Outgoings have no minus sign in a context that already says "spent". Incomings show `+£`.
- **Scores** read as a bare number (`88`), with "out of 100" only in VoiceOver.
- **Counts** use grouping separators: `8,412 steps`.
- **Missing values** show `—` with the reason one tap away, never `0`. A partial day says so: "So far today".
- **Freshness**: when data is older than its TTL, the context line says "From 3h ago" in `secondary`, and the hero does not change colour.
- **Comparisons** name the baseline: "9 below your 30d trend", not "-9". The default baseline is the 30-day trend, written "30d trend".
- **Labels** come from the backend `display_name`. Clients don't guess labels from `block_type` strings.

## 10. Interaction and motion

- **Targets**: 44 × 44pt on iOS, 24 × 24px minimum on the web (WCAG 2.5.8), 44px for primary actions.
- **Motion** explains a change of state (a card expanding, a value updating) and nothing else. No looping or pulsing animation. Under Reduce Motion, use cross-fades instead of movement.
- **Hover** on the web changes the background (`hover:bg-base-300`). It does not lift, scale or shadow.
- **Loading** uses the shape of the content (skeleton rows at the card's real size), not spinners in cards.
- **Haptics** on iOS only confirm a commit (check-in saved, task done).

## 11. Accessibility checks

Run these on every new card or layout before merging.

- Text is 4.5:1 against its surface in both themes (3:1 at 24pt bold and above). Glyphs, ring strokes and focus rings are 3:1, or a number is right beside them.
- Nothing is told apart by hue alone. Domain tints are always paired with a glyph and a label, and deltas with an arrow.
- The card reads as one VoiceOver element: "Sleep score, 66, 12 below your usual, 7:40 am". Decorative glyphs are hidden.
- Works at the largest accessibility text size (iOS) and at 200% zoom (web) without clipping a number.
- Works under Reduce Transparency, Increase Contrast and Reduce Motion.
- Tested with empty, missing, very long and very large values (a £12,450.00 payment, a 90-character track title).

## 12. What to remove

From the web block and event layouts (`resources/views/blocks/types/*.blade.php`, 64 files, and `components/block-card.blade.php`):

| Remove | Found in | Replace with |
|---|---|---|
| `shadow` + `hover:shadow-lg transition-all` on every card | 64 of 64 layouts | Flat `bg-base-200`, `hover:bg-base-300` when the card is a link |
| Per-card footer: type badge + `⋮` menu with "View Block" / "View Event" | 64 of 64 | The whole card links to the detail page, the eyebrow names the type, and the `⋮` moves into the eyebrow after the time (7.1) |
| Domain colour via daisyUI status colours (`warning` for money, `info` for media) | 9 `$domainColors` / `$badgeColorMap` arrays | `domain-*` tokens from 3.2 |
| The `warning`-coloured AI gradient, model-name and word-count footers | 11 AI layouts with `from-warning/5 to-warning/25` | The generated surface in 3.5, which keeps a gradient in the Flint hues |
| `animate-pulse` heart | `heart_rate.blade.php` | Static glyph in the eyebrow |
| Raw metadata preview ("Key: value" for the first three keys) in the generic value card | `block-card.blade.php` | The context line, or nothing |
| Hero value in `text-{domain}` amber or green on a light ground | 9 layouts with `text-primary`, plus the value card | `base-content`, or the domain ink |
| Heroicons (`o-*`) | 38 of 64 layouts, 158 uses app-wide | Font Awesome solid per 6 |
| Left-border status cards (`border-l-4`) | 2 uses | Status in text, with a chip when action is needed |

From iOS:

| Remove | Found in | Replace with |
|---|---|---|
| Glass on content cards | `GlassCard`, `MetricCard` and 95 `.sparkGlass` call sites | `.sparkContentSurface` for content; keep glass on controls |
| System colours (`.red`, `.green`, `.indigo`, `.yellow`, `.teal`, `.purple`, `.orange`) | about 23 call sites | Semantic or domain tokens |
| Substring-matched layouts and glyphs (`"takeaway"`, `"summary"`, `action.contains("sleep")`) | `EventDetailView.BlockGroups`, `EntityPresentation.icon`, three `flint_*` switches | `BlockKind` / `EventKind` registries |

## 13. Known deviations

Things in the current code that break these rules, for the convergence threads to fix:

1. **Amber text on light grounds.** Flint prose highlights (`SparkLongFormContentView(tint: .sparkAccent)` in `RecapScreen` and `FlintDigestScreen`) set spark-5 text on the light wash at about 1.6:1. The Up to Speed briefing is the visible case. Use the `highlight` token from 3.3: ocean-5 in light, spark-5 in dark.
2. **`MetricCard.Trend` colours by direction**: up is always green and down is always red, so lower spend shows red. Add polarity (3.4).
3. **Media glyph differs within iOS**: `EntityPresentation.icon` returns `iphone` for media, `integrationIcon` returns `play.circle.fill`. Use `play.circle.fill`.
4. **No `online` tint on iOS**: it falls through to `sparkAccent`, so calendar and email events look like money.
5. **`Block.mediaUrl` is decoded and stored but never drawn.** The image-led pattern needs it.
6. **Raw durations**: the compact API sends `formatted_value` unchanged for `sleep_stage` and `duration`, so iOS can show seconds. Format on the client (9).
7. **Web `DESIGN_PATTERNS.md`** still recommends `text-primary` hero values, `shadow` on primary cards, and semantic-colour-only icons. Update it to point here.

## 14. Sources

- Apple, [Materials](https://developer.apple.com/design/human-interface-guidelines/materials) and [Adopting Liquid Glass](https://developer.apple.com/documentation/technologyoverviews/adopting-liquid-glass): Liquid Glass is for controls and navigation, not the content layer; don't stack glass on glass.
- Apple, [Meet Liquid Glass (WWDC25)](https://developer.apple.com/videos/play/wwdc2025/219/).
- Apple, [Typography](https://developer.apple.com/design/human-interface-guidelines/typography), [Accessibility](https://developer.apple.com/design/human-interface-guidelines/accessibility), [Charting data](https://developer.apple.com/design/human-interface-guidelines/charting-data) and [SF Symbols](https://developer.apple.com/design/human-interface-guidelines/sf-symbols).
- W3C, [WCAG 2.2](https://www.w3.org/TR/WCAG22/): 1.4.3 text contrast, 1.4.11 non-text contrast, 1.4.1 use of colour, 2.5.8 target size, 2.3.3 animation from interactions.
- Cloudscape, [Generative AI output label](https://cloudscape.design/patterns/genai/output-label/); PatternFly, [AI iconography](https://www.patternfly.org/ai/guidelines/iconography); Red Hat, [AI transparency notices](https://ux.redhat.com/ai-guidelines/transparency-notices/): mark generated output with a glyph and text, never the glyph alone, and not beside human-written text.
- Stephen Few, *Information Dashboard Design*, and Edward Tufte, *The Visual Display of Quantitative Information*: lead with the measure and its context, and remove non-data ink (borders, shadows, gridlines) that doesn't carry information.
- Spark iOS, `docs/reviews/2026-09-06-ui-ux-review.md` (findings 4, 8 and 12, and the product roles); the 27 October 2026 block design review; the Spark Design System README and `tokens.json`.
