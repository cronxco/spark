# Spotlight Command Palette

A keyboard-driven command palette powered by Wire Elements Pro that enables navigation, search, and action execution throughout the application.

## Overview

Spotlight provides power users with rapid access to all application features through keyboard shortcuts and search. Users can navigate to any page, search across events, objects, blocks, metrics, and integrations, execute context-aware commands, and leverage AI-powered semantic search. The system supports scoped navigation, allowing users to explore relationships between entities by pressing Tab on search results.

## Architecture

### Components

```
app/Spotlight/
├── Actions/                    # Custom Spotlight actions
│   └── ClearBinAction.php
├── Queries/
│   ├── Actions/                # Action command queries
│   │   ├── BookmarkUrlQuery.php
│   │   ├── ContextualActionsQuery.php
│   │   └── GlobalActionsQuery.php
│   ├── Integration/            # Integration-related queries
│   │   ├── IntegrationSearchQuery.php
│   │   └── PluginCommandsQuery.php
│   ├── Navigation/             # Navigation command queries
│   │   ├── AdminNavigationQuery.php
│   │   ├── CoreNavigationQuery.php
│   │   ├── DailyNavigationQuery.php
│   │   ├── FetchNavigationQuery.php
│   │   ├── HelpQuery.php
│   │   └── SettingsNavigationQuery.php
│   ├── Scoped/                 # Token-scoped relationship queries
│   │   ├── AccountActionsQuery.php
│   │   ├── AccountEventsQuery.php
│   │   ├── AccountIntegrationQuery.php
│   │   ├── BlockActionsQuery.php
│   │   ├── BlockEventQuery.php
│   │   ├── BlockRelatedBlocksQuery.php
│   │   ├── EventActionsQuery.php
│   │   ├── EventActorQuery.php
│   │   ├── EventBlocksQuery.php
│   │   ├── EventIntegrationQuery.php
│   │   ├── EventTargetQuery.php
│   │   ├── IntegrationActionsQuery.php
│   │   ├── IntegrationBlocksQuery.php
│   │   ├── IntegrationObjectsQuery.php
│   │   ├── MetricActionsQuery.php
│   │   ├── MetricAnomaliesQuery.php
│   │   ├── MetricEventsQuery.php
│   │   ├── ObjectActionsQuery.php
│   │   ├── ObjectEventsQuery.php
│   │   └── ObjectIntegrationQuery.php
│   └── Search/                 # Entity search queries
│       ├── BlockSearchQuery.php
│       ├── EventSearchQuery.php
│       ├── FinancialAccountSearchQuery.php
│       ├── IntegrationEventsQuery.php
│       ├── MetricSearchQuery.php
│       ├── MetricTrendsQuery.php
│       ├── ObjectSearchQuery.php
│       ├── SemanticModeQuery.php
│       ├── SemanticSearchQuery.php
│       └── TagSearchQuery.php
└── Scopes/                     # Context-aware scopes (auto-apply tokens)
    ├── BlockDetailScope.php
    ├── EventDetailScope.php
    ├── FinancialAccountScope.php
    ├── IntegrationDetailScope.php
    ├── MetricDetailScope.php
    └── ObjectDetailScope.php

app/Providers/SpotlightServiceProvider.php   # Central registration point
```

### Data Flow

1. **Registration**: All configuration occurs in `SpotlightServiceProvider::boot()` via `Spotlight::setup()`
2. **Context Detection**: Spotlight determines current route, active mode, and applied tokens
3. **Query Selection**: Chooses which queries to execute based on context
4. **Result Collection**: Executes selected queries and collects results
5. **Filtering**: Filters results by user search query
6. **Prioritization**: Sorts by priority, recency, and context-relevance
7. **Grouping**: Organizes results into registered categories
8. **Display**: Renders results in the Spotlight dropdown

## Usage

### Basic Usage

**Opening Spotlight:**
- Keyboard: Press `Cmd+K` (Mac) or `Ctrl+K` (Windows/Linux)
- Mouse: Click the Search button in the header

**Keyboard Shortcuts:**

| Shortcut | Action |
|----------|--------|
| `Cmd+K` / `Ctrl+K` | Open/close Spotlight |
| `Arrow Up` / `Arrow Down` | Navigate results |
| `Enter` | Execute selected action |
| `Tab` | Apply token/scope to filter results |
| `Escape` | Close Spotlight |
| `Backspace` (when empty) | Remove last token |
| `Shift+Backspace` | Clear all tokens |

**Search Modes:**

| Mode | Prefix | Description |
|------|--------|-------------|
| Default | (none) | Search everything: navigation, events, objects, blocks, accounts |
| Actions | `>` | Show available commands and actions |
| Tags | `#` | Search tags by name or type |
| Metrics | `$` | Search metrics and trends |
| Integrations | `@` | Search integrations and trigger updates |
| Admin | `!` | Admin-only commands (destructive actions require confirmation) |
| Semantic | `~` | AI-powered semantic search with boosted recent results |
| Help | `?` | Help and tips |

### Advanced Usage

**Context-Aware Commands:**

Spotlight shows different commands based on your current page:

- **Metrics Overview**: Calculate All Statistics, Detect All Trends
- **Metric Detail**: Acknowledge All Trends, Calculate Statistics for This Metric
- **Financial Account Detail**: Add Balance Update, Archive Account, Edit Account
- **Integration Detail**: Trigger Update Now, Pause/Resume Integration, Configure Integration
- **Event Detail**: Tag Event, Edit Event, Delete Event
- **Object Detail**: Tag Object, View Timeline, Edit Object, Delete Object
- **Block Detail**: View Parent Event, Edit Block, Delete Block
- **Admin Bin**: Clear Bin (with confirmation)

**Scoped Navigation:**

When viewing detail pages, Spotlight automatically shows related entities:

On Event Pages:
- Navigate to Actor Object (if present)
- Navigate to Target Object (if present)
- List all Blocks for this event
- View Source Integration

On Object Pages:
- List recent Events (as actor or target)
- Navigate to Source Integration

On Block Pages:
- Navigate to Parent Event
- List Related Blocks from same event

**Using Scoped Navigation:**
1. Visit an event/object/block detail page
2. Open Spotlight (`Cmd+K`)
3. Related entities appear automatically
4. Press `Tab` on any result to scope into it and explore relationships

## Configuration

### Result Groups

| Group | Priority | Description |
|-------|----------|-------------|
| commands | 1 | Action commands |
| events | 2 | Event search results |
| objects | 3 | Object search results |
| accounts | 4 | Financial account results |
| blocks | 5 | Block search results |
| navigation | 6 | Navigation links |
| tags | 7 | Tag search results |
| metrics | 8 | Metric search results |
| integrations | 9 | Integration results |
| admin | 10 | Admin commands |

### Registered Tokens

Tokens represent contextual filters that scope search results:

| Token | Description | Auto-applied Route |
|-------|-------------|-------------------|
| `integration` | Scoped to a specific integration | `/integrations/{integration}/details` |
| `metric` | Scoped to a specific metric | `/metrics/{metric}` |
| `account` | Scoped to a financial account | `/accounts/{account}` |
| `tag` | Scoped to a tag | - |
| `event` | Scoped to an event | `/events/{event}` |
| `object` | Scoped to an object (EventObject) | `/objects/{object}` |
| `block` | Scoped to a block | `/blocks/{block}` |

## Development

### Adding New Commands/Queries

**Simple Navigation Command:**

```php
// In a Query class's make() method
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

**Entity Search Query:**

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

**Mode-Specific Query:**

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

**Context-Aware Action:**

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

**Custom Action with Confirmation:**

```php
// 1. Create action class
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

// 2. Register in SpotlightServiceProvider::registerActions()
Spotlight::registerAction('clear_bin', ClearBinAction::class);

// 3. Use in query
->setAction('clear_bin', [])
```

**Scoped Navigation Query:**

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
                    ->setSubtitle('Block - ' . $block->block_type)
                    ->setIcon('squares-2x2')
                    ->setGroup('blocks')
                    ->setPriority(8)
                    ->setAction('jump_to', ['path' => route('blocks.show', $block)])
                    ->setTokens(['block' => $block]);
            });
        });
    }
}
```

### Integration Plugin Commands

Plugins can provide Spotlight commands by implementing `SupportsSpotlightCommands`:

```php
namespace App\Integrations\Contracts;

interface SupportsSpotlightCommands
{
    public static function getSpotlightCommands(): array;
}
```

Example implementation:

```php
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

Plugin commands are automatically discovered via `PluginRegistry::getSpotlightCommands()` - no manual registration required.

### Query Types

| Method | Description |
|--------|-------------|
| `SpotlightQuery::asDefault()` | Runs on all routes |
| `SpotlightQuery::forRoute('route.name')` | Route-specific |
| `SpotlightQuery::forMode('mode')` | Mode-specific (triggered by prefix character) |
| `SpotlightQuery::forToken('token')` | Token-scoped (runs when token is active) |

### Result Methods

| Method | Description |
|--------|-------------|
| `setTitle()` | Primary text (required) |
| `setSubtitle()` | Secondary text |
| `setTypeahead()` | Text shown when item is selected |
| `setIcon()` | Heroicon name |
| `setGroup()` | Result category |
| `setPriority()` | Higher values shown first |
| `setAction()` | Action to execute on Enter |
| `setTokens()` | Tokens applied when Tab is pressed |

### Built-in Actions

| Action | Description |
|--------|-------------|
| `jump_to` | Navigate to a URL |
| `dispatch_event` | Trigger Livewire event |
| `replace_query` | Change search query |

### Best Practices

1. **Keep queries fast**: Limit to 5 results, use indexes, eager load relationships
2. **Prioritize contextually**: Boost priority for recent items and context-relevant results
3. **Use descriptive typeahead**: Help users understand what they are selecting
4. **Group logically**: Use appropriate groups for result organization
5. **Handle empty states**: Return empty collection when query is too short
6. **Test dark mode**: Verify styling works in both light and dark themes
7. **Use confirmations**: Always confirm destructive actions
8. **Leverage context**: Make commands context-aware when possible

### Troubleshooting

**Commands Not Appearing:**
- Check query is registered in `SpotlightServiceProvider::registerQueries()`
- Query may be filtering out results (check `blank($query)` logic)
- Mode-specific queries only run when mode character is typed
- Group must be registered in `registerGroups()`
- Clear cache: `php artisan config:clear`

**Results Not Clickable:**
- Ensure `setAction()` is called on result
- Check action parameters are valid (route exists)
- Verify custom action is registered in `registerActions()`

**Styling Issues:**
- Custom Spotlight CSS is in `resources/css/app.css`
- Uses Spark theme variables (`--color-primary`, `--color-accent`, etc.)
- Dark mode automatically supported via theme system

**Performance Issues:**
- Limit database queries to 5 results per query
- Use `blank($query) || strlen($query) < 2` to avoid searching on single characters
- Add indexes to frequently searched columns
- Use eager loading (`->with()`) to prevent N+1 queries

**Icons Not Showing:**
- Icons use Heroicons via Blade Heroicons
- Icon names use format: `o-icon-name` (outline) or `s-icon-name` (solid)
- Full list: https://heroicons.com

## Related Documentation

- [Wire Elements Pro Spotlight](https://wire-elements.dev/docs/getting-started/spotlight-component)
- [Heroicons](https://heroicons.com)
- [daisyUI Colors](https://daisyui.com/docs/colors/)
- [Livewire Events](https://livewire.laravel.com/docs/events)
