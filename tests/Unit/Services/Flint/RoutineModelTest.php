<?php

namespace Tests\Unit\Services\Flint;

use App\Services\Flint\RoutineModel;
use PHPUnit\Framework\Attributes\Test;
use Tests\FrameworkTestCase;

class RoutineModelTest extends FrameworkTestCase
{
    #[Test]
    public function the_openai_driver_reports_the_configured_reasoning_model(): void
    {
        config(['services.openai.models.reasoning' => 'gpt-test-reasoning']);

        $this->assertSame('gpt-test-reasoning', RoutineModel::for('openai'));
    }

    #[Test]
    public function the_webhook_driver_and_an_unknown_driver_report_no_model(): void
    {
        config(['services.openai.models.reasoning' => 'gpt-test-reasoning']);

        $this->assertNull(RoutineModel::for('webhook'));
        $this->assertNull(RoutineModel::for(null));
    }

    #[Test]
    public function an_unconfigured_reasoning_model_reports_no_model(): void
    {
        config(['services.openai.models.reasoning' => '']);

        $this->assertNull(RoutineModel::for('openai'));
    }
}
