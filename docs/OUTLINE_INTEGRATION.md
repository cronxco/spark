# Outline Integration

Sync documents and tasks from your Outline knowledge base workspace.

## Overview

The Outline integration connects to your self-hosted or cloud Outline workspace to sync collections, documents, and day notes. It extracts Markdown checkbox tasks into blocks for tracking and provides automated task jobs to generate and manage day notes.

## Features

- Sync recent day notes from a designated collection
- Sync recent documents across all collections
- Extract Markdown checkbox tasks (`- [ ]` and `- [x]`) as blocks
- Track task completion status changes
- Automated day note generation for entire years
- Auto-pin today's day note at midnight
- Full migration support for historical data

## Setup

### Prerequisites

- An Outline workspace (self-hosted or cloud)
- An API access token from Outline (Settings > API)
- The collection ID for your day notes (found in the collection URL)

### Configuration

1. Add required environment variables to your `.env` file
2. Create an Outline integration group in the UI
3. Add an instance of the desired type (`recent_daynotes` recommended)

### Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `OUTLINE_URL` | Yes | - | Base URL of your Outline instance (e.g., `https://outline.example.com`) |
| `OUTLINE_ACCESS_TOKEN` | Yes | - | Your Outline API access token |
| `OUTLINE_DAYNOTES_COLLECTION_ID` | Yes | - | UUID of the collection containing day notes |

## Data Model

### Instance Types

| Type | Label | Description | Default Frequency |
|------|-------|-------------|-------------------|
| `recent_daynotes` | Recent Day Notes | Syncs most recently edited day notes | 15 minutes |
| `recent_documents` | Recent Documents | Syncs most recently updated documents across all collections | 2 hours |
| `task` | Outline Task | Runs specific Outline tasks (Pin Day Note, Generate Day Notes) | Scheduled |

### Action Types

| Action | Display Name | Description | Icon |
|--------|--------------|-------------|------|
| `had_day_note` | Had Day Note | A Day Note existed for the day | `o-calendar` |
| `created` | Created Document | An Outline document was created | `o-plus-circle` |

### Block Types

| Type | Display Name | Description | Icon |
|------|--------------|-------------|------|
| `day_task` | Day Task | A task extracted from a Day Note | `o-check-circle` |
| `doc_task` | Document Task | A task extracted from a document | `o-check-circle` |

### Object Types

| Type | Display Name | Concept | Description | Hidden |
|------|--------------|---------|-------------|--------|
| `outline_collection` | Outline Collection | `category` | An Outline collection | No |
| `outline_document` | Outline Document | `document` | An Outline document | No |
| `outline_user` | Outline User | `user` | An Outline user | Yes |

## Usage

### Connecting

1. Navigate to Integrations in the Spark UI
2. Create a new Outline integration group
3. Enter your API URL, access token, and day notes collection ID
4. Create an instance with the desired type

### Configuration Options

**Recent Day Notes Instance:**

| Option | Type | Default | Range | Description |
|--------|------|---------|-------|-------------|
| `update_frequency_minutes` | integer | 15 | 5-60 | How often to sync recent day notes |
| `document_limit` | integer | 5 | 1-20 | Number of most recent day notes to sync |

**Recent Documents Instance:**

| Option | Type | Default | Range | Description |
|--------|------|---------|-------|-------------|
| `update_frequency_minutes` | integer | 120 | 60-1440 | How often to sync recent documents |
| `document_limit` | integer | 10 | 1-50 | Number of most recent documents to sync |

**Task Instance Presets:**

| Preset | Job Class | Description |
|--------|-----------|-------------|
| Pin Day Note | `App\Jobs\Outline\PinTodayDayNote` | Unpins existing day notes and pins today's note (runs at 00:05 UTC) |
| Generate Day Notes | `App\Jobs\Outline\GenerateDayNotes` | Creates Year, Month, and Day documents for the current year |

### Manual Operations

**Running a migration:**

Use the migration system to perform a full historical data sync. The migration processes documents in 50-document chunks with 2-second delays to respect API rate limits.

**Task reconciliation:**

On each sync, tasks are reconciled:
- New tasks are created as blocks
- Changed tasks have their title/checked status updated
- Deleted tasks are soft-deleted with `removed=true` and `removed_at` in metadata

## API Reference

Outline API documentation: https://www.getoutline.com/developers

**Jobs:**

| Job | Queue | Description |
|-----|-------|-------------|
| `OutlinePullRecentDayNotes` | pull | Retrieves recent day notes from configured collection |
| `OutlinePullRecentDocuments` | pull | Retrieves recent documents across all collections |
| `OutlineMigrationPull` | migration | Handles chunked migration in 50-document increments |
| `OutlineData` | data | Processes fetched data, upserts objects/events, extracts tasks |
| `GenerateDayNotes` | pull | Creates year/month/day document hierarchy |
| `PinTodayDayNote` | pull | Pins today's day note in Outline |

## Troubleshooting

### Common Issues

| Issue | Cause | Solution |
|-------|-------|----------|
| 401/403 responses | Invalid or expired access token | Regenerate your API token in Outline Settings > API |
| Tasks not appearing | Document lacks Markdown checkboxes | Ensure tasks use `- [ ]` or `- [x]` format |
| Day notes not syncing | Incorrect collection ID | Verify `daynotes_collection_id` matches your collection URL |
| Migration stuck | API rate limiting | Check migration status in integration configuration; restart if needed |
| Pin task failing | No matching day note | Ensure day note exists with title format `YYYY-MM-DD: DayName` |

## Related Documentation

- [CLAUDE.md](/CLAUDE.md) - Integration plugin system architecture
- [Outline API Documentation](https://www.getoutline.com/developers)
