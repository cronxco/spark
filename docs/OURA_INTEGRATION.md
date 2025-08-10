# Oura Integration: Data Model Per Instance

This document describes what Spark creates per Oura instance type: event values, target objects, and blocks. Decimal precision is preserved by encoding decimals as integer `value` with a `value_multiplier` of 1000 when needed. Reconstruct using: real = value / value_multiplier.

## OAuth & Scopes
- Authorization: `https://cloud.ouraring.com/oauth/authorize`
- Token: `https://api.ouraring.com/oauth/token`
- Scopes: `email personal daily heartrate workout tag session spo2Daily`

## Common Structures (applies to all instances)
- Actor (created/updated once and reused):
  - concept: `user`
  - type: `oura_user`
  - title: instance name (your chosen name)
  - metadata: `{ user_id, email, age, height, weight, biological_sex, dominant_hand }`
- Precision rule:
  - If a metric is fractional, we store `value = round(real * 1000)` and `value_multiplier = 1000`.
  - If integer, `value_multiplier = 1`.

## Instance Types

### 1) Daily Activity (instance_type: `activity`)
- Source: `/v2/usercollection/daily_activity?start_date&end_date`
- Event
  - domain: `health`
  - action: `had_activity_score`
  - value: activity score (scaled if fractional)
  - value_unit: `percent`
  - event_metadata: `{ day, kind: "activity" }`
- Target object
  - concept: `metric`
  - type: `oura_daily_activity`
  - title: `Activity`
  - metadata: full daily activity item
- Blocks
  - One per contributor in `contributors` (e.g., `Meet Daily Targets`, `Move Every Hour`, `Stay Active`)
    - title: title-cased key
    - content: `Contributor score`
    - value: contributor score (scaled if fractional)
    - value_unit: `percent`
  - One per detail type (`steps`, `cal_total`, `equivalent_walking_distance`, `target_calories`, `non_wear_time` etc.)
    - title: title-cased key
    - value: detail value
    - value_unit: relevant detail unit

### 2) Daily Sleep (instance_type: `sleep`)
- Source: `/v2/usercollection/daily_sleep?start_date&end_date`
- Event
  - domain: `health`
  - action: `had_sleep_score`
  - value: sleep score (scaled if fractional)
  - value_unit: `percent`
  - event_metadata: `{ day, kind: "sleep" }`
- Target object
  - concept: `metric`
  - type: `oura_daily_sleep`
  - title: `Sleep`
  - metadata: full daily sleep item
- Blocks
  - One per contributor in `contributors` (e.g., `Efficiency`, `Latency`, `REM`, `Restfulness`, `Timing`)
    - value: contributor score (scaled if fractional)
    - value_unit: `percent`

### 3) Sleep Records (instance_type: `sleep_records`)
- Source: `/v2/usercollection/sleep?start_date&end_date`
- Event
  - domain: `sleep`
  - action: `slept_for`
  - value: `duration` (seconds)
  - value_unit: `seconds`
  - event_metadata: `{ end, efficiency }`
- Target object
  - concept: `sleep`
  - type: `oura_sleep_record`
  - title: `Sleep Record`
  - metadata: full sleep record item
- Blocks
  - Sleep stages: `Deep Sleep`, `Light Sleep`, `REM Sleep`, `Awake Time`
    - value: stage seconds
    - value_unit: `seconds`
  - Average Heart Rate
    - value: `average_heart_rate` (scaled if fractional)
    - value_unit: `bpm`

### 4) Daily Readiness (instance_type: `readiness`)
- Source: `/v2/usercollection/daily_readiness?start_date&end_date`
- Event
  - domain: `health`
  - action: `had_readiness_score`
  - value: `percent` (scaled if fractional)
  - value_unit: `percent`
  - event_metadata: `{ day, kind: "readiness" }`
- Target object
  - concept: `metric`
  - type: `oura_daily_readiness`
  - title: `Readiness`
  - metadata: full item
- Blocks
  - One per contributor in `contributors` (scaled if fractional)

### 5) Daily Resilience (instance_type: `resilience`)
- Source: `/v2/usercollection/daily_resilience?start_date&end_date`
- Event
  - domain: `health`
  - action: `had_resilience_score`
  - value: `resilience_score` (scaled if fractional)
  - value_unit: `percent`
  - event_metadata: `{ day, kind: "resilience" }`
- Target object
  - concept: `metric`
  - type: `oura_daily_resilience`
  - title: `Resilience`
  - metadata: full item
- Blocks
  - One per contributor in `contributors` (scaled if fractional)

### 6) Daily Stress (instance_type: `stress`)
- Source: `/v2/usercollection/daily_stress?start_date&end_date`
- Event
  - domain: `health`
  - action: `had_stress_score`
  - value: `stress_score` (scaled if fractional)
  - value_unit: `percent`
  - event_metadata: `{ day, kind: "stress" }`
- Target object
  - concept: `metric`
  - type: `oura_daily_stress`
  - title: `Stress`
  - metadata: full item
- Blocks
  - One per contributor in `contributors` (scaled if fractional)

### 7) Workouts (instance_type: `workouts`)
- Source: `/v2/usercollection/workout?start_date&end_date`
- Event
  - domain: `fitness`
  - action: `did_workout`
  - value: `duration` (seconds)
  - value_unit: `seconds`
  - event_metadata: `{ end, calories }`
- Target object
  - concept: `workout`
  - type: Oura activity name
  - title: title-cased activity
  - metadata: full workout item
- Blocks
  - Calories
    - value: `calories` (scaled if fractional)
    - value_unit: `kcal`
  - Average Heart Rate (if present)
    - value: `average_heart_rate` (scaled if fractional)
    - value_unit: `bpm`

### 8) Sessions (instance_type: `sessions`)
- Source: `/v2/usercollection/session?start_date&end_date`
- Event
  - domain: `health`
  - action: `had_mindfulness_session`
  - value: `duration` (seconds)
  - value_unit: `seconds`
- Target object
  - concept: `mindfulness_session`
  - type: Oura session type
  - title: title-cased session type
  - metadata: full item
- Blocks (optional)
  - State
    - title: `State`
    - content: textual state/mood

### 9) Tags (instance_type: `tags`)
- Source: `/v2/usercollection/tag?start_date&end_date`
- Event
  - domain: `health`
  - action: `had_oura_tag`
  - value: null
- Target: none
- Blocks
  - Tag
    - title: `Tag`
    - content: tag label/text

### 10) Heart Rate Series (instance_type: `heartrate`)
- Source: `/v2/usercollection/heartrate?start_datetime&end_datetime`
- Aggregation: one event per day (summary from time-series points)
- Event
  - domain: `health`
  - action: `had_heart_rate`
  - value: avg bpm (scaled if fractional)
  - value_unit: `bpm`
  - event_metadata: `{ day, min_bpm, max_bpm, avg_bpm }`
- Target object
  - concept: `metric`
  - type: `heartrate_series`
  - title: `Heart Rate`
  - metadata: `{ interval: "irregular" }`
- Blocks
  - Min Heart Rate
    - value: min bpm (scaled if fractional)
    - value_unit: `bpm`
  - Max Heart Rate
    - value: max bpm (scaled if fractional)
    - value_unit: `bpm`

### 11) SpO2 (instance_type: `spo2`)
- Source: `/v2/usercollection/daily_spo2?start_date&end_date`
- Event
  - domain: `health`
  - action: `had_spo2`
  - value: `spo2_average` (scaled if fractional)
  - value_unit: `percent`
  - event_metadata: `{ day, kind: "spo2" }`
- Target object
  - concept: `metric`
  - type: `oura_daily_spo2`
  - title: `SpO2`
  - metadata: full item

## Instance Naming & Refresh Frequency
- Default instance name: instance label (e.g., `Daily Sleep`, `Daily Activity`).
- Onboarding: set a custom name and per-instance `update_frequency_minutes`.
- Configure screen: change name and refresh frequency later as needed.

## Notes
- Duplicate prevention: unique `source_id` per integration ensures idempotency.
- Tokens: access/refresh tokens live on the integration group; instances share auth.
- Precision: decimals encoded by `(value, value_multiplier=1000)`.

