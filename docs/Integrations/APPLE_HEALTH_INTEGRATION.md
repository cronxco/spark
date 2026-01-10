# Apple Health Integration

Sync workouts and health metrics from Apple Health via webhook.

## Overview

The Apple Health integration receives data from Apple Health Export apps via webhook. It supports two instance types: workouts (exercise sessions) and metrics (health measurements like heart rate, steps, and body measurements). Data is pushed to Spark in real-time when exported from your iOS device.

## Features

- Workout tracking with duration, distance, energy burned, and intensity
- 25+ health metrics including heart rate, steps, VO2 max, and sleep data
- Real-time webhook-based data sync
- Automatic tagging by workout location
- Support for both single measurements and aggregated statistics (min/avg/max)

## Setup

### Prerequisites

- An iOS device with Apple Health
- An app that exports Apple Health data via webhook (e.g., Health Auto Export, Auto Health Export)

### Configuration

1. Navigate to Integrations in Spark
2. Create a new Apple Health integration
3. Select instance type: "Workouts" or "Metrics"
4. Copy the webhook URL and secret
5. Configure your iOS export app with the webhook URL

### Environment Variables

No environment variables required. Each integration generates its own webhook secret.

### Webhook URL Format

```
POST /api/webhooks/apple_health/{secret}
```

The secret is generated when creating the integration and serves as authentication.

## Data Model

### Instance Types

| Type       | Description                         |
| ---------- | ----------------------------------- |
| `workouts` | Exercise sessions from Apple Health |
| `metrics`  | Health measurements and biometrics  |

### Action Types

#### Workout Actions

| Action        | Description               | Value Unit |
| ------------- | ------------------------- | ---------- |
| `did_workout` | Completed workout session | kcal       |

#### Health Metric Actions

| Action                                  | Description                | Value Unit  |
| --------------------------------------- | -------------------------- | ----------- |
| `had_heart_rate`                        | Heart rate measurement     | bpm         |
| `had_resting_heart_rate`                | Resting heart rate         | bpm         |
| `had_walking_heart_rate_average`        | Average walking heart rate | bpm         |
| `had_heart_rate_variability`            | HRV measurement            | ms          |
| `had_step_count`                        | Steps taken                | steps       |
| `had_walking_running_distance`          | Distance covered           | km          |
| `had_flights_climbed`                   | Flights of stairs          | flights     |
| `had_active_energy`                     | Active calories burned     | kcal        |
| `had_basal_energy_burned`               | Basal metabolic calories   | kcal        |
| `had_apple_exercise_time`               | Exercise minutes           | min         |
| `had_vo2_max`                           | VO2 max measurement        | mL/kg/min   |
| `had_respiratory_rate`                  | Breathing rate             | breaths/min |
| `had_blood_oxygen_saturation`           | SpO2 level                 | percent     |
| `had_walking_speed`                     | Walking speed              | km/h        |
| `had_walking_step_length`               | Step length                | cm          |
| `had_walking_asymmetry_percentage`      | Gait asymmetry             | percent     |
| `had_walking_double_support_percentage` | Double support time        | percent     |
| `had_stair_speed_up`                    | Stair climbing speed       | steps/s     |
| `had_stair_speed_down`                  | Stair descent speed        | steps/s     |
| `had_time_in_daylight`                  | Daylight exposure          | min         |
| `had_apple_stand_hour`                  | Stand hours                | hours       |
| `had_apple_stand_time`                  | Stand time                 | min         |
| `had_environmental_audio_exposure`      | Environmental noise        | dB          |
| `had_headphone_audio_exposure`          | Headphone volume           | dB          |
| `had_apple_sleeping_wrist_temperature`  | Sleep wrist temp           | C           |
| `had_breathing_disturbances`            | Sleep breathing issues     | count       |
| `had_physical_effort`                   | Physical effort score      | score       |
| `had_six_minute_walking_test_distance`  | 6-min walk test            | m           |

### Block Types

Workouts automatically create blocks for:

| Block Type  | Description          |
| ----------- | -------------------- |
| `distance`  | Workout distance     |
| `energy`    | Active energy burned |
| `intensity` | Workout intensity    |
| `duration`  | Workout duration     |

Metrics create blocks for min/avg/max values when available.

### Object Types

| Object Type         | Description                 |
| ------------------- | --------------------------- |
| `apple_health_user` | User profile (actor)        |
| `apple_workout`     | Workout session (target)    |
| `apple_metric`      | Health metric type (target) |

## Usage

### Webhook Payload Format

The webhook expects JSON payloads in this format:

**Workouts:**

```json
{
    "workouts": [
        {
            "id": "unique-workout-id",
            "name": "Running",
            "start": "2024-01-15T08:00:00Z",
            "end": "2024-01-15T08:30:00Z",
            "duration": 1800,
            "distance": { "qty": 5.2, "units": "km" },
            "activeEnergyBurned": { "qty": 320, "units": "kcal" },
            "intensity": { "qty": 7.5, "units": "METs" },
            "location": "Outdoor"
        }
    ]
}
```

**Metrics:**

```json
{
    "metrics": [
        {
            "name": "heart_rate",
            "units": "bpm",
            "data": [
                {
                    "date": "2024-01-15T08:00:00Z",
                    "Avg": 72,
                    "Min": 58,
                    "Max": 95
                },
                { "date": "2024-01-15T12:00:00Z", "qty": 68 }
            ]
        }
    ]
}
```

### Signature Verification

Webhook requests are verified using the secret in the URL path. Include the secret header:

```
X-Webhook-Secret: {your-webhook-secret}
```

## Troubleshooting

### Common Issues

1. **Webhook not receiving data**
    - Verify the webhook URL is correct in your iOS app
    - Check that the secret in the URL matches the integration
    - Ensure your iOS app has permission to export health data

2. **Missing metrics**
    - Confirm you have "Metrics" instance type configured
    - Check that your iOS export app is configured to send the specific metrics
    - Verify Apple Health has data for those metrics

3. **Duplicate workouts**
    - Each workout needs a unique ID from the source app
    - If IDs are missing, a hash is generated from start time + name

## Related Documentation

- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - Plugin architecture
- [README_JOBS.md](README_JOBS.md) - Job system overview
