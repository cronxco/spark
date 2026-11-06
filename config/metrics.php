<?php

/*
|--------------------------------------------------------------------------
| Metric catalogue
|--------------------------------------------------------------------------
|
| An event with a value and a unit can be charted, but only the metrics listed
| here are judged: anomalies, trends and insights are opt-in, by kind
| (Will, 5 Nov 2026). An action that is not listed is never judged.
|
| Kinds
|   reading      a daily reading or score (sleep score, resting heart rate)
|   daily_total  a total that fills in through the day (steps, active energy)
|   ordinal      a small ordered level (stress, resilience), judged by bands
|   rating, amount, balance, event_measure, progress
|                 can be charted but are never anomaly-checked
|
| Keys per metric
|   kind           one of the above
|   anomalies      raise adverse anomalies (default false)
|   insights       produce period and typical-day insights (default false)
|   method         mean_sd (scores), median_mad (amounts and durations), band (ordinals).
|                  Defaults from the kind: reading mean_sd, daily_total median_mad, ordinal band
|   higher_is_better  polarity, when the plugin does not declare one
|   settle_hours   hours before a value is final: after the local day ends for a total that
|                  fills in, or since the source last rewrote it for a reading
|   accumulates    the day's value keeps filling in until the day ends
|   duplicate_of   service.action of the preferred source for the same concept
|
*/

$reading = fn (array $extra = []): array => ['kind' => 'reading'] + $extra;
$total = fn (array $extra = []): array => ['kind' => 'daily_total', 'accumulates' => true, 'settle_hours' => 3] + $extra;

return [

    // Window the baseline is learned from, and the window shown to the user.
    'detection_window_days' => 90,
    'display_window_days' => 30,

    // Insights shown per user per day, most significant first.
    'max_insights_per_day' => 5,

    // Services recorded under two spellings. The first is the one to keep.
    'service_aliases' => [
        'google-calendar' => ['google_calendar'],
    ],

    'metrics' => [

        // Oura
        'oura.had_readiness_score' => $reading(['anomalies' => true, 'insights' => true, 'settle_hours' => 6]),
        'oura.had_sleep_score' => $reading(['anomalies' => true, 'insights' => true, 'settle_hours' => 6]),
        'oura.had_activity_score' => $reading(['anomalies' => true, 'insights' => true, 'settle_hours' => 30]),
        'oura.had_heart_rate' => $reading(['anomalies' => true, 'insights' => true, 'higher_is_better' => false, 'accumulates' => true, 'settle_hours' => 6]),
        'oura.had_spo2' => $reading(['anomalies' => true, 'higher_is_better' => true, 'settle_hours' => 6]),
        'oura.had_cardiovascular_age' => $reading(['anomalies' => true, 'settle_hours' => 6]),
        'oura.had_vo2_max' => $reading(['insights' => true, 'higher_is_better' => true]),
        'oura.had_stress_score' => ['kind' => 'ordinal', 'anomalies' => true, 'insights' => true, 'settle_hours' => 6],
        'oura.had_resilience_score' => ['kind' => 'ordinal', 'anomalies' => true, 'insights' => true, 'settle_hours' => 6],
        'oura.slept_for' => ['kind' => 'daily_total', 'insights' => true, 'higher_is_better' => true, 'settle_hours' => 6],
        'oura.napped_for' => ['kind' => 'event_measure'],
        'oura.did_workout' => ['kind' => 'event_measure'],

        // Apple Health: anomalies for the curated list only (Will, 5 Nov 2026)
        'apple_health.had_resting_heart_rate' => $reading(['anomalies' => true, 'insights' => true, 'higher_is_better' => false, 'settle_hours' => 6]),
        'apple_health.had_heart_rate_variability' => $reading(['anomalies' => true, 'insights' => true, 'higher_is_better' => true, 'settle_hours' => 6]),
        'apple_health.had_apple_sleeping_wrist_temperature' => $reading(['anomalies' => true, 'settle_hours' => 6]),
        'apple_health.had_blood_oxygen_saturation' => $reading(['anomalies' => true, 'higher_is_better' => true, 'settle_hours' => 6]),
        'apple_health.had_respiratory_rate' => $reading(['anomalies' => true, 'settle_hours' => 6]),
        'apple_health.had_step_count' => $total(['anomalies' => true, 'insights' => true, 'higher_is_better' => true]),
        'apple_health.had_active_energy' => $total(['insights' => true, 'higher_is_better' => true]),
        'apple_health.had_apple_exercise_time' => $total(['insights' => true, 'higher_is_better' => true]),
        'apple_health.had_apple_stand_hour' => $total(['higher_is_better' => true]),
        'apple_health.had_apple_stand_time' => $total(['higher_is_better' => true]),
        'apple_health.had_flights_climbed' => $total(['higher_is_better' => true]),
        'apple_health.had_walking_running_distance' => $total(['higher_is_better' => true]),
        'apple_health.had_time_in_daylight' => $total(['higher_is_better' => true]),
        'apple_health.had_basal_energy_burned' => $total(),
        'apple_health.had_walking_speed' => $reading(['settle_hours' => 6]),
        'apple_health.had_walking_step_length' => $reading(['settle_hours' => 6]),
        'apple_health.had_walking_heart_rate_average' => $reading(['settle_hours' => 6]),
        'apple_health.had_walking_double_support_percentage' => $reading(['settle_hours' => 6]),
        'apple_health.had_walking_asymmetry_percentage' => $reading(['settle_hours' => 6]),
        'apple_health.had_stair_speed_up' => $reading(['settle_hours' => 6]),
        'apple_health.had_stair_speed_down' => $reading(['settle_hours' => 6]),
        'apple_health.had_physical_effort' => $reading(['settle_hours' => 6]),
        'apple_health.had_cardio_recovery' => $reading(['settle_hours' => 6]),
        'apple_health.had_environmental_audio_exposure' => $reading(['settle_hours' => 6]),
        'apple_health.had_headphone_audio_exposure' => $reading(['settle_hours' => 6]),
        'apple_health.had_six_minute_walking_test_distance' => $reading(),
        'apple_health.had_weight_body_mass' => $reading(),
        'apple_health.had_body_fat_percentage' => $reading(),
        'apple_health.had_body_mass_index' => $reading(),
        'apple_health.had_lean_body_mass' => $reading(),
        'apple_health.had_mindful_minutes' => $total(),
        // The same concepts come from Oura, which is the preferred source.
        'apple_health.had_heart_rate' => $reading(['duplicate_of' => 'oura.had_heart_rate', 'settle_hours' => 6]),
        'apple_health.had_vo2_max' => $reading(['duplicate_of' => 'oura.had_vo2_max']),
        'apple_health.did_workout' => ['kind' => 'event_measure'],

        // Self-reported check-ins
        'daily_checkin.had_morning_checkin' => $reading(['anomalies' => true, 'insights' => true]),
        'daily_checkin.had_afternoon_checkin' => $reading(['anomalies' => true, 'insights' => true]),

        // Things that can be charted but are not signals about the person
        'untappd.drank' => ['kind' => 'rating'],
        'goodreads.finished_reading' => ['kind' => 'rating'],
        'goodreads.is_reading' => ['kind' => 'progress'],
        'google-calendar.had_event' => ['kind' => 'event_measure'],
        'google_calendar.had_event' => ['kind' => 'event_measure'],
        'hevy.completed_workout' => ['kind' => 'event_measure'],
        'receipt.had_receipt_from' => ['kind' => 'amount'],

        // Money out per day, built from payments (see SpendSeries)
        'spend.spent' => ['kind' => 'daily_total', 'method' => 'median_mad', 'insights' => true, 'higher_is_better' => false, 'accumulates' => true, 'settle_hours' => 3],
    ],

    // Money is judged as spend (see App\Services\Metrics\SpendSeries), never as
    // single transactions or balances.
    'money_kinds' => [
        'had_balance' => 'balance',
        'interest_earned' => 'amount',
        '*' => 'amount',
    ],

    // The actions that count as spending, for the spend insights.
    'spend' => [
        'services' => ['monzo', 'gocardless'],
        'actions' => ['card_payment_to', 'payment_to'],
        'currency' => 'GBP',
    ],

    // Day-type tests: a weekday or weekend comparison is only worded as one
    // when the data shows a real difference.
    'day_type' => [
        'min_weeks' => 8,
        'min_group_samples' => 5,
        'alpha' => 0.01,
    ],

];
