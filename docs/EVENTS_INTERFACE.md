# Events Interface

The Events interface provides a comprehensive view of events from your integrations, displayed in a timeline format with detailed information about each event.

## Features

### Events Timeline
- **Today's Events**: Shows all events from the current day in chronological order
- **Search Functionality**: Search through events by action, domain, service, actor, or target
- **Event Cards**: Each event is displayed in a card format with:
  - Event action and service badges
  - Actor and target information with visual flow indicators
  - Timestamp and integration details
  - Value information (if available)
  - Associated tags

### Event Details
Click on any event card to view detailed information including:

#### Event Overview
- Complete event information with action, service, and domain
- Visual representation of actor → target flow
- Timestamp, integration, and value details
- Event-specific tags

#### Actor Details
- Actor title and content
- Type and concept information
- Associated URLs (if available)
- Actor-specific tags

#### Target Details
- Target title and content
- Type and concept information
- Associated URLs (if available)
- Target-specific tags

#### Related Blocks
- List of all blocks associated with the event
- Block titles, content, and values
- Timestamps and URLs for each block

#### Event Metadata
- Raw JSON metadata for technical users
- Formatted for easy reading

## Navigation

### Accessing Events
1. Log into your account
2. Click on "Events" in the left sidebar navigation
3. You'll see today's events displayed in a timeline

### Viewing Event Details
1. From the events timeline, click on any event card
2. The detailed view will show comprehensive information about the event
3. Use the "Back to Events" button to return to the timeline

### Searching Events
1. Use the search box in the top-right corner of the events page
2. Search by:
   - Event action (e.g., "play", "like", "share")
   - Domain (e.g., "music", "social")
   - Service (e.g., "spotify", "github")
   - Actor name
   - Target name

## Data Display

### Event Information
- **Action**: The type of event (e.g., "played", "liked", "commented")
- **Service**: The integration service (e.g., "Spotify", "GitHub")
- **Domain**: The category of the event (e.g., "music", "development")
- **Time**: When the event occurred
- **Value**: Quantitative data associated with the event (if available)

### Actor and Target
- **Actor**: The entity that performed the action
- **Target**: The entity that received the action
- Visual flow indicators show the relationship between actor and target

### Tags
- Events, actors, and targets can have associated tags
- Tags help categorize and organize your data
- Different color coding for event, actor, and target tags

### Blocks
- Related content blocks provide additional context
- Each block includes title, content, and metadata
- Links to external resources when available

## Technical Details

### Data Sources
Events are automatically collected from your configured integrations:
- **Spotify**: Music listening activity, playlist changes
- **GitHub**: Repository activity, commits, issues
- **Slack**: Message activity, reactions
- And more integrations as they're added

### Real-time Updates
- Events are updated in real-time as new data is fetched from integrations
- The timeline automatically refreshes to show the latest events

### Performance
- Events are paginated and optimized for fast loading
- Search functionality uses efficient database queries
- Images and media are loaded asynchronously

## Troubleshooting

### No Events Showing
If you don't see any events:
1. Check that your integrations are properly configured
2. Verify that data is being fetched from your integrations
3. Ensure the integration is active and connected

### Missing Event Details
If event details are incomplete:
1. Check the integration's data fetching status
2. Verify that the integration has the necessary permissions
3. Some integrations may have limited data availability

### Search Not Working
If search isn't finding expected results:
1. Try different search terms
2. Check spelling and case sensitivity
3. Some fields may not be searchable depending on the integration

## Future Enhancements

Planned features for the Events interface:
- Date range selection for viewing historical events
- Advanced filtering options
- Event analytics and insights
- Export functionality
- Custom event views and dashboards
