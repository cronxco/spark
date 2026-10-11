# Knowledge tasks and projects

Implementation branch: `feat/tasks-projects`, based on `dev`. Product specification: https://docs.cronx.co/doc/feature-plan-tasks-projects-yR0SgCAZ0n.

## Implemented behavior

Tasks are existing owned Blocks on internal Events. Projects, recurrence series and review proposals are existing EventObjects; task links use existing Relationships. No schema migration or new index is introduced. Native capture, legacy day/document checklists and thread tasks share `TaskService` resources and version checks. Internal carriers do not enter the enrichment pipeline.

A day task expires at the next midnight in its captured schedule timezone. A week task lasts through original day + 6 and expires on day + 7; deferral preserves that original window. Persistent tasks do not expire. Unscheduled persistent tasks live in Inbox. Explicit reactivation starts a new window. Deadline, review date and exact reminder are separate fields. Read-time expiry remains correct when workers are delayed.

Web management lives at `/knowledge/tasks`. Task/project destinations are `/tasks/{id}` and `/projects/{id}`. Equivalent APIs are under both `/api/v1` and `/api/v1/mobile`: tasks, task detail/history/actions/bulk-actions, and projects. Existing thread-task routes include native tasks linked to that thread. MCP `knowledge_tasks` supports explicit capture and reviewed proposals.

Mutations require owned targets and strong `If-Match` versions. Task creation requires `client_mutation_id`; edits/actions may supply one for durable replay. Reusing an ID with different contents is rejected. Bulk commands return individual results. Delete is reversible; deleting a project preserves its tasks and removes the project links. History is retained as internal Events.

Integration failures and consent-expiry warnings create deduplicated persistent recovery tasks independently of notification preferences. OAuth recovery destinations preserve Reconnect intent in web, inbox and APNs. Recovery requires newer verified integration success; consent renewal additionally requires a changed consent reference. Incident tasks cannot be manually completed.

`MaintainTasks` runs each minute on the pull queue: expires task envelopes, generates bounded daily/weekly/monthly occurrences, recovers pending Outline dispatches and schedules reminders. Reminders reuse notification preferences, delayed delivery and digest machinery and re-check the task before sending. Rollover itself sends no reminder. Flint proposals enter Review before creating an active task.

## Outline write-back gate

`OUTLINE_REVISION_WRITES_VERIFIED` defaults to **false**. Source edits remain visible as blocked pending intent until the deployed Outline server is certified. The day-note editor also requires this gate before saving its entire document; it retains the draft on failure.

The writer reads a revision and replaces only the exact task fragment with `editMode=patch`, `findText` and `lastRevision`. A visible Open in Spark link carries stable task identity. It preserves indentation and surrounding formatting. Removed items use saved neighboring lines for restore. Concurrent changes, changed anchors and ambiguous unmarked duplicates stop with a visible conflict; they never guess. Previously committed writes can be acknowledged after a lost response. Conflicted edits require source review; Retry never authorizes a blind overwrite.

Before enabling the gate, use a scratch document on the actual deployed server and prove:

1. A stale `lastRevision` rejects the write with no document change.
2. Marker links survive save/read, including nested items and duplicate titles.
3. Complete/reopen, rename/details, delete/restore and concurrent day-note editing preserve surrounding rich content.
4. Permission loss, auth expiry, lost responses and worker retries remain recoverable.

Upstream API schema inspection and fake-provider tests do not certify the deployed provider. Production documents were not modified during implementation.

## Canary and rollback

Global flags in `config/knowledge.php`: `KNOWLEDGE_TASK_READS_ENABLED`, `KNOWLEDGE_TASK_WRITES_ENABLED`, `KNOWLEDGE_PROJECTS_ENABLED`, `KNOWLEDGE_INCIDENT_TASKS_ENABLED`, `KNOWLEDGE_TASK_RECURRENCE_ENABLED`, `KNOWLEDGE_TASK_REMINDERS_ENABLED`, `KNOWLEDGE_OUTLINE_SYNC_ENABLED`. Per-user overrides live at `settings.knowledge_features` with matching keys (`reads`, `writes`, `projects`, `incident_tasks`, `recurrence`, `reminders`, `outline_sync`). Enable all applicable surfaces together for a canary account. Configure the global flags before deploying if rollout must be opt-in; their code defaults are true except the separate Outline certification gate.

The value backfill is dry-run by default:

```sh
php artisan knowledge:tasks-backfill --user=USER_UUID
php artisan knowledge:tasks-backfill --user=USER_UUID --apply
php artisan knowledge:tasks-backfill --user=USER_UUID --rollback=BATCH_UUID
```

Record batch IDs and counts. Backfill retains IDs, source references, completion and compatibility metadata. Rollback restores only unchanged backfilled values and reports subsequently edited tasks without erasing those edits. No production backfill has been run.

Stop producers/recurrence/reminders/source writes independently when rolling back. Preserve tasks, pending sync intent, history and deleted records. Never erase data or automatically reverse a source edit that already reached Outline. Confirm Horizon supervisors consume `pull` and `notifications`, and the scheduler runs, before canary verification.

## Validation and remaining acceptance work

Focused PHPUnit coverage includes lifecycle boundaries, ownership, replay/version conflicts, shared thread adapters, incidents/recovery, proposals, recurrence/month end, expiry/reminders, Outline duplicate identity/revision conflicts, backfill and guarded rollback, existing notification/feed/review behavior. The production Vite build passes. See PR validation for final counts.

Release remains gated on actual Outline certification, native Xcode/Tuist builds and app tests, physical-device OAuth/APNs cold/warm/account-switch routing, accessibility, browser end-to-end workflows, representative query/queue budgets and crash-point delivery checks. The Linux Swift harness compiles actual portable models/endpoints and checks lifecycle contracts; a stubbed dependency harness type-checks the offline store. Neither substitutes for an iOS build.

The full product plan is broader than this first implementation. Remaining product work includes generic entity-detail quick capture, project-level thread/evidence linking, a dedicated future-series editor with end dates, editing proposals before acceptance in both Review UIs, full historical relationship reconstruction, and richer deadline/review reminder presets. These remain on the canonical plan and must not be marked delivered by these branches. Native outbox conflicts are retained for review/discard; an explicit draft-merge recovery editor is still needed. Web projections paginate raw rows before effective filtering; verify usability/query budgets on large accounts before expanding rollout.
