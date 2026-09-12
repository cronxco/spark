<?php

namespace Tests\Unit\Services;

use App\Models\EventObject;
use App\Models\MetricStatistic;
use App\Services\MetricPresentation;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetricPresentationTest extends TestCase
{
    private MetricPresentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presentation = new MetricPresentation;
    }

    // -------------------------------------------------------------------------
    // Naming
    // -------------------------------------------------------------------------

    /**
     * "Had Balance" was the title-cased raw event action. `had_` is the event
     * verb ("Will *had* balance"), not part of the metric's name — and the
     * plugin already declared the right one.
     */
    #[Test]
    public function uses_the_plugin_declared_display_name(): void
    {
        $statistic = $this->statistic('gocardless', 'had_balance', 'GBP');

        $this->assertSame('Balance Update', $this->presentation->displayName($statistic));
    }

    #[Test]
    public function uses_the_plugin_declared_name_for_oura_scores(): void
    {
        $statistic = $this->statistic('oura', 'had_cardiovascular_age', 'years');

        $this->assertSame('Cardiovascular Age', $this->presentation->displayName($statistic));
    }

    #[Test]
    public function strips_the_action_verb_when_no_name_is_declared(): void
    {
        $statistic = $this->statistic('oura', 'had_something_undeclared', 'units');

        $this->assertSame('Something Undeclared', $this->presentation->displayName($statistic));
    }

    #[Test]
    public function falls_back_to_the_action_for_an_unknown_service(): void
    {
        $statistic = $this->statistic('not-a-real-service', 'had_widgets', 'units');

        $this->assertSame('Widgets', $this->presentation->displayName($statistic));
    }

    // -------------------------------------------------------------------------
    // Domain
    // -------------------------------------------------------------------------

    #[Test]
    public function resolves_the_plugin_domain(): void
    {
        $this->assertSame('health', $this->presentation->domain($this->statistic('oura', 'had_sleep_score', 'percent')));
        $this->assertSame('money', $this->presentation->domain($this->statistic('gocardless', 'had_balance', 'GBP')));
    }

    #[Test]
    public function returns_no_domain_for_an_unknown_service(): void
    {
        $this->assertNull($this->presentation->domain($this->statistic('not-a-real-service', 'had_widgets', 'units')));
    }

    // -------------------------------------------------------------------------
    // Value formatting
    // -------------------------------------------------------------------------

    #[Test]
    public function formats_currency_through_the_plugin_formatter(): void
    {
        $statistic = $this->statistic('gocardless', 'had_balance', 'GBP');

        $this->assertSame('£2,082.23', $this->presentation->formatValue($statistic, 2082.23));
        $this->assertSame('£241.68', $this->presentation->formatValue($statistic, 241.68));
        $this->assertSame('€241.68', $this->presentation->formatValue(
            $this->statistic('gocardless', 'had_balance', 'EUR'),
            241.68
        ));
    }

    /**
     * Plugin formatters are Blade templates written for the web and wrap units
     * in markup. API clients render text, so the tags must not survive.
     */
    #[Test]
    public function strips_web_markup_from_formatted_values(): void
    {
        $statistic = $this->statistic('oura', 'had_readiness_score', 'percent');

        $formatted = $this->presentation->formatValue($statistic, 78.0);

        $this->assertStringNotContainsString('<', (string) $formatted);
        $this->assertSame('78%', $formatted);
    }

    #[Test]
    public function renders_an_ordinal_band_as_its_word(): void
    {
        $statistic = $this->statistic('oura', 'had_resilience_score', 'resilience_level');

        $this->assertSame('Limited', $this->presentation->formatValue($statistic, 1.0));
        $this->assertSame('Adequate', $this->presentation->formatValue($statistic, 2.0));
        $this->assertSame('Exceptional', $this->presentation->formatValue($statistic, 5.0));
    }

    #[Test]
    public function formats_null_as_null(): void
    {
        $statistic = $this->statistic('oura', 'had_sleep_score', 'percent');

        $this->assertNull($this->presentation->formatValue($statistic, null));
    }

    // -------------------------------------------------------------------------
    // Ordinality
    // -------------------------------------------------------------------------

    /**
     * A mean over "Limited / Adequate / Solid / Strong / Exceptional" is not a
     * meaningful number, so clients need to know not to treat it as one.
     */
    #[Test]
    public function identifies_ordinal_metrics(): void
    {
        $this->assertTrue($this->presentation->isOrdinal($this->statistic('oura', 'had_resilience_score', 'resilience_level')));
        $this->assertTrue($this->presentation->isOrdinal($this->statistic('oura', 'had_stress_score', 'stress_level')));
        $this->assertFalse($this->presentation->isOrdinal($this->statistic('oura', 'had_resilience_score', 'percent')));
        $this->assertFalse($this->presentation->isOrdinal($this->statistic('oura', 'had_stress_score', 'percent')));
        $this->assertFalse($this->presentation->isOrdinal($this->statistic('oura', 'had_sleep_score', 'percent')));
    }

    #[Test]
    public function formats_oura_percent_variants_as_continuous_metrics(): void
    {
        $stress = $this->statistic('oura', 'had_stress_score', 'percent');
        $resilience = $this->statistic('oura', 'had_resilience_score', 'percent');

        $this->assertSame('78%', $this->presentation->formatValue($stress, 78.0));
        $this->assertSame('84%', $this->presentation->formatValue($resilience, 84.0));
    }

    // -------------------------------------------------------------------------
    // Valence
    // -------------------------------------------------------------------------

    /**
     * Direction is not valence. A balance going up is good news; a
     * cardiovascular age going up is not. The shipped UI tinted both as
     * warnings because it only had `direction`.
     */
    #[Test]
    public function a_rising_asset_balance_is_good_news(): void
    {
        $statistic = $this->statistic('gocardless', 'had_balance', 'GBP');
        $account = new EventObject(['metadata' => ['account_type' => 'current_account']]);

        $this->assertSame('good', $this->presentation->valence($statistic, 'up', $account));
        $this->assertSame('bad', $this->presentation->valence($statistic, 'down', $account));
    }

    #[Test]
    public function a_rising_liability_balance_is_bad_news(): void
    {
        $manualStatistic = $this->statistic('manual_account', 'had_balance', 'GBP');
        $manualLiability = new EventObject(['metadata' => ['is_negative_balance' => true]]);
        $bankStatistic = $this->statistic('gocardless', 'had_balance', 'EUR');

        $this->assertSame('bad', $this->presentation->valence($manualStatistic, 'up', $manualLiability));

        foreach (['credit_card', 'loan'] as $accountType) {
            $account = new EventObject(['metadata' => ['account_type' => $accountType]]);
            $this->assertSame('bad', $this->presentation->valence($bankStatistic, 'up', $account));
            $this->assertSame('good', $this->presentation->valence($bankStatistic, 'down', $account));
        }
    }

    #[Test]
    public function a_rising_cardiovascular_age_is_bad_news(): void
    {
        $statistic = $this->statistic('oura', 'had_cardiovascular_age', 'years');

        $this->assertSame('bad', $this->presentation->valence($statistic, 'up'));
        $this->assertSame('good', $this->presentation->valence($statistic, 'down'));
    }

    #[Test]
    public function falling_resilience_is_bad_news(): void
    {
        $statistic = $this->statistic('oura', 'had_resilience_score', 'resilience_level');

        $this->assertSame('bad', $this->presentation->valence($statistic, 'down'));
        $this->assertSame('good', $this->presentation->valence($statistic, 'up'));
    }

    #[Test]
    public function an_undeclared_metric_is_neutral_rather_than_alarming(): void
    {
        $statistic = $this->statistic('not-a-real-service', 'had_widgets', 'units');

        $this->assertSame('neutral', $this->presentation->valence($statistic, 'up'));
        $this->assertSame('neutral', $this->presentation->valence($statistic, 'down'));
    }

    #[Test]
    public function an_unknown_direction_is_neutral(): void
    {
        $statistic = $this->statistic('gocardless', 'had_balance', 'GBP');

        $this->assertSame('neutral', $this->presentation->valence($statistic, 'neutral'));
    }

    #[Test]
    public function an_account_metric_without_account_identity_is_neutral(): void
    {
        $statistic = $this->statistic('gocardless', 'had_balance', 'GBP');

        $this->assertSame('neutral', $this->presentation->valence($statistic, 'up'));
    }

    // -------------------------------------------------------------------------
    // Flint exclusion
    // -------------------------------------------------------------------------

    #[Test]
    public function respects_exclude_from_flint(): void
    {
        $this->assertTrue($this->presentation->isExcludedFromFlint($this->statistic('gocardless', 'had_balance', 'GBP')));
        $this->assertFalse($this->presentation->isExcludedFromFlint($this->statistic('oura', 'had_sleep_score', 'percent')));
    }

    // -------------------------------------------------------------------------
    // Baseline comparison — ordinal metrics
    // -------------------------------------------------------------------------

    /**
     * Resilience is a five-point band. With a mean of 3.19 and a standard
     * deviation of 0.49, a reading of 2 ("Adequate") computes as -37% and sits
     * below the 2.2 lower bound — so every single step down was reported as a
     * double-digit collapse and flagged as an anomaly. It is one band.
     */
    #[Test]
    public function gives_no_percentage_for_an_ordinal_metric(): void
    {
        $statistic = $this->resilience();

        $this->assertTrue($this->presentation->isOrdinal($statistic));
        $this->assertNull($this->presentation->baselineDeltaPct($statistic, 2.0));
    }

    #[Test]
    public function one_band_below_usual_is_not_an_anomaly(): void
    {
        $this->assertFalse($this->presentation->isAnomalous($this->resilience(), 2.0));
    }

    #[Test]
    public function two_bands_from_usual_is_an_anomaly(): void
    {
        $this->assertTrue($this->presentation->isAnomalous($this->resilience(), 1.0));
        $this->assertTrue($this->presentation->isAnomalous($this->resilience(), 5.0));
    }

    #[Test]
    public function still_gives_a_percentage_for_a_continuous_metric(): void
    {
        $statistic = $this->sleepScore();

        $this->assertFalse($this->presentation->isOrdinal($statistic));
        $this->assertSame(-20.0, $this->presentation->baselineDeltaPct($statistic, 64.0));
    }

    #[Test]
    public function continuous_metrics_still_use_the_stored_bounds(): void
    {
        $statistic = $this->sleepScore();

        $this->assertTrue($this->presentation->isAnomalous($statistic, 55.0));
        $this->assertFalse($this->presentation->isAnomalous($statistic, 78.0));
    }

    #[Test]
    public function nothing_is_anomalous_without_valid_statistics(): void
    {
        $bare = $this->statistic('oura', 'had_resilience_score', 'resilience_level');

        $this->assertFalse($this->presentation->isAnomalous($bare, 1.0));
        $this->assertNull($this->presentation->baselineDeltaPct($bare, 1.0));
    }

    /** Will's real resilience baseline over the 90 days to 12 September 2026. */
    private function resilience(): MetricStatistic
    {
        return $this->withStats(
            $this->statistic('oura', 'had_resilience_score', 'resilience_level'),
            mean: 3.19, stddev: 0.49, lower: 2.2, upper: 4.17,
        );
    }

    private function sleepScore(): MetricStatistic
    {
        return $this->withStats(
            $this->statistic('oura', 'had_sleep_score', 'percent'),
            mean: 80.0, stddev: 8.0, lower: 64.5, upper: 96.0,
        );
    }

    private function withStats(
        MetricStatistic $statistic,
        float $mean,
        float $stddev,
        float $lower,
        float $upper,
    ): MetricStatistic {
        $statistic->fill([
            'event_count' => 90,
            'mean_value' => $mean,
            'stddev_value' => $stddev,
            'normal_lower_bound' => $lower,
            'normal_upper_bound' => $upper,
        ]);

        return $statistic;
    }

    private function statistic(string $service, string $action, string $unit): MetricStatistic
    {
        return new MetricStatistic([
            'service' => $service,
            'action' => $action,
            'value_unit' => $unit,
        ]);
    }
}
