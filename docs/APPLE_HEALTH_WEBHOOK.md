## Apple Health Webhook Integration

This integration accepts Apple Health JSON exports via a webhook and stores them as Events with rich Blocks. It creates two instance types under one group/secret:

- workouts: ingests `workouts.json`
- metrics: ingests `metrics.json`

### Setup

1. Go to Integrations in the app and add Apple Health. A group will be created with a shared secret and webhook URL displayed as the group access token.

2. Create instances:

- "Workouts" instance for workouts
- "Metrics" instance for daily metrics

Both instances reuse the group secret and webhook URL.

### Webhook URL

POST `{APP_URL}/webhook/apple_health/{secret}` with JSON payload.

The plugin auto-detects which instance the payload applies to based on the instance type bound to the secret. Use the same URL for both instances.

### Payloads

- Workouts payload shape:

```json
{
    "workouts": [
        {
            "id": "uuid",
            "name": "Outdoor Walk",
            "start": "2025-08-13 19:37:47 +0100",
            "end": "2025-08-13 19:56:37 +0100",
            "duration": 1130.08,
            "distance": { "qty": 1.82, "units": "km" },
            "activeEnergyBurned": { "qty": 77.31, "units": "kcal" },
            "intensity": { "qty": 4.33, "units": "kcal/hr·kg" },
            "location": "Outdoor"
        }
    ]
}
```

- Metrics payload shape:

```json
{
    "metrics": [
        {
            "name": "resting_heart_rate",
            "units": "count/min",
            "data": [{ "date": "2025-08-12 00:00:00 +0100", "qty": 79 }]
        }
    ]
}
```

See `demofiles/workouts.json` and `demofiles/metrics.json` for full examples.

### Storage Model

- Each workout produces one Event with `domain=fitness`, `action=completed_workout`.
- Blocks capture summary, distance, active energy, intensity, and duration.
- Each metric data point produces one Event with `domain=health`, `action=measurement`.
- If a metric point has Min/Avg/Max, each is also stored as a Block.

### Testing with Sail

Use Laravel Sail to post payloads:

```bash
# Workouts example
echo '{"workouts":[{"id":"TEST-1","name":"Walk","start":"2025-08-13 12:12:00 +0100","end":"2025-08-13 12:28:00 +0100","duration":960,"distance":{"qty":1.8,"units":"km"},"activeEnergyBurned":{"qty":59.2,"units":"kcal"},"intensity":{"qty":4.3,"units":"kcal/hr·kg"}}]}' \
  | ./vendor/bin/sail exec -T laravel.test curl -sS -X POST -H 'Content-Type: application/json' --data @- http://localhost/webhook/apple_health/{secret}

# Metrics example
echo '{"metrics":[{"name":"resting_heart_rate","units":"count/min","data":[{"date":"2025-08-12 00:00:00 +0100","qty":79}]}]}' \
  | ./vendor/bin/sail exec -T laravel.test curl -sS -X POST -H 'Content-Type: application/json' --data @- http://localhost/webhook/apple_health/{secret}
```

Replace `{secret}` with your group secret. Repeat for `demofiles/metrics.json`.
