# Oura Integration

Sync health and wellness data from Oura Ring.

## Overview

The Oura integration connects to your Oura Ring account and syncs comprehensive health metrics including sleep data, readiness scores, activity levels, heart rate, SpO2, stress, and resilience. It supports multiple instance types for granular data collection and provides historical data migration.

## Features

- Daily scores (sleep, readiness, activity, stress, resilience)
- Sleep records with detailed sleep stages
- Heart rate monitoring with daily aggregates
- SpO2 (blood oxygen) tracking
- Workout and session tracking
- User tags and enhanced tags
- Cardiovascular age and VO2 Max
- Sleep timing recommendations
- Historical data migration support

## Setup

### Prerequisites

- Oura Ring device
- Oura developer account and OAuth application

### Configuration

1. Go to [Oura Developer Portal](https://cloud.ouraring.com/oauth/applications)
2. Create a new application
3. Add redirect URI: `https://yourdomain.com/integrations/oura/callback`
4. Note your Client ID and Client Secret

### Environment Variables

| Variable             | Description         | Required |
| -------------------- | ------------------- | -------- |
| `OURA_CLIENT_ID`     | OAuth client ID     | Yes      |
| `OURA_CLIENT_SECRET` | OAuth client secret | Yes      |
| `OURA_REDIRECT_URI`  | OAuth callback URL  | Yes      |

### OAuth Scopes

The integration requests these scopes:

- `email` - User email address
- `personal` - Personal information (age, height, weight)
- `daily` - Daily scores and summaries
- `heartrate` - Heart rate data
- `workout` - Workout data
- `tag` - User tags
- `session` - Mindfulness sessions
- `spo2` - Blood oxygen data

## Data Model

### Instance Types

| Type            | Description             | API Endpoint                          |
| --------------- | ----------------------- | ------------------------------------- |
| `activity`      | Daily activity scores   | `/v2/usercollection/daily_activity`   |
| `sleep`         | Daily sleep scores      | `/v2/usercollection/daily_sleep`      |
| `sleep_records` | Detailed sleep records  | `/v2/usercollection/sleep`            |
| `readiness`     | Daily readiness scores  | `/v2/usercollection/daily_readiness`  |
| `resilience`    | Daily resilience scores | `/v2/usercollection/daily_resilience` |
| `stress`        | Daily stress levels     | `/v2/usercollection/daily_stress`     |
| `workouts`      | Workout activities      | `/v2/usercollection/workout`          |
| `sessions`      | Mindfulness sessions    | `/v2/usercollection/session`          |
| `tags`          | User-defined tags       | `/v2/usercollection/tag`              |
| `heartrate`     | Heart rate time series  | `/v2/usercollection/heartrate`        |
| `spo2`          | Blood oxygen levels     | `/v2/usercollection/daily_spo2`       |

### Action Types

| Action                     | Description                  | Value Unit       |
| -------------------------- | ---------------------------- | ---------------- |
| `had_activity_score`       | Daily activity score         | percent          |
| `had_sleep_score`          | Daily sleep score            | percent          |
| `slept_for`                | Sleep duration               | seconds          |
| `had_readiness_score`      | Daily readiness score        | percent          |
| `had_resilience_score`     | Daily resilience level (1-5) | resilience_level |
| `had_stress_score`         | Daily stress level (1-3)     | stress_level     |
| `did_workout`              | Workout activity             | kcal             |
| `had_mindfulness_session`  | Mindfulness session          | seconds          |
| `had_oura_tag`             | User-defined tag             | null             |
| `had_heart_rate`           | Heart rate measurement       | bpm              |
| `had_spo2`                 | Blood oxygen level           | percent          |
| `had_cardiovascular_age`   | Cardiovascular age           | years            |
| `had_vo2_max`              | VO2 Max                      | ml/kg/min        |
| `had_enhanced_tag`         | Enhanced tag                 | seconds          |
| `had_sleep_recommendation` | Sleep timing recommendation  | null             |

### Value Formatting

The Oura integration uses custom value formatters for readable display:

| Action                                 | Formatter                                               |
| -------------------------------------- | ------------------------------------------------------- |
| `had_resilience_score`                 | 5=Exceptional, 4=Strong, 3=Solid, 2=Adequate, 1=Limited |
| `had_stress_score`                     | 3=Stressful, 2=Normal, 1=Restored                       |
| `slept_for`, `had_mindfulness_session` | Uses `format_duration()` helper                         |

### Object Types

| Object Type             | Description             |
| ----------------------- | ----------------------- |
| `oura_user`             | Oura user account       |
| `oura_daily_activity`   | Daily activity metric   |
| `oura_daily_sleep`      | Daily sleep metric      |
| `oura_sleep_record`     | Individual sleep record |
| `oura_daily_readiness`  | Daily readiness metric  |
| `oura_daily_resilience` | Daily resilience metric |
| `oura_daily_spo2`       | Daily SpO2 metric       |
| `heartrate_series`      | Heart rate time series  |

### Block Types

Blocks are created for detailed contributor scores and metrics:

- Sleep contributors (efficiency, latency, REM, restfulness, timing)
- Activity contributors (meet daily targets, move every hour, stay active)
- Readiness contributors
- Resilience contributors
- Sleep stages (deep, light, REM, awake)
- Heart rate (min, max, average)
- Workout details (calories, heart rate)

## Usage

### Connecting the Integration

1. Navigate to Integrations in Spark
2. Click "Connect" on Oura integration
3. Authorize with Oura (grants required permissions)
4. Select instance types to enable (each is independent)
5. Configure update frequency per instance

### Configuration Options

| Option                     | Type    | Default | Description                                  |
| -------------------------- | ------- | ------- | -------------------------------------------- |
| `update_frequency_minutes` | integer | 60      | Fetch interval                               |
| Instance-specific          | varies  | -       | Each instance type has its own configuration |

### Manual Operations

```bash
# Fetch data for all Oura integrations
sail artisan integrations:fetch --service=oura

# Fetch specific instance type
sail artisan tinker
>>> $integration = App\Models\Integration::where('service', 'oura')->where('instance_type', 'sleep')->first();
>>> App\Jobs\OAuth\Oura\OuraDailyPull::dispatch($integration);
```

## Precision Encoding

The Oura integration preserves decimal precision using integer encoding:

- Fractional values: `value = round(real * 1000)`, `value_multiplier = 1000`
- Integer values: `value_multiplier = 1`
- Reconstruct: `real = value / value_multiplier`

Example:

```php
// Sleep efficiency of 94.5%
$value = 94500;
$value_multiplier = 1000;
$real = $value / $value_multiplier; // 94.5
```

## Event Structure

### Daily Score Event

```json
{
    "source_id": "oura_activity_2024-01-15",
    "time": "2024-01-15T00:00:00Z",
    "service": "oura",
    "domain": "health",
    "action": "had_activity_score",
    "value": 85,
    "value_unit": "percent",
    "event_metadata": {
        "day": "2024-01-15",
        "kind": "activity"
    },
    "target": {
        "concept": "metric",
        "type": "oura_daily_activity",
        "title": "Activity"
    }
}
```

### Sleep Record Event

```json
{
    "source_id": "oura_sleep_record_{id}",
    "time": "2024-01-15T06:30:00Z",
    "service": "oura",
    "domain": "sleep",
    "action": "slept_for",
    "value": 28800,
    "value_unit": "seconds",
    "event_metadata": {
        "end": "2024-01-15T06:30:00Z",
        "efficiency": 92
    }
}
```

## API Reference

### Base URL

`https://api.ouraring.com/v2`

### Authentication

OAuth 2.0 with authorization code flow:

- Authorization: `https://cloud.ouraring.com/oauth/authorize`
- Token: `https://api.ouraring.com/oauth/token`

### Rate Limits

Oura API has rate limits per endpoint. The integration handles these with:

- Exponential backoff on rate limit errors
- Configurable fetch intervals per instance

## Troubleshooting

### Common Issues

1. **OAuth Errors**
    - Verify redirect URI matches exactly
    - Check client ID and secret are correct
    - Ensure all required scopes are requested

2. **No Data Appearing**
    - Verify the Oura Ring has synced with the Oura app
    - Check that the date range has data
    - Some metrics require Oura membership

3. **Missing Instance Types**
    - Some data types require Oura subscription
    - VO2 Max and cardiovascular age require sufficient historical data

4. **Stale Data**
    - Check `last_triggered_at` on the integration
    - Verify the queue worker is running
    - Check for API errors in logs

### Debug Commands

```bash
# Test Oura connection
sail artisan tinker
>>> $group = App\Models\IntegrationGroup::where('service', 'oura')->first();
>>> $group->expiry;
>>> $group->access_token ? 'Token present' : 'No token';

# Check recent events
>>> App\Models\Event::where('service', 'oura')->latest()->take(10)->get(['action', 'value', 'time']);
```

## Related Documentation

- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - Plugin architecture
- [oura-api-v2-specification.md](oura-api-v2-specification.md) - Oura API reference
- [README_JOBS.md](README_JOBS.md) - Job system overview
