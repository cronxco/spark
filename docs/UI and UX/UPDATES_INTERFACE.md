# Integration and Task Updates Interface

A single page showing when each integration last synced, what runs next, and which ones need attention.

## Overview

The Updates page (`/updates`) lists every integration and task grouped by plugin. It leads with a one-line summary ("1 integration needs attention · 1 paused · 2 quiet") and opens the groups that need attention by default. Livewire polls every five seconds, so status changes appear without a reload.

The page follows the Spark Design System: flat groups on `base-100` with a hairline border, status chips only where someone needs to act, sentence case throughout, and times that are relative within a day and absolute (in mono) beyond it.

## Status

Status comes from `Integration::statusKey()`, which the mobile API also uses, so web and iOS always agree.

| Status         | Shown as                  | Meaning                                                                                                                      |
| -------------- | ------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `up_to_date`   | Nothing (quiet default)   | Not yet due, or overdue by less than the two-minute scheduler grace period                                                   |
| `needs_update` | "Needs update" error chip | A pull integration is overdue for its next fetch                                                                             |
| `processing`   | Spinner and "Updating"    | A fetch was triggered after the last success and is still inside the processing window                                       |
| `paused`       | "Paused" neutral chip     | Scheduled fetches are switched off                                                                                           |
| `stale`        | "Quiet" in muted text     | A push or manual source hasn't received data recently. There is nothing to trigger, so it isn't counted as needing attention |

A failed migration also shows an error chip and counts towards "needs attention".

## What each group shows

- **Group header**: plugin name, instance count, and facts shared by every instance — the update cadence ("Hourly", "Every 15 minutes", or the schedule summary) and, for plugins that implement `SupportsSweeps`, the most recent back-fill sweep.
- **Instance row**: name, status, when it last updated (or last received data), when it runs next ("Was due …" if overdue), and its cadence only when it differs from the group's.
- **Actions**: "Update now" for pull integrations that aren't paused or already updating, and "Pause" / "Resume".

## Filtering

- **All / Integrations / Tasks** switches between everything, non-task instances and task instances.
- **Search** matches the instance name, the service slug and the plugin's display name (so "check-in" finds Daily Check-in).
- An empty result says nothing matches and offers "Clear filters"; the "No integrations yet" state only appears when nothing is connected at all.

## How updates run

1. "Update now" calls `DispatchIntegrationFetchJobs`, which queues the plugin's fetch jobs.
2. Fetch jobs mark the integration as triggered (`last_triggered_at`), so it shows as updating.
3. On success the integration records `last_successful_update_at`; on failure the trigger is cleared so it can run again.
4. `CheckIntegrationUpdates` runs every minute and dispatches anything that is due and not paused.

## Sweeps

Plugins that periodically re-fetch a longer window implement `App\Integrations\Contracts\SupportsSweeps` and describe the sweep via `getSweepSchedule()` (label, window, period and the configuration key they stamp after each run). Monzo, Oura, Hevy and GoCardless implement it today.

## Navigation

| Access method | Value                                |
| ------------- | ------------------------------------ |
| URL           | `/updates`                           |
| Sidebar       | "Updates" with cloud-arrow-down icon |
| Route name    | `updates.index`                      |

## Mobile

The iOS app reads the same status through `GET /api/v1/mobile/integrations` and can trigger a sync or pause/resume through `POST /integrations/{id}/sync` and `POST /integrations/{id}/pause`. See [mobile_API.md](../API/mobile_API.md).
