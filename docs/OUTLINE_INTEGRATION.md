# Outline Integration

This integration connects your Outline workspace to Spark. It syncs collections and documents, represents Day Notes as first-class objects, and extracts Markdown checkbox tasks into blocks. It also provides task jobs to generate Day Notes and pin today’s note in Outline.

## Setup

- Environment variables (defaults shown):
    - `OUTLINE_URL` (no default) – e.g. `https://outline.example.com`
    - `OUTLINE_ACCESS_TOKEN` (no default) – Outline API token
    - `OUTLINE_DAYNOTES_COLLECTION_ID=5622670a-e725-437d-b747-a17905038df8`
    - `OUTLINE_POLL_INTERVAL_MINUTES=15`

In the UI, create an Outline integration instance (service `outline`):

- Instance type `pull`: provide API URL, token, day notes collection id, and polling interval
- Optional task instances (see Tasks below)

Reference: Outline API docs (`https://www.getoutline.com/developers`).

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

- Fetch job: `OutlinePull` retrieves collections and updated documents
- Processing job: `OutlineData` upserts objects, creates/upserts events, and extracts task blocks
- Task reconciliation on re-runs:
    - New tasks: created as new blocks
    - Changed tasks: block updated (title/checked)
    - Deleted tasks: block soft-deleted and metadata augmented with `removed=true`, `removed_at=UTC ISO`

## Scheduling

- CheckIntegrationUpdates dispatches Outline pull every 15 minutes (default)
- You can enable `use_schedule` on the instance for explicit run times if desired

## Tasks

- Generate Day Notes (job: `App\\Jobs\\Outline\\GenerateDayNotes`)
    - Creates Year (`YYYY`), Month (`YYYY-MM: MonthName`), and Day (`YYYY-MM-DD: DayName`) documents in the configured Day Notes collection
- Pin Today’s Day Note (job: `App\\Jobs\\Outline\\PinTodayDayNote`)
    - Unpins any Day Notes in the collection and pins today’s document based on UTC title

Both tasks can be run as Task instances (instance type `task`) using `task_mode=job` and `task_job_class` configured, with queue `pull`.

## Backfill

- Initial backfill recommended: last 3 years
- After backfill, normal polling (15 minutes) is sufficient

## Limitations

- No webhooks: polling based
- Outline API pagination is followed using `nextPath`

## Troubleshooting

- Ensure tokens and URL are correct; 401/403 responses indicate auth/config issues
- If tasks aren’t appearing, confirm the document content includes Markdown checkboxes and the job ran
