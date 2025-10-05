<?php

namespace App\Integrations\Oura;

use App\Integrations\Base\OAuthPlugin;
use App\Integrations\Contracts\SupportsValueMapping;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Throwable;

class OuraPlugin extends OAuthPlugin implements SupportsValueMapping
{
    protected string $baseUrl = 'https://api.ouraring.com/v2';

    protected string $authUrl = 'https://cloud.ouraring.com';

    protected string $clientId;

    protected string $clientSecret;

    protected string $redirectUri;

    public function __construct()
    {
        $this->clientId = config('services.oura.client_id') ?? '';
        $this->clientSecret = config('services.oura.client_secret') ?? '';
        $this->redirectUri = config('services.oura.redirect') ?? route('integrations.oauth.callback', ['service' => 'oura']);

        if (app()->environment() !== 'testing' && (empty($this->clientId) || empty($this->clientSecret))) {
            throw new InvalidArgumentException('Oura OAuth credentials are not configured');
        }
    }

    public static function getIcon(): string
    {
        return 'o-heart';
    }

    public static function getAccentColor(): string
    {
        return 'primary';
    }

    public static function getDomain(): string
    {
        return 'health';
    }

    public static function supportsMigration(): bool
    {
        return true;
    }

    public static function getActionTypes(): array
    {
        return [
            'slept_for' => [
                'icon' => 'o-moon',
                'display_name' => 'Sleep',
                'description' => 'Sleep duration and quality data',
                'display_with_object' => false,
                'value_unit' => 'hours',
                'hidden' => false,
            ],
            'had_heart_rate' => [
                'icon' => 'o-heart',
                'display_name' => 'Heart Rate',
                'description' => 'Heart rate measurement data',
                'display_with_object' => false,
                'value_unit' => 'bpm',
                'hidden' => false,
            ],
            'did_workout' => [
                'icon' => 'o-fire',
                'display_name' => 'Workout',
                'description' => 'Workout activity data',
                'display_with_object' => true,
                'value_unit' => 'calories',
                'hidden' => false,
            ],
            'had_mindfulness_session' => [
                'icon' => 'o-sparkles',
                'display_name' => 'Mindfulness Session',
                'description' => 'Mindfulness or meditation session',
                'display_with_object' => false,
                'value_unit' => 'minutes',
                'hidden' => false,
            ],
            'had_oura_tag' => [
                'icon' => 'o-tag',
                'display_name' => 'Oura Tag',
                'description' => 'User-defined tag for the day',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'had_readiness_score' => [
                'icon' => 'o-battery-100',
                'display_name' => 'Readiness Score',
                'description' => 'Daily readiness score assessment',
                'display_with_object' => false,
                'value_unit' => 'percent',
                'hidden' => false,
            ],
            'had_sleep_score' => [
                'icon' => 'o-moon',
                'display_name' => 'Sleep Score',
                'description' => 'Daily sleep quality score assessment',
                'display_with_object' => false,
                'value_unit' => 'percent',
                'hidden' => false,
            ],
            'had_activity_score' => [
                'icon' => 'o-chart-bar',
                'display_name' => 'Activity Score',
                'description' => 'Daily activity score assessment',
                'display_with_object' => false,
                'value_unit' => 'percent',
                'hidden' => false,
            ],
            'had_stress_score' => [
                'icon' => 'o-exclamation-triangle',
                'display_name' => 'Stress Level',
                'description' => 'Daily stress level assessment',
                'display_with_object' => false,
                'value_unit' => 'stress_level',
                'value_mapping' => 'stress_day_summary',
                'hidden' => false,
            ],
            'had_resilience_score' => [
                'icon' => 'o-shield-check',
                'display_name' => 'Resilience Level',
                'description' => 'Daily resilience level assessment',
                'display_with_object' => false,
                'value_unit' => 'resilience_level',
                'value_mapping' => 'resilience_level',
                'hidden' => false,
            ],
            'had_spo2' => [
                'icon' => 'o-heart',
                'display_name' => 'SpO2',
                'description' => 'Blood oxygen saturation level',
                'display_with_object' => false,
                'value_unit' => 'percent',
                'hidden' => false,
            ],
            'had_cardiovascular_age' => [
                'icon' => 'o-heart',
                'display_name' => 'Cardiovascular Age',
                'description' => 'Estimated cardiovascular age',
                'display_with_object' => false,
                'value_unit' => 'years',
                'hidden' => false,
            ],
            'had_vo2_max' => [
                'icon' => 'o-fire',
                'display_name' => 'VO2 Max',
                'description' => 'Maximum oxygen consumption rate',
                'display_with_object' => false,
                'value_unit' => 'ml/kg/min',
                'hidden' => false,
            ],
            'had_enhanced_tag' => [
                'icon' => 'o-tag',
                'display_name' => 'Enhanced Tag',
                'description' => 'Enhanced tag with detailed information',
                'display_with_object' => true,
                'value_unit' => 'seconds',
                'hidden' => false,
            ],
            'had_sleep_recommendation' => [
                'icon' => 'o-moon',
                'display_name' => 'Sleep Recommendation',
                'description' => 'Personalized sleep timing recommendation',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'had_rest_period' => [
                'icon' => 'o-pause',
                'display_name' => 'Rest Period',
                'description' => 'Rest mode period duration',
                'display_with_object' => true,
                'value_unit' => 'seconds',
                'hidden' => false,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'activity_metrics' => [
                'icon' => 'o-chart-bar',
                'display_name' => 'Activity Metrics',
                'description' => 'Detailed activity measurements and statistics',
                'display_with_object' => true,
                'value_unit' => 'various',
                'hidden' => false,
            ],
            'sleep_stages' => [
                'icon' => 'o-clock',
                'display_name' => 'Sleep Stages',
                'description' => 'Sleep stage duration information',
                'display_with_object' => true,
                'value_unit' => 'seconds',
                'hidden' => false,
            ],
            'heart_rate' => [
                'icon' => 'o-heart',
                'display_name' => 'Heart Rate Data',
                'description' => 'Heart rate measurements and statistics',
                'display_with_object' => true,
                'value_unit' => 'bpm',
                'hidden' => false,
            ],
            'contributors' => [
                'icon' => 'o-puzzle-piece',
                'display_name' => 'Score Contributors',
                'description' => 'Individual components contributing to daily scores',
                'display_with_object' => true,
                'value_unit' => 'percent',
                'hidden' => false,
            ],
            'workout_metrics' => [
                'icon' => 'o-fire',
                'display_name' => 'Workout Metrics',
                'description' => 'Detailed workout measurements',
                'display_with_object' => true,
                'value_unit' => 'various',
                'hidden' => false,
            ],
            'tag_info' => [
                'icon' => 'o-tag',
                'display_name' => 'Tag Information',
                'description' => 'User-defined tags and annotations',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'biometrics' => [
                'icon' => 'o-heart',
                'display_name' => 'Biometric Data',
                'description' => 'Physiological measurements and health metrics',
                'display_with_object' => true,
                'value_unit' => 'various',
                'hidden' => false,
            ],
            'configuration' => [
                'icon' => 'o-cog',
                'display_name' => 'Configuration',
                'description' => 'Device and system configuration details',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
            'recommendation' => [
                'icon' => 'o-light-bulb',
                'display_name' => 'Recommendation',
                'description' => 'Personalized recommendations and insights',
                'display_with_object' => true,
                'value_unit' => null,
                'hidden' => false,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'oura_user' => [
                'icon' => 'o-user',
                'display_name' => 'Oura User',
                'description' => 'An Oura Ring user account',
                'hidden' => false,
            ],
            'oura_sleep_record' => [
                'icon' => 'o-moon',
                'display_name' => 'Oura Sleep Record',
                'description' => 'A sleep record from Oura Ring',
                'hidden' => false,
            ],
            'heartrate_series' => [
                'icon' => 'o-heart',
                'display_name' => 'Heart Rate Series',
                'description' => 'A series of heart rate measurements',
                'hidden' => false,
            ],
            'oura_daily_{$kind}' => [
                'icon' => 'o-calendar',
                'display_name' => 'Oura Daily Record',
                'description' => 'A daily record from Oura Ring',
                'hidden' => false,
            ],
            'oura_tag' => [
                'icon' => 'o-tag',
                'display_name' => 'Oura Tag',
                'description' => 'A tag from Oura Ring',
                'hidden' => false,
            ],
            'oura_mapped_value' => [
                'icon' => 'o-chart-bar',
                'display_name' => 'Oura Mapped Value',
                'description' => 'A mapped value from Oura Ring data',
                'hidden' => false,
            ],
            'cardiovascular_age' => [
                'icon' => 'o-heart',
                'display_name' => 'Cardiovascular Age',
                'description' => 'Estimated cardiovascular age measurement',
                'hidden' => false,
            ],
            'vo2_max' => [
                'icon' => 'o-fire',
                'display_name' => 'VO2 Max',
                'description' => 'Maximum oxygen consumption measurement',
                'hidden' => false,
            ],
            'enhanced_tag' => [
                'icon' => 'o-tag',
                'display_name' => 'Enhanced Tag',
                'description' => 'Enhanced tag with detailed metadata',
                'hidden' => false,
            ],
            'sleep_recommendation' => [
                'icon' => 'o-moon',
                'display_name' => 'Sleep Recommendation',
                'description' => 'Personalized sleep timing recommendation',
                'hidden' => false,
            ],
            'rest_period' => [
                'icon' => 'o-pause',
                'display_name' => 'Rest Period',
                'description' => 'Rest mode period with episodes',
                'hidden' => false,
            ],
            // Metadata object types used in blocks and processing
            'contributor' => [
                'icon' => 'o-puzzle-piece',
                'display_name' => 'Score Contributor',
                'description' => 'Individual component contributing to daily scores',
                'hidden' => true,
            ],
            'detail' => [
                'icon' => 'o-document-text',
                'display_name' => 'Detail Information',
                'description' => 'Detailed information or metadata',
                'hidden' => true,
            ],
            'calorie_burn' => [
                'icon' => 'o-fire',
                'display_name' => 'Calorie Burn',
                'description' => 'Calorie burn measurement',
                'hidden' => true,
            ],
            'average' => [
                'icon' => 'o-chart-bar',
                'display_name' => 'Average Value',
                'description' => 'Average measurement value',
                'hidden' => true,
            ],
            'stage_duration' => [
                'icon' => 'o-clock',
                'display_name' => 'Stage Duration',
                'description' => 'Duration of a specific sleep stage',
                'hidden' => true,
            ],
            'minimum' => [
                'icon' => 'o-minus',
                'display_name' => 'Minimum Value',
                'description' => 'Minimum measurement value',
                'hidden' => true,
            ],
            'maximum' => [
                'icon' => 'o-plus',
                'display_name' => 'Maximum Value',
                'description' => 'Maximum measurement value',
                'hidden' => true,
            ],
            'count' => [
                'icon' => 'o-hashtag',
                'display_name' => 'Count',
                'description' => 'Count or quantity measurement',
                'hidden' => true,
            ],
            'mood_state' => [
                'icon' => 'o-face-smile',
                'display_name' => 'Mood State',
                'description' => 'Mood or emotional state indicator',
                'hidden' => true,
            ],
            'user_tag' => [
                'icon' => 'o-tag',
                'display_name' => 'User Tag',
                'description' => 'User-defined tag or annotation',
                'hidden' => true,
            ],
            'tag_type_code' => [
                'icon' => 'o-code-bracket',
                'display_name' => 'Tag Type Code',
                'description' => 'Tag type classification code',
                'hidden' => true,
            ],
            'user_comment' => [
                'icon' => 'o-chat-bubble-left-right',
                'display_name' => 'User Comment',
                'description' => 'User-provided comment or note',
                'hidden' => true,
            ],
            'sleep_guidance' => [
                'icon' => 'o-light-bulb',
                'display_name' => 'Sleep Guidance',
                'description' => 'Sleep-related guidance or recommendation',
                'hidden' => true,
            ],
            'episode_count' => [
                'icon' => 'o-list-bullet',
                'display_name' => 'Episode Count',
                'description' => 'Number of episodes or occurrences',
                'hidden' => true,
            ],

            // Original missing object types from your list
            'oura_profile' => [
                'icon' => 'o-user',
                'display_name' => 'Oura Profile',
                'description' => 'User profile information from Oura Ring',
                'hidden' => false,
            ],
            'oura_daily_activity' => [
                'icon' => 'o-chart-bar',
                'display_name' => 'Daily Activity',
                'description' => 'Daily activity summary from Oura Ring',
                'hidden' => false,
            ],
            'oura_daily_sleep' => [
                'icon' => 'o-moon',
                'display_name' => 'Daily Sleep',
                'description' => 'Daily sleep summary from Oura Ring',
                'hidden' => false,
            ],
            'oura_daily_readiness' => [
                'icon' => 'o-battery-100',
                'display_name' => 'Daily Readiness',
                'description' => 'Daily readiness score from Oura Ring',
                'hidden' => false,
            ],
            'oura_session' => [
                'icon' => 'o-clock',
                'display_name' => 'Oura Session',
                'description' => 'Session data from Oura Ring',
                'hidden' => false,
            ],
            'oura_enhanced_tag' => [
                'icon' => 'o-tag',
                'display_name' => 'Enhanced Tag',
                'description' => 'Enhanced tag with metadata from Oura Ring',
                'hidden' => false,
            ],
            'oura_cardiovascular_age' => [
                'icon' => 'o-heart',
                'display_name' => 'Cardiovascular Age',
                'description' => 'Cardiovascular age measurement from Oura Ring',
                'hidden' => false,
            ],
            'oura_vo2_max' => [
                'icon' => 'o-fire',
                'display_name' => 'VO2 Max',
                'description' => 'VO2 Max measurement from Oura Ring',
                'hidden' => false,
            ],
            'oura_sleep_time' => [
                'icon' => 'o-moon',
                'display_name' => 'Sleep Time',
                'description' => 'Sleep timing data from Oura Ring',
                'hidden' => false,
            ],
            'oura_rest_mode_period' => [
                'icon' => 'o-pause',
                'display_name' => 'Rest Mode Period',
                'description' => 'Rest mode period data from Oura Ring',
                'hidden' => false,
            ],

            // Workout activity types (from Oura API activity field)
            'walking' => [
                'icon' => 'o-user',
                'display_name' => 'Walking',
                'description' => 'Walking workout activity',
                'hidden' => false,
            ],
            'running' => [
                'icon' => 'o-bolt',
                'display_name' => 'Running',
                'description' => 'Running workout activity',
                'hidden' => false,
            ],
            'cycling' => [
                'icon' => 'o-arrow-path',
                'display_name' => 'Cycling',
                'description' => 'Cycling workout activity',
                'hidden' => false,
            ],
            'strength_training' => [
                'icon' => 'o-fire',
                'display_name' => 'Strength Training',
                'description' => 'Strength training workout activity',
                'hidden' => false,
            ],
            'cardio_training' => [
                'icon' => 'o-heart',
                'display_name' => 'Cardio Training',
                'description' => 'Cardio training workout activity',
                'hidden' => false,
            ],
            'elliptical' => [
                'icon' => 'o-arrow-path',
                'display_name' => 'Elliptical',
                'description' => 'Elliptical workout activity',
                'hidden' => false,
            ],
            'swimming' => [
                'icon' => 'o-beaker',
                'display_name' => 'Swimming',
                'description' => 'Swimming workout activity',
                'hidden' => false,
            ],
            'yoga' => [
                'icon' => 'o-user',
                'display_name' => 'Yoga',
                'description' => 'Yoga workout activity',
                'hidden' => false,
            ],
            'pilates' => [
                'icon' => 'o-user',
                'display_name' => 'Pilates',
                'description' => 'Pilates workout activity',
                'hidden' => false,
            ],
            'rowing' => [
                'icon' => 'o-arrow-path',
                'display_name' => 'Rowing',
                'description' => 'Rowing workout activity',
                'hidden' => false,
            ],
            'hiking' => [
                'icon' => 'o-map',
                'display_name' => 'Hiking',
                'description' => 'Hiking workout activity',
                'hidden' => false,
            ],
            'basketball' => [
                'icon' => 'o-globe-americas',
                'display_name' => 'Basketball',
                'description' => 'Basketball workout activity',
                'hidden' => false,
            ],
            'football' => [
                'icon' => 'o-globe-americas',
                'display_name' => 'Football',
                'description' => 'Football workout activity',
                'hidden' => false,
            ],
            'tennis' => [
                'icon' => 'o-globe-americas',
                'display_name' => 'Tennis',
                'description' => 'Tennis workout activity',
                'hidden' => false,
            ],
            'soccer' => [
                'icon' => 'o-globe-americas',
                'display_name' => 'Soccer',
                'description' => 'Soccer workout activity',
                'hidden' => false,
            ],
            'baseball' => [
                'icon' => 'o-globe-americas',
                'display_name' => 'Baseball',
                'description' => 'Baseball workout activity',
                'hidden' => false,
            ],
            'volleyball' => [
                'icon' => 'o-globe-americas',
                'display_name' => 'Volleyball',
                'description' => 'Volleyball workout activity',
                'hidden' => false,
            ],
            'badminton' => [
                'icon' => 'o-globe-americas',
                'display_name' => 'Badminton',
                'description' => 'Badminton workout activity',
                'hidden' => false,
            ],
            'table_tennis' => [
                'icon' => 'o-globe-americas',
                'display_name' => 'Table Tennis',
                'description' => 'Table tennis workout activity',
                'hidden' => false,
            ],
            'golf' => [
                'icon' => 'o-globe-americas',
                'display_name' => 'Golf',
                'description' => 'Golf workout activity',
                'hidden' => false,
            ],
            'martial_arts' => [
                'icon' => 'o-fire',
                'display_name' => 'Martial Arts',
                'description' => 'Martial arts workout activity',
                'hidden' => false,
            ],
            'boxing' => [
                'icon' => 'o-fire',
                'display_name' => 'Boxing',
                'description' => 'Boxing workout activity',
                'hidden' => false,
            ],
            'dancing' => [
                'icon' => 'o-musical-note',
                'display_name' => 'Dancing',
                'description' => 'Dancing workout activity',
                'hidden' => false,
            ],
            'climbing' => [
                'icon' => 'o-map',
                'display_name' => 'Climbing',
                'description' => 'Climbing workout activity',
                'hidden' => false,
            ],
            'skiing' => [
                'icon' => 'o-map',
                'display_name' => 'Skiing',
                'description' => 'Skiing workout activity',
                'hidden' => false,
            ],
            'snowboarding' => [
                'icon' => 'o-map',
                'display_name' => 'Snowboarding',
                'description' => 'Snowboarding workout activity',
                'hidden' => false,
            ],
            'skating' => [
                'icon' => 'o-arrow-path',
                'display_name' => 'Skating',
                'description' => 'Skating workout activity',
                'hidden' => false,
            ],
            'surfing' => [
                'icon' => 'o-beaker',
                'display_name' => 'Surfing',
                'description' => 'Surfing workout activity',
                'hidden' => false,
            ],
            'other' => [
                'icon' => 'o-ellipsis-horizontal',
                'display_name' => 'Other Activity',
                'description' => 'Other workout activity',
                'hidden' => false,
            ],
            'workout' => [
                'icon' => 'o-fire',
                'display_name' => 'Workout',
                'description' => 'Generic workout activity',
                'hidden' => false,
            ],
        ];
    }

    public static function getIdentifier(): string
    {
        return 'oura';
    }

    public static function getDisplayName(): string
    {
        return 'Oura';
    }

    public static function getDescription(): string
    {
        return 'Connect your Oura Ring to track daily activity, sleep, readiness, resilience, stress, workouts, sessions, tags, and time-series metrics like heart rate and SpO2.';
    }

    public static function getValueMappings(): array
    {
        return [
            'stress_day_summary' => [
                'field_name' => 'day_summary',
                'mappings' => [
                    'stressful' => 3,
                    'normal' => 2,
                    'restored' => 1,  // Fixed: API uses 'restored' not 'restful'
                    null => 0,
                ],
                'display_mappings' => [
                    3 => 'Stressful',
                    2 => 'Normal',
                    1 => 'Restored',  // Fixed: Updated display text
                    0 => 'No Data',
                ],
                'unit' => 'stress_level',
            ],
            'resilience_level' => [
                'field_name' => 'level',
                'mappings' => [
                    'exceptional' => 5,  // Fixed: API uses 'exceptional' not 'excellent'
                    'strong' => 4,       // Fixed: API includes 'strong' level
                    'solid' => 3,        // Fixed: Moved from 4 to 3
                    'adequate' => 2,     // Fixed: Moved from 3 to 2
                    'limited' => 1,      // Fixed: Moved from 2 to 1
                    null => 0,
                ],
                'display_mappings' => [
                    5 => 'Exceptional',  // Fixed: Updated display text
                    4 => 'Strong',       // Fixed: New level
                    3 => 'Solid',
                    2 => 'Adequate',
                    1 => 'Limited',
                    0 => 'No Data',
                ],
                'unit' => 'resilience_level',
            ],
        ];
    }

    public static function getConfigurationSchema(): array
    {
        return [
            'update_frequency_minutes' => [
                'type' => 'number',
                'label' => 'Update frequency (minutes)',
                'default' => 60,
                'min' => 5,
                'max' => 1440,
            ],
            'days_back' => [
                'type' => 'number',
                'label' => 'Days back to fetch on each run',
                'default' => 7,
                'min' => 1,
                'max' => 30,
            ],
        ];
    }

    public static function getInstanceTypes(): array
    {
        return [
            // Existing endpoints (enhanced)
            'activity' => [
                'label' => 'Daily Activity',
                'schema' => self::getConfigurationSchema(),
            ],
            'sleep' => [
                'label' => 'Daily Sleep',
                'schema' => self::getConfigurationSchema(),
            ],
            'sleep_records' => [
                'label' => 'Sleep Records',
                'schema' => self::getConfigurationSchema(),
            ],
            'readiness' => [
                'label' => 'Daily Readiness',
                'schema' => self::getConfigurationSchema(),
            ],
            'resilience' => [
                'label' => 'Daily Resilience',
                'schema' => self::getConfigurationSchema(),
            ],
            'stress' => [
                'label' => 'Daily Stress',
                'schema' => self::getConfigurationSchema(),
            ],
            'workouts' => [
                'label' => 'Workouts',
                'schema' => self::getConfigurationSchema(),
            ],
            'sessions' => [
                'label' => 'Sessions',
                'schema' => self::getConfigurationSchema(),
            ],
            'tags' => [
                'label' => 'Tags',
                'schema' => self::getConfigurationSchema(),
            ],
            'heartrate' => [
                'label' => 'Heart Rate (time series)',
                'schema' => self::getConfigurationSchema(),
            ],
            'spo2' => [
                'label' => 'Daily SpO2',
                'schema' => self::getConfigurationSchema(),
            ],

            // New API v2 endpoints
            'cardiovascular_age' => [
                'label' => 'Cardiovascular Age',
                'schema' => self::getConfigurationSchema(),
            ],
            'vo2_max' => [
                'label' => 'VO2 Max',
                'schema' => self::getConfigurationSchema(),
            ],
            'enhanced_tag' => [
                'label' => 'Enhanced Tags',
                'schema' => self::getConfigurationSchema(),
            ],
            'sleep_time' => [
                'label' => 'Sleep Recommendations',
                'schema' => self::getConfigurationSchema(),
            ],
            'rest_mode_period' => [
                'label' => 'Rest Mode Periods',
                'schema' => self::getConfigurationSchema(),
            ],
        ];
    }

    public function mapValueForStorage(string $mappingKey, mixed $value): ?float
    {
        $mappings = static::getValueMappings();

        if (! isset($mappings[$mappingKey])) {
            return is_numeric($value) ? (float) $value : null;
        }

        $mapping = $mappings[$mappingKey]['mappings'];

        return $mapping[$value] ?? $mapping[null] ?? null;
    }

    public function mapValueForDisplay(string $mappingKey, ?float $numericValue): string
    {
        $mappings = static::getValueMappings();

        if (! isset($mappings[$mappingKey]) || $numericValue === null) {
            return 'No Data';
        }

        $displayMappings = $mappings[$mappingKey]['display_mappings'];

        return $displayMappings[(int) $numericValue] ?? 'Unknown';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getOAuthUrl(IntegrationGroup $group): string
    {
        // Use cloud.ouraring.com for authorization endpoint
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $csrfToken = Str::random(32);
        $sessionKey = 'oauth_csrf_' . session_id() . '_' . $group->id;
        Session::put($sessionKey, $csrfToken);

        $state = encrypt([
            'group_id' => $group->id,
            'user_id' => $group->user_id,
            'csrf_token' => $csrfToken,
            'code_verifier' => $codeVerifier,
        ]);

        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => $this->getRequiredScopes(),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return $this->authUrl . '/oauth/authorize?' . http_build_query($params);
    }

    public function handleOAuthCallback(Request $request, IntegrationGroup $group): void
    {
        $error = $request->get('error');
        if ($error) {
            Log::error('Oura OAuth callback returned error', [
                'group_id' => $group->id,
                'error' => $error,
                'error_description' => $request->get('error_description'),
            ]);
            throw new Exception('Oura authorization failed: ' . $error);
        }

        $code = $request->get('code');
        if (! $code) {
            Log::error('Oura OAuth callback missing authorization code', [
                'group_id' => $group->id,
            ]);
            throw new Exception('Invalid OAuth callback: missing authorization code');
        }

        $state = $request->get('state');
        if (! $state) {
            Log::error('Oura OAuth callback missing state parameter', [
                'group_id' => $group->id,
            ]);
            throw new Exception('Invalid OAuth callback: missing state parameter');
        }

        try {
            $stateData = decrypt($state);
        } catch (Throwable $e) {
            Log::error('Oura OAuth state decryption failed', [
                'group_id' => $group->id,
                'exception' => $e->getMessage(),
            ]);
            throw new Exception('Invalid OAuth callback: state decryption failed');
        }

        if ((string) ($stateData['group_id'] ?? '') !== (string) $group->id) {
            throw new Exception('Invalid state parameter');
        }

        if (! isset($stateData['csrf_token']) || ! $this->validateCsrfToken($stateData['csrf_token'], $group)) {
            throw new Exception('Invalid CSRF token');
        }

        $codeVerifier = $stateData['code_verifier'] ?? null;
        if (! $codeVerifier) {
            throw new Exception('Missing code verifier');
        }

        // Log the API request
        $this->logApiRequest('POST', '/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], [
            'client_id' => $this->clientId,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => '[REDACTED]', // PKCE code verifier
        ]);

        // Exchange code for tokens with PKCE against api.ouraring.com
        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('POST https://api.ouraring.com/oauth/token'));
        $response = Http::asForm()->post('https://api.ouraring.com/oauth/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
        $span?->finish();

        // Log the API response
        $this->logApiResponse('POST', '/oauth/token', $response->status(), $response->body(), $response->headers());

        if (! $response->successful()) {
            Log::error('Oura token exchange failed', [
                'response' => $response->body(),
                'status' => $response->status(),
            ]);
            throw new Exception('Failed to exchange code for tokens: ' . $response->body());
        }

        $tokenData = $response->json();

        // Update group with tokens
        $group->update([
            'access_token' => $tokenData['access_token'] ?? null,
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'expiry' => isset($tokenData['expires_in']) ? now()->addSeconds($tokenData['expires_in']) : null,
        ]);

        $this->fetchAccountInfoForGroup($group);
    }

    public function fetchData(Integration $integration): void
    {
        $type = $integration->instance_type ?? 'activity';
        $daysBack = (int) ($integration->configuration['days_back'] ?? 7);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        // Check if we should perform a sweep for this integration
        $this->performSweepIfNeeded($integration);

        if ($type === 'sleep') {
            $this->fetchDailySleep($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'sleep_records') {
            $this->fetchSleepRecords($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'activity') {
            $this->fetchDailyActivity($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'readiness') {
            $this->fetchDailyReadiness($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'resilience') {
            $this->fetchDailyResilience($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'stress') {
            $this->fetchDailyStress($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'workouts') {
            $this->fetchWorkouts($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'sessions') {
            $this->fetchSessions($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'tags') {
            $this->fetchTags($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'heartrate') {
            $this->fetchHeartRateSeries($integration, now()->subDays($daysBack)->toIso8601String(), now()->toIso8601String());

            return;
        }

        if ($type === 'spo2') {
            $this->fetchDailySpO2($integration, $startDate, $endDate);

            return;
        }

        // New API v2 endpoints
        if ($type === 'cardiovascular_age') {
            $this->fetchCardiovascularAge($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'vo2_max') {
            $this->fetchVO2Max($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'enhanced_tag') {
            $this->fetchEnhancedTags($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'sleep_time') {
            $this->fetchSleepTime($integration, $startDate, $endDate);

            return;
        }

        if ($type === 'rest_mode_period') {
            $this->fetchRestModePeriods($integration, $startDate, $endDate);

            return;
        }
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        // Not used for Oura (we directly create events), but required by interface
        return [];
    }

    // Public helper for migration processing: converts items into events per instance type
    public function processOuraMigrationItems(Integration $integration, string $instanceType, array $items): void
    {
        switch ($instanceType) {
            case 'activity':
                foreach ($items as $item) {
                    $this->createDailyRecordEvent($integration, 'activity', $item, [
                        'score_field' => 'score',
                        'contributors_field' => 'contributors',
                        'title' => 'Activity',
                        'value_unit' => 'percent',
                        'contributors_value_unit' => 'percent',
                        'details_fields' => ['steps', 'cal_total', 'equivalent_walking_distance', 'target_calories', 'non_wear_time'],
                    ]);
                }
                break;
            case 'sleep_records':
                foreach ($items as $item) {
                    $this->createSleepRecordFromItem($integration, $item);
                }
                break;
            case 'sleep':
                foreach ($items as $item) {
                    $this->createDailyRecordEvent($integration, 'sleep', $item, [
                        'score_field' => 'score',
                        'contributors_field' => 'contributors',
                        'title' => 'Sleep',
                        'value_unit' => 'percent',
                    ]);
                }
                break;
            case 'readiness':
                foreach ($items as $item) {
                    $this->createDailyRecordEvent($integration, 'readiness', $item, [
                        'score_field' => 'score',
                        'contributors_field' => 'contributors',
                        'title' => 'Readiness',
                        'value_unit' => 'percent',
                        'contributors_value_unit' => 'percent',
                    ]);
                }
                break;
            case 'resilience':
                foreach ($items as $item) {
                    $this->createDailyRecordEvent($integration, 'resilience', $item, [
                        'score_field' => 'resilience_score',
                        'contributors_field' => 'contributors',
                        'title' => 'Resilience',
                        'value_unit' => 'percent',
                        'contributors_value_unit' => 'percent',
                    ]);
                }
                break;
            case 'stress':
                foreach ($items as $item) {
                    $this->createDailyRecordEvent($integration, 'stress', $item, [
                        'score_field' => 'stress_score',
                        'contributors_field' => 'contributors',
                        'title' => 'Stress',
                        'value_unit' => 'percent',
                        'contributors_value_unit' => 'percent',
                    ]);
                }
                break;
            case 'spo2':
                foreach ($items as $item) {
                    $this->createDailyRecordEvent($integration, 'spo2', $item, [
                        'score_field' => 'spo2_percentage.average',
                        'contributors_field' => null,
                        'title' => 'SpO2',
                        'value_unit' => 'percent',
                    ]);
                }
                break;
            case 'workouts':
                foreach ($items as $item) {
                    $this->createWorkoutEvent($integration, $item);
                }
                break;
            case 'sessions':
                foreach ($items as $item) {
                    $this->createSessionEvent($integration, $item);
                }
                break;
            case 'tags':
                foreach ($items as $item) {
                    $this->createTagEvent($integration, $item);
                }
                break;
            default:
                // leave unsupported types to other paths
                break;
        }
    }

    /**
     * Public helper for migration: fetch a window for a given instance type with headers/status.
     * Cursor: start_date/end_date (Y-m-d) or start_datetime/end_datetime (ISO8601 for heartrate)
     * Returns array with keys: ok, status, headers, items
     */
    public function fetchWindowWithMeta(Integration $integration, string $instanceType, array $cursor): array
    {
        $endpoint = null;
        $query = [];
        if ($instanceType === 'heartrate') {
            $endpoint = '/usercollection/heartrate';
            $query = [
                'start_datetime' => $cursor['start_datetime'] ?? now()->subDays(6)->toIso8601String(),
                'end_datetime' => $cursor['end_datetime'] ?? now()->toIso8601String(),
            ];
        } else {
            $endpoint = match ($instanceType) {
                'activity' => '/usercollection/daily_activity',
                'sleep' => '/usercollection/daily_sleep',
                'sleep_records' => '/usercollection/sleep',
                'readiness' => '/usercollection/daily_readiness',
                'resilience' => '/usercollection/daily_resilience',
                'stress' => '/usercollection/daily_stress',
                'workouts' => '/usercollection/workout',
                'sessions' => '/usercollection/session',
                'tags' => '/usercollection/tag',
                'spo2' => '/usercollection/daily_spo2',
                default => null,
            };
            $query = [
                'start_date' => $cursor['start_date'] ?? now()->subDays(29)->toDateString(),
                'end_date' => $cursor['end_date'] ?? now()->toDateString(),
            ];
        }

        if (! $endpoint) {
            return [
                'ok' => false,
                'status' => 400,
                'headers' => [],
                'items' => [],
            ];
        }

        // Token handling like getJson, but we need headers/status
        $group = $integration->group;
        $token = $group?->access_token;
        if ($group && $group->expiry && $group->expiry->isPast()) {
            $this->refreshToken($group);
            $token = $group->access_token;
        }
        if (empty($token)) {
            return [
                'ok' => false,
                'status' => 401,
                'headers' => [],
                'items' => [],
            ];
        }

        // Log the API request
        $this->logApiRequest('GET', $endpoint, [
            'Authorization' => '[REDACTED]',
        ], $query, $integration->id);

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $desc = 'GET ' . $this->baseUrl . $endpoint . (! empty($query) ? '?' . http_build_query($query) : '');
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription($desc));
        $response = Http::withToken($token)->get($this->baseUrl . $endpoint, $query);
        $span?->finish();

        // Log the API response
        $this->logApiResponse('GET', $endpoint, $response->status(), $response->body(), $response->headers(), $integration->id);

        $ok = $response->successful();
        $status = $response->status();
        $headers = $response->headers();
        $json = $ok ? ($response->json() ?? []) : [];
        $items = $json['data'] ?? $json ?? [];

        if (! $ok) {
            Log::warning('Oura window fetch failed', [
                'endpoint' => $endpoint,
                'status' => $status,
                'response' => $response->body(),
            ]);
        }

        return [
            'ok' => $ok,
            'status' => $status,
            'headers' => $headers,
            'items' => is_array($items) ? $items : [],
        ];
    }

    /**
     * Log API request details for debugging
     */
    public function logApiRequest(string $method, string $endpoint, array $headers = [], array $data = [], ?string $integrationId = null): void
    {
        log_integration_api_request(
            static::getIdentifier(),
            $method,
            $endpoint,
            $this->sanitizeHeaders($headers),
            $this->sanitizeData($data),
            $integrationId ?: '',
            true // Use per-instance logging
        );
    }

    /**
     * Log API response details for debugging
     */
    public function logApiResponse(string $method, string $endpoint, int $statusCode, string $body, array $headers = [], ?string $integrationId = null): void
    {
        log_integration_api_response(
            static::getIdentifier(),
            $method,
            $endpoint,
            $statusCode,
            $this->sanitizeResponseBody($body),
            $this->sanitizeHeaders($headers),
            $integrationId ?: '',
            true // Use per-instance logging
        );
    }

    /**
     * HTTP helper that attaches Bearer token from the group and refreshes when needed.
     */
    /**
     * Get authentication headers for HTTP requests
     */
    public function authHeaders(Integration $integration): array
    {
        $group = $integration->group;
        $token = $group?->access_token;
        if ($group && $group->expiry && $group->expiry->isPast()) {
            $this->refreshToken($group);
            $token = $group->access_token;
        }

        if (empty($token)) {
            throw new Exception('Missing access token for authenticated request');
        }

        return [
            'Authorization' => 'Bearer ' . $token,
        ];
    }

    /**
     * Generic daily record event creator with contributory blocks.
     * Options: score_field, contributors_field, title, value_unit, details_fields
     */
    public function createDailyRecordEvent(Integration $integration, string $kind, array $item, array $options): void
    {
        $day = $item['day'] ?? $item['date'] ?? null;
        if (! $day) {
            return;
        }

        $sourceId = "oura_{$kind}_{$integration->id}_{$day}";
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $this->ensureUserProfile($integration);
        $target = EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'metric',
            'type' => "oura_daily_{$kind}",
            'title' => $options['title'] ?? Str::title($kind),
        ], [
            'time' => $day . ' 00:00:00',
            'content' => ($options['title'] ?? Str::title($kind)) . ' daily summary',
            'metadata' => $item,
        ]);

        $scoreField = $options['score_field'] ?? 'score';
        $score = Arr::get($item, $scoreField);

        // Don't create event if score field is missing
        if ($score === null) {
            return;
        }

        [$encodedScore, $scoreMultiplier] = $this->encodeNumericValue(is_numeric($score) ? (float) $score : null);

        // Action mapping for daily score-based instances
        $actionMap = [
            'activity' => 'had_activity_score',
            'sleep' => 'had_sleep_score',
            'readiness' => 'had_readiness_score',
            'resilience' => 'had_resilience_score',
            'stress' => 'had_stress_score',
            'spo2' => 'had_spo2',
        ];
        $action = $actionMap[$kind] ?? 'scored';

        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $day . ' 00:00:00',
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => self::getDomain(),
            'action' => $action,
            'value' => $encodedScore,
            'value_multiplier' => $scoreMultiplier,
            'value_unit' => $options['value_unit'] ?? 'score',
            'event_metadata' => [
                'day' => $day,
                'kind' => $kind,
            ],
            'target_id' => $target->id,
        ]);

        $contributorsField = $options['contributors_field'] ?? null;
        $contributors = $contributorsField ? Arr::get($item, $contributorsField, []) : [];
        foreach ($contributors as $name => $value) {
            [$encodedContrib, $contribMultiplier] = $this->encodeNumericValue(is_numeric($value) ? (float) $value : null);
            $event->createBlock([
                'block_type' => 'contributors',
                'time' => $event->time,
                'title' => Str::title(str_replace('_', ' ', (string) $name)),
                'metadata' => ['type' => 'contributor', 'field' => $name],
                'value' => $encodedContrib,
                'value_multiplier' => $contribMultiplier,
                'value_unit' => $options['contributors_value_unit'] ?? $options['value_unit'] ?? 'score',
            ]);
        }

        $detailsFields = $options['details_fields'] ?? [];
        if (! empty($detailsFields)) {
            $unitMap = [
                'steps' => 'count',
                'cal_total' => 'kcal',
                'equivalent_walking_distance' => 'km',
                'target_calories' => 'kcal',
                'non_wear_time' => 'seconds',
            ];
            foreach ($detailsFields as $field) {
                if (! array_key_exists($field, $item)) {
                    continue;
                }
                $label = Str::title(str_replace('_', ' ', $field));
                $value = $item[$field];
                [$encodedDetail, $detailMultiplier] = $this->encodeNumericValue(is_numeric($value) ? (float) $value : null);
                $event->createBlock([
                    'block_type' => 'activity_metrics',
                    'time' => $event->time,
                    'title' => $label,
                    'metadata' => ['type' => 'detail', 'field' => $field],
                    'value' => $encodedDetail,
                    'value_multiplier' => $detailMultiplier,
                    'value_unit' => $unitMap[$field] ?? null,
                ]);
            }
        }
    }

    public function createWorkoutEvent(Integration $integration, array $item): void
    {
        $start = Arr::get($item, 'start_datetime');
        $end = Arr::get($item, 'end_datetime');
        $day = $start ? Str::substr($start, 0, 10) : (Arr::get($item, 'day') ?? now()->toDateString());
        $sourceId = "oura_workout_{$integration->id}_" . (Arr::get($item, 'id') ?? ($day . '_' . md5(json_encode($item))));
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $this->ensureUserProfile($integration);
        $target = EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'workout',
            'type' => Arr::get($item, 'activity', 'workout'),
            'title' => Str::title((string) Arr::get($item, 'activity', 'Workout')),
        ], [
            'time' => $start ?? ($day . ' 00:00:00'),
            'content' => 'Oura workout session',
            'metadata' => $item,
        ]);

        $durationSec = (int) Arr::get($item, 'duration', 0);
        $calories = (float) Arr::get($item, 'calories', Arr::get($item, 'total_calories', 0));
        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $start ?? ($day . ' 00:00:00'),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => self::getDomain(),
            'action' => 'did_workout',
            'value' => $durationSec,
            'value_multiplier' => 1,
            'value_unit' => 'seconds',
            'event_metadata' => [
                'end' => $end,
                'calories' => $calories,
            ],
            'target_id' => $target->id,
        ]);

        [$encodedCalories, $calMultiplier] = $this->encodeNumericValue($calories);
        $event->createBlock([
            'block_type' => 'workout_metrics',
            'time' => $event->time,
            'title' => 'Calories',
            'metadata' => ['type' => 'calorie_burn', 'estimated' => true],
            'value' => $encodedCalories,
            'value_multiplier' => $calMultiplier,
            'value_unit' => 'kcal',
        ]);

        $avgHr = Arr::get($item, 'average_heart_rate');
        if ($avgHr !== null) {
            [$encodedAvgHr, $avgHrMultiplier] = $this->encodeNumericValue($avgHr);
            $event->createBlock([
                'block_type' => 'heart_rate',
                'time' => $event->time,
                'title' => 'Average Heart Rate',
                'metadata' => ['type' => 'average', 'context' => 'workout'],
                'value' => $encodedAvgHr,
                'value_multiplier' => $avgHrMultiplier,
                'value_unit' => 'bpm',
            ]);
        }
    }

    /**
     * Encode a numeric value into an integer with a multiplier to retain precision.
     * If the value has a fractional part, scale by 1000 and round.
     * Returns [encodedInt|null, multiplier|null].
     */
    public function encodeNumericValue(null|int|float|string $raw, int $defaultMultiplier = 1): array
    {
        if ($raw === null || $raw === '') {
            return [null, null];
        }
        $float = (float) $raw;
        if (! is_finite($float)) {
            return [null, null];
        }
        if (fmod($float, 1.0) !== 0.0) {
            $multiplier = 1000;
            $intValue = (int) round($float * $multiplier);

            return [$intValue, $multiplier];
        }

        return [(int) $float, $defaultMultiplier];
    }

    /**
     * Helper method to create or update objects.
     */
    public function createOrUpdateObject(Integration $integration, array $objectData): EventObject
    {
        return EventObject::updateOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => $objectData['concept'],
                'type' => $objectData['type'],
                'title' => $objectData['title'],
            ],
            [
                'time' => $objectData['time'] ?? now(),
                'content' => $objectData['content'] ?? null,
                'metadata' => $objectData['metadata'] ?? [],
                'url' => $objectData['url'] ?? null,
                'media_url' => $objectData['image_url'] ?? null,
                'embeddings' => $objectData['embeddings'] ?? null,
            ]
        );
    }

    /**
     * Create events safely with race condition protection
     */
    public function createEventsSafely(Integration $integration, array $eventData): void
    {
        foreach ($eventData as $data) {
            // Use updateOrCreate to prevent race conditions
            $event = Event::updateOrCreate(
                [
                    'integration_id' => $integration->id,
                    'source_id' => $data['source_id'],
                ],
                [
                    'time' => $data['time'],
                    'actor_id' => $this->createOrUpdateObject($integration, $data['actor'])->id,
                    'service' => 'oura',
                    'domain' => $data['domain'],
                    'action' => $data['action'],
                    'value' => $data['value'] ?? null,
                    'value_multiplier' => $data['value_multiplier'] ?? 1,
                    'value_unit' => $data['value_unit'] ?? null,
                    'event_metadata' => $data['event_metadata'] ?? [],
                    'target_id' => $this->createOrUpdateObject($integration, $data['target'])->id,
                ]
            );

            // Create blocks if any using the new unique creation method
            if (isset($data['blocks'])) {
                foreach ($data['blocks'] as $blockData) {
                    $event->createBlock([
                        'time' => $blockData['time'] ?? $event->time,
                        'block_type' => $blockData['block_type'] ?? '',
                        'title' => $blockData['title'],
                        'metadata' => $blockData['metadata'] ?? [],
                        'url' => $blockData['url'] ?? null,
                        'media_url' => $blockData['media_url'] ?? null,
                        'value' => $blockData['value'] ?? null,
                        'value_multiplier' => $blockData['value_multiplier'] ?? 1,
                        'value_unit' => $blockData['value_unit'] ?? null,
                        'embeddings' => $blockData['embeddings'] ?? null,
                    ]);
                }
            }

            Log::info('Oura: Created event safely', [
                'integration_id' => $integration->id,
                'source_id' => $data['source_id'],
                'action' => $data['action'],
            ]);
        }
    }

    public function getJson(string $endpoint, Integration $integration, array $query = []): array
    {
        $group = $integration->group;
        $token = $group?->access_token;
        if ($group && $group->expiry && $group->expiry->isPast()) {
            $this->refreshToken($group);
            $token = $group->access_token;
        }

        if (empty($token)) {
            throw new Exception('Missing access token for authenticated request');
        }

        // Log the API request
        $this->logApiRequest('GET', $endpoint, [
            'Authorization' => '[REDACTED]',
        ], $query, $integration->id);

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $desc = 'GET ' . $this->baseUrl . $endpoint . (! empty($query) ? '?' . http_build_query($query) : '');
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription($desc));
        $response = Http::withToken($token)->get($this->baseUrl . $endpoint, $query);
        $span?->finish();

        // Log the API response
        $this->logApiResponse('GET', $endpoint, $response->status(), $response->body(), $response->headers(), $integration->id);

        if (! $response->successful()) {
            Log::warning('Oura API request failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return [];
        }

        return $response->json();
    }

    /**
     * Pull activity data for pull jobs
     */
    public function pullActivityData(Integration $integration): array
    {
        $config = $integration->configuration ?? [];
        // Incremental window 3 days; daily sweep 30 days
        $incrementalDays = max(2, (int) ($config['oura_incremental_days'] ?? 3));
        $startDate = now()->subDays($incrementalDays)->toDateString();
        $endDate = now()->toDateString();
        $lastSweepAt = isset($config['oura_last_sweep_at']) ? Carbon::parse($config['oura_last_sweep_at']) : null;
        $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subHours(22));

        $data = $this->getJson('/usercollection/daily_activity', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $items = $data['data'] ?? [];
        if ($doSweep) {
            $sweepData = $this->getJson('/usercollection/daily_activity', $integration, [
                'start_date' => now()->subDays(30)->toDateString(),
                'end_date' => $endDate,
            ]);
            $sweepItems = $sweepData['data'] ?? [];
            if (! empty($sweepItems)) {
                // Merge unique by date
                $byDate = [];
                foreach (array_merge($items, $sweepItems) as $row) {
                    $key = $row['day'] ?? ($row['date'] ?? null);
                    if ($key) {
                        $byDate[$key] = $row;
                    }
                }
                $items = array_values($byDate);
                $config['oura_last_sweep_at'] = now()->toIso8601String();
                $integration->update(['configuration' => $config]);
            }
        }

        return $items;
    }

    /**
     * Pull heartrate data for pull jobs
     */
    public function pullHeartrateData(Integration $integration): array
    {
        $config = $integration->configuration ?? [];
        $incrementalDays = max(2, (int) ($config['oura_incremental_days'] ?? 3));
        $startDatetime = now()->subDays($incrementalDays)->toIso8601String();
        $endDatetime = now()->toIso8601String();
        $lastSweepAt = isset($config['oura_last_sweep_at']) ? Carbon::parse($config['oura_last_sweep_at']) : null;
        $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subHours(22));

        $data = $this->getJson('/usercollection/heartrate', $integration, [
            'start_datetime' => $startDatetime,
            'end_datetime' => $endDatetime,
        ]);

        $items = $data['data'] ?? [];

        if ($doSweep) {
            $sweepData = $this->getJson('/usercollection/heartrate', $integration, [
                'start_datetime' => now()->subDays(7)->toIso8601String(),
                'end_datetime' => $endDatetime,
            ]);
            $sweepItems = $sweepData['data'] ?? [];
            if (! empty($sweepItems)) {
                // Merge unique by timestamp and source
                $byKey = [];
                foreach (array_merge($items, $sweepItems) as $row) {
                    $key = ($row['timestamp'] ?? '') . '|' . ($row['source'] ?? '');
                    if ($key !== '|') {
                        $byKey[$key] = $row;
                    }
                }
                $items = array_values($byKey);
                $config['oura_last_sweep_at'] = now()->toIso8601String();
                $integration->update(['configuration' => $config]);
            }
        }

        return $items;
    }

    /**
     * Pull readiness data for pull jobs
     */
    public function pullReadinessData(Integration $integration): array
    {
        $daysBack = (int) ($integration->configuration['days_back'] ?? 7);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        $data = $this->getJson('/usercollection/daily_readiness', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return $data['data'] ?? [];
    }

    /**
     * Pull resilience data for pull jobs
     */
    public function pullResilienceData(Integration $integration): array
    {
        $daysBack = (int) ($integration->configuration['days_back'] ?? 7);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        $data = $this->getJson('/usercollection/daily_resilience', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return $data['data'] ?? [];
    }

    /**
     * Pull sessions data for pull jobs
     */
    public function pullSessionsData(Integration $integration): array
    {
        $config = $integration->configuration ?? [];
        $incrementalDays = max(2, (int) ($config['oura_incremental_days'] ?? 3));
        $startDate = now()->subDays($incrementalDays)->toDateString();
        $endDate = now()->toDateString();
        $lastSweepAt = isset($config['oura_last_sweep_at']) ? Carbon::parse($config['oura_last_sweep_at']) : null;
        $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subHours(22));

        $data = $this->getJson('/usercollection/session', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $items = $data['data'] ?? [];

        if ($doSweep) {
            $sweepData = $this->getJson('/usercollection/session', $integration, [
                'start_date' => now()->subDays(30)->toDateString(),
                'end_date' => $endDate,
            ]);
            $sweepItems = $sweepData['data'] ?? [];
            if (! empty($sweepItems)) {
                // Merge unique by day/date
                $byDay = [];
                foreach (array_merge($items, $sweepItems) as $row) {
                    $key = $row['day'] ?? ($row['date'] ?? null);
                    if ($key) {
                        $byDay[$key] = $row;
                    }
                }
                $items = array_values($byDay);
                $config['oura_last_sweep_at'] = now()->toIso8601String();
                $integration->update(['configuration' => $config]);
            }
        }

        return $items;
    }

    /**
     * Pull sleep data for pull jobs
     */
    public function pullSleepData(Integration $integration): array
    {
        $config = $integration->configuration ?? [];
        $incrementalDays = max(2, (int) ($config['oura_incremental_days'] ?? 3));
        $startDate = now()->subDays($incrementalDays)->toDateString();
        $endDate = now()->toDateString();
        $lastSweepAt = isset($config['oura_last_sweep_at']) ? Carbon::parse($config['oura_last_sweep_at']) : null;
        $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subHours(22));

        $data = $this->getJson('/usercollection/daily_sleep', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $items = $data['data'] ?? [];

        if ($doSweep) {
            $sweepData = $this->getJson('/usercollection/daily_sleep', $integration, [
                'start_date' => now()->subDays(30)->toDateString(),
                'end_date' => $endDate,
            ]);
            $sweepItems = $sweepData['data'] ?? [];
            if (! empty($sweepItems)) {
                // Merge unique by day/date
                $byDay = [];
                foreach (array_merge($items, $sweepItems) as $row) {
                    $key = $row['day'] ?? ($row['date'] ?? null);
                    if ($key) {
                        $byDay[$key] = $row;
                    }
                }
                $items = array_values($byDay);
                $config['oura_last_sweep_at'] = now()->toIso8601String();
                $integration->update(['configuration' => $config]);
            }
        }

        return $items;
    }

    /**
     * Pull sleep records data for pull jobs
     */
    public function pullSleepRecordsData(Integration $integration): array
    {
        $daysBack = (int) ($integration->configuration['days_back'] ?? 7);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        $data = $this->getJson('/usercollection/sleep', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return $data['data'] ?? [];
    }

    /**
     * Pull SpO2 data for pull jobs
     */
    public function pullSpo2Data(Integration $integration): array
    {
        $daysBack = (int) ($integration->configuration['days_back'] ?? 7);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        try {
            $data = $this->getJson('/usercollection/daily_spo2', $integration, [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            return $data['data'] ?? [];
        } catch (Exception $e) {
            // Handle authorization errors gracefully
            if (str_contains($e->getMessage(), '403') || str_contains($e->getMessage(), 'authorization')) {
                Log::warning('Oura SpO2 access not authorized for integration', [
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ]);

                return []; // Return empty array to skip processing
            }

            throw $e;
        }
    }

    /**
     * Pull stress data for pull jobs
     */
    public function pullStressData(Integration $integration): array
    {
        $daysBack = (int) ($integration->configuration['days_back'] ?? 7);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        $data = $this->getJson('/usercollection/daily_stress', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return $data['data'] ?? [];
    }

    /**
     * Pull tags data for pull jobs
     */
    public function pullTagsData(Integration $integration): array
    {
        $daysBack = (int) ($integration->configuration['days_back'] ?? 7);
        $startDate = now()->subDays($daysBack)->toDateString();
        $endDate = now()->toDateString();

        $data = $this->getJson('/usercollection/tag', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return $data['data'] ?? [];
    }

    /**
     * Pull workouts data for pull jobs
     */
    public function pullWorkoutsData(Integration $integration): array
    {
        $config = $integration->configuration ?? [];
        $incrementalDays = max(2, (int) ($config['oura_incremental_days'] ?? 3));
        $startDate = now()->subDays($incrementalDays)->toDateString();
        $endDate = now()->toDateString();

        $data = $this->getJson('/usercollection/workout', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return $data['data'] ?? [];
    }

    public function ensureUserProfile(Integration $integration): EventObject
    {
        $info = $this->getJson('/usercollection/personal_info', $integration);
        $data = Arr::first($info['data'] ?? []) ?? $info;
        $profile = [
            'user_id' => $integration->group?->account_id,
            'email' => Arr::get($data, 'email'),
            'age' => Arr::get($data, 'age'),
            'biological_sex' => Arr::get($data, 'biological_sex'),
            'weight' => Arr::get($data, 'weight'),
            'height' => Arr::get($data, 'height'),
            'dominant_hand' => Arr::get($data, 'dominant_hand'),
        ];

        return $this->createOrUpdateUser($integration, $profile);
    }

    /**
     * Perform a sweep if needed for any instance type
     */
    protected function performSweepIfNeeded(Integration $integration): void
    {
        $config = $integration->configuration ?? [];
        $lastSweepAt = isset($config['oura_last_sweep_at']) ? Carbon::parse($config['oura_last_sweep_at']) : null;
        $doSweep = ! $lastSweepAt || $lastSweepAt->lt(now()->subHours(22));

        // Only perform sweep for specific instance types that benefit from it
        // Skip sweep for specific instance types to avoid duplicate events
        $skipSweepTypes = ['readiness', 'resilience', 'stress', 'spo2'];
        $instanceType = $integration->instance_type ?? 'activity';

        if ($doSweep && ! in_array($instanceType, $skipSweepTypes)) {
            Log::info('Oura sweep triggered', [
                'integration_id' => $integration->id,
                'instance_type' => $integration->instance_type,
                'last_sweep_at' => $lastSweepAt?->toIso8601String(),
            ]);

            // Perform sweep for all data types
            $this->performDataSweep($integration);

            // Update sweep timestamp
            $config['oura_last_sweep_at'] = now()->toIso8601String();
            $integration->update(['configuration' => $config]);

            Log::info('Oura sweep completed', [
                'integration_id' => $integration->id,
                'instance_type' => $integration->instance_type,
            ]);
        } else {
            Log::info('Oura sweep skipped', [
                'integration_id' => $integration->id,
                'instance_type' => $integration->instance_type,
                'reason' => $doSweep ? 'instance_type_excluded' : 'recent_sweep',
                'last_sweep_at' => $lastSweepAt?->toIso8601String(),
            ]);
        }
    }

    /**
     * Perform the actual data sweep across all Oura data types
     */
    protected function performDataSweep(Integration $integration): void
    {
        $sweepStartDate = now()->subDays(30)->toDateString();
        $endDate = now()->toDateString();

        try {
            // Sweep workouts data
            $workoutData = $this->getJson('/usercollection/workout', $integration, [
                'start_date' => $sweepStartDate,
                'end_date' => $endDate,
            ]);
            $workoutItems = $workoutData['data'] ?? [];
            foreach ($workoutItems as $workout) {
                $this->createWorkoutEvent($integration, $workout);
            }

            // Sweep daily activity data
            $activityData = $this->getJson('/usercollection/daily_activity', $integration, [
                'start_date' => $sweepStartDate,
                'end_date' => $endDate,
            ]);
            $activityItems = $activityData['data'] ?? [];
            foreach ($activityItems as $activity) {
                $this->createDailyRecordEvent($integration, 'activity', $activity, [
                    'score_field' => 'score',
                    'contributors_field' => 'contributors',
                    'title' => 'Activity',
                    'value_unit' => 'percent',
                    'contributors_value_unit' => 'percent',
                    'details_fields' => ['steps', 'cal_total', 'equivalent_walking_distance', 'target_calories', 'non_wear_time'],
                ]);
            }

            // Sweep sleep data
            $sleepData = $this->getJson('/usercollection/daily_sleep', $integration, [
                'start_date' => $sweepStartDate,
                'end_date' => $endDate,
            ]);
            $sleepItems = $sleepData['data'] ?? [];
            foreach ($sleepItems as $sleep) {
                $this->createDailyRecordEvent($integration, 'sleep', $sleep, [
                    'score_field' => 'score',
                    'contributors_field' => 'contributors',
                    'title' => 'Sleep',
                    'value_unit' => 'percent',
                    'contributors_value_unit' => 'percent',
                    'details_fields' => ['total_sleep_duration', 'rem_sleep_duration', 'deep_sleep_duration', 'light_sleep_duration', 'awake_time', 'sleep_efficiency', 'bedtime_start', 'bedtime_end'],
                ]);
            }

            // Sweep readiness data
            $readinessData = $this->getJson('/usercollection/daily_readiness', $integration, [
                'start_date' => $sweepStartDate,
                'end_date' => $endDate,
            ]);
            $readinessItems = $readinessData['data'] ?? [];
            foreach ($readinessItems as $readiness) {
                $this->createDailyRecordEvent($integration, 'readiness', $readiness, [
                    'score_field' => 'score',
                    'contributors_field' => 'contributors',
                    'title' => 'Readiness',
                    'value_unit' => 'percent',
                    'contributors_value_unit' => 'percent',
                    'details_fields' => ['temperature_deviation', 'temperature_trend_deviation', 'resting_heart_rate', 'heart_rate_variability', 'recovery_index'],
                ]);
            }

            Log::info('Oura data sweep completed successfully', [
                'integration_id' => $integration->id,
                'workouts_count' => count($workoutItems),
                'activity_count' => count($activityItems),
                'sleep_count' => count($sleepItems),
                'readiness_count' => count($readinessItems),
            ]);

        } catch (Throwable $e) {
            Log::error('Oura data sweep failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    protected function getRequiredScopes(): string
    {
        return implode(' ', [
            'email',           // For personal_info endpoint
            'personal',        // For personal_info, ring_configuration endpoints
            'daily',           // For all daily_* endpoints (activity, readiness, resilience, sleep, spo2, stress, cardiovascular_age), vo2_max, sleep_time, rest_mode_period
            'heartrate',       // For heartrate time-series endpoint
            'workout',         // For workout endpoint
            'tag',             // For tag, enhanced_tag endpoints
            'session',         // For session endpoint (mindfulness/meditation)
            'spo2',            // For daily_spo2 endpoint (if separate scope required)
            'stress',          // For daily_stress endpoint (if separate scope required)
            'resilience',      // For daily_resilience endpoint (if separate scope required)
            'heart_health',    // For heart_health (vO2 Max, CVA)
        ]);
    }

    protected function refreshToken(IntegrationGroup $group): void
    {
        // Log the API request
        $this->logApiRequest('POST', '/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], [
            'client_id' => $this->clientId,
            'grant_type' => 'refresh_token',
        ]);

        $hub = SentrySdk::getCurrentHub();
        $parentSpan = $hub->getSpan();
        $span = $parentSpan?->startChild((new SpanContext)->setOp('http.client')->setDescription('POST https://api.ouraring.com/oauth/token'));
        $response = Http::asForm()->post('https://api.ouraring.com/oauth/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $group->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        $span?->finish();

        // Log the API response
        $this->logApiResponse('POST', '/oauth/token', $response->status(), $response->body(), $response->headers());

        if (! $response->successful()) {
            throw new Exception('Failed to refresh token');
        }

        $tokenData = $response->json();

        $group->update([
            'access_token' => $tokenData['access_token'] ?? $group->access_token,
            'refresh_token' => $tokenData['refresh_token'] ?? $group->refresh_token,
            'expiry' => isset($tokenData['expires_in']) ? now()->addSeconds($tokenData['expires_in']) : null,
        ]);
    }

    protected function fetchAccountInfoForGroup(IntegrationGroup $group): void
    {
        try {
            // Create a temp Integration bound to the group to reuse token handling
            $temp = new Integration;
            $temp->setRelation('group', $group);
            $info = $this->getJson('/usercollection/personal_info', $temp);

            // Extract account ID with proper fallback logic for Oura API response format
            // Oura personal_info endpoint returns: {"id": "...", "email": "...", ...}
            $accountId = Arr::get($info, 'id') ??           // Direct id field (current Oura API)
                        Arr::get($info, 'data.0.user_id') ?? // Legacy nested format
                        Arr::get($info, 'user_id') ??        // Legacy flat format
                        Arr::get($info, 'data.0.email') ??   // Fallback to email in nested format
                        Arr::get($info, 'email');            // Fallback to email in flat format

            if (! $accountId) {
                Log::warning('Oura fetchAccountInfoForGroup: No account ID found in API response', [
                    'group_id' => $group->id,
                    'api_response' => $info,
                ]);
                throw new Exception('Unable to extract account ID from Oura personal_info response');
            }

            $group->update([
                'account_id' => $accountId,
            ]);

            Log::info('Oura account info fetched successfully', [
                'group_id' => $group->id,
                'account_id' => $accountId,
            ]);

        } catch (Exception $e) {
            Log::error('Oura fetchAccountInfoForGroup failed', [
                'group_id' => $group->id,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Re-throw to fail the OAuth callback if account info can't be fetched
        }
    }

    protected function createOrUpdateUser(Integration $integration, array $profile = []): EventObject
    {
        $title = $integration->name ?: 'Oura Account';

        return EventObject::updateOrCreate(
            [
                'user_id' => $integration->user_id,
                'concept' => 'user',
                'type' => 'oura_user',
                'title' => $title,
            ],
            [
                'integration_id' => $integration->id,
                'time' => now(),
                'content' => 'Oura account',
                'metadata' => $profile,
                'url' => null,
                'media_url' => null,
            ]
        );
    }

    protected function fetchDailySleep(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/daily_sleep', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createDailyRecordEvent($integration, 'sleep', $item, [
                'score_field' => 'score',
                'contributors_field' => 'contributors',
                'title' => 'Sleep',
                'value_unit' => 'percent',
                'contributors_value_unit' => 'percent',
            ]);
        }
    }

    protected function fetchSleepRecords(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/sleep', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $start = Arr::get($item, 'bedtime_start');
            $end = Arr::get($item, 'bedtime_end');
            $day = $start ? Str::substr($start, 0, 10) : (Arr::get($item, 'day') ?? now()->toDateString());
            $id = Arr::get($item, 'id') ?? md5(json_encode([$day, Arr::get($item, 'duration', 0), Arr::get($item, 'total', 0)]));
            $sourceId = "oura_sleep_record_{$integration->id}_{$id}";
            $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
            if ($exists) {
                continue;
            }

            $actor = $this->ensureUserProfile($integration);
            $target = EventObject::updateOrCreate([
                'user_id' => $integration->user_id,
                'concept' => 'sleep',
                'type' => 'oura_sleep_record',
                'title' => 'Sleep Record',
            ], [
                'time' => $start ?? ($day . ' 00:00:00'),
                'content' => 'Detailed sleep record including stages and efficiency',
                'metadata' => $item,
            ]);

            // Use total_sleep_duration as the main value (matching our OuraSleepRecordsData job)
            $totalSleepDuration = (int) Arr::get($item, 'total_sleep_duration', Arr::get($item, 'duration', 0));
            $efficiency = Arr::get($item, 'efficiency');
            $event = Event::create([
                'source_id' => $sourceId,
                'time' => $start ?? ($day . ' 00:00:00'),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'service' => 'oura',
                'domain' => self::getDomain(),
                'action' => 'slept_for',
                'value' => $totalSleepDuration, // Use total_sleep_duration as main event value
                'value_multiplier' => 1,
                'value_unit' => 'seconds',
                'event_metadata' => [
                    'end' => $end,
                    'efficiency' => $efficiency,
                ],
                'target_id' => $target->id,
            ]);

            // Use actual API field names (not sleep_stages array)
            $sleepStages = [
                'deep_sleep_duration' => 'Deep Sleep',
                'light_sleep_duration' => 'Light Sleep',
                'rem_sleep_duration' => 'REM Sleep',
                'awake_time' => 'Awake Time',
            ];
            foreach ($sleepStages as $field => $title) {
                $seconds = Arr::get($item, $field);
                if ($seconds === null) {
                    continue;
                }
                $event->createBlock([
                    'block_type' => 'sleep_stages',
                    'time' => $event->time,
                    'integration_id' => $integration->id,
                    'title' => $title,
                    'metadata' => ['type' => 'stage_duration', 'field' => $field],
                    'value' => (int) $seconds,
                    'value_multiplier' => 1,
                    'value_unit' => 'seconds',
                ]);
            }

            $hrAvg = Arr::get($item, 'average_heart_rate');
            if ($hrAvg !== null) {
                [$encodedHrAvg, $hrAvgMultiplier] = $this->encodeNumericValue($hrAvg);
                $event->createBlock([
                    'block_type' => 'heart_rate',
                    'time' => $event->time,
                    'integration_id' => $integration->id,
                    'title' => 'Average Heart Rate',
                    'metadata' => ['type' => 'average', 'context' => 'sleep'],
                    'value' => $encodedHrAvg,
                    'value_multiplier' => $hrAvgMultiplier,
                    'value_unit' => 'bpm',
                ]);
            }
        }
    }

    protected function fetchDailyActivity(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/daily_activity', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createDailyRecordEvent($integration, 'activity', $item, [
                'score_field' => 'score',
                'contributors_field' => 'contributors',
                'title' => 'Activity',
                'value_unit' => 'percent',
                'contributors_value_unit' => 'percent',
                'details_fields' => [
                    'steps', 'cal_total', 'equivalent_walking_distance', 'target_calories', 'non_wear_time',
                ],
            ]);
        }
    }

    protected function fetchDailyReadiness(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/daily_readiness', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createDailyRecordEvent($integration, 'readiness', $item, [
                'score_field' => 'score',
                'contributors_field' => 'contributors',
                'title' => 'Readiness',
                'value_unit' => 'percent',
                'contributors_value_unit' => 'percent',
            ]);
        }
    }

    protected function fetchDailyResilience(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/daily_resilience', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            // Existing numeric score processing (if any)
            // Note: Resilience might not have a numeric score field, so this might not create events
            $this->createDailyRecordEvent($integration, 'resilience', $item, [
                'score_field' => 'resilience_score',
                'contributors_field' => 'contributors',
                'title' => 'Resilience',
                'value_unit' => 'percent',
                'contributors_value_unit' => 'percent',
            ]);

            // NEW: Process non-numeric level
            if (isset($item['level'])) {
                $this->createMappedValueEvent(
                    $integration,
                    'had_resilience_score',
                    $item['day'],
                    $item['level'],
                    'resilience_level'
                );
            }
        }
    }

    protected function fetchDailyStress(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/daily_stress', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            // Existing numeric score processing
            $this->createDailyRecordEvent($integration, 'stress', $item, [
                'score_field' => 'stress_score',
                'contributors_field' => 'contributors',
                'title' => 'Stress',
                'value_unit' => 'percent',
                'contributors_value_unit' => 'percent',
            ]);

            // NEW: Process non-numeric day_summary
            if (isset($item['day_summary'])) {
                $this->createMappedValueEvent(
                    $integration,
                    'had_stress_score',
                    $item['day'],
                    $item['day_summary'],
                    'stress_day_summary'
                );
            }
        }
    }

    protected function fetchDailySpO2(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/daily_spo2', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createDailyRecordEvent($integration, 'spo2', $item, [
                'score_field' => 'spo2_percentage.average',
                'contributors_field' => null,
                'title' => 'SpO2',
                'value_unit' => 'percent',
            ]);
        }
    }

    protected function fetchWorkouts(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/workout', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createWorkoutEvent($integration, $item);
        }
    }

    protected function fetchSessions(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/session', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createSessionEvent($integration, $item);
        }
    }

    protected function fetchTags(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/tag', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];
        foreach ($items as $item) {
            $this->createTagEvent($integration, $item);
        }
    }

    protected function fetchHeartRateSeries(Integration $integration, string $startIso, string $endIso): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/heartrate', $integration, [
            'start_datetime' => $startIso,
            'end_datetime' => $endIso,
        ]);
        $items = $json['data'] ?? [];
        if (empty($items)) {
            return;
        }

        // Aggregate to one event per day with a few summary blocks
        $byDay = collect($items)->groupBy(fn ($p) => Str::substr($p['timestamp'] ?? $p['start_datetime'] ?? '', 0, 10));
        foreach ($byDay as $day => $points) {
            $min = (int) collect($points)->min('bpm');
            $max = (int) collect($points)->max('bpm');
            $avg = (float) collect($points)->avg('bpm');

            $sourceId = "oura_heartrate_{$integration->id}_{$day}";
            $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
            if ($exists) {
                continue;
            }

            $actor = $this->ensureUserProfile($integration);
            $target = EventObject::updateOrCreate([
                'user_id' => $integration->user_id,
                'concept' => 'metric',
                'type' => 'heartrate_series',
                'title' => 'Heart Rate',
            ], [
                'time' => now(),
                'content' => 'Heart rate time series',
                'metadata' => [
                    'interval' => 'irregular',
                ],
            ]);

            [$encodedAvg, $avgMultiplier] = $this->encodeNumericValue($avg);
            $event = Event::create([
                'source_id' => $sourceId,
                'time' => $day . ' 00:00:00',
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'service' => 'oura',
                'domain' => self::getDomain(),
                'action' => 'had_heart_rate',
                'value' => $encodedAvg,
                'value_multiplier' => $avgMultiplier,
                'value_unit' => 'bpm',
                'event_metadata' => [
                    'day' => $day,
                    'min_bpm' => $min,
                    'max_bpm' => $max,
                    'avg_bpm' => $avg,
                ],
                'target_id' => $target->id,
            ]);

            // Replace summary with separate min/max blocks
            [$encMin, $minMult] = $this->encodeNumericValue($min);
            $event->createBlock([
                'block_type' => 'heart_rate',
                'time' => $event->time,
                'title' => 'Min Heart Rate',
                'metadata' => ['type' => 'minimum', 'context' => 'daily_series'],
                'value' => $encMin,
                'value_multiplier' => $minMult,
                'value_unit' => 'bpm',
            ]);

            [$encMax, $maxMult] = $this->encodeNumericValue($max);
            $event->createBlock([
                'block_type' => 'heart_rate',
                'time' => $event->time,
                'title' => 'Max Heart Rate',
                'metadata' => ['type' => 'maximum', 'context' => 'daily_series'],
                'value' => $encMax,
                'value_multiplier' => $maxMult,
                'value_unit' => 'bpm',
            ]);

            $event->createBlock([
                'block_type' => 'heart_rate',
                'time' => $event->time,
                'title' => 'Data Points',
                'metadata' => ['type' => 'count', 'context' => 'daily_series'],
                'value' => (int) $points->count(),
                'value_multiplier' => 1,
                'value_unit' => 'count',
            ]);
        }
    }

    protected function createSessionEvent(Integration $integration, array $item): void
    {
        $start = Arr::get($item, 'start_datetime') ?? Arr::get($item, 'timestamp');
        $day = $start ? Str::substr($start, 0, 10) : (Arr::get($item, 'day') ?? now()->toDateString());
        $sourceId = "oura_session_{$integration->id}_" . (Arr::get($item, 'id') ?? ($day . '_' . md5(json_encode($item))));
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $this->ensureUserProfile($integration);
        $target = EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'mindfulness_session',
            'type' => Arr::get($item, 'type', 'session'),
            'title' => Str::title((string) Arr::get($item, 'type', 'Session')),
        ], [
            'time' => $start ?? ($day . ' 00:00:00'),
            'content' => 'Oura guided or unguided session',
            'metadata' => $item,
        ]);

        $durationSec = (int) Arr::get($item, 'duration', 0);
        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $start ?? ($day . ' 00:00:00'),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => self::getDomain(),
            'action' => 'had_mindfulness_session',
            'value' => $durationSec,
            'value_multiplier' => 1,
            'value_unit' => 'seconds',
            'target_id' => $target->id,
        ]);

        $state = Arr::get($item, 'mood', Arr::get($item, 'state'));
        if ($state) {
            $event->createBlock([
                'block_type' => 'biometrics',
                'time' => $event->time,
                'title' => 'State',
                'metadata' => ['type' => 'mood_state', 'value' => (string) $state],
                'content' => (string) $state,
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
            ]);
        }
    }

    protected function createTagEvent(Integration $integration, array $item): void
    {
        $timestamp = Arr::get($item, 'timestamp') ?? Arr::get($item, 'time') ?? now()->toIso8601String();
        $day = Str::substr($timestamp, 0, 10);
        $sourceId = "oura_tag_{$integration->id}_" . md5(json_encode($item));
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $this->ensureUserProfile($integration);
        // Create a simple target object for tag to satisfy non-null target_id
        $tagTarget = EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'tag',
            'type' => 'oura_tag',
            'title' => 'Oura Tag',
        ], [
            'time' => $timestamp,
            'content' => 'Oura tag entry',
            'metadata' => $item,
        ]);

        $label = Arr::get($item, 'tag') ?? Arr::get($item, 'label', 'Tag');
        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $timestamp,
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => self::getDomain(),
            'action' => 'had_oura_tag',
            'value' => null,
            'value_multiplier' => 1,
            'value_unit' => null,
            'event_metadata' => [
                'day' => $day,
                'label' => $label,
            ],
            'target_id' => $tagTarget->id,
        ]);

        $event->createBlock([
            'block_type' => 'tag_info',
            'time' => $event->time,
            'title' => 'Tag',
            'metadata' => ['type' => 'user_tag', 'label' => (string) $label],
            'content' => (string) $label,
        ]);
    }

    /**
     * Get the appropriate log channel for this plugin
     */
    protected function getLogChannel(): string
    {
        $pluginChannel = 'api_debug_' . str_replace([' ', '-', '_'], '_', static::getIdentifier());

        return config('logging.channels.' . $pluginChannel) ? $pluginChannel : 'api_debug';
    }

    /**
     * Log webhook payload for debugging
     */
    protected function logWebhookPayload(string $service, string $integrationId, array $payload, array $headers = []): void
    {
        log_integration_webhook(
            $service,
            $integrationId,
            $this->sanitizeData($payload),
            $this->sanitizeHeaders($headers),
            true // Use per-instance logging
        );
    }

    /**
     * Sanitize headers for logging (remove sensitive data)
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'x-auth-token'];
        $sanitized = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveHeaders)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize data for logging (remove sensitive data)
     */
    protected function sanitizeData(array $data): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth'];
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveKeys)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize response body for logging (limit size and remove sensitive data)
     */
    protected function sanitizeResponseBody(string $body): string
    {
        // Limit response body size to prevent huge logs
        $maxLength = 10000;
        if (strlen($body) > $maxLength) {
            return substr($body, 0, $maxLength) . '... [TRUNCATED]';
        }

        // Try to parse as JSON and sanitize sensitive fields
        $parsed = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            $sanitized = $this->sanitizeData($parsed);

            return json_encode($sanitized, JSON_PRETTY_PRINT);
        }

        return $body;
    }

    protected function fetchCardiovascularAge(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/daily_cardiovascular_age', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];

        foreach ($items as $item) {
            $day = $item['day'] ?? null;
            $vascularAge = $item['vascular_age'] ?? null;

            if (! $day || $vascularAge === null) {
                continue;
            }

            $sourceId = "oura_cardiovascular_age_{$integration->id}_{$day}";
            $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
            if ($exists) {
                continue;
            }

            $actor = $this->ensureUserProfile($integration);
            $target = EventObject::updateOrCreate([
                'user_id' => $integration->user_id,
                'concept' => 'metric',
                'type' => 'cardiovascular_age',
                'title' => 'Cardiovascular Age',
            ], [
                'time' => $day . ' 00:00:00',
                'content' => 'Estimated cardiovascular age measurement',
                'metadata' => $item,
            ]);

            [$encodedAge, $ageMultiplier] = $this->encodeNumericValue((float) $vascularAge);

            Event::create([
                'source_id' => $sourceId,
                'time' => $day . ' 00:00:00',
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'service' => 'oura',
                'domain' => self::getDomain(),
                'action' => 'had_cardiovascular_age',
                'value' => $encodedAge,
                'value_multiplier' => $ageMultiplier,
                'value_unit' => 'years',
                'event_metadata' => ['day' => $day],
                'target_id' => $target->id,
            ]);
        }
    }

    protected function fetchVO2Max(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/vO2_max', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];

        foreach ($items as $item) {
            $day = $item['day'] ?? null;
            $id = $item['id'] ?? null;
            $vo2Max = $item['vo2_max'] ?? null;

            if (! $day || ! $id || $vo2Max === null) {
                continue;
            }

            $sourceId = "oura_vo2_max_{$integration->id}_{$id}";
            $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
            if ($exists) {
                continue;
            }

            $actor = $this->ensureUserProfile($integration);
            $target = EventObject::updateOrCreate([
                'user_id' => $integration->user_id,
                'concept' => 'metric',
                'type' => 'vo2_max',
                'title' => 'VO2 Max',
            ], [
                'time' => $item['timestamp'] ?? ($day . ' 00:00:00'),
                'content' => 'Maximum oxygen consumption measurement',
                'metadata' => $item,
            ]);

            [$encodedVO2, $vo2Multiplier] = $this->encodeNumericValue((float) $vo2Max);

            Event::create([
                'source_id' => $sourceId,
                'time' => $item['timestamp'] ?? ($day . ' 00:00:00'),
                'integration_id' => $integration->id,
                'actor_id' => $actor->id,
                'service' => 'oura',
                'domain' => self::getDomain(),
                'action' => 'had_vo2_max',
                'value' => $encodedVO2,
                'value_multiplier' => $vo2Multiplier,
                'value_unit' => 'ml/kg/min',
                'event_metadata' => ['day' => $day, 'measurement_id' => $id],
                'target_id' => $target->id,
            ]);
        }
    }

    protected function fetchEnhancedTags(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/enhanced_tag', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];

        foreach ($items as $item) {
            // Use existing createTagEvent method or create enhanced version
            $this->createEnhancedTagEvent($integration, $item);
        }
    }

    protected function fetchSleepTime(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/sleep_time', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];

        foreach ($items as $item) {
            $this->createSleepTimeEvent($integration, $item);
        }
    }

    protected function fetchRestModePeriods(Integration $integration, string $startDate, string $endDate): void
    {
        $this->ensureUserProfile($integration);
        $json = $this->getJson('/usercollection/rest_mode_period', $integration, [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $items = $json['data'] ?? [];

        foreach ($items as $item) {
            $this->createRestModePeriodEvent($integration, $item);
        }
    }

    protected function createEnhancedTagEvent(Integration $integration, array $item): void
    {
        $id = $item['id'] ?? null;
        $startDay = $item['start_day'] ?? null;

        if (! $id || ! $startDay) {
            return;
        }

        $sourceId = "oura_enhanced_tag_{$integration->id}_{$id}";
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
        if ($exists) {
            return;
        }

        $duration = $this->calculateTagDuration($item);
        [$encodedDuration, $durationMultiplier] = $this->encodeNumericValue($duration);

        $actor = $this->ensureUserProfile($integration);
        $tagType = $item['tag_type_code'] ?? 'unknown';
        $customName = $item['custom_name'] ?? null;

        $target = EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'tag',
            'type' => 'enhanced_tag',
            'title' => $customName ?: "Enhanced Tag ({$tagType})",
        ], [
            'time' => $startDay . ' ' . ($item['start_time'] ?? '00:00:00'),
            'content' => $item['comment'] ?: 'Enhanced tag with detailed metadata',
            'metadata' => $item,
        ]);

        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $startDay . ' ' . ($item['start_time'] ?? '00:00:00'),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => self::getDomain(),
            'action' => 'had_enhanced_tag',
            'value' => $encodedDuration,
            'value_multiplier' => $durationMultiplier,
            'value_unit' => 'seconds',
            'event_metadata' => [
                'start_day' => $startDay,
                'end_day' => $item['end_day'] ?? null,
                'tag_type' => $tagType,
                'tag_id' => $id,
            ],
            'target_id' => $target->id,
        ]);

        // Add tag details as blocks
        if ($tagType) {
            $event->createBlock([
                'block_type' => 'tag_info',
                'time' => $event->time,
                'integration_id' => $integration->id,
                'title' => 'Tag Type',
                'metadata' => ['type' => 'tag_type_code', 'code' => $tagType],
                'content' => $tagType,
            ]);
        }

        if ($item['comment'] ?? null) {
            $event->createBlock([
                'block_type' => 'tag_info',
                'time' => $event->time,
                'integration_id' => $integration->id,
                'title' => 'Comment',
                'metadata' => ['type' => 'user_comment'],
                'content' => $item['comment'],
            ]);
        }
    }

    protected function createSleepTimeEvent(Integration $integration, array $item): void
    {
        $day = $item['day'] ?? null;
        $id = $item['id'] ?? null;

        if (! $day || ! $id) {
            return;
        }

        $sourceId = "oura_sleep_time_{$integration->id}_{$id}";
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $this->ensureUserProfile($integration);
        $recommendation = $item['recommendation'] ?? 'Sleep timing recommendation';

        $target = EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'recommendation',
            'type' => 'sleep_recommendation',
            'title' => 'Sleep Recommendation',
        ], [
            'time' => $day . ' 00:00:00',
            'content' => $recommendation,
            'metadata' => $item,
        ]);

        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $day . ' 00:00:00',
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => self::getDomain(),
            'action' => 'had_sleep_recommendation',
            'value' => null,
            'value_multiplier' => 1,
            'value_unit' => null,
            'event_metadata' => [
                'day' => $day,
                'status' => $item['status'] ?? 'unknown',
                'recommendation_id' => $id,
            ],
            'target_id' => $target->id,
        ]);

        // Add recommendation blocks
        if ($recommendation) {
            $event->createBlock([
                'block_type' => 'recommendation',
                'time' => $event->time,
                'integration_id' => $integration->id,
                'title' => 'Recommendation',
                'metadata' => ['type' => 'sleep_guidance'],
                'content' => $recommendation,
            ]);
        }
    }

    protected function createRestModePeriodEvent(Integration $integration, array $item): void
    {
        $id = $item['id'] ?? null;
        $startDay = $item['start_day'] ?? null;

        if (! $id || ! $startDay) {
            return;
        }

        $duration = $this->calculateRestPeriodDuration($item);
        [$encodedDuration, $durationMultiplier] = $this->encodeNumericValue($duration);

        $sourceId = "oura_rest_mode_period_{$integration->id}_{$id}";
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $this->ensureUserProfile($integration);
        $episodes = $item['episodes'] ?? [];

        $target = EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'rest_period',
            'type' => 'rest_period',
            'title' => 'Rest Mode Period',
        ], [
            'time' => $startDay . ' ' . ($item['start_time'] ?? '00:00:00'),
            'content' => 'Rest mode period with episodes',
            'metadata' => $item,
        ]);

        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $startDay . ' ' . ($item['start_time'] ?? '00:00:00'),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => self::getDomain(),
            'action' => 'had_rest_period',
            'value' => $encodedDuration,
            'value_multiplier' => $durationMultiplier,
            'value_unit' => 'seconds',
            'event_metadata' => [
                'start_day' => $startDay,
                'end_day' => $item['end_day'] ?? null,
                'episode_count' => is_array($episodes) ? count($episodes) : 0,
                'period_id' => $id,
            ],
            'target_id' => $target->id,
        ]);

        // Add episode blocks
        if (is_array($episodes) && count($episodes) > 0) {
            $event->createBlock([
                'block_type' => 'biometrics',
                'time' => $event->time,
                'integration_id' => $integration->id,
                'title' => 'Episodes',
                'metadata' => ['type' => 'episode_count', 'episodes' => $episodes],
                'value' => count($episodes),
                'value_multiplier' => 1,
                'value_unit' => 'count',
            ]);
        }
    }

    private function createMappedValueEvent(
        Integration $integration,
        string $action,
        string $day,
        mixed $originalValue,
        string $mappingKey
    ): void {
        $mappedValue = $this->mapValueForStorage($mappingKey, $originalValue);

        if ($mappedValue === null) {
            return; // Skip if no mapping found
        }

        $sourceId = "oura_{$action}_{$integration->id}_{$day}";

        if (Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->exists()) {
            return;
        }

        $actor = $this->ensureUserProfile($integration);

        // Create a simple target object to satisfy non-null target_id constraint
        $target = EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'mapped_value',
            'type' => 'oura_mapped_value',
            'title' => 'Oura Mapped Value',
        ], [
            'time' => $day . ' 12:00:00',
            'content' => 'Oura mapped value entry',
            'metadata' => [
                'mapping_key' => $mappingKey,
                'original_value' => $originalValue,
                'mapped_value' => $mappedValue,
            ],
        ]);

        [$encodedValue, $multiplier] = $this->encodeNumericValue($mappedValue);

        Event::create([
            'source_id' => $sourceId,
            'integration_id' => $integration->id,
            'user_id' => $integration->user_id,
            'action' => $action,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
            'time' => $day . ' 12:00:00',
            'value' => $encodedValue,
            'value_multiplier' => $multiplier,
            'service' => 'oura',
            'domain' => self::getDomain(),
            'metadata' => [
                'original_value' => $originalValue,
                'mapping_key' => $mappingKey,
                'mapped_value' => $mappedValue,
            ],
        ]);
    }

    /**
     * Helper used by migration for sleep_records
     */
    private function createSleepRecordFromItem(Integration $integration, array $item): void
    {
        $start = Arr::get($item, 'bedtime_start');
        $end = Arr::get($item, 'bedtime_end');
        $day = $start ? Str::substr($start, 0, 10) : (Arr::get($item, 'day') ?? now()->toDateString());
        $id = Arr::get($item, 'id') ?? md5(json_encode([$day, Arr::get($item, 'duration', 0), Arr::get($item, 'total', 0)]));
        $sourceId = "oura_sleep_record_{$integration->id}_{$id}";
        $exists = Event::where('source_id', $sourceId)->where('integration_id', $integration->id)->first();
        if ($exists) {
            return;
        }

        $actor = $this->ensureUserProfile($integration);
        $target = EventObject::updateOrCreate([
            'user_id' => $integration->user_id,
            'concept' => 'sleep',
            'type' => 'oura_sleep_record',
            'title' => 'Sleep Record',
        ], [
            'time' => $start ?? ($day . ' 00:00:00'),
            'content' => 'Detailed sleep record including stages and efficiency',
            'metadata' => $item,
        ]);

        // Use total_sleep_duration as the main value (consistent with OuraSleepRecordsData job)
        $totalSleepDuration = (int) Arr::get($item, 'total_sleep_duration', Arr::get($item, 'duration', 0));
        $efficiency = Arr::get($item, 'efficiency');
        $event = Event::create([
            'source_id' => $sourceId,
            'time' => $start ?? ($day . ' 00:00:00'),
            'integration_id' => $integration->id,
            'actor_id' => $actor->id,
            'service' => 'oura',
            'domain' => self::getDomain(),
            'action' => 'slept_for',
            'value' => $totalSleepDuration,
            'value_multiplier' => 1,
            'value_unit' => 'seconds',
            'event_metadata' => [
                'end' => $end,
                'efficiency' => $efficiency,
            ],
            'target_id' => $target->id,
        ]);

        // Use actual API field names (not sleep_stages array)
        $sleepStages = [
            'deep_sleep_duration' => 'Deep Sleep',
            'light_sleep_duration' => 'Light Sleep',
            'rem_sleep_duration' => 'REM Sleep',
            'awake_time' => 'Awake Time',
        ];
        foreach ($sleepStages as $field => $title) {
            $seconds = Arr::get($item, $field);
            if ($seconds === null) {
                continue;
            }
            $event->createBlock([
                'block_type' => 'sleep_stages',
                'time' => $event->time,
                'integration_id' => $integration->id,
                'title' => $title,
                'metadata' => ['type' => 'stage_duration', 'field' => $field],
                'value' => (int) $seconds,
                'value_multiplier' => 1,
                'value_unit' => 'seconds',
            ]);
        }

        $hrAvg = Arr::get($item, 'average_heart_rate');
        if ($hrAvg !== null) {
            [$encodedHrAvg, $hrAvgMultiplier] = $this->encodeNumericValue($hrAvg);
            $event->createBlock([
                'block_type' => 'heart_rate',
                'time' => $event->time,
                'integration_id' => $integration->id,
                'title' => 'Average Heart Rate',
                'metadata' => ['type' => 'average', 'context' => 'sleep'],
                'value' => $encodedHrAvg,
                'value_multiplier' => $hrAvgMultiplier,
                'value_unit' => 'bpm',
            ]);
        }
    }

    private function calculateTagDuration(array $item): ?int
    {
        $startDay = $item['start_day'] ?? null;
        $startTime = $item['start_time'] ?? null;
        $endDay = $item['end_day'] ?? null;
        $endTime = $item['end_time'] ?? null;

        if (! $startDay || ! $endDay || ! $endTime) {
            return null;
        }

        try {
            $start = Carbon::parse($startDay . ' ' . ($startTime ?? '00:00:00'));
            $end = Carbon::parse($endDay . ' ' . $endTime);

            return $end->diffInSeconds($start);
        } catch (Exception $e) {
            return null;
        }
    }

    private function calculateRestPeriodDuration(array $item): ?int
    {
        return $this->calculateTagDuration($item); // Same logic
    }
}
