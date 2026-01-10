# Card Streams System

## Overview

The Card Streams system provides an Instagram Stories-like UI for displaying contextual, interactive cards to users. Cards are organized into "streams" (e.g., "day", "health") and can show different content based on time of day, data availability, and user interactions.

## Architecture

### Core Components

```
app/Cards/
├── CardRegistry.php              # Central registry for managing cards
├── Contracts/
│   └── StreamCard.php            # Interface all cards must implement
├── Base/
│   └── BaseCard.php              # Abstract base class with sensible defaults
├── Streams/
│   └── StreamDefinition.php      # Defines available streams
└── Cards/
    ├── Day/                      # Day stream cards
    │   ├── MorningIntroCard.php
    │   ├── MorningCheckinCard.php
    │   ├── OvernightStatsCard.php
    │   ├── DayIntroCard.php
    │   ├── AfternoonCheckinCard.php
    │   ├── EveningIntroCard.php
    │   └── CheckinHistoryCard.php
    └── Health/                   # Health stream cards (future)
```

### Key Concepts

**Streams**: Collections of related cards (e.g., "day", "health"). Each stream has its own set of eligible cards.

**Cards**: Individual units of content/interaction that can be:

- **Informational**: Display data (auto-complete on view)
- **Interactive**: Require user action (e.g., check-in ratings)
- **Sync Triggers**: Dispatch background jobs to fetch fresh data

**Eligibility**: Cards determine when they should be shown based on:

- Time of day
- Data availability
- Completion status
- User preferences

**Priority**: Cards are sorted by priority (higher = shown first) to control display order.

## Card Interface

All cards implement the `StreamCard` interface:

```php
interface StreamCard
{
    // Determine if card should be shown
    public function isEligible(Carbon $now, User $user, string $date): bool;

    // Sort order (higher = earlier, default: 50)
    public function getPriority(): int;

    // Unique ID (defaults to class name)
    public function getId(): string;

    // Display title
    public function getTitle(): string;

    // Heroicon name (e.g., 'o-sun')
    public function getIcon(): string;

    // Blade view path (e.g., 'livewire.cards.day.intro')
    public function getViewPath(): string;

    // Data to pass to view
    public function getData(User $user, string $date): array;

    // Whether card requires interaction to complete
    public function requiresInteraction(): bool;

    // Whether to trigger background sync jobs
    public function shouldTriggerSync(): bool;

    // Sync jobs to dispatch
    public function getSyncJobs(User $user, string $date): array;

    // Handle card interaction completion
    public function markInteracted(User $user, string $date): void;
}
```

## Creating a New Card

### 1. Create the Card Class

```php
<?php

namespace App\Cards\Cards\Day;

use App\Cards\Base\BaseCard;
use App\Models\User;
use Carbon\Carbon;

class MyCustomCard extends BaseCard
{
    public function isEligible(Carbon $now, User $user, string $date): bool
    {
        // Example: Only show in the morning for today
        $isToday = Carbon::parse($date)->isToday();
        $isMorning = $now->hour >= 6 && $now->hour < 12;

        return $isToday && $isMorning;
    }

    public function getPriority(): int
    {
        return 80; // Higher than default (50)
    }

    public function getTitle(): string
    {
        return 'My Custom Card';
    }

    public function getIcon(): string
    {
        return 'o-sparkles'; // Heroicons outline icon
    }

    public function getViewPath(): string
    {
        return 'livewire.cards.day.my-custom-card';
    }

    public function getData(User $user, string $date): array
    {
        // Fetch and return data for the view
        return [
            'userName' => $user->name,
            'customData' => $this->fetchCustomData($user, $date),
        ];
    }

    // Optional: Override if card requires user interaction
    public function requiresInteraction(): bool
    {
        return true; // Default is false
    }

    // Optional: Override to trigger background jobs
    public function shouldTriggerSync(): bool
    {
        return true; // Default is false
    }

    public function getSyncJobs(User $user, string $date): array
    {
        return [
            MyDataPullJob::class => [$integration],
        ];
    }

    private function fetchCustomData(User $user, string $date)
    {
        // Your custom data fetching logic
        return [];
    }
}
```

### 2. Create the Blade View

Create `resources/views/livewire/cards/day/my-custom-card.blade.php`:

```blade
<div class="flex flex-col h-full p-6">
    <div class="text-center mb-6">
        <h2 class="text-2xl font-bold mb-2">{{ $title ?? 'My Custom Card' }}</h2>
        <p class="text-base-content/70">{{ $subtitle ?? '' }}</p>
    </div>

    <div class="flex-1 flex items-center justify-center">
        <!-- Your card content here -->
        <div class="text-center">
            <p>Hello, {{ $userName }}!</p>
            <!-- Display your custom data -->
        </div>
    </div>
</div>
```

**Design Guidelines:**

- Use full height: `h-full`
- Center content vertically with flexbox
- Use DaisyUI/Tailwind classes
- Avoid custom CSS
- Keep it simple and focused

### 3. Register the Card

In `app/Providers/CardServiceProvider.php`:

```php
public function boot(): void
{
    CardRegistry::register('day', \App\Cards\Cards\Day\MyCustomCard::class);
}
```

### 4. Test Your Card

```bash
sail artisan tinker

$user = App\Models\User::first();
$date = now()->format('Y-m-d');
$cards = App\Cards\CardRegistry::getEligibleCards('day', $user, $date);
$cards->pluck('title');
```

## Creating a New Stream

### 1. Define the Stream

In `app/Cards/Streams/StreamDefinition.php`, add to the `all()` method:

```php
public static function all(): array
{
    return [
        'day' => new self(
            id: 'day',
            name: 'Day',
            icon: 'o-calendar',
            color: 'primary',
            description: 'Daily check-ins, stats, and reflections',
        ),
        'health' => new self(
            id: 'health',
            name: 'Health',
            icon: 'o-heart',
            color: 'error',
            description: 'Deep-dive into your health data',
        ),
        'workout' => new self(
            id: 'workout',
            name: 'Workout',
            icon: 'o-fire',
            color: 'warning',
            description: 'Exercise tracking and progress',
        ),
    ];
}
```

### 2. Create Cards for the Stream

```php
<?php

namespace App\Cards\Cards\Workout;

use App\Cards\Base\BaseCard;
use App\Models\User;
use Carbon\Carbon;

class WorkoutSummaryCard extends BaseCard
{
    public function isEligible(Carbon $now, User $user, string $date): bool
    {
        // Show if user has workout data for this date
        return \App\Models\Event::whereHas('integration', fn($q) =>
            $q->where('user_id', $user->id)
              ->where('service', 'oura')
        )
        ->where('action', 'had_workout')
        ->whereDate('time', $date)
        ->exists();
    }

    public function getTitle(): string
    {
        return 'Workout Summary';
    }

    public function getIcon(): string
    {
        return 'o-fire';
    }

    public function getViewPath(): string
    {
        return 'livewire.cards.workout.summary';
    }

    public function getData(User $user, string $date): array
    {
        // Fetch workout events for the date
        $workouts = \App\Models\Event::whereHas('integration', fn($q) =>
            $q->where('user_id', $user->id)
              ->where('service', 'oura')
        )
        ->where('action', 'had_workout')
        ->whereDate('time', $date)
        ->get();

        return [
            'workouts' => $workouts,
        ];
    }
}
```

### 3. Register Cards for the Stream

```php
// In CardServiceProvider.php
CardRegistry::register('workout', \App\Cards\Cards\Workout\WorkoutSummaryCard::class);
CardRegistry::register('workout', \App\Cards\Cards\Workout\WorkoutStatsCard::class);
```

## UI Components

### Card Streams Component

The main Livewire component (`resources/views/livewire/card-streams.blade.php`) handles:

- **State Management**: Current card index, navigation, data caching
- **Mobile**: Full-screen overlay with swipe gestures
- **Desktop**: Portrait modal (Instagram-like)
- **Progress Bars**: Visual indicators for all cards
- **Navigation**: Swipe, tap, keyboard (arrows, ESC)
- **Auto-Advance**: Interactive cards advance automatically on completion
- **Prefetching**: Next card data loads in background

### Floating Action Button (FAB)

The FAB appears on pages when eligible streams exist:

- **Single Stream**: Simple circular button with stream icon
- **Multiple Streams**: Dropdown menu with all available streams
- **Position**: Fixed bottom-right (`z-40`)
- **Click**: Dispatches `open-card-stream` event

## Existing Day Stream Cards

### MorningIntroCard

- **Priority**: 100 (highest)
- **Eligibility**: Today, 6am-12pm
- **Trigger Syncs**: Oura sleep & readiness
- **View**: Simple greeting with time-based message

### MorningCheckinCard

- **Priority**: 90
- **Eligibility**: Today, 6am-12pm, not yet completed
- **Interactive**: Yes (requires ratings)
- **View**: Embeds existing daily-checkin component
- **Auto-Advance**: On completion

### OvernightStatsCard

- **Priority**: 85
- **Eligibility**: Today, 6am-12pm, has Oura sleep data
- **View**: Sleep score, readiness, sleep breakdown (REM/deep/light)

### DayIntroCard

- **Priority**: 100
- **Eligibility**: Today, 12pm-6pm
- **Trigger Syncs**: Oura activity
- **View**: Afternoon greeting

### AfternoonCheckinCard

- **Priority**: 90
- **Eligibility**: Today, 12pm onwards, not yet completed
- **Interactive**: Yes
- **View**: Afternoon energy ratings

### EveningIntroCard

- **Priority**: 100
- **Eligibility**: Today, 6pm-11pm
- **View**: Evening greeting

### CheckinHistoryCard

- **Priority**: 10 (low - shows at end)
- **Eligibility**: Always
- **View**: GitHub-style 30-day heatmap with streak tracking

## Events & Communication

### Opening a Stream

Dispatch from anywhere in the app:

```blade
<button @click="$dispatch('open-card-stream', { streamId: 'day', date: '{{ now()->format('Y-m-d') }}' })">
    Open Day Stream
</button>
```

### Card Interactions

Cards can dispatch Livewire events:

```blade
<!-- In a card view -->
<button wire:click="saveData">Save</button>

<!-- Card component listens and auto-advances -->
@if ($saved)
    <script>
        window.dispatchEvent(new CustomEvent('checkin-saved'));
    </script>
@endif
```

The card-streams component listens for:

- `checkin-saved`: Auto-advance to next card
- `open-card-stream`: Open stream modal

## Data Flow

1. **User Clicks FAB** → Dispatches `open-card-stream` event
2. **Card Streams Opens** → Loads eligible cards for stream
3. **First Card Loads** → If `shouldTriggerSync()`, dispatch background jobs
4. **Card Displays** → View renders with data from `getData()`
5. **User Navigates** → Swipe/tap/keyboard to move between cards
6. **Next Card Prefetches** → Data loads in background for smooth transition
7. **Interactive Card** → User completes action, card dispatches event
8. **Auto-Advance** → Moves to next card on completion
9. **Stream Ends** → Modal closes automatically

## localStorage View Tracking

**Status**: ✅ Implemented

The card-streams component now tracks which cards users have viewed and completed each day using browser localStorage. This ensures users don't see the same informational cards repeatedly while allowing them to complete interactive cards.

### How It Works

**Storage Key Format**: `spark_card_views_{userId}_{date}`

**Data Structure**:

```json
{
    "day": {
        "MorningIntroCard": {
            "viewed": true,
            "interacted": false,
            "timestamp": "2025-10-22T06:30:00Z"
        },
        "MorningCheckinCard": {
            "viewed": true,
            "interacted": true,
            "timestamp": "2025-10-22T06:35:00Z"
        }
    }
}
```

### Filtering Logic

Cards are filtered from streams based on their viewed/interacted state:

1. **Not viewed**: Always show the card
2. **Viewed + Non-interactive**: Hide the card (already seen)
3. **Viewed + Interactive + Not completed**: Show the card (needs completion)
4. **Viewed + Interactive + Completed**: Hide the card (already done)

### Features

- **Automatic tracking**: Cards are marked as viewed when displayed
- **Interaction tracking**: Interactive cards marked complete via `checkin-saved` event
- **Midnight reset**: localStorage checked every minute, cleared if date changes
- **7-day purge**: Old entries automatically removed to prevent storage bloat
- **Stream-aware**: Each stream (`day`, `health`, etc.) has separate tracking
- **Alpine.js integration**: All state management handled client-side

### Implementation Details

**Client-Side Only Approach**:

- All filtering happens in the browser using localStorage
- Server always returns all eligible cards (stable card array prevents Livewire snapshot issues)
- Cards are tracked and hidden with Alpine.js using `shouldShowCard()` method

**Alpine.js Methods**:

- `getCardState()`: Load full state from localStorage
- `markCardViewed(cardId)`: Mark a card as viewed
- `markCardInteracted(cardId)`: Mark a card as interacted/completed
- `shouldShowCard(cardId, requiresInteraction)`: Determine if card should be visible
- `checkMidnightReset()`: Clear storage if date has changed
- `purgeOldEntries()`: Remove entries older than 7 days

**Event System**:

- Cards dispatch `card-viewed` event when displayed (automatic via `x-init`)
- Check-in completion triggers `checkin-saved` Livewire event
- Livewire dispatches `card-interacted-server` event to Alpine
- Alpine updates localStorage automatically

### Testing

```javascript
// In browser console, check current state
localStorage.getItem("spark_card_views_1_2025-10-22");

// Clear state for testing
localStorage.removeItem("spark_card_views_1_2025-10-22");

// View all stored keys
Object.keys(localStorage).filter((k) => k.startsWith("spark_card_views_"));
```

## Future Enhancements (Not Yet Built)

### 1. Loading Skeletons

**Goal**: Show skeleton UI while card data is loading

**Implementation Prompt**:

```
Add loading skeleton states to card-streams component.

Requirements:
- Show skeleton when card data is still loading
- Match card content structure (heading, content area, footer)
- Use DaisyUI skeleton utilities
- Smooth fade transition from skeleton to content
- Handle slow network gracefully

Create resources/views/livewire/cards/skeleton.blade.php:
- Use <div class="skeleton"> for placeholder elements
- Match typical card layout (title, content blocks, buttons)
- Animate with pulse effect

Update card-streams.blade.php:
- Show skeleton if cardViewData is empty/null
- Add loading state to Livewire component
- Transition with Alpine.js x-show/x-transition
```

### 2. Health Stream Cards

**Goal**: Create deep-dive health analytics stream

**Implementation Prompt**:

```
Build a Health stream with the following cards:

1. HealthIntroCard
   - Show if user has any health integrations (Oura, Apple Health, etc.)
   - Priority: 100
   - View: Welcome message, explain what's in the stream

2. SleepTrendsCard
   - Show if user has 7+ days of sleep data
   - Priority: 90
   - View: Line chart of sleep score over last 30 days
   - Use Chart.js for visualization
   - Show average, best, worst nights

3. ActivitySummaryCard
   - Show if user has activity data for last 7 days
   - Priority: 80
   - View: Steps, calories, activity time trends
   - Compare to personal averages

4. ReadinessAnalysisCard
   - Show if user has Oura readiness data
   - Priority: 70
   - View: Breakdown of readiness contributors (sleep, recovery, activity balance)
   - Identify improvement opportunities

5. HeartRateVariabilityCard
   - Show if user has HRV data
   - Priority: 60
   - View: HRV trend, explain what it means
   - Show correlation with sleep quality

6. HealthGoalsCard
   - Show always
   - Priority: 10
   - Interactive: Yes
   - View: Set/update health goals (sleep target, step target, etc.)

Register all cards in CardServiceProvider for 'health' stream.
Create corresponding Blade views in resources/views/livewire/cards/health/
```

### 3. Card Dismissal/Skipping

**Goal**: Allow users to permanently dismiss certain cards

**Implementation Prompt**:

```
Add card dismissal functionality to the card-streams system.

Requirements:
- Add "Don't show this again" option for certain cards
- Store dismissals in user preferences or database
- Create user_card_dismissals table or add to user meta
- Filter dismissed cards from eligibility check
- Add UI to manage dismissed cards in settings

Database migration:
- user_id, card_id, dismissed_at, stream_id

Update CardRegistry::getEligibleCards() to:
- Check dismissal status before including cards
- Respect user preferences

Add to card-streams UI:
- Long-press or swipe down to show dismiss option
- Confirm dialog before dismissing
- Toast notification "Card dismissed. Manage in settings."
```

### 4. Card Scheduling

**Goal**: Allow cards to specify exact times they should appear

**Implementation Prompt**:

```
Extend card eligibility to support precise scheduling.

Add to StreamCard interface:
- getScheduleTimes(): ?array  // Returns ['06:00', '18:00'] or null
- getScheduleTimezone(): string  // User's timezone

Update isEligible() logic:
- Check if current time matches any schedule times (±15 min window)
- Combine with existing eligibility rules

Use case: "Daily Reflection" card only at 9pm
Use case: "Medication Reminder" card at 8am and 8pm

Example:
class MedicationReminderCard extends BaseCard
{
    public function getScheduleTimes(): ?array
    {
        return ['08:00', '20:00'];
    }

    public function isEligible(Carbon $now, User $user, string $date): bool
    {
        $scheduleTimes = $this->getScheduleTimes();
        if ($scheduleTimes) {
            $currentTime = $now->format('H:i');
            foreach ($scheduleTimes as $time) {
                if ($this->isWithinWindow($currentTime, $time, 15)) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }
}
```

### 5. Trends Integration

**Goal**: Show trend insights in cards when patterns are detected

**Implementation Prompt**:

```
When you add trends/pattern detection to the app, create cards that surface insights.

Example cards:
- "You've improved your sleep score by 15% this week!"
- "Your step count has been declining - here's how to boost it"
- "You're on a 7-day check-in streak!"
- "Your HRV is highest on days when you sleep >8 hours"

Implementation:
1. Create TrendsAnalysisCard base class
2. Calculate trends in getData() method
3. Only show when significant patterns exist
4. Make actionable with suggested next steps
5. Link to detailed trend views

Register trend cards with low priority (20-30) so they appear mid-stream.
```

### 6. Google Calendar Integration

**Goal**: Show calendar/agenda cards in day stream

**Implementation Prompt**:

```
Create calendar integration cards for the day stream.

Cards to build:

1. DailyAgendaCard
   - Show today's calendar events
   - Priority: 95 (early in stream)
   - Eligibility: Has Google Calendar integration, events exist
   - View: List of events with times, color-coded by calendar
   - Interactive: Mark events as attended/completed

2. BirthdaysCard
   - Show birthdays happening today
   - Priority: 100 (very first)
   - Eligibility: Today is someone's birthday
   - View: "Today is X's birthday! 🎂"
   - Interactive: Option to send message/reminder

3. UpcomingEventsCard
   - Show next 3-5 important events
   - Priority: 30
   - Eligibility: Has upcoming events in next 7 days
   - View: Timeline of upcoming meetings/events

Create app/Integrations/GoogleCalendar/ plugin following OAuth pattern.
Store events as Event model with service='google_calendar'.
Add calendar sync jobs (GoogleCalendarPull, GoogleCalendarData).
```

### 7. Card Analytics

**Goal**: Track card engagement metrics

**Implementation Prompt**:

```
Add analytics to understand which cards users engage with.

Track:
- View count per card type
- Completion rate for interactive cards
- Time spent on each card (average)
- Drop-off points in streams
- Most/least popular streams

Create card_analytics table:
- card_id, stream_id, user_id, viewed_at, interacted_at, time_spent_seconds

Add tracking to card-streams component:
- Record view on card display
- Record interaction on completion
- Calculate time spent using timestamps

Create admin dashboard to view metrics:
- Which cards have highest engagement?
- Where do users drop off?
- Which streams are most popular?
- Optimize card order based on data
```

## Troubleshooting

### Cards Not Showing

1. Check card is registered in `CardServiceProvider`
2. Verify `isEligible()` returns true for current context
3. Check stream definition exists in `StreamDefinition::all()`
4. Ensure user has required data/integrations

```bash
# Debug in tinker
$user = App\Models\User::first();
$date = now()->format('Y-m-d');
$cards = App\Cards\CardRegistry::getEligibleCards('day', $user, $date);

foreach ($cards as $card) {
    echo $card->getTitle() . ': ' . ($card->isEligible(now(), $user, $date) ? 'YES' : 'NO') . PHP_EOL;
}
```

### FAB Not Appearing

1. Check `$availableStreams` computed property is working
2. Verify at least one stream has eligible cards
3. Check browser console for JavaScript errors
4. Ensure Alpine.js is loaded

### Modal Not Opening

1. Verify `@click` handler is using Alpine.js syntax
2. Check card-streams component is loaded in app layout
3. Look for Livewire event dispatching errors in console
4. Ensure `open-card-stream` event listener is registered

### Sync Jobs Not Triggering

1. Verify `shouldTriggerSync()` returns true
2. Check `getSyncJobs()` returns correct job classes
3. Ensure queue is running (`sail artisan horizon`)
4. Check job logs for errors

## Best Practices

### Card Design

- **Keep it focused**: One clear purpose per card
- **Make it scannable**: Users should understand in <3 seconds
- **Use familiar patterns**: Follow existing card styles
- **Be contextual**: Only show when relevant
- **Respect time**: Don't show too many cards at once

### Data Fetching

- **Lazy load**: Don't fetch all data upfront
- **Cache aggressively**: Reuse data within session
- **Prefetch wisely**: Load next card data in background
- **Handle errors**: Gracefully degrade if data unavailable

### Sync Jobs

- **Be conservative**: Only sync when necessary
- **Use intro cards**: Trigger syncs on first card of time period
- **Avoid duplicates**: Check if sync already ran recently
- **Queue properly**: Use 'pull' queue for background syncs

### Testing

- **Test all times**: Morning, afternoon, evening
- **Test edge cases**: No data, partial data, stale data
- **Test interactions**: Complete flows, error states
- **Test performance**: Multiple cards, large datasets

## Integration Points

### With Existing Features

- **Daily Check-in**: Morning/afternoon check-in cards embed existing component
- **Oura**: Overnight stats card shows sleep/readiness data
- **Events**: History card queries Event model for visualizations
- **Integrations**: Cards can trigger syncs for any integration

### Extension Points

- Add new streams by updating `StreamDefinition`
- Create new cards by implementing `StreamCard` interface
- Add new card views in `resources/views/livewire/cards/`
- Register cards in `CardServiceProvider`

## File Reference

| Component          | File Path                                          |
| ------------------ | -------------------------------------------------- |
| Card Interface     | `app/Cards/Contracts/StreamCard.php`               |
| Base Card          | `app/Cards/Base/BaseCard.php`                      |
| Card Registry      | `app/Cards/CardRegistry.php`                       |
| Stream Definitions | `app/Cards/Streams/StreamDefinition.php`           |
| Service Provider   | `app/Providers/CardServiceProvider.php`            |
| Streams Component  | `resources/views/livewire/card-streams.blade.php`  |
| Day Cards          | `app/Cards/Cards/Day/*.php`                        |
| Day Views          | `resources/views/livewire/cards/day/*.blade.php`   |
| App Layout         | `resources/views/components/layouts/app.blade.php` |

## Summary

The Card Streams system provides a flexible, extensible way to surface contextual information and interactions to users. It follows the plugin architecture pattern used throughout the app, making it easy to add new cards and streams without modifying core code.

Key benefits:

- **Contextual**: Cards show based on time, data, and completion status
- **Interactive**: Mix informational and action cards
- **Extensible**: Easy to add new cards and streams
- **Familiar UX**: Instagram Stories-like interface users already know
- **Smart**: Auto-syncs data, prefetches content, auto-advances
- **Responsive**: Full-screen mobile, modal desktop

The system is production-ready for the "day" stream and can be extended with health, workout, calendar, and custom streams as needed.
