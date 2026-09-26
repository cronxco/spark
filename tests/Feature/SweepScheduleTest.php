<?php

namespace Tests\Feature;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Integrations\Hevy\HevyPlugin;
use App\Integrations\Monzo\MonzoPlugin;
use App\Integrations\Oura\OuraPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SweepScheduleTest extends TestCase
{
    /**
     * The period each plugin reports must equal the age at which its
     * performSweepIfNeeded() sweeps again, or the Updates page's next-sweep
     * time drifts from when the sweep really runs.
     *
     * @return array<string, array{class-string, int}>
     */
    public static function sweepThresholds(): array
    {
        return [
            'GoCardless sweeps after 6 days' => [GoCardlessBankPlugin::class, 6 * 24],
            'Hevy sweeps after 6 days' => [HevyPlugin::class, 6 * 24],
            'Monzo sweeps after 22 hours' => [MonzoPlugin::class, 22],
            'Oura sweeps after 22 hours' => [OuraPlugin::class, 22],
        ];
    }

    #[Test]
    #[DataProvider('sweepThresholds')]
    public function reported_period_matches_the_sweep_threshold(string $pluginClass, int $thresholdHours): void
    {
        $this->assertSame($thresholdHours, $pluginClass::getSweepSchedule()['period_hours']);
    }
}
