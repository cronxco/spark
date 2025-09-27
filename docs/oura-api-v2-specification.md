# Oura API v2 Complete Specification Analysis

**Generated:** 2025-09-27  
**Purpose:** Comprehensive analysis for Oura plugin refactoring to support all API v2 endpoints

## Production Endpoints Overview

| Endpoint                                      | Currently Supported | Priority | Notes                  |
| --------------------------------------------- | ------------------- | -------- | ---------------------- |
| `/v2/usercollection/daily_activity`           | ✅ Partial          | High     | Missing many fields    |
| `/v2/usercollection/daily_readiness`          | ✅ Partial          | High     | Missing many fields    |
| `/v2/usercollection/daily_resilience`         | ✅ Partial          | High     | Wrong value mapping    |
| `/v2/usercollection/daily_sleep`              | ✅ Partial          | High     | Missing many fields    |
| `/v2/usercollection/daily_spo2`               | ✅ Partial          | Medium   | Basic support          |
| `/v2/usercollection/daily_stress`             | ✅ Partial          | High     | Wrong value mapping    |
| `/v2/usercollection/heartrate`                | ✅ Partial          | Medium   | Basic support          |
| `/v2/usercollection/personal_info`            | ✅ Internal         | Low      | Used for account info  |
| `/v2/usercollection/session`                  | ✅ Partial          | Medium   | Basic support          |
| `/v2/usercollection/sleep`                    | ✅ Partial          | High     | Sleep records vs daily |
| `/v2/usercollection/tag`                      | ✅ Partial          | Low      | Basic tags             |
| `/v2/usercollection/workout`                  | ✅ Partial          | Medium   | Missing fields         |
| `/v2/usercollection/daily_cardiovascular_age` | ❌ Missing          | Medium   | New health metric      |
| `/v2/usercollection/enhanced_tag`             | ❌ Missing          | Low      | Enhanced tag data      |
| `/v2/usercollection/rest_mode_period`         | ❌ Missing          | Low      | Rest periods           |
| `/v2/usercollection/ring_configuration`       | ❌ Missing          | Low      | Hardware info          |
| `/v2/usercollection/sleep_time`               | ❌ Missing          | Medium   | Sleep recommendations  |
| `/v2/usercollection/vO2_max`                  | ❌ Missing          | Medium   | Fitness metric         |

## Missing Endpoints Analysis

### 1. Daily Cardiovascular Age (`/v2/usercollection/daily_cardiovascular_age`)

**Fields:**

- `day` (date): Day of measurement
- `vascular_age` (number): Calculated vascular age in years

**Scope Required:** `daily`  
**Instance Type:** `cardiovascular_age`  
**Primary Event Value:** `vascular_age` (years)  
**Event Action:** `had_cardiovascular_age`

### 2. VO2 Max (`/v2/usercollection/vO2_max`)

**Fields:**

- `day` (date): Day of measurement
- `id` (string): Unique identifier
- `timestamp` (datetime): Exact measurement time
- `vo2_max` (number): VO2 max value in ml/kg/min

**Scope Required:** `daily`  
**Instance Type:** `vo2_max`  
**Primary Event Value:** `vo2_max` (ml/kg/min)  
**Event Action:** `had_vo2_max`

### 3. Enhanced Tag (`/v2/usercollection/enhanced_tag`)

**Fields:**

- `id` (string): Unique identifier
- `start_day` (date): Start day
- `start_time` (time): Start time
- `end_day` (date): End day
- `end_time` (time): End time
- `tag_type_code` (string): Type of tag
- `custom_name` (string): User-defined name
- `comment` (string): Additional notes

**Scope Required:** `tag`  
**Instance Type:** `enhanced_tag`  
**Primary Event Value:** Duration in seconds  
**Event Action:** `had_enhanced_tag`

### 4. Sleep Time (`/v2/usercollection/sleep_time`)

**Fields:**

- `day` (date): Day of recommendation
- `id` (string): Unique identifier
- `optimal_bedtime` (object): Optimal bedtime recommendation
- `recommendation` (string): Sleep recommendation text
- `status` (string): Status of recommendation

**Scope Required:** `daily`  
**Instance Type:** `sleep_time`  
**Primary Event Value:** null (informational)  
**Event Action:** `had_sleep_recommendation`

### 5. Rest Mode Period (`/v2/usercollection/rest_mode_period`)

**Fields:**

- `id` (string): Unique identifier
- `start_day` (date): Start day
- `start_time` (time): Start time
- `end_day` (date): End day
- `end_time` (time): End time
- `episodes` (array): Rest episodes

**Scope Required:** `daily`  
**Instance Type:** `rest_mode_period`  
**Primary Event Value:** Duration in seconds  
**Event Action:** `had_rest_period`

### 6. Ring Configuration (`/v2/usercollection/ring_configuration`)

**Fields:**

- `id` (string): Unique identifier
- `color` (string): Ring color
- `design` (string): Ring design
- `firmware_version` (string): Firmware version
- `hardware_type` (string): Hardware type
- `set_up_at` (datetime): Setup timestamp
- `size` (number): Ring size

**Scope Required:** `personal`  
**Instance Type:** `ring_configuration`  
**Primary Event Value:** null (configuration)  
**Event Action:** `updated_ring_config`

## Current Value Mapping Issues

### Stress Day Summary (CRITICAL FIX NEEDED)

**Current (WRONG):**

```php
'stress_day_summary' => [
    'mappings' => [
        'stressful' => 3,
        'normal' => 2,
        'restful' => 1,  // ❌ WRONG - API uses "restored"
        null => 0,
    ],
]
```

**Correct API Values:**

```php
'stress_day_summary' => [
    'mappings' => [
        'stressful' => 3,
        'normal' => 2,
        'restored' => 1,  // ✅ CORRECT
        null => 0,
    ],
]
```

### Resilience Level (CRITICAL FIX NEEDED)

**Current (WRONG):**

```php
'resilience_level' => [
    'mappings' => [
        'excellent' => 5,  // ❌ WRONG - API uses "exceptional"
        'solid' => 4,
        'adequate' => 3,
        'limited' => 2,
        'poor' => 1,       // ❌ WRONG - API doesn't have "poor"
        null => 0,
    ],
]
```

**Correct API Values:**

```php
'resilience_level' => [
    'mappings' => [
        'exceptional' => 5,  // ✅ CORRECT
        'strong' => 4,       // ✅ NEW
        'solid' => 3,        // ✅ MOVED
        'adequate' => 2,     // ✅ MOVED
        'limited' => 1,      // ✅ MOVED
        null => 0,
    ],
]
```

## Required Scopes Analysis

Based on the API documentation, the complete scope list should be:

```php
protected function getRequiredScopes(): string
{
    return implode(' ', [
        'email',           // For personal_info
        'personal',        // For personal_info, ring_configuration
        'daily',           // For all daily_* endpoints, vo2_max, sleep_time, rest_mode_period
        'heartrate',       // For heartrate endpoint
        'workout',         // For workout endpoint
        'tag',             // For tag, enhanced_tag endpoints
        'session',         // For session endpoint
        'spo2',            // For daily_spo2 endpoint
        'stress',          // For daily_stress endpoint (if separate scope exists)
        'resilience',      // For daily_resilience endpoint (if separate scope exists)
    ]);
}
```

## Enhanced Field Mapping for Existing Endpoints

### Daily Activity - Missing Fields

- `active_calories`, `total_calories` (separate from cal_total)
- `average_met_minutes`, `high_activity_met_minutes`, `low_activity_met_minutes`, `medium_activity_met_minutes`, `sedentary_met_minutes`
- `high_activity_time`, `low_activity_time`, `medium_activity_time`, `sedentary_time`, `resting_time`
- `class_5_min` (5-minute activity classification array)
- `met` (MET array data)
- `inactivity_alerts`
- `meters_to_target`, `target_meters`

### Daily Sleep - Missing Fields

- Many detailed sleep metrics beyond basic score/contributors

### Workout - Missing Fields

- Enhanced workout classification and metrics

## Recommended Instance Type Updates

```php
public static function getInstanceTypes(): array
{
    return [
        // Existing (enhanced)
        'activity' => ['label' => 'Daily Activity', 'schema' => self::getConfigurationSchema()],
        'sleep' => ['label' => 'Daily Sleep', 'schema' => self::getConfigurationSchema()],
        'sleep_records' => ['label' => 'Sleep Records', 'schema' => self::getConfigurationSchema()],
        'readiness' => ['label' => 'Daily Readiness', 'schema' => self::getConfigurationSchema()],
        'resilience' => ['label' => 'Daily Resilience', 'schema' => self::getConfigurationSchema()],
        'stress' => ['label' => 'Daily Stress', 'schema' => self::getConfigurationSchema()],
        'workouts' => ['label' => 'Workouts', 'schema' => self::getConfigurationSchema()],
        'sessions' => ['label' => 'Sessions', 'schema' => self::getConfigurationSchema()],
        'tags' => ['label' => 'Tags', 'schema' => self::getConfigurationSchema()],
        'heartrate' => ['label' => 'Heart Rate (time series)', 'schema' => self::getConfigurationSchema()],
        'spo2' => ['label' => 'Daily SpO2', 'schema' => self::getConfigurationSchema()],

        // New endpoints
        'cardiovascular_age' => ['label' => 'Cardiovascular Age', 'schema' => self::getConfigurationSchema()],
        'vo2_max' => ['label' => 'VO2 Max', 'schema' => self::getConfigurationSchema()],
        'enhanced_tag' => ['label' => 'Enhanced Tags', 'schema' => self::getConfigurationSchema()],
        'sleep_time' => ['label' => 'Sleep Recommendations', 'schema' => self::getConfigurationSchema()],
        'rest_mode_period' => ['label' => 'Rest Mode Periods', 'schema' => self::getConfigurationSchema()],
        'ring_configuration' => ['label' => 'Ring Configuration', 'schema' => self::getConfigurationSchema()],
    ];
}
```

## Block Type Registry

```php
public static function getBlockTypes(): array
{
    return [
        'activity_metrics' => ['icon' => 'o-chart-bar', 'display_name' => 'Activity Metrics'],
        'sleep_stages' => ['icon' => 'o-clock', 'display_name' => 'Sleep Stages'],
        'heart_rate' => ['icon' => 'o-heart', 'display_name' => 'Heart Rate Data'],
        'contributors' => ['icon' => 'o-puzzle-piece', 'display_name' => 'Score Contributors'],
        'workout_metrics' => ['icon' => 'o-fire', 'display_name' => 'Workout Metrics'],
        'tag_info' => ['icon' => 'o-tag', 'display_name' => 'Tag Information'],
        'biometrics' => ['icon' => 'o-heart', 'display_name' => 'Biometric Data'],
        'configuration' => ['icon' => 'o-cog', 'display_name' => 'Configuration'],
        'recommendation' => ['icon' => 'o-light-bulb', 'display_name' => 'Recommendation'],
    ];
}
```

## Next Steps for Implementation

1. ✅ **Completed:** Complete API specification analysis
2. **Next:** Update plugin metadata (value mappings, scopes, instance types)
3. **Next:** Create new pull/processing jobs for missing endpoints
4. **Next:** Enhance existing jobs with full field sets
5. **Next:** Update tests with correct API response fixtures
