# Hevy Integration

Connect your Hevy account to import workouts. Each exercise set is represented as a Block attached to a workout Event. This enables fine-grained analysis of volume, reps, and intensity over time.

## Overview
- Service: `hevy`
- Type: OAuth (`App\Integrations\Hevy\HevyPlugin`)
- Instances: `workouts`
- Core mapping:
  - Workout → Event (service `hevy`, domain `fitness`, action `completed_workout`)
  - Exercise Set → Block (title: "{Exercise} - Set {n}")
  - Exercise Summary (optional) → Block (title: "{Exercise} - Total Volume")

## Setup
1. Create OAuth credentials in Hevy.
2. Configure environment variables:
```env
HEVY_CLIENT_ID=your_client_id
HEVY_CLIENT_SECRET=your_client_secret
HEVY_REDIRECT_URI=${APP_URL}/integrations/hevy/callback
```
3. Ensure the plugin is registered (done in `app/Providers/IntegrationServiceProvider.php`).
4. Navigate to `/integrations` and connect Hevy.

## Configuration
The `workouts` instance supports:
- `update_frequency_minutes` (default: 30)
- `days_back` (default: 14)
- `units`: `kg` or `lb` (default: `kg`)
- `include_exercise_summary_blocks`: optional array; include `enabled` to create per-exercise volume summary blocks

## Scopes
Hevy OAuth scopes requested:
- `profile.read`
- `workouts.read`

## Data model
- Event (workout):
  - `service`: `hevy`
  - `domain`: `fitness`
  - `action`: `completed_workout`
  - `value`: total volume (encoded int) and `value_unit`: `kg`/`lb`
  - `event_metadata`: `duration_seconds`, `end`
- Block (set):
  - `title`: `{Exercise} - Set {n}`
  - `content`: exercise, set number, reps, weight, optional RPE/rest
  - `value`: set weight (encoded) and `value_unit`: `kg`/`lb`
- Block (exercise summary):
  - `title`: `{Exercise} - Total Volume`
  - `value`: sum of (weight × reps)

## Sync behavior
- Fetch window: `days_back` days ending today.
- De-duplication: `source_id` of form `hevy_workout_{integration_id}_{workout_id}` prevents duplicates.
- Token handling: managed via `IntegrationGroup` (auto refresh via base class when needed).

## Troubleshooting
- Invalid credentials: verify `HEVY_*` env values and redirect URI.
- 401/403: confirm scopes and token validity in Hevy console.
- No workouts: expand `days_back` and check user actually has workouts in the period.

## Testing
Run via Sail:
```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan test --filter=HevyIntegrationTest
```
The test `tests/Feature/HevyIntegrationTest.php` fakes Hevy API responses and asserts:
- Metadata/scopes
- Initialization of group and instance
- Workout fetch creates a workout Event and Blocks per set with an exercise summary block

## Extending
- Add additional blocks (e.g., tempo, failure, notes) if Hevy surfaces them.
- Add per-workout summary blocks (e.g., total sets, avg RPE).
- Auto-tag exercises or muscle groups from Hevy taxonomy.
