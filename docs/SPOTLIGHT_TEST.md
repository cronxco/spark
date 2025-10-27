# Spotlight Testing Checklist

Date: \***\*\_\_\*\***
Tester: \***\*\_\_\*\***
Browser: \***\*\_\_\*\***
OS: \***\*\_\_\*\***

## Setup

- [ ] Run `sail up -d && sail npm run build`
- [ ] Navigate to http://localhost
- [ ] Log in to the application

## Basic Tests

- [ ] Open Spotlight with Cmd+K (Mac) or Ctrl+K (Windows/Linux): **\_\_**
- [ ] Close with Escape: **\_\_**
- [ ] Open via search button in header: **\_\_**
- [ ] Type and see results update in real-time: **\_\_**
- [ ] Use arrow keys to navigate between results: **\_\_**
- [ ] Press Enter to execute selected result: **\_\_**
- [ ] Results appear/disappear with smooth animations: **\_\_**

## Navigation Commands (Record navigation time)

- [ ] "today" → Today page: \_\_\_\_ms
- [ ] "yesterday" → Yesterday page: \_\_\_\_ms
- [ ] "tomorrow" → Tomorrow page: \_\_\_\_ms
- [ ] "tags" → Tags index: \_\_\_\_ms
- [ ] "money" → Money page: \_\_\_\_ms
- [ ] "metrics" → Metrics page: \_\_\_\_ms
- [ ] "settings" → Settings results: \_\_\_\_ms
- [ ] All navigation results have appropriate icons: **\_\_**

## Search Modes

- [ ] Type `>` - shows action commands: **\_\_**
- [ ] Type `#` - shows tag search mode: **\_\_**
- [ ] Type `$` - shows metrics search mode: **\_\_**
- [ ] Type `@` - shows integrations search mode: **\_\_**
- [ ] Type `!` - shows admin commands: **\_\_**
- [ ] Type `?` - shows help/tips: **\_\_**
- [ ] Each mode displays correct placeholder text: **\_\_**

## Entity Search

- [ ] Search for event action names - shows recent events with formatted values: **\_\_**
- [ ] Search for object names - shows objects with integration info: **\_\_**
- [ ] Search for block types - shows blocks with date ranges: **\_\_**
- [ ] Recent items (today/last week) appear with higher priority: **\_\_**
- [ ] Each search type limits to 5 results: **\_\_**

## Context-Aware Actions

- [ ] On metrics overview page: "Calculate All Statistics" appears: **\_\_**
- [ ] On metric detail page: "Acknowledge All Trends" appears: **\_\_**
- [ ] On financial account page: "Add Balance Update" appears: **\_\_**
- [ ] On integration detail page: "Trigger Update Now" appears: **\_\_**
- [ ] On admin bin page: "Clear Bin" appears with warning: **\_\_**
- [ ] Context commands have higher priority than general commands: **\_\_**

## Token-Based Filtering

- [ ] On metric detail page: metric token auto-applied: **\_\_**
- [ ] With metric token active: see metric-specific trends: **\_\_**
- [ ] On integration detail page: integration token auto-applied: **\_\_**
- [ ] With integration token active: see integration-specific events: **\_\_**
- [ ] Press Tab on a result to apply its token (if supported): **\_\_**
- [ ] Token displays with correct name in search bar: **\_\_**

## Dark Mode

- [ ] Switch to dark mode in browser/system settings: **\_\_**
- [ ] Spotlight backdrop uses correct dark overlay: **\_\_**
- [ ] Spotlight container uses dark theme colors: **\_\_**
- [ ] Active/selected items use primary color correctly: **\_\_**
- [ ] Text remains readable in dark mode: **\_\_**
- [ ] Icons are visible in dark mode: **\_\_**
- [ ] All color transitions are smooth: **\_\_**

## Visual Details

- [ ] Spotlight appears centered on screen: **\_\_**
- [ ] Backdrop blur effect is visible: **\_\_**
- [ ] Results are grouped with proper headers: **\_\_**
- [ ] Group headers have correct styling: **\_\_**
- [ ] Icons align properly with text: **\_\_**
- [ ] Subtitle text is visually distinct from titles: **\_\_**
- [ ] Typeahead suggestions appear correctly: **\_\_**
- [ ] Footer tips rotate properly: **\_\_**
- [ ] No visual glitches or flashing: **\_\_**

## Performance (Average of 5 tests)

- [ ] Spotlight opens instantly (<100ms): \_\_\_\_ms
- [ ] Search results appear with minimal lag (<200ms debounce): \_\_\_\_ms
- [ ] Typing feels responsive: **\_\_**
- [ ] No freezing with large result sets: **\_\_**
- [ ] Navigation actions execute immediately: **\_\_**
- [ ] Memory usage remains stable after repeated use: **\_\_**

## Edge Cases

- [ ] Empty search shows appropriate message or default results: **\_\_**
- [ ] Very long titles truncate properly: **\_\_**
- [ ] Very long subtitles truncate properly: **\_\_**
- [ ] No results state displays correctly: **\_\_**
- [ ] Keyboard navigation wraps around results: **\_\_**
- [ ] Rapid mode switching doesn't cause issues: **\_\_**

## Color Verification

### Light Mode

- [ ] Primary highlight color is #ffbf00 (or theme primary): **\_\_**
- [ ] Background is #fcfcfc (base-100): **\_\_**
- [ ] Text is #161616 (base-content): **\_\_**
- [ ] Hover state shows subtle highlight: **\_\_**
- [ ] Selected item uses primary color with proper contrast: **\_\_**
- [ ] Icons use base-content color when not selected: **\_\_**
- [ ] Icons use primary-content when selected: **\_\_**

### Dark Mode

- [ ] Primary highlight color is #ffd966 (dark primary): **\_\_**
- [ ] Background is #011627 (dark base-100): **\_\_**
- [ ] Text is light (#ebebeb or similar): **\_\_**
- [ ] Hover state shows subtle highlight: **\_\_**
- [ ] Selected item uses primary color with proper contrast: **\_\_**
- [ ] Icons are clearly visible: **\_\_**

## Typography Verification

- [ ] Font family matches Spark (Comfortaa for display, Nunito for body): **\_\_**
- [ ] Font sizes are consistent and readable: **\_\_**
- [ ] Font weights differentiate titles from subtitles: **\_\_**
- [ ] Monospace font (PT Mono) used for technical details: **\_\_**
- [ ] Line heights prevent text overlap: **\_\_**

## Spacing & Layout

- [ ] Consistent padding around all elements: **\_\_**
- [ ] Results don't feel cramped or too spacious: **\_\_**
- [ ] Groups have clear visual separation: **\_\_**
- [ ] Footer has appropriate spacing: **\_\_**
- [ ] Modal doesn't extend beyond viewport on small screens: **\_\_**

## Search Quality (Rate 1-5, 5 being best)

- [ ] Event search relevance: **\_\_**
- [ ] Object search relevance: **\_\_**
- [ ] Tag search relevance: **\_\_**
- [ ] Integration search relevance: **\_\_**
- [ ] Metric search relevance: **\_\_**
- [ ] Financial account search relevance: **\_\_**

## Issues Found

1. ***
2. ***
3. ***
4. ***
5. ***

## Overall Rating: \_\_\_\_/10

## Notes

---

---

---

---
