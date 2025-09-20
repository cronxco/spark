# Outline Integration

This integration connects your Outline workspace to Spark. It syncs collections and documents, represents Day Notes as first-class objects, and extracts Markdown checkbox tasks into blocks. It also provides task jobs to generate Day Notes and pin today's note in Outline.

## Setup

- Environment variables (defaults shown):
    - `OUTLINE_URL` (no default) – e.g. `https://outline.example.com`
    - `OUTLINE_ACCESS_TOKEN` (no default) – Outline API token
    - `OUTLINE_DAYNOTES_COLLECTION_ID=5622670a-e725-437d-b747-a17905038df8`

In the UI, create an Outline integration instance (service `outline`):

- Instance type `recent_daynotes`: syncs 5 most recent day notes every 15 minutes (recommended)
- Instance type `recent_documents`: syncs 10 most recent documents every 2 hours
- Optional task instances (see Tasks below)

Reference: Outline API docs (`https://www.getoutline.com/developers`).

## Instance Types

### Recent Day Notes (`recent_daynotes`)

- **Purpose**: Syncs the most recently edited documents from the day notes collection
- **Frequency**: Every 15 minutes (configurable: 5-60 minutes)
- **Document Limit**: 5 documents (configurable: 1-20)
- **Use Case**: Keep day notes and tasks up-to-date for active users

### Recent Documents (`recent_documents`)

- **Purpose**: Syncs the most recently updated documents across all collections
- **Frequency**: Every 2 hours (configurable: 1-24 hours)
- **Document Limit**: 10 documents (configurable: 1-50)
- **Use Case**: Keep general document activity up-to-date

### Task (`task`)

- **Purpose**: Run specific Outline tasks (Pin Day Note, Generate Day Notes)
- **Configuration**: Task-specific settings and scheduling
- **Use Case**: Automated day note management

## Data Mapping

- Objects
    - Collections: `concept=category`, `type=outline_collection`
    - Documents: `concept=document`, `type=outline_document`
    - Day Notes: `concept=day_note` when in configured collection and title matches `YYYY-MM-DD: DayName`
    - Users (actors): `concept=b_party`, `type=outline_user`
- Events
    - Day Notes: `action=had_day_note`, time is start of the day (UTC)
    - Other documents: `action=created`, time is Outline `createdAt`
- Blocks (tasks)
    - Extracted from document text lines that match `- [ ]` or `- [x]`
    - Day Notes: `block_type=day_task`
    - Other docs: `block_type=doc_task`
    - Metadata: `{ outline_document_id, line_number, checked, hash }`
    - URL: document URL

## Processing & Idempotency

- **Recent Day Notes**: `OutlinePullRecentDayNotes` retrieves recent day notes from the configured collection
- **Recent Documents**: `OutlinePullRecentDocuments` retrieves recent documents across all collections
- **Migration**: `OutlineMigrationPull` handles chunked migration in 50-document increments
- **Processing**: `OutlineData` upserts objects, creates/upserts events, and extracts task blocks
- **Task reconciliation** on re-runs:
    - New tasks: created as new blocks
    - Changed tasks: block updated (title/checked)
    - Deleted tasks: block soft-deleted and metadata augmented with `removed=true`, `removed_at=UTC ISO`

## Scheduling

- **Recent Day Notes**: CheckIntegrationUpdates dispatches every 15 minutes (configurable: 5-60 minutes)
- **Recent Documents**: CheckIntegrationUpdates dispatches every 2 hours (configurable: 1-24 hours)
- **Tasks**: Can be scheduled using `use_schedule` on task instances for explicit run times
- **Migration**: Handled separately through the migration system for initial data loading

## Tasks

- Generate Day Notes (job: `App\\Jobs\\Outline\\GenerateDayNotes`)
    - Creates Year (`YYYY`), Month (`YYYY-MM: MonthName`), and Day (`YYYY-MM-DD: DayName`) documents in the configured Day Notes collection
- Pin Today’s Day Note (job: `App\\Jobs\\Outline\\PinTodayDayNote`)
    - Unpins any Day Notes in the collection and pins today’s document based on UTC title

Both tasks can be run as Task instances (instance type `task`) using `task_mode=job` and `task_job_class` configured, with queue `pull`.

## Migration

- **Initial Migration**: Use the migration system to perform full data sync in 50-document chunks
- **Migration Job**: `OutlineMigrationPull` handles chunked migration with automatic progression
- **Migration Status**: Tracked in integration configuration (`migration_status`, `migration_started_at`, `migration_completed_at`)
- **After Migration**: Use `recent_daynotes` or `recent_documents` instance types for ongoing sync
- **Recommended**: Start with `recent_daynotes` instance type for active day note users

## Limitations

- **No webhooks**: Polling-based integration
- **API Rate Limits**: Migration uses 2-second delays between chunks to respect API limits
- **Document Limits**: Recent sync jobs are limited to prevent API overload
- **Outline API pagination**: Followed using `nextPath` for migration jobs
- **Legacy Support**: Old `pull` instance type still supported but not recommended for new integrations

## Troubleshooting

- **Authentication**: Ensure tokens and URL are correct; 401/403 responses indicate auth/config issues
- **Tasks Not Appearing**: Confirm the document content includes Markdown checkboxes and the job ran
- **Migration Issues**: Check migration status in integration configuration; failed migrations can be restarted
- **Performance**: Use `recent_daynotes` for active users, `recent_documents` for general monitoring
- **Pin Issues**: Pin Day Note task now includes better error handling and verification
