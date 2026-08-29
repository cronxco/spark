# Check-ins

The check-in feature lets users record how they feel twice a day — once in the morning and once in the afternoon. Each check-in captures a physical energy rating and a mental energy rating, both on a 1–5 scale, plus an optional free-text note.

## Table of Contents

- [Overview](#overview)
- [Data Model](#data-model)
- [Authentication](#authentication)
- [Endpoints](#endpoints)
    - [POST /check-ins — Submit a check-in](#post-check-ins)
    - [GET /check-ins — Status for a date](#get-check-ins)
    - [GET /check-ins/history — History for a date range](#get-check-inshistory)
- [Periods and Timing](#periods-and-timing)
- [Idempotency](#idempotency)
- [Error Reference](#error-reference)

---

## Overview

Check-ins are backed by the `daily_checkin` integration — a manual integration in the `health` domain that never polls an external service. All data originates from the user.

Each check-in you submit becomes a Spark **Event** with:

- Two **Blocks** — one for `physical_energy`, one for `mental_energy`
- An **EventObject** representing the calendar day (target)
- An **EventObject** representing the user (actor)

The web UI shows check-in prompts on a card stream (morning 6am–12pm, afternoon 12pm onwards). The mobile app can mirror this behaviour using the status endpoint to decide whether to prompt the user.

---

## Data Model

### Event fields

| Field            | Type    | Description                                      |
| ---------------- | ------- | ------------------------------------------------ |
| `action`         | string  | `had_morning_checkin` or `had_afternoon_checkin` |
| `value`          | integer | Combined score: `physical + mental` (2–10)       |
| `value_unit`     | string  | `"out of 10"`                                    |
| `event_metadata` | object  | See below                                        |
| `time`           | ISO8601 | Timestamp of submission (not the check-in date)  |

### `event_metadata` shape

```json
{
    "period": "morning",
    "physical_energy": 4,
    "mental_energy": 3,
    "date": "2026-05-09",
    "has_location": false,
    "notes": "Slept well, feeling energised"
}
```

`notes` is `null` when not provided.

### Blocks

Each event always has exactly two blocks:

| `block_type`      | `value` | `value_unit` |
| ----------------- | ------- | ------------ |
| `physical_energy` | 1–5     | `out of 5`   |
| `mental_energy`   | 1–5     | `out of 5`   |

---

## Authentication

All check-in endpoints sit under `/api/v1/mobile/` and require a valid Sanctum Bearer token.

| Operation            | Required ability |
| -------------------- | ---------------- |
| Submit (`POST`)      | `ios:write`      |
| Read status (`GET`)  | `ios:read`       |
| Read history (`GET`) | `ios:read`       |

See [mobile_API.md](./mobile_API.md) for how to obtain tokens via OAuth PKCE.

---

## Endpoints

### POST /check-ins

Submit a morning or afternoon check-in. If a check-in already exists for the same `period` + `date`, it is **updated in place** (idempotent).

```
POST /api/v1/mobile/check-ins
Authorization: Bearer <token with ios:write>
Content-Type: application/json
```

#### Request body

| Field       | Type    | Required | Constraints                      |
| ----------- | ------- | -------- | -------------------------------- |
| `period`    | string  | Yes      | `"morning"` or `"afternoon"`     |
| `physical`  | integer | Yes      | 1–5                              |
| `mental`    | integer | Yes      | 1–5                              |
| `date`      | string  | Yes      | `YYYY-MM-DD` (the check-in date) |
| `latitude`  | float   | No       | −90 to 90                        |
| `longitude` | float   | No       | −180 to 180                      |
| `address`   | string  | No       | Max 255 characters               |
| `notes`     | string  | No       | Max 1000 characters              |

If `latitude` and `longitude` are both provided, the event is linked to a PostGIS location point. Spark will attempt to reverse-geocode the address and detect a known place. You may optionally pass `address` to skip reverse-geocoding and use a pre-resolved address instead.

#### Example request

```json
{
    "period": "morning",
    "physical": 4,
    "mental": 3,
    "date": "2026-05-09",
    "notes": "Good sleep last night"
}
```

#### Response — 201 Created

Returns the created or updated event as a `CompactEvent` object.

```json
{
    "id": "01931c4e-1234-7abc-abcd-000000000001",
    "time": "2026-05-09T08:14:32+01:00",
    "service": "daily_checkin",
    "domain": "health",
    "action": "had_morning_checkin",
    "display_name": "Morning Check-in",
    "display_value": "7",
    "value": 7,
    "unit": "out of 10",
    "actor": {
        "id": "01931c4e-0000-7abc-abcd-000000000001",
        "concept": "user",
        "type": "user",
        "title": "Will"
    },
    "target": {
        "id": "01931c4e-0000-7abc-abcd-000000000002",
        "concept": "day",
        "type": "day",
        "title": "2026-05-09"
    },
    "blocks": [
        {
            "id": "01931c4e-2222-7abc-abcd-000000000003",
            "block_type": "physical_energy",
            "title": "Physical Energy",
            "value": 4,
            "unit": "out of 5"
        },
        {
            "id": "01931c4e-3333-7abc-abcd-000000000004",
            "block_type": "mental_energy",
            "title": "Mental Energy",
            "value": 3,
            "unit": "out of 5"
        }
    ],
    "tags": [],
    "integration": {
        "id": "01931c4e-4444-7abc-abcd-000000000005",
        "service": "daily_checkin",
        "name": "Daily Check-in",
        "instance_type": "checkin"
    }
}
```

---

### GET /check-ins

Returns the completion status for morning and afternoon on a specific date. Use this to decide whether to prompt the user.

```
GET /api/v1/mobile/check-ins?date=YYYY-MM-DD
Authorization: Bearer <token with ios:read>
```

#### Query parameters

| Parameter | Type   | Required | Description  |
| --------- | ------ | -------- | ------------ |
| `date`    | string | Yes      | `YYYY-MM-DD` |

#### Response — 200 OK

```json
{
    "date": "2026-05-09",
    "morning": {
        "completed": true,
        "event": {
            /* CompactEvent — same shape as POST response */
        }
    },
    "afternoon": {
        "completed": false,
        "event": null
    }
}
```

When `completed` is `false`, `event` is always `null`. When `completed` is `true`, `event` contains the full `CompactEvent`.

#### Recommended usage

```
// On app foreground / tab focus:
GET /api/v1/mobile/check-ins?date=2026-05-09

// Show morning prompt if: morning.completed == false && current time < 12:00
// Show afternoon prompt if: afternoon.completed == false && current time >= 12:00
```

---

### GET /check-ins/history

Returns a lightweight day-by-day summary for a date range. Designed for streak display, calendar heat maps, and chart data. Maximum range is 90 days.

```
GET /api/v1/mobile/check-ins/history?from=YYYY-MM-DD&to=YYYY-MM-DD
Authorization: Bearer <token with ios:read>
```

#### Query parameters

| Parameter | Type   | Required | Constraints                                      |
| --------- | ------ | -------- | ------------------------------------------------ |
| `from`    | string | Yes      | `YYYY-MM-DD`                                     |
| `to`      | string | Yes      | `YYYY-MM-DD`, ≥ `from`, max 90 days after `from` |

#### Response — 200 OK

The `days` array contains one entry per calendar day in the requested range, ordered chronologically.

```json
{
    "from": "2026-05-01",
    "to": "2026-05-09",
    "days": [
        {
            "date": "2026-05-01",
            "morning": {
                "completed": true,
                "physical": 4,
                "mental": 3,
                "combined": 7,
                "notes": null,
                "event_id": "01931c4e-1111-7abc-abcd-000000000001"
            },
            "afternoon": {
                "completed": true,
                "physical": 3,
                "mental": 4,
                "combined": 7,
                "notes": "Tired after long meeting",
                "event_id": "01931c4e-2222-7abc-abcd-000000000002"
            }
        },
        {
            "date": "2026-05-02",
            "morning": { "completed": false },
            "afternoon": { "completed": false }
        }
    ]
}
```

When `completed` is `false`, no further keys are present for that period. When `completed` is `true` the period object includes `physical`, `mental`, `combined`, `notes`, and `event_id`.

`event_id` can be used to fetch the full event via `GET /api/v1/mobile/events/{id}`.

---

## Periods and Timing

| Period      | Action type             | Suggested prompt window  |
| ----------- | ----------------------- | ------------------------ |
| `morning`   | `had_morning_checkin`   | 6:00 – 11:59 local time  |
| `afternoon` | `had_afternoon_checkin` | 12:00 – 23:59 local time |

The `date` field in the request body is the **calendar date the check-in belongs to**, not the submission time. This allows late submissions (e.g., submitting yesterday's afternoon check-in the next morning) without corrupting the timeline.

The event's `time` field is always set to the **moment of submission**, not midnight of `date`. This records exactly when the user checked in.

---

## Idempotency

Submitting the same `period` + `date` combination a second time **updates the existing event** rather than creating a duplicate. The `source_id` uniqueness key is `daily_checkin_{period}_{date}`.

This means:

- Re-submitting with corrected values works safely — the old values are overwritten
- The response always returns the current state of the event (201 whether created or updated)
- Block values (`physical_energy`, `mental_energy`) are also updated

---

## Error Reference

| Status | Meaning                                                        |
| ------ | -------------------------------------------------------------- |
| `201`  | Check-in created or updated successfully                       |
| `200`  | Status / history retrieved successfully                        |
| `401`  | Missing or expired token                                       |
| `403`  | Token lacks the required ability (`ios:read` or `ios:write`)   |
| `404`  | Mobile API is disabled (`ios.mobile_api_enabled` feature flag) |
| `422`  | Validation error — response body contains `errors` object      |

### Example 422 body

```json
{
    "message": "The physical field must be between 1 and 5.",
    "errors": {
        "physical": ["The physical field must be between 1 and 5."]
    }
}
```

### 90-day range exceeded (history endpoint)

```json
{
    "message": "Date range may not exceed 90 days."
}
```
