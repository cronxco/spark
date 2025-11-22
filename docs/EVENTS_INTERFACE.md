# Events Interface

The Events interface provides a timeline view for browsing and searching events from your integrations.

## Overview

Events are automatically collected from configured integrations and displayed in a chronological timeline. Each event shows the action performed, the actor and target entities, associated metadata, and related blocks.

## Features

| Feature | Description |
|---------|-------------|
| Timeline View | Chronological display of today's events |
| Search | Filter by action, domain, service, actor, or target |
| Event Cards | Visual cards with badges, flow indicators, and tags |
| Detail View | Full event information including metadata and blocks |

## Event Card Display

Each event card includes:

- Action and service badges
- Actor and target with visual flow indicators
- Timestamp and integration details
- Value information (when available)
- Associated tags

## Event Details

Click any event card to view:

| Section | Contents |
|---------|----------|
| Overview | Action, service, domain, timestamps, value |
| Actor | Title, content, type, concept, URLs, tags |
| Target | Title, content, type, concept, URLs, tags |
| Blocks | Related content blocks with metadata |
| Metadata | Raw JSON data for debugging |

## Navigation

### Accessing Events

1. Log into your account
2. Click "Events" in the sidebar
3. Browse today's events in the timeline

### Searching Events

Use the search box to filter by:

- Event action (e.g., "play", "like", "share")
- Domain (e.g., "music", "social")
- Service (e.g., "spotify", "github")
- Actor or target name

## Data Model

| Field | Description |
|-------|-------------|
| Action | Event type (e.g., "played", "liked", "commented") |
| Service | Integration source (e.g., "Spotify", "GitHub") |
| Domain | Category (e.g., "music", "development") |
| Time | When the event occurred |
| Value | Quantitative data (optional) |
| Actor | Entity that performed the action |
| Target | Entity that received the action |

## Data Sources

Events are collected from configured integrations including Spotify, GitHub, Slack, and others. The timeline updates automatically as new data is fetched.

## Troubleshooting

| Issue | Solution |
|-------|----------|
| No events showing | Verify integrations are configured and active |
| Missing details | Check integration permissions and data fetching status |
| Search not working | Try different terms; some fields may not be searchable |
