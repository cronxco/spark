# Hevy Integration

Connect your Hevy account to import workout data, with each exercise set represented as a Block for detailed training analysis.

## Overview

The Hevy integration syncs workout data from the Hevy fitness tracking app using API key authentication. Each workout creates an Event with exercise sets stored as Blocks, enabling fine-grained analysis of volume, reps, and intensity over time. The integration supports automatic periodic sweeps to catch any missed data.

## Features

- Import completed workouts with full exercise details
- Track individual exercise sets with weight, reps, and RPE
- Generate per-exercise volume summary blocks
- Support for both metric (kg) and imperial (lb) units
- Automatic deduplication prevents duplicate workout entries
- Configurable fetch window and update frequency
- Automatic weekly data sweeps for data integrity

## Setup

### Prerequisites

- A Hevy account with workout data
- Hevy API key (obtainable from Hevy app/website)

### Configuration

1. Obtain an API key from Hevy
2. Navigate to `/integrations` in Spark
3. Add the Hevy integration using your API key
4. Configure the workouts instance with your preferences

### Environment Variables

| Variable       | Required | Description                                               |
| -------------- | -------- | --------------------------------------------------------- |
| `HEVY_API_KEY` | No       | Global fallback API key (per-instance keys are preferred) |

## Data Model

### Instance Types

| Type       | Label    | Description                              |
| ---------- | -------- | ---------------------------------------- |
| `workouts` | Workouts | Syncs workout sessions and exercise data |

### Action Types

| Action              | Display Name      | Description                                       | Value Unit |
| ------------------- | ----------------- | ------------------------------------------------- | ---------- |
| `completed_workout` | Completed Workout | A workout session that has been completed in Hevy | kcal       |

### Block Types

| Type               | Display Name     | Description                                     | Value Unit |
| ------------------ | ---------------- | ----------------------------------------------- | ---------- |
| `exercise`         | Exercise         | A specific exercise performed during a workout  | -          |
| `exercise_summary` | Exercise Summary | Summary statistics for an exercise in a workout | kg         |

### Object Types

| Type           | Display Name | Description             |
| -------------- | ------------ | ----------------------- |
| `hevy_workout` | Hevy Workout | A workout from Hevy app |
| `hevy_user`    | Hevy User    | A Hevy user account     |

## Usage

### Connecting

1. Go to the Integrations page
2. Click "Add Integration" and select Hevy
3. Enter your Hevy API key
4. Create a "Workouts" instance
5. Configure update frequency and other options

### Configuration Options

| Option                            | Type    | Default | Range  | Description                               |
| --------------------------------- | ------- | ------- | ------ | ----------------------------------------- |
| `api_key`                         | string  | -       | -      | Hevy API key (overrides global key)       |
| `update_frequency_minutes`        | integer | 30      | 5-1440 | Minutes between sync operations           |
| `days_back`                       | integer | 14      | 1-90   | Number of days to fetch on each run       |
| `units`                           | select  | kg      | kg, lb | Preferred weight unit for display         |
| `include_exercise_summary_blocks` | array   | enabled | -      | Create per-exercise volume summary blocks |

### Manual Operations

Workouts are synced automatically based on the configured update frequency. The integration also performs automatic weekly sweeps (every 6 days) to fetch any missed data from the past 30 days.

## API Reference

The integration uses the Hevy REST API:

- **Base URL**: `https://api.hevyapp.com`
- **Authentication**: API key via `api-key` header
- **Endpoints used**:
    - `GET /v1/workouts` - Fetch workout history
    - `GET /v1/me` - Fetch user profile

## Troubleshooting

### Common Issues

| Issue                 | Cause                              | Solution                                                                   |
| --------------------- | ---------------------------------- | -------------------------------------------------------------------------- |
| Invalid credentials   | API key is incorrect or expired    | Verify API key in Hevy console and update integration configuration        |
| 401/403 errors        | API key lacks required permissions | Confirm API key validity and regenerate if necessary                       |
| No workouts syncing   | Fetch window too narrow            | Increase `days_back` value or check that workouts exist in the time period |
| Missing exercise data | Workout has no exercises recorded  | Verify workout contains exercise sets in Hevy app                          |

## Related Documentation

- [CLAUDE.md](/CLAUDE.md) - Plugin system architecture
- [Integration Plugin System](/CLAUDE.md#integration-plugin-system) - How plugins work
- [Job Architecture](/CLAUDE.md#job-architecture) - Background job processing
