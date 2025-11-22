# Daily Check-in Integration

Rate your physical and mental energy levels twice daily.

## Overview

The Daily Check-in integration is a manual data entry system for tracking personal energy levels. Users rate their physical and mental energy on a 1-5 scale in the morning and afternoon, creating a combined score out of 10 for each check-in period.

## Features

- Morning and afternoon check-in periods
- Physical energy rating (1-5 scale)
- Mental energy rating (1-5 scale)
- Combined score calculation (out of 10)
- Historical tracking per day
- Integration with Card Streams for daily prompts

## Setup

### Prerequisites

No external configuration required. This is a manual plugin.

### Configuration

1. Navigate to Integrations in Spark
2. Create a new Daily Check-in integration
3. Start recording your daily energy levels

### Environment Variables

None required.

## Data Model

### Instance Types

| Type | Description |
|------|-------------|
| `checkin` | Daily check-in tracking |

### Action Types

| Action | Description | Value Unit |
|--------|-------------|------------|
| `had_morning_checkin` | Morning energy levels recorded | /10 |
| `had_afternoon_checkin` | Afternoon energy levels recorded | /10 |

### Block Types

| Block Type | Description | Value Unit |
|------------|-------------|------------|
| `physical_energy` | Physical energy rating | out of 5 |
| `mental_energy` | Mental energy rating | out of 5 |

### Object Types

| Object Type | Description |
|-------------|-------------|
| `day` | A calendar day (target) |
| `user` | The user performing the check-in (actor) |

## Usage

### Recording a Check-in

Check-ins can be recorded via:

1. **Card Streams UI**: Morning and afternoon cards prompt for check-ins
2. **API**: Programmatic recording

### API Usage

```php
use App\Integrations\DailyCheckin\DailyCheckinPlugin;

$plugin = new DailyCheckinPlugin();

// Create a morning check-in
$event = $plugin->createCheckinEvent(
    $integration,
    'morning',      // period: 'morning' or 'afternoon'
    4,              // physical energy (1-5)
    3,              // mental energy (1-5)
    '2024-01-15'    // date in Y-m-d format
);

// Get check-ins for a specific date
$checkins = $plugin->getCheckinsForDate($userId, '2024-01-15');
// Returns: ['morning' => Event|null, 'afternoon' => Event|null]
```

### Rating Scale

| Rating | Physical Energy | Mental Energy |
|--------|-----------------|---------------|
| 1 | Very low, exhausted | Foggy, unfocused |
| 2 | Low, tired | Low motivation |
| 3 | Moderate, average | Average focus |
| 4 | Good, energetic | Sharp, focused |
| 5 | Excellent, peak | Crystal clear |

### Check-in Times

| Period | Default Time | Description |
|--------|--------------|-------------|
| Morning | 08:00 | Start of day energy |
| Afternoon | 17:00 | End of day energy |

The actual check-in time is recorded when you submit, but events are associated with these default times for consistency.

## Event Structure

```json
{
  "source_id": "daily_checkin_morning_2024-01-15",
  "time": "2024-01-15T08:30:00Z",
  "service": "daily_checkin",
  "domain": "health",
  "action": "had_morning_checkin",
  "value": 7,
  "value_unit": "out of 10",
  "event_metadata": {
    "period": "morning",
    "physical_energy": 4,
    "mental_energy": 3,
    "date": "2024-01-15"
  }
}
```

## Best Practices

1. **Consistency**: Check in at similar times each day for comparable data
2. **Honesty**: Rate based on how you actually feel, not how you want to feel
3. **Context**: Consider external factors (sleep, stress) when reviewing trends
4. **Patterns**: Look for correlations with other health data (sleep, exercise)

## Troubleshooting

### Common Issues

1. **Missing check-in for a day**
   - You can still record past check-ins by specifying the date
   - Historical data can be entered at any time

2. **Duplicate check-ins**
   - The system uses updateOrCreate, so submitting again updates the existing entry
   - Only one morning and one afternoon check-in per day

## Related Documentation

- [CARD_STREAMS.md](../CARD_STREAMS.md) - Card-based UI for check-ins
- [INTEGRATION_PLUGINS.md](INTEGRATION_PLUGINS.md) - Plugin architecture
