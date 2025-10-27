# Spotlight Command Palette

Spark uses [Wire Elements Pro Spotlight](https://wire-elements.dev/) as a keyboard-driven command palette that allows power users to navigate, search, and execute actions throughout the application without using the mouse.

## Table of Contents

1. [User Guide](#user-guide)
2. [Developer Guide](#developer-guide)
3. [Architecture](#architecture)
4. [Adding Commands](#adding-commands)
5. [Integration Plugin Support](#integration-plugin-support)
6. [Troubleshooting](#troubleshooting)

---

## User Guide

### Opening Spotlight

- **Keyboard Shortcut**: Press `Cmd+K` (Mac) or `Ctrl+K` (Windows/Linux)
- **Click**: Click the "Search" button in the header

### Search Modes

Spotlight supports multiple modes triggered by prefix characters:

| Mode         | Prefix   | Description                                                      |
| ------------ | -------- | ---------------------------------------------------------------- |
| Default      | _(none)_ | Search everything: navigation, events, objects, blocks, accounts |
| Actions      | `>`      | Show available commands and actions                              |
| Tags         | `#`      | Search tags by name or type                                      |
| Metrics      | `$`      | Search metrics and trends                                        |
| Integrations | `@`      | Search integrations and trigger updates                          |
| Admin        | `!`      | Admin-only commands (destructive actions require confirmation)   |
| Help         | `?`      | Help and tips                                                    |

### Keyboard Shortcuts

| Shortcut                  | Action                              |
| ------------------------- | ----------------------------------- |
| `Cmd+K` / `Ctrl+K`        | Open/close Spotlight                |
| `Arrow Up` / `Arrow Down` | Navigate results                    |
| `Enter`                   | Execute selected action             |
| `Tab`                     | Apply token/scope to filter results |
| `Escape`                  | Close Spotlight                     |
| `Backspace` (when empty)  | Remove last token                   |
| `Shift+Backspace`         | Clear all tokens                    |

### Context-Aware Commands

Spotlight shows different commands based on your current page:

- **Metrics Overview**: "Calculate All Statistics", "Detect All Trends"
- **Metric Detail**: "Acknowledge All Trends", "Calculate Statistics for This Metric"
- **Financial Account Detail**: "Add Balance Update", "Archive Account", "Edit Account"
- **Integration Detail**: "Trigger Update Now", "Pause/Resume Integration", "Configure Integration"
- **Event Detail**: "Tag Event", "Edit Event", "Delete Event"
- **Object Detail**: "Tag Object", "View Timeline", "Edit Object", "Delete Object"
- **Block Detail**: "View Parent Event", "Edit Block", "Delete Block"
- **Admin Bin**: "Clear Bin" (with confirmation)

### Scoped Navigation

When viewing detail pages for Events, Objects, or Blocks, Spotlight automatically shows related entities:

**On Event Pages:**

- Navigate to Actor Object (if present)
- Navigate to Target Object (if present)
- List all Blocks for this event
- View Source Integration

**On Object Pages:**

- List recent Events (as actor or target)
- Navigate to Source Integration

**On Block Pages:**

- Navigate to Parent Event
- List Related Blocks from same event

**Usage:**

1. Visit an event/object/block detail page
2. Open Spotlight (`Cmd+K`)
3. See related entities automatically appear
4. Or press `Tab` on any search result to scope into it and explore relationships

### Tips

- Start typing to filter results
- Results are prioritized by: context → recency → usage frequency → alphabetical
- Search results show up to 5 items per category
- Recent items (from today or last week) appear first
- Metrics with unacknowledged trends are boosted in search results

---

## Developer Guide

### Project Structure

```
app/Spotlight/
├── Actions/                    # Custom Spotlight actions
│   └── ClearBinAction.php
├── Queries/
│   ├── Actions/                # Action command queries
│   │   ├── ContextualActionsQuery.php
│   │   └── GlobalActionsQuery.php
│   ├── Integration/            # Integration-related queries
│   │   ├── IntegrationSearchQuery.php
│   │   └── PluginCommandsQuery.php
│   ├── Navigation/             # Navigation command queries
│   │   ├── AdminNavigationQuery.php
│   │   ├── CoreNavigationQuery.php
│   │   ├── DailyNavigationQuery.php
│   │   ├── HelpQuery.php
│   │   └── SettingsNavigationQuery.php
│   ├── Scoped/                 # Token-scoped relationship queries
│   │   ├── EventActorQuery.php
│   │   ├── EventTargetQuery.php
│   │   ├── EventBlocksQuery.php
│   │   ├── EventIntegrationQuery.php
│   │   ├── ObjectEventsQuery.php
│   │   ├── ObjectIntegrationQuery.php
│   │   ├── BlockEventQuery.php
│   │   └── BlockRelatedBlocksQuery.php
│   └── Search/                 # Entity search queries
│       ├── BlockSearchQuery.php
│       ├── EventSearchQuery.php
│       ├── FinancialAccountSearchQuery.php
│       ├── IntegrationEventsQuery.php
│       ├── MetricSearchQuery.php
│       ├── MetricTrendsQuery.php
│       ├── ObjectSearchQuery.php
│       └── TagSearchQuery.php
├── Scopes/                     # Context-aware scopes (auto-apply tokens)
│   ├── BlockDetailScope.php
│   ├── EventDetailScope.php
│   ├── FinancialAccountScope.php
│   ├── IntegrationDetailScope.php
│   ├── MetricDetailScope.php
│   └── ObjectDetailScope.php
└── Helpers/                    # Shared utilities

app/Providers/SpotlightServiceProvider.php   # Central registration point
```

### Core Concepts

#### 1. **Queries**

Queries define searchable content and return `SpotlightResult` objects.

**Query Types:**

- `SpotlightQuery::asDefault()` - Runs on all routes
- `SpotlightQuery::forRoute('route.name')` - Route-specific
- `SpotlightQuery::forMode('mode')` - Mode-specific (e.g., `#`, `$`, `@`)
- `SpotlightQuery::forToken('token')` - Token-scoped

#### 2. **Results**

Results are individual items shown in Spotlight dropdown.

**Key Methods:**

- `setTitle()` - Primary text (required)
- `setSubtitle()` - Secondary text
- `setTypeahead()` - Text shown when item is selected
- `setIcon()` - Heroicon name
- `setGroup()` - Result category
- `setPriority()` - Higher = shown first
- `setAction()` - What happens on Enter

#### 3. **Actions**

Actions define what happens when a result is selected.

**Built-in Actions:**

- `jump_to` - Navigate to a URL
- `dispatch_event` - Trigger Livewire event
- `replace_query` - Change search query

**Custom Actions:**
Extend `SpotlightAction` class and register in `SpotlightServiceProvider`.

#### 4. **Modes**

Modes are search contexts triggered by prefix characters.

**Registration:**

```php
SpotlightMode::make('tags', 'Search Tags')->setCharacter('#')
```

#### 5. **Groups**

Groups organize results into categories.

**Registration:**

```php
Spotlight::registerGroup('events', 'Events', 2);  // priority: 2
```

#### 6. **Tokens & Scopes**

**Tokens** represent contextual filters that scope search results to specific entities. Think of them as "I'm looking at this specific thing, show me related stuff."

**Registered Tokens:**

- `integration` - Scoped to a specific integration
- `metric` - Scoped to a specific metric
- `account` - Scoped to a financial account
- `tag` - Scoped to a tag
- `event` - Scoped to an event
- `object` - Scoped to an object (EventObject)
- `block` - Scoped to a block

**Scopes** automatically apply tokens when you visit certain routes:

- Visit `/events/{event}` → `event` token auto-applied
- Visit `/objects/{object}` → `object` token auto-applied
- Visit `/blocks/{block}` → `block` token auto-applied
- Visit `/metrics/{metric}` → `metric` token auto-applied
- Visit `/integrations/{integration}/details` → `integration` token auto-applied

**Usage Pattern:**

```php
// Define a scope (auto-applies token on route)
SpotlightScope::forRoute('events.show', function ($scope, $request) {
    $event = $request->route('event');
    $scope->applyToken('event', ['id' => $event->id, ...]);
});

// Define a scoped query (only runs when token is active)
SpotlightQuery::forToken('event', function ($eventToken, $query) {
    $eventId = $eventToken->getParameter('id');
    // Return results related to this event
});
```

---

## Architecture

### Registration Flow

All Spotlight configuration happens in `SpotlightServiceProvider::boot()`:

```php
Spotlight::setup(function () {
    $this->registerActions();     // Custom actions
    $this->registerModes();        // Search modes
    $this->registerGroups();       // Result categories
    $this->registerTokens();       // Context tokens
    $this->registerScopes();       // Auto-applied tokens
    $this->registerQueries();      // Search & navigation
    $this->registerTips();         // Footer tips
});
```

### Query Execution Order

1. **Context Detection**: Spotlight determines current route/mode/tokens
2. **Query Selection**: Chooses which queries to run based on context
3. **Result Collection**: Executes queries and collects results
4. **Filtering**: Filters results by search query
5. **Prioritization**: Sorts by priority, recency, context-relevance
6. **Grouping**: Organizes into categories
7. **Display**: Renders in Spotlight dropdown

---

## Adding Commands

### Example 1: Simple Navigation Command

```php
// In a Query class's make() method:
return SpotlightQuery::asDefault(function (string $query) {
    if (blank($query) || !str_contains('today', strtolower($query))) {
        return collect();
    }

    return collect([
        SpotlightResult::make()
            ->setTitle('Today')
            ->setSubtitle('View today\'s events and timeline')
            ->setIcon('calendar-days')
            ->setGroup('navigation')
            ->setPriority(15)
            ->setAction('jump_to', ['path' => route('today.main')])
    ]);
});
```

### Example 2: Entity Search

```php
return SpotlightQuery::asDefault(function (string $query) {
    if (blank($query) || strlen($query) < 2) {
        return collect();
    }

    return Event::where('action', 'like', "%{$query}%")
        ->latest('occurred_at')
        ->limit(5)
        ->get()
        ->map(function (Event $event) {
            $priority = $event->occurred_at->isToday() ? 8 : 5;

            return SpotlightResult::make()
                ->setTitle(ucfirst(str_replace('_', ' ', $event->action)))
                ->setSubtitle(format_event_value_display(...))
                ->setIcon('list-bullet')
                ->setGroup('events')
                ->setPriority($priority)
                ->setAction('jump_to', ['path' => route('events.show', $event)]);
        });
});
```

### Example 3: Mode-Specific Query

```php
// Only runs when user types "#"
return SpotlightQuery::forMode('tags', function (string $query) {
    return Tag::where('name->en', 'like', "%{$query}%")
        ->limit(5)
        ->get()
        ->map(function (Tag $tag) {
            return SpotlightResult::make()
                ->setTitle($tag->name)
                ->setIcon('tag')
                ->setGroup('tags')
                ->setAction('jump_to', ['path' => route('tags.show', ...)]);
        });
});
```

### Example 4: Context-Aware Action

```php
return SpotlightQuery::asDefault(function (string $query) {
    $routeName = request()->route()->getName();

    if ($routeName === 'metrics.show') {
        return collect([
            SpotlightResult::make()
                ->setTitle('Acknowledge All Trends')
                ->setIcon('check-circle')
                ->setGroup('commands')
                ->setPriority(10)
                ->setAction('dispatch_event', [
                    'name' => 'acknowledge-all-trends',
                    'close' => true,
                ])
        ]);
    }

    return collect();
});
```

### Example 5: Custom Action with Confirmation

```php
// 1. Create action class:
class ClearBinAction extends SpotlightAction
{
    public function description(): string
    {
        return 'Clear Bin';
    }

    public function execute(Spotlight $spotlight): void
    {
        $spotlight->dispatch('confirm-clear-bin');
    }
}

// 2. Register in SpotlightServiceProvider:
protected function registerActions(): void
{
    Spotlight::registerAction('clear_bin', ClearBinAction::class);
}

// 3. Use in query:
->setAction('clear_bin', [])
```

### Example 6: Scoped Navigation Query

```php
// Create a scope that auto-applies on route
class EventDetailScope
{
    public static function make(): SpotlightScope
    {
        return SpotlightScope::forRoute('events.show', function ($scope, $request) {
            $event = $request->route('event');
            if ($event) {
                $scope->applyToken('event', [
                    'id' => $event->id,
                    'display' => format_action_title($event->action),
                ]);
            }
        });
    }
}

// Create a scoped query that shows related entities
class EventBlocksQuery
{
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('event', function ($eventToken, string $query) {
            $eventId = $eventToken->getParameter('id');

            $event = Event::with('blocks')->find($eventId);
            if (!$event || $event->blocks->isEmpty()) {
                return collect();
            }

            return $event->blocks->map(function ($block) {
                return SpotlightResult::make()
                    ->setTitle($block->title)
                    ->setSubtitle('Block • ' . $block->block_type)
                    ->setIcon('squares-2x2')
                    ->setGroup('blocks')
                    ->setPriority(8)
                    ->setAction('jump_to', ['path' => route('blocks.show', $block)])
                    ->setTokens(['block' => $block]);  // Allows Tab to scope into block
            });
        });
    }
}
```

**Flow:**

1. User visits `/events/{event}`
2. EventDetailScope auto-applies `event` token
3. User opens Spotlight
4. EventBlocksQuery automatically runs (because `event` token is active)
5. User sees all blocks for this event
6. User can press Tab on a block result to scope into that block

---

## Integration Plugin Support

Integration plugins can provide their own Spotlight commands by implementing `SupportsSpotlightCommands`.

### Interface

```php
namespace App\Integrations\Contracts;

interface SupportsSpotlightCommands
{
    public static function getSpotlightCommands(): array;
}
```

### Example Implementation

```php
// In SpotifyPlugin.php:
use App\Integrations\Contracts\SupportsSpotlightCommands;

class SpotifyPlugin extends OAuthPlugin implements SupportsSpotlightCommands
{
    public static function getSpotlightCommands(): array
    {
        return [
            'spotify-sync' => [
                'title' => 'Sync Spotify Listening History',
                'subtitle' => 'Fetch latest listening activity from Spotify',
                'icon' => 'musical-note',
                'action' => 'dispatch_event',
                'actionParams' => [
                    'name' => 'trigger-spotify-sync',
                    'close' => true,
                ],
                'priority' => 7,
            ],
        ];
    }
}
```

### Automatic Registration

Plugin commands are automatically discovered and registered by `PluginRegistry::getSpotlightCommands()`.

No manual registration required - just implement the interface!

---

## Troubleshooting

### Commands Not Appearing

1. **Check registration**: Ensure query is registered in `SpotlightServiceProvider::registerQueries()`
2. **Check filtering**: Query may be filtering out results (check `blank($query)` logic)
3. **Check mode**: Mode-specific queries only run when mode character is typed
4. **Check group**: Group must be registered in `registerGroups()`
5. **Clear cache**: Run `php artisan config:clear` and rebuild assets

### Results Not Clickable

- Ensure `setAction()` is called on result
- Check action parameters are valid (e.g., route exists)
- Verify custom action is registered in `registerActions()`

### Styling Issues

- Custom Spotlight CSS is in `resources/css/app.css` (lines 140-566)
- Uses Spark theme variables (`--color-primary`, `--color-accent`, etc.)
- Dark mode automatically supported via theme system
- Do not import Wire Elements CSS - custom styling replaces it

### Performance Issues

- Limit database queries to 5 results per query
- Use `blank($query) || strlen($query) < 2` to avoid searching on single characters
- Add indexes to frequently searched columns
- Use eager loading (`->with()`) to prevent N+1 queries

### Icons Not Showing

- Icons use Heroicons (via Mary UI / Blade Heroicons)
- Icon names use format: `o-icon-name` (outline) or `s-icon-name` (solid)
- Full list: https://heroicons.com

---

## Best Practices

1. **Keep queries fast**: Limit to 5 results, use indexes, eager load relationships
2. **Prioritize contextually**: Boost priority for recent items, context-relevant results
3. **Use descriptive typeahead**: Help users understand what they're selecting
4. **Group logically**: Use appropriate groups for result organization
5. **Handle empty states**: Return empty collection when query is too short
6. **Test dark mode**: Verify styling works in both light and dark themes
7. **Document commands**: Add comments explaining what each command does
8. **Use confirmations**: Always confirm destructive actions
9. **Leverage context**: Make commands context-aware when possible
10. **Format values**: Use existing helpers like `format_event_value_display()`

---

## Future Enhancements

Potential improvements for Spotlight:

- ✅ ~~**Scoped search**: Implement tokens for filtering~~ (IMPLEMENTED - use Tab to scope into entities)
- **Recent searches**: Remember and suggest recent searches
- **Command history**: Track frequently used commands and boost their priority
- **Fuzzy search**: Improve search matching with fuzzy algorithms
- **Command aliases**: Allow multiple ways to trigger the same command
- **Custom icons**: Support for custom icon sets beyond Heroicons
- **Multi-select**: Enable bulk actions on search results
- **Preview pane**: Show preview of result before navigating
- **Search analytics**: Track what users search for to improve discoverability
- **Breadcrumb navigation**: Visual token breadcrumbs showing scope chain
- **Saved scopes**: Save frequently used scope combinations

---

## Additional Resources

- [Wire Elements Pro Documentation](https://wire-elements.dev/docs/getting-started/spotlight-component)
- [Heroicons](https://heroicons.com)
- [daisyUI Colors](https://daisyui.com/docs/colors/)
- [Livewire Events](https://livewire.laravel.com/docs/events)
